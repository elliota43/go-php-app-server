package server

import (
	"bytes"
	"encoding/binary"
	"encoding/json"
	"io"
	"net/http/httptest"
	"strings"
	"testing"
	"time"
)

// helper to build a length-prefixed JSON frame
func encodeFrame(t *testing.T, frame StreamFrame) []byte {
	t.Helper()
	raw, err := json.Marshal(frame)
	if err != nil {
		t.Fatalf("marshal frame: %v", err)
	}
	buf := new(bytes.Buffer)
	if err := binary.Write(buf, binary.BigEndian, uint32(len(raw))); err != nil {
		t.Fatalf("write len: %v", err)
	}
	buf.Write(raw)
	return buf.Bytes()
}

func TestWorkerStreamErrorFramePropagates(t *testing.T) {
	w := &Worker{
		requestTimeout: 500 * time.Millisecond,
	}

	// Build a single error frame
	errorFrame := StreamFrame{
		Type:  "error",
		Error: "something went wrong",
	}
	data := encodeFrame(t, errorFrame)

	// stdout: our fake frame stream
	w.stdout = io.NopCloser(bytes.NewReader(data))

	// stdin: we don't care what gets written, just need a non-nil WriteCloser
	w.stdin = nopWriteCloser{Writer: io.Discard}

	rr := httptest.NewRecorder()
	req := &RequestPayload{} // body doesn't matter for this test

	err := w.streamInternal(req, rr)
	if err == nil {
		t.Fatalf("expected error from streamInternal, got nil")
	}
	if err.Error() != "stream error from worker: something went wrong" {
		t.Fatalf("unexpected error: %v", err)
	}
}

func TestFrameHeadersMultiValue(t *testing.T) {
	w := &Worker{
		requestTimeout: 500 * time.Millisecond,
		stdin:          nopWriteCloser{Writer: io.Discard},
	}

	// One headers frame with multi-valued header + a normal end frame.
	headersFrame := StreamFrame{
		Type:   "headers",
		Status: 200,
		Headers: map[string][]string{
			"X-Test":     {"one", "two"},
			"Set-Cookie": {"a=1", "b=2"},
		},
		Data: "hello",
	}
	endFrame := StreamFrame{
		Type: "end",
	}

	buf := new(bytes.Buffer)
	buf.Write(encodeFrame(t, headersFrame))
	buf.Write(encodeFrame(t, endFrame))

	w.stdout = io.NopCloser(bytes.NewReader(buf.Bytes()))

	rr := httptest.NewRecorder()
	req := &RequestPayload{}

	if err := w.streamInternal(req, rr); err != nil {
		t.Fatalf("streamInternal error: %v", err)
	}

	resp := rr.Result()
	if resp.StatusCode != 200 {
		t.Fatalf("expected status 200, got %d", resp.StatusCode)
	}

	// Check multi-value header joined behavior.
	if got := resp.Header.Get("X-Test"); got != "one, two" {
		t.Fatalf("expected X-Test=one, two, got %q", got)
	}

	// For Set-Cookie, we expect separate headers.
	cookies := resp.Header["Set-Cookie"]
	if len(cookies) != 2 {
		t.Fatalf("expected 2 Set-Cookie headers, got %d", len(cookies))
	}
}

func TestWorkerStreamHeadersFrameWithEmptyHeaders(t *testing.T) {
	w := &Worker{
		requestTimeout: 500 * time.Millisecond,
	}

	headersFrame := StreamFrame{
		Type:    "headers",
		Status:  201,
		Headers: map[string][]string{},
		Data:    "initial data",
	}
	endFrame := StreamFrame{
		Type: "end",
	}

	buf := new(bytes.Buffer)
	buf.Write(encodeFrame(t, headersFrame))
	buf.Write(encodeFrame(t, endFrame))

	w.stdout = io.NopCloser(bytes.NewReader(buf.Bytes()))
	w.stdin = nopWriteCloser{Writer: io.Discard}

	rr := httptest.NewRecorder()
	req := &RequestPayload{}

	if err := w.streamInternal(req, rr); err != nil {
		t.Fatalf("streamInternal error: %v", err)
	}

	resp := rr.Result()
	if resp.StatusCode != 201 {
		t.Fatalf("expected status 201, got %d", resp.StatusCode)
	}
}

func TestWorkerStreamHeadersFrameWithEmptyHeaderValues(t *testing.T) {
	w := &Worker{
		requestTimeout: 500 * time.Millisecond,
	}

	headersFrame := StreamFrame{
		Type:   "headers",
		Status: 200,
		Headers: map[string][]string{
			"X-Empty": {},
			"X-Test":  {"value"},
		},
	}
	endFrame := StreamFrame{
		Type: "end",
	}

	buf := new(bytes.Buffer)
	buf.Write(encodeFrame(t, headersFrame))
	buf.Write(encodeFrame(t, endFrame))

	w.stdout = io.NopCloser(bytes.NewReader(buf.Bytes()))
	w.stdin = nopWriteCloser{Writer: io.Discard}

	rr := httptest.NewRecorder()
	req := &RequestPayload{}

	if err := w.streamInternal(req, rr); err != nil {
		t.Fatalf("streamInternal error: %v", err)
	}

	resp := rr.Result()
	if resp.Header.Get("X-Test") != "value" {
		t.Fatalf("expected X-Test header to be set")
	}
	if resp.Header.Get("X-Empty") != "" {
		t.Fatalf("expected X-Empty header to be skipped")
	}
}

func TestWorkerStreamChunkFrame(t *testing.T) {
	w := &Worker{
		requestTimeout: 500 * time.Millisecond,
	}

	headersFrame := StreamFrame{
		Type:   "headers",
		Status: 200,
	}
	chunkFrame := StreamFrame{
		Type: "chunk",
		Data: "chunk data",
	}
	endFrame := StreamFrame{
		Type: "end",
	}

	buf := new(bytes.Buffer)
	buf.Write(encodeFrame(t, headersFrame))
	buf.Write(encodeFrame(t, chunkFrame))
	buf.Write(encodeFrame(t, endFrame))

	w.stdout = io.NopCloser(bytes.NewReader(buf.Bytes()))
	w.stdin = nopWriteCloser{Writer: io.Discard}

	rr := httptest.NewRecorder()
	req := &RequestPayload{}

	if err := w.streamInternal(req, rr); err != nil {
		t.Fatalf("streamInternal error: %v", err)
	}

	body, _ := io.ReadAll(rr.Body)
	if string(body) != "chunk data" {
		t.Fatalf("expected chunk data in body, got %q", string(body))
	}
}

func TestWorkerStreamChunkFrameWithoutHeaders(t *testing.T) {
	w := &Worker{
		requestTimeout: 500 * time.Millisecond,
	}

	// Chunk frame without headers frame first
	chunkFrame := StreamFrame{
		Type: "chunk",
		Data: "data",
	}
	endFrame := StreamFrame{
		Type: "end",
	}

	buf := new(bytes.Buffer)
	buf.Write(encodeFrame(t, chunkFrame))
	buf.Write(encodeFrame(t, endFrame))

	w.stdout = io.NopCloser(bytes.NewReader(buf.Bytes()))
	w.stdin = nopWriteCloser{Writer: io.Discard}

	rr := httptest.NewRecorder()
	req := &RequestPayload{}

	if err := w.streamInternal(req, rr); err != nil {
		t.Fatalf("streamInternal error: %v", err)
	}

	// Should have written headers with default status
	resp := rr.Result()
	if resp.StatusCode != 200 {
		t.Fatalf("expected default status 200, got %d", resp.StatusCode)
	}
}

func TestWorkerStreamChunkFrameWithEmptyData(t *testing.T) {
	w := &Worker{
		requestTimeout: 500 * time.Millisecond,
	}

	headersFrame := StreamFrame{
		Type:   "headers",
		Status: 200,
	}
	chunkFrame := StreamFrame{
		Type: "chunk",
		Data: "", // Empty data
	}
	endFrame := StreamFrame{
		Type: "end",
	}

	buf := new(bytes.Buffer)
	buf.Write(encodeFrame(t, headersFrame))
	buf.Write(encodeFrame(t, chunkFrame))
	buf.Write(encodeFrame(t, endFrame))

	w.stdout = io.NopCloser(bytes.NewReader(buf.Bytes()))
	w.stdin = nopWriteCloser{Writer: io.Discard}

	rr := httptest.NewRecorder()
	req := &RequestPayload{}

	if err := w.streamInternal(req, rr); err != nil {
		t.Fatalf("streamInternal error: %v", err)
	}
}

func TestWorkerStreamUnknownFrameType(t *testing.T) {
	w := &Worker{
		requestTimeout: 500 * time.Millisecond,
	}

	unknownFrame := StreamFrame{
		Type: "unknown",
	}
	data := encodeFrame(t, unknownFrame)

	w.stdout = io.NopCloser(bytes.NewReader(data))
	w.stdin = nopWriteCloser{Writer: io.Discard}

	rr := httptest.NewRecorder()
	req := &RequestPayload{}

	err := w.streamInternal(req, rr)
	if err == nil {
		t.Fatalf("expected error for unknown frame type")
	}
	if !strings.Contains(err.Error(), "unknown stream frame type") {
		t.Fatalf("expected error about unknown frame type, got %v", err)
	}
}

func TestWorkerStreamHeadersFrameWithoutData(t *testing.T) {
	w := &Worker{
		requestTimeout: 500 * time.Millisecond,
	}

	headersFrame := StreamFrame{
		Type:   "headers",
		Status: 200,
		Data:   "", // No data
	}
	endFrame := StreamFrame{
		Type: "end",
	}

	buf := new(bytes.Buffer)
	buf.Write(encodeFrame(t, headersFrame))
	buf.Write(encodeFrame(t, endFrame))

	w.stdout = io.NopCloser(bytes.NewReader(buf.Bytes()))
	w.stdin = nopWriteCloser{Writer: io.Discard}

	rr := httptest.NewRecorder()
	req := &RequestPayload{}

	if err := w.streamInternal(req, rr); err != nil {
		t.Fatalf("streamInternal error: %v", err)
	}
}
