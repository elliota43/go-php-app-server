package server

import (
	"encoding/binary"
	"encoding/json"
	"io"
	"log"
	"testing"
	"time"
)

// newFakeWorker returns a Worker whose stdin/stdout are in-memory pipes.
// The goroutine reads RequestPayload, writes a ResponsePayload whose
// Body is a label + ":" + req.Path, so you can tell which worker handled it.
func newFakeWorker(t *testing.T, label string, timeout time.Duration) *Worker {
	t.Helper()

	stdinR, stdinW := io.Pipe()
	stdoutR, stdoutW := io.Pipe()

	w := &Worker{
		stdin:          stdinW,
		stdout:         stdoutR,
		maxRequests:    1000,
		requestTimeout: timeout,
	}

	// Fake PHP worker loop.
	go func() {
		defer func(stdinR *io.PipeReader) {
			err := stdinR.Close()
			if err != nil {
				log.Fatalf("stdin pipe close error: %v", err)
			}
		}(stdinR)
		defer func(stdoutW *io.PipeWriter) {
			err := stdoutW.Close()
			if err != nil {
				log.Fatalf("stdout pipe close error: %v", err)
			}
		}(stdoutW)

		for {
			// 1) Read request length header (4 bytes big-endian)
			hdr := make([]byte, 4)
			if _, err := io.ReadFull(stdinR, hdr); err != nil {
				return // client closed or error
			}

			length := (uint32(hdr[0]) << 24) |
				(uint32(hdr[1]) << 16) |
				(uint32(hdr[2]) << 8) |
				uint32(hdr[3])

			if length == 0 {
				return
			}

			body := make([]byte, length)
			if _, err := io.ReadFull(stdinR, body); err != nil {
				return
			}

			var req RequestPayload
			if err := json.Unmarshal(body, &req); err != nil {
				return
			}

			resp := ResponsePayload{
				ID:     req.ID,
				Status: 200,
				Headers: map[string]string{
					"X-Worker": label,
				},
				Body: label + ":" + req.Path,
			}

			respJSON, err := json.Marshal(&resp)
			if err != nil {
				return
			}

			respLen := uint32(len(respJSON))
			outHdr := make([]byte, 4)
			binary.BigEndian.PutUint32(outHdr, respLen)

			if _, err := stdoutW.Write(outHdr); err != nil {
				return
			}
			if _, err := stdoutW.Write(respJSON); err != nil {
				return
			}
		}
	}()

	return w
}

// newFakePool builds a WorkerPool with N fake workers labeled w0, w1, ...
func newFakePool(t *testing.T, n int, timeout time.Duration) *WorkerPool {
	t.Helper()
	workers := make([]*Worker, 0, n)
	for i := 0; i < n; i++ {
		workers = append(workers, newFakeWorker(t, "w"+string(rune('0'+i)), timeout))
	}

	return &WorkerPool{workers: workers}
}
