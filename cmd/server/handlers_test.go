// cmd/server/handlers_test.go
package main

import (
	"bytes"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"

	"go-php/server"

	"github.com/gorilla/websocket"
)

// setupTestServer creates a test server with minimal configuration
func setupTestServer(t *testing.T) (*httptest.Server, *server.Server) {
	t.Helper()

	slowCfg := server.SlowRequestConfig{
		RoutePrefixes: []string{"/slow"},
		Methods:       []string{"PUT", "DELETE"},
		BodyThreshold: 2000000,
	}

	srv, err := server.NewServer(
		1, // fast workers
		1, // slow workers
		1000,
		10*time.Second,
		slowCfg,
	)
	if err != nil {
		t.Fatalf("failed to create server: %v", err)
	}

	metrics := NewMetrics()
	mux := http.NewServeMux()
	wsHub := server.NewWSHub()
	hub := server.NewSSEHub()

	wsUpgrader := websocket.Upgrader{
		ReadBufferSize:  1024,
		WriteBufferSize: 1024,
		CheckOrigin: func(r *http.Request) bool {
			return true
		},
	}

	// Health endpoint
	mux.HandleFunc("/__baremetal/health", func(w http.ResponseWriter, r *http.Request) {
		summary := srv.Health()
		w.Header().Set("Content-Type", "application/json")
		if err := json.NewEncoder(w).Encode(summary); err != nil {
			http.Error(w, "Failed to encode health summary", http.StatusInternalServerError)
			return
		}
	})

	// Recycle endpoint
	mux.HandleFunc("/__baremetal/recycle", func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			w.WriteHeader(http.StatusMethodNotAllowed)
			return
		}
		srv.ForceRecycleWorkers()
		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(map[string]string{
			"status": "ok",
			"note":   "all workers marked dead; will respawn on next requests",
		})
	})

	// Metrics endpoint
	mux.HandleFunc("/__baremetal/metrics", func(w http.ResponseWriter, r *http.Request) {
		snap := metrics.Snapshot()
		w.Header().Set("Content-Type", "application/json")
		if err := json.NewEncoder(w).Encode(snap); err != nil {
			http.Error(w, "failed to encode metrics", http.StatusInternalServerError)
		}
	})

	// SSE endpoint
	mux.HandleFunc("/__sse", func(w http.ResponseWriter, r *http.Request) {
		flusher, ok := w.(http.Flusher)
		if !ok {
			http.Error(w, "streaming unsupported", http.StatusInternalServerError)
			return
		}

		channel := r.URL.Query().Get("channel")
		if channel == "" {
			http.Error(w, "missing channel", http.StatusBadRequest)
			return
		}

		client := hub.Subscribe(channel)
		defer hub.Unsubscribe(channel, client)

		w.Header().Set("Content-Type", "text/event-stream")
		w.Header().Set("Cache-Control", "no-cache")
		w.Header().Set("Connection", "keep-alive")

		_, _ = w.Write([]byte(": connected\n\n"))
		flusher.Flush()

		for {
			select {
			case ev := <-client.Ch():
				if ev.Event != "" {
					_, _ = w.Write([]byte("event: " + ev.Event + "\n"))
				}
				_, _ = w.Write([]byte("data: "))
				_, _ = w.Write(ev.Data)
				_, _ = w.Write([]byte("\n\n"))
				flusher.Flush()
			case <-r.Context().Done():
				return
			case <-client.Done():
				return
			}
		}
	})

	// SSE publish endpoint
	mux.HandleFunc("/__sse/publish", func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			w.WriteHeader(http.StatusMethodNotAllowed)
			return
		}

		var body struct {
			Channel string      `json:"channel"`
			Event   string      `json:"event"`
			Data    interface{} `json:"data"`
		}
		if err := json.NewDecoder(r.Body).Decode(&body); err != nil {
			http.Error(w, "invalid JSON", http.StatusBadRequest)
			return
		}

		if body.Channel == "" {
			http.Error(w, "missing channel", http.StatusBadRequest)
			return
		}

		hub.Publish(body.Channel, body.Event, body.Data)
		w.WriteHeader(http.StatusAccepted)
	})

	// WebSocket user endpoint
	mux.HandleFunc("/__ws/user", func(w http.ResponseWriter, r *http.Request) {
		userID, err := authenticateWS(r)
		if err != nil || userID == "" {
			http.Error(w, "unauthorized", http.StatusUnauthorized)
			return
		}

		channel := "user:" + userID
		conn, err := wsUpgrader.Upgrade(w, r, nil)
		if err != nil {
			return
		}
		defer conn.Close()

		client := wsHub.Subscribe(channel)
		defer wsHub.Unsubscribe(channel, client)

		done := make(chan struct{})
		go func() {
			defer close(done)
			for msg := range client.Send {
				if err := conn.WriteJSON(msg); err != nil {
					return
				}
			}
		}()

		for {
			var incoming map[string]any
			if err := conn.ReadJSON(&incoming); err != nil {
				if websocket.IsCloseError(err,
					websocket.CloseGoingAway,
					websocket.CloseNormalClosure,
					websocket.CloseAbnormalClosure,
				) {
					return
				}
				return
			}
			wsHub.Publish(channel, "client", incoming)
		}
	})

	// WebSocket endpoint
	mux.HandleFunc("/__ws", func(w http.ResponseWriter, r *http.Request) {
		channel := r.URL.Query().Get("channel")
		if channel == "" {
			http.Error(w, "missing channel", http.StatusBadRequest)
			return
		}

		conn, err := wsUpgrader.Upgrade(w, r, nil)
		if err != nil {
			return
		}
		defer conn.Close()

		client := wsHub.Subscribe(channel)
		defer wsHub.Unsubscribe(channel, client)

		done := make(chan struct{})
		go func() {
			defer close(done)
			for msg := range client.Send {
				if err := conn.WriteJSON(msg); err != nil {
					return
				}
			}
		}()

		for {
			var incoming map[string]any
			if err := conn.ReadJSON(&incoming); err != nil {
				if websocket.IsCloseError(err,
					websocket.CloseGoingAway,
					websocket.CloseNormalClosure,
					websocket.CloseAbnormalClosure,
				) {
					return
				}
				return
			}
			wsHub.Publish(channel, "client", incoming)
		}
	})

	// WebSocket publish endpoint
	mux.HandleFunc("/__ws/publish", func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			w.WriteHeader(http.StatusMethodNotAllowed)
			return
		}

		var body struct {
			Channel string      `json:"channel"`
			Type    string      `json:"type"`
			Data    interface{} `json:"data"`
		}
		if err := json.NewDecoder(r.Body).Decode(&body); err != nil {
			http.Error(w, "invalid json", http.StatusBadRequest)
			return
		}
		if body.Channel == "" {
			http.Error(w, "missing channel", http.StatusBadRequest)
			return
		}

		wsHub.Publish(body.Channel, body.Type, body.Data)
		w.WriteHeader(http.StatusAccepted)
	})

	ts := httptest.NewServer(mux)
	return ts, srv
}

func TestHealthEndpoint(t *testing.T) {
	ts, _ := setupTestServer(t)
	defer ts.Close()

	resp, err := http.Get(ts.URL + "/__baremetal/health")
	if err != nil {
		t.Fatalf("GET /__baremetal/health: %v", err)
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		t.Fatalf("expected 200, got %d", resp.StatusCode)
	}

	var summary server.HealthSummary
	if err := json.NewDecoder(resp.Body).Decode(&summary); err != nil {
		t.Fatalf("decode health summary: %v", err)
	}
}

func TestRecycleEndpoint(t *testing.T) {
	ts, _ := setupTestServer(t)
	defer ts.Close()

	// Test wrong method
	resp, err := http.Get(ts.URL + "/__baremetal/recycle")
	if err != nil {
		t.Fatalf("GET /__baremetal/recycle: %v", err)
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusMethodNotAllowed {
		t.Fatalf("expected 405, got %d", resp.StatusCode)
	}

	// Test POST
	resp2, err := http.Post(ts.URL+"/__baremetal/recycle", "application/json", nil)
	if err != nil {
		t.Fatalf("POST /__baremetal/recycle: %v", err)
	}
	defer resp2.Body.Close()
	if resp2.StatusCode != http.StatusOK {
		t.Fatalf("expected 200, got %d", resp2.StatusCode)
	}
}

func TestMetricsEndpoint(t *testing.T) {
	ts, _ := setupTestServer(t)
	defer ts.Close()

	resp, err := http.Get(ts.URL + "/__baremetal/metrics")
	if err != nil {
		t.Fatalf("GET /__baremetal/metrics: %v", err)
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		t.Fatalf("expected 200, got %d", resp.StatusCode)
	}
}

func TestSSEEndpointMissingChannel(t *testing.T) {
	ts, _ := setupTestServer(t)
	defer ts.Close()

	resp, err := http.Get(ts.URL + "/__sse")
	if err != nil {
		t.Fatalf("GET /__sse: %v", err)
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusBadRequest {
		t.Fatalf("expected 400, got %d", resp.StatusCode)
	}
}

func TestSSEPublishEndpoint(t *testing.T) {
	ts, _ := setupTestServer(t)
	defer ts.Close()

	// Test wrong method
	resp, err := http.Get(ts.URL + "/__sse/publish")
	if err != nil {
		t.Fatalf("GET /__sse/publish: %v", err)
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusMethodNotAllowed {
		t.Fatalf("expected 405, got %d", resp.StatusCode)
	}

	// Test POST with valid body
	body := map[string]interface{}{
		"channel": "test",
		"event":   "test-event",
		"data":    map[string]string{"key": "value"},
	}
	bodyBytes, _ := json.Marshal(body)
	resp2, err := http.Post(ts.URL+"/__sse/publish", "application/json", bytes.NewReader(bodyBytes))
	if err != nil {
		t.Fatalf("POST /__sse/publish: %v", err)
	}
	defer resp2.Body.Close()
	if resp2.StatusCode != http.StatusAccepted {
		t.Fatalf("expected 202, got %d", resp2.StatusCode)
	}

	// Test POST with missing channel
	body2 := map[string]interface{}{
		"event": "test-event",
		"data":  map[string]string{"key": "value"},
	}
	bodyBytes2, _ := json.Marshal(body2)
	resp3, err := http.Post(ts.URL+"/__sse/publish", "application/json", bytes.NewReader(bodyBytes2))
	if err != nil {
		t.Fatalf("POST /__sse/publish: %v", err)
	}
	defer resp3.Body.Close()
	if resp3.StatusCode != http.StatusBadRequest {
		t.Fatalf("expected 400, got %d", resp3.StatusCode)
	}

	// Test POST with invalid JSON
	resp4, err := http.Post(ts.URL+"/__sse/publish", "application/json", bytes.NewReader([]byte("invalid json")))
	if err != nil {
		t.Fatalf("POST /__sse/publish: %v", err)
	}
	defer resp4.Body.Close()
	if resp4.StatusCode != http.StatusBadRequest {
		t.Fatalf("expected 400, got %d", resp4.StatusCode)
	}
}

func TestWSEndpointMissingChannel(t *testing.T) {
	ts, _ := setupTestServer(t)
	defer ts.Close()

	resp, err := http.Get(ts.URL + "/__ws")
	if err != nil {
		t.Fatalf("GET /__ws: %v", err)
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusBadRequest {
		t.Fatalf("expected 400, got %d", resp.StatusCode)
	}
}

func TestWSPublishEndpoint(t *testing.T) {
	ts, _ := setupTestServer(t)
	defer ts.Close()

	// Test wrong method
	resp, err := http.Get(ts.URL + "/__ws/publish")
	if err != nil {
		t.Fatalf("GET /__ws/publish: %v", err)
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusMethodNotAllowed {
		t.Fatalf("expected 405, got %d", resp.StatusCode)
	}

	// Test POST with valid body
	body := map[string]interface{}{
		"channel": "test",
		"type":    "test-type",
		"data":    map[string]string{"key": "value"},
	}
	bodyBytes, _ := json.Marshal(body)
	resp2, err := http.Post(ts.URL+"/__ws/publish", "application/json", bytes.NewReader(bodyBytes))
	if err != nil {
		t.Fatalf("POST /__ws/publish: %v", err)
	}
	defer resp2.Body.Close()
	if resp2.StatusCode != http.StatusAccepted {
		t.Fatalf("expected 202, got %d", resp2.StatusCode)
	}

	// Test POST with missing channel
	body2 := map[string]interface{}{
		"type": "test-type",
		"data": map[string]string{"key": "value"},
	}
	bodyBytes2, _ := json.Marshal(body2)
	resp3, err := http.Post(ts.URL+"/__ws/publish", "application/json", bytes.NewReader(bodyBytes2))
	if err != nil {
		t.Fatalf("POST /__ws/publish: %v", err)
	}
	defer resp3.Body.Close()
	if resp3.StatusCode != http.StatusBadRequest {
		t.Fatalf("expected 400, got %d", resp3.StatusCode)
	}

	// Test POST with invalid JSON
	resp4, err := http.Post(ts.URL+"/__ws/publish", "application/json", bytes.NewReader([]byte("invalid json")))
	if err != nil {
		t.Fatalf("POST /__ws/publish: %v", err)
	}
	defer resp4.Body.Close()
	if resp4.StatusCode != http.StatusBadRequest {
		t.Fatalf("expected 400, got %d", resp4.StatusCode)
	}
}

func TestWSUserEndpointUnauthorized(t *testing.T) {
	ts, _ := setupTestServer(t)
	defer ts.Close()

	resp, err := http.Get(ts.URL + "/__ws/user")
	if err != nil {
		t.Fatalf("GET /__ws/user: %v", err)
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusUnauthorized {
		t.Fatalf("expected 401, got %d", resp.StatusCode)
	}
}

// Note: jwtSecret is initialized at package load time from APP_JWT_SECRET,
// so we can't test JWT authentication in handlers_test.go.
// JWT authentication is tested in main_test.go.
