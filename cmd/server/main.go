package main

import (
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net/http"
	"os/exec"
	"sync"

	"github.com/google/uuid"
)

// Payloads sent to/from worker.php

type RequestPayload struct {
	ID      string            `json:"id"`
	Method  string            `json:"method"`
	Path    string            `json:"path"`
	Headers map[string]string `json:"headers"`
	Body    string            `json:"body"`
}

type ResponsePayload struct {
	ID      string            `json:"id"`
	Status  int               `json:"status"`
	Headers map[string]string `json:"headers"`
	Body    string            `json:"body"`
}

// a single long-running PHP worker
type Worker struct {
	cmd    *exec.Cmd
	stdin  io.WriteCloser
	stdout io.ReadCloser
	mu     sync.Mutex
}

func NewWorker() (*Worker, error) {
	cmd := exec.Command("php", "php/worker.php")

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

	return &Worker{cmd: cmd, stdin: stdin, stdout: stdout}, nil
}

// send request -> receive response
func (w *Worker) Handle(req *RequestPayload) (*ResponsePayload, error) {
	w.mu.Lock()
	defer w.mu.Unlock()

	// encode request json
	jsonBytes, err := json.Marshal(req)
	if err != nil {
		return nil, err
	}

	// write length header
	length := uint32(len(jsonBytes))
	lenBuf := []byte{
		byte(length >> 24),
		byte(length >> 16),
		byte(length >> 8),
		byte(length),
	}

	if _, err := w.stdin.Write(lenBuf); err != nil {
		return nil, err
	}

	if _, err := w.stdin.Write(jsonBytes); err != nil {
		return nil, err
	}

	// read 4-byte response header
	header := make([]byte, 4)
	if _, err := io.ReadFull(w.stdout, header); err != nil {
		return nil, fmt.Errorf("failed reading response length: %w", err)
	}

	respLen := uint32(header[0])<<24 |
		uint32(header[1])<<16 |
		uint32(header[2])<<8 |
		uint32(header[3])

	respBytes := make([]byte, respLen)

	if _, err := io.ReadFull(w.stdout, respBytes); err != nil {
		return nil, fmt.Errorf("failed reading response payload: %w", err)
	}

	var resp ResponsePayload
	if err := json.Unmarshal(respBytes, &resp); err != nil {
		return nil, fmt.Errorf("invalid JSON from worker: %w", err)
	}

	return &resp, nil
}

func main() {
	worker, err := NewWorker()
	if err != nil {
		log.Fatalf("failed to start php worker: %v", err)
	}

	log.Println("PHP worker started successfully")

	http.HandleFunc("/", func(w http.ResponseWriter, r *http.Request) {
		payload := &RequestPayload{
			ID:      uuid.NewString(),
			Method:  r.Method,
			Path:    r.URL.Path,
			Headers: map[string]string{},
		}

		for k, v := range r.Header {
			if len(v) > 0 {
				payload.Headers[k] = v[0]
			}
		}

		body, _ := io.ReadAll(r.Body)
		payload.Body = string(body)

		resp, err := worker.Handle(payload)
		if err != nil {
			log.Printf("worker.Handle error: %v", err)
			http.Error(w, "Worker error: "+err.Error(), 500)
			return
		}

		for k, v := range resp.Headers {
			w.Header().Set(k, v)
		}

		if resp.Status == 0 {
			resp.Status = 200
		}
		w.WriteHeader(resp.Status)
		w.Write([]byte(resp.Body))
	})

	log.Println("Go-PHP server running at http://localhost:8080")
	log.Fatal(http.ListenAndServe(":8080", nil))
}
