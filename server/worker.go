package server

import (
	"encoding/json"
	"io"
	"log"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"sync"
)

type Worker struct {
	cmd     *exec.Cmd
	stdin   io.WriteCloser
	stdout  io.ReadCloser
	mu      sync.Mutex
	baseDir string
	dead    bool
	deadMu  sync.RWMutex
}

func NewWorker() (*Worker, error) {
	// Get the base directory (where go.mod is located)
	wd, err := os.Getwd()
	if err != nil {
		return nil, err
	}

	// Try to find the project root by looking for go.mod
	baseDir := wd
	for {
		if _, err := os.Stat(filepath.Join(baseDir, "go.mod")); err == nil {
			break
		}
		parent := filepath.Dir(baseDir)
		if parent == baseDir {
			// Reached root, use current directory
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
		return nil, err
	}

	cmd.Stderr = log.Writer()

	if err := cmd.Start(); err != nil {
		return nil, err
	}

	return &Worker{
		cmd:     cmd,
		stdin:   stdin,
		stdout:  stdout,
		baseDir: baseDir,
		dead:    false,
	}, nil
}

func (w *Worker) isDead() bool {
	w.deadMu.RLock()
	defer w.deadMu.RUnlock()
	return w.dead
}

func (w *Worker) markDead() {
	w.deadMu.Lock()
	defer w.deadMu.Unlock()
	w.dead = true
}

func (w *Worker) restart() error {
	w.mu.Lock()
	defer w.mu.Unlock()

	// Close old pipes if still open
	if w.stdin != nil {
		w.stdin.Close()
	}
	if w.stdout != nil {
		w.stdout.Close()
	}

	// Kill old process if still running
	if w.cmd != nil && w.cmd.Process != nil {
		w.cmd.Process.Kill()
		w.cmd.Wait()
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

	return nil
}

func (w *Worker) Handle(payload *RequestPayload) (*ResponsePayload, error) {
	// Retry logic: if worker is dead or fails, restart and retry once
	for attempt := 0; attempt < 2; attempt++ {
		if w.isDead() {
			if err := w.restart(); err != nil {
				return nil, err
			}
		}

		resp, err := w.handleRequest(payload)
		if err != nil {
			// If we get a broken pipe or EOF error, mark as dead and retry
			if isBrokenPipe(err) {
				w.markDead()
				continue
			}
			return nil, err
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

	// Encode request
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

	// Write header + body
	if _, err := w.stdin.Write(header); err != nil {
		return nil, err
	}
	if _, err := w.stdin.Write(jsonBytes); err != nil {
		return nil, err
	}

	// Read 4-byte length
	hdr := make([]byte, 4)
	if _, err := io.ReadFull(w.stdout, hdr); err != nil {
		return nil, err
	}

	respLen := (uint32(hdr[0]) << 24) |
		(uint32(hdr[1]) << 16) |
		(uint32(hdr[2]) << 8) |
		uint32(hdr[3])

	if respLen == 0 || respLen > 10*1024*1024 { // 10MB max
		return nil, io.ErrUnexpectedEOF
	}

	respJSON := make([]byte, respLen)
	if _, err := io.ReadFull(w.stdout, respJSON); err != nil {
		return nil, err
	}

	var resp ResponsePayload
	if err := json.Unmarshal(respJSON, &resp); err != nil {
		return nil, err
	}

	return &resp, nil
}
