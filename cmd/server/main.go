package main

import (
	"io"
	"log"
	"net/http"

	"go-php/server" // ← CHANGE THIS to match your module path

	"github.com/google/uuid"
)

// -------------------------------
// BuildPayload: Converts HTTP request → bridge format
// -------------------------------

func BuildPayload(r *http.Request) *server.RequestPayload {
	headers := map[string]string{}
	for k, v := range r.Header {
		if len(v) > 0 {
			headers[k] = v[0]
		}
	}

	bodyBytes, _ := io.ReadAll(r.Body)

	return &server.RequestPayload{
		ID:      uuid.NewString(),
		Method:  r.Method,
		Path:    r.URL.RequestURI(),
		Headers: headers,
		Body:    string(bodyBytes),
	}
}

// -------------------------------
// MAIN
// -------------------------------

func main() {

	// Create multipools: 4 fast workers, 2 slow workers
	srv, err := server.NewServer(4, 2)
	if err != nil {
		log.Fatal("Failed creating worker pools:", err)
	}

	log.Println("BareMetalPHP App Server starting on :8080")
	log.Println("Fast workers: 4 | Slow workers: 2")

	http.HandleFunc("/", func(w http.ResponseWriter, r *http.Request) {

		// Build PHP-bridge payload
		payload := BuildPayload(r)

		// Dispatch to either FastPool or SlowPool
		resp, err := srv.Dispatch(payload)
		if err != nil {
			log.Println("Worker error:", err)
			http.Error(w, "Worker error: "+err.Error(), 500)
			return
		}

		// Write headers
		for k, v := range resp.Headers {
			w.Header().Set(k, v)
		}

		// Default 200 if not set
		status := resp.Status
		if status == 0 {
			status = 200
		}
		w.WriteHeader(status)

		// Write body
		_, _ = w.Write([]byte(resp.Body))
	})

	// Start the HTTP server
	if err := http.ListenAndServe(":8080", nil); err != nil {
		log.Fatal("HTTP Server failed:", err)
	}
}
