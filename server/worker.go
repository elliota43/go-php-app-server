package server

import (
	"encoding/json"
	"fmt"
	"io"
	"log"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"sync"
	"sync/atomic"
	"time"
)

type Worker struct {
	cmd            *exec.Cmd
	stdin          io.WriteCloser
	stdout         io.ReadCloser
	mu             sync.Mutex
	baseDir        string
	dead           bool
	deadMu         sync.RWMutex
	maxRequests    int
	requestTimeout time.Duration
	requestCount   uint64
}

func NewWorker(maxRequests int, requestTimeout time.Duration) (*Worker, error) {
	wd, err := os.Getwd()
	if err != nil {
		return nil, err
	}

	baseDir := wd
	for {
		if _, err := os.Stat(filepath.Join(baseDir, "go.mod")); err == nil {
			break
		}
		parent := filepath.Dir(baseDir)
		if parent == baseDir {
			break
		}
		baseDir = parent
	}

	workerPath := filepath.Join(baseDir, "php", "worker.php")

	cmd := exec.Command("php", workerPath)
	cmd.Dir = baseDir

	stdin, err := cmd.StdinPipe()
	if err != nil {
		return nil, err
	}

	stdout, err := cmd.StdoutPipe()
	if err != nil {
		stdin.Close()
		return nil, err
	}

	cmd.Stderr = log.Writer()

	if err := cmd.Start(); err != nil {
		stdin.Close()
		stdout.Close()
		return nil, err
	}

	return &Worker{
		cmd:            cmd,
		stdin:          stdin,
		stdout:         stdout,
		baseDir:        baseDir,
		dead:           false,
		maxRequests:    maxRequests,
		requestTimeout: requestTimeout,
	}, nil
}

func (w *Worker) isDead() bool {
	w.deadMu.RLock()
	defer w.deadMu.RUnlock()
	return w.dead
}

func (w *Worker) markDead() {
	w.deadMu.Lock()
	w.dead = true
	w.deadMu.Unlock()
}

func (w *Worker) restart() error {
	w.mu.Lock()
	defer w.mu.Unlock()

	if w.stdin != nil {
		_ = w.stdin.Close()
	}
	if w.stdout != nil {
		_ = w.stdout.Close()
	}
	if w.cmd != nil && w.cmd.Process != nil {
		_ = w.cmd.Process.Kill()
		_, _ = w.cmd.Process.Wait()
	}

	workerPath := filepath.Join(w.baseDir, "php", "worker.php")
	cmd := exec.Command("php", workerPath)
	cmd.Dir = w.baseDir

	stdin, err := cmd.StdinPipe()
	if err != nil {
		return err
	}

	stdout, err := cmd.StdoutPipe()
	if err != nil {
		stdin.Close()
		return err
	}

	cmd.Stderr = log.Writer()

	if err := cmd.Start(); err != nil {
		stdin.Close()
		stdout.Close()
		return err
	}

	w.cmd = cmd
	w.stdin = stdin
	w.stdout = stdout

	w.deadMu.Lock()
	w.dead = false
	w.deadMu.Unlock()

	atomic.StoreUint64(&w.requestCount, 0)

	log.Println("Restarted PHP worker in", w.baseDir)

	return nil
}

func (w *Worker) Handle(payload *RequestPayload) (*ResponsePayload, error) {
	for attempt := 0; attempt < 2; attempt++ {
		if w.isDead() {
			if err := w.restart(); err != nil {
				return nil, err
			}
		}

		resp, err := w.handleRequest(payload)
		if err != nil {
			if isBrokenPipe(err) {
				w.markDead()
				continue
			}
			return nil, err
		}

		// increment request count and recycle if exceeding maxRequests
		n := atomic.AddUint64(&w.requestCount, 1)
		if w.maxRequests > 0 && int(n) >= w.maxRequests {
			w.markDead()
		}

		return resp, nil
	}

	return nil, io.ErrUnexpectedEOF
}

func isBrokenPipe(err error) bool {
	if err == nil {
		return false
	}
	errStr := err.Error()
	return err == io.EOF ||
		err == io.ErrUnexpectedEOF ||
		strings.Contains(errStr, "broken pipe") ||
		strings.Contains(errStr, "write |1:") ||
		strings.Contains(errStr, "read |0:")
}

func (w *Worker) handleRequest(payload *RequestPayload) (*ResponsePayload, error) {
	w.mu.Lock()
	defer w.mu.Unlock()

	jsonBytes, err := json.Marshal(payload)
	if err != nil {
		return nil, err
	}
	length := uint32(len(jsonBytes))

	header := []byte{
		byte(length >> 24),
		byte(length >> 16),
		byte(length >> 8),
		byte(length),
	}

	if _, err := w.stdin.Write(header); err != nil {
		return nil, err
	}
	if _, err := w.stdin.Write(jsonBytes); err != nil {
		return nil, err
	}

	type result struct {
		resp *ResponsePayload
		err  error
	}

	resCh := make(chan result, 1)

	go func() {
		// read length header
		hdr := make([]byte, 4)
		if _, err := io.ReadFull(w.stdout, hdr); err != nil {
			resCh <- result{nil, err}
			return
		}

		respLen := (uint32(hdr[0]) << 24) |
			(uint32(hdr[1]) << 16) |
			(uint32(hdr[2]) << 8) |
			uint32(hdr[3])

		if respLen == 0 || respLen > 10*1024*1024 {
			resCh <- result{nil, io.ErrUnexpectedEOF}
			return
		}

		respJSON := make([]byte, respLen)
		if _, err := io.ReadFull(w.stdout, respJSON); err != nil {
			resCh <- result{nil, err}
			return
		}

		var resp ResponsePayload
		if err := json.Unmarshal(respJSON, &resp); err != nil {
			resCh <- result{nil, err}
			return
		}

		resCh <- result{&resp, nil}
	}()

	if w.requestTimeout > 0 {
		select {
		case res := <-resCh:
			return res.resp, res.err
		case <-time.After(w.requestTimeout):
			// Kill and mark dead on timeout
			w.markDead()
			if w.cmd != nil && w.cmd.Process != nil {
				_ = w.cmd.Process.Kill()
				_, _ = w.cmd.Process.Wait()
			}
			return nil, fmt.Errorf("worker request timeout after %s", w.requestTimeout)
		}
	}

	res := <-resCh
	return res.resp, res.err
}
