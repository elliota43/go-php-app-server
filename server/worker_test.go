package server

import (
	"errors"
	"io"
	"testing"
	"time"
)

func TestWorkerHandleHappyPath(t *testing.T) {
	w := newFakeWorker(t, "w0", time.Second)

	resp, err := w.Handle(&RequestPayload{
		ID:     "abc",
		Method: "GET",
		Path:   "/test",
		Body:   "",
	})

	if err != nil {
		t.Fatalf("Handle returned error: %v", err)
	}

	if resp.Status != 200 {
		t.Fatalf("expected status 200, got %d", resp.Status)
	}

	if resp.Body != "w0:/test" {
		t.Fatalf("unexpected response body: %q", resp.Body)
	}
}

func TestIsBrokenPipe(t *testing.T) {
	if !isBrokenPipe(io.EOF) {
		t.Fatalf("expected io.EOF to be treated as broken pipe")
	}

	if isBrokenPipe(nil) {
		t.Fatalf("nil error should not be broken pipe")
	}

	if isBrokenPipe(errors.New("some other error")) {
		t.Fatalf("unexpected error treated as broken pipe")
	}
}

func TestWorkerPoolDispatch(t *testing.T) {
	pool := newFakePool(t, 1, time.Second)
	resp, err := pool.Dispatch(&RequestPayload{
		ID:     "1",
		Method: "GET",
		Path:   "/foo",
		Body:   "",
	})

	if err != nil {
		t.Fatalf("Pool.Dispatch error: %v", err)
	}

	if resp.Status != 200 {
		t.Fatalf("expected 200 from fake worker, got %d", resp.Status)
	}
}

func TestWorkerTimeoutMarksDead(t *testing.T) {
	// Build a worker with no responding goroutine to force timeout.
	w := &Worker{
		stdin:          nopWriteCloser{}, //writes go nowhere
		stdout:         nopReadCloser{},  // reads block/eof
		maxRequests:    1000,
		requestTimeout: time.Millisecond,
	}

	_, err := w.Handle(&RequestPayload{
		ID:     "1",
		Method: "GET",
		Path:   "/timeout",
		Body:   "",
	})

	if err == nil {
		t.Fatalf("expected timeout error from Handle")
	}

	if !w.isDead() {
		t.Fatalf("expected worker to be marked dead after timeout")
	}
}

type nopWriteCloser struct{}

func (nopWriteCloser) Write(p []byte) (int, error) { return len(p), nil }
func (nopWriteCloser) Close() error                { return nil }

type nopReadCloser struct{}

func (nopReadCloser) Read(p []byte) (int, error) { return 0, io.EOF }
func (nopReadCloser) Close() error               { return nil }
