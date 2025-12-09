package server

import (
	"encoding/binary"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"testing"
	"time"
)

// newFakeStreamWorker simulates a PHP worker that speaks the streaming protocol:
// it reads a length-prefixed RequestPayload, then emits a header frame,
// zero or more chunk frames, and an end frame.
func newFakeStreamWorker(t *testing.T, status int, headers map[string]string, chunks []string) *Worker {
	t.Helper()

	stdinR, stdinW := io.Pipe()
	stdoutR, stdoutW := io.Pipe()

	w := &Worker{
		stdin:          stdinW,
		stdout:         stdoutR,
		maxRequests:    100,
		requestTimeout: 0, // disable timeout for this test
	}

	go func() {
		defer func(stdinR *io.PipeReader) {
			_ = stdinR.Close()
		}(stdinR)
		defer func(stoutW *io.PipeWriter) {
			_ = stdoutW.Close()
		}(stdoutW)

		// 1) Read request header (length) and JSON body sent by streamInternal
		hdr := make([]byte, 4)
		if _, err := io.ReadFull(stdinR, hdr); err != nil {
			return
		}
		length := binary.BigEndian.Uint32(hdr)

		body := make([]byte, length)
		if _, err := io.ReadFull(stdinR, body); err != nil {
			return
		}

		var req RequestPayload
		_ = json.Unmarshal(body, &req) // don't need this
		// helper to write a single StreamFrame back to the worker.stdout
		writeFrame := func(frame StreamFrame) error {
			b, err := json.Marshal(frame)
			if err != nil {
				return err
			}

			header := make([]byte, 4)
			binary.BigEndian.PutUint32(header, uint32(len(b)))

			if _, err := stdoutW.Write(header); err != nil {
				return err
			}

			if _, err := stdoutW.Write(b); err != nil {
				return err
			}

			return nil
		}

		if headers == nil {
			headers = map[string]string{}
		}

		// 2) headers frame (with initial body chunk)
		_ = writeFrame(StreamFrame{
			Type:    "headers",
			Status:  status,
			Headers: headers,
			Data:    "hello",
		})

		// 3) chunk frames
		for _, c := range chunks {
			_ = writeFrame(StreamFrame{
				Type: "chunk",
				Data: c,
			})
		}

		// 4) end frame
		_ = writeFrame(StreamFrame{
			Type: "end",
		})
	}()

	return w
}

func TestWorkerStreamHappyPath(t *testing.T) {
	hdrs := map[string]string{"X-Test": "ok"}
	w := newFakeStreamWorker(t, http.StatusCreated, hdrs, []string{"world"})

	req := &RequestPayload{
		ID:      "1",
		Method:  "GET",
		Path:    "/stream",
		Headers: map[string][]string{},
		Body:    "",
	}

	rr := httptest.NewRecorder()

	if err := w.Stream(req, rr); err != nil {
		t.Fatalf("stream returned error: %v", err)
	}

	resp := rr.Result()
	if resp.StatusCode != http.StatusCreated {
		t.Fatalf("expected status %d, get %d", http.StatusCreated, resp.StatusCode)
	}
	if got := resp.Header.Get("X-Test"); got != "ok" {
		t.Fatalf("expected X-Test=ok header, got %q", got)
	}
	body, _ := io.ReadAll(resp.Body)

	// just testing concatenation
	if string(body) != "helloworld" {
		t.Fatalf("unexpected body %q, want %q", string(body), "helloworld")
	}
}

// TestEnableHotReloadHappyPath makes sure that when a watched file changes,
// EnableHotReload's watcher eventually calls markAllWorkersDead.
func TestEnableHotReloadHappyPath(t *testing.T) {
	tmp := t.TempDir()

	phpDir := filepath.Join(tmp, "php")
	routesDir := filepath.Join(tmp, "routes")
	if err := os.MkdirAll(phpDir, 0o755); err != nil {
		t.Fatalf("mkdir php: %v", err)
	}
	if err := os.MkdirAll(routesDir, 0o755); err != nil {
		t.Fatalf("mkdir routes: %v", err)
	}

	fast := &Worker{}
	slow := &Worker{}

	s := &Server{
		fastPool: &WorkerPool{workers: []*Worker{fast}},
		slowPool: &WorkerPool{workers: []*Worker{slow}},
		slowCfg:  SlowRequestConfig{},
	}

	if err := s.EnableHotReload(tmp); err != nil {
		t.Fatalf("EnableHotReload returned error: %v", err)
	}

	// Touch a file in php/ to trigger a change event
	testFile := filepath.Join(phpDir, "test.php")
	if err := os.WriteFile(testFile, []byte("<?php // test"), 0o644); err != nil {
		t.Fatalf("write test file: %v", err)
	}

	// wait up to 2 sreconds for the watcher goroutine to observe the change.
	deadline := time.Now().Add(2 * time.Second)
	for time.Now().Before(deadline) {
		if fast.isDead() && slow.isDead() {
			return // success
		}
		time.Sleep(20 * time.Millisecond)
	}

	t.Fatalf("expected workers to be marked dead after file change; fast.dead=%v slow.dead=%v", fast.isDead(), slow.isDead())
}
