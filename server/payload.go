package server

type RequestPayload struct {
	ID      string              `json:"id"`
	Method  string              `json:"method"`
	Path    string              `json:"path"`
	Headers map[string][]string `json:"headers"`
	Body    string              `json:"body"`
}

type ResponsePayload struct {
	ID      string            `json:"id"`
	Status  int               `json:"status"`
	Headers map[string]string `json:"headers"`
	Body    string            `json:"body"`
}

type StreamFrame struct {
	Type    string            `json:"type"`              // "headers", "chunk", "end", "error"
	Status  int               `json:"status,omitempty"`  // only for headers
	Headers map[string]string `json:"headers,omitempty"` // only for headers
	Data    string            `json:"data,omitempty"`    // for headers (optional) or chunk
	Error   string            `json:"error,omitempty"`   // optional error message
}
