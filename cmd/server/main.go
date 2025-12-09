package main

import (
	"context"
	"encoding/json"
	"errors"
	"io"
	"log"
	"net"
	"net/http"
	"os"
	"os/signal"
	"path/filepath"
	"strings"
	"sync"
	"syscall"
	"time"

	"go-php/server" // IMPORTANT: change this if your module path differs

	"github.com/golang-jwt/jwt/v5"
	"github.com/google/uuid"
	"github.com/gorilla/websocket"
)

type RequestLog struct {
	Time       time.Time `json:"time"`
	ID         string    `json:"id"`
	Method     string    `json:"method"`
	Path       string    `json:"path"`
	Status     int       `json:"status"`
	DurationMs float64   `json:"duration_ms"`
	RemoteAddr string    `json:"remote_addr,omitempty"`
	UserAgent  string    `json:"user_agent,omitempty"`
	Pool       string    `json:"pool,omitempty"` // "fast" or "slow" (@todo: will fill later)
	Error      string    `json:"error,omitempty"`
}

type RouteMetrics struct {
	Count        uint64        `json:"count"`
	TotalLatency time.Duration `json:"total_lacency_ns"`
}

type Metrics struct {
	mu            sync.Mutex
	TotalRequests uint64                   `json:"total_requests"`
	TotalErrors   uint64                   `json:"total_errors"`
	InFlight      uint64                   `json:"in_flight"`
	ByRoute       map[string]*RouteMetrics `json:"by_route"`
}

var (
	// Secret for HMAC JWTs (HS256).  Set in .env
	jwtSecret = []byte(os.Getenv("APP_JWT_SECRET"))
)

type WSClaims struct {
	UserID string `json:"sub"`
	jwt.RegisteredClaims
}

// authenticateWS extracts the user ID from:
// 1) Authorization: Bearer <jwt> using HS256 + APP_JWT_SECRET
// 2) A session cookie (e.g. bm_user_id) as a fallback
func authenticateWS(r *http.Request) (string, error) {
	// Authorization: Bearer <token>
	auth := r.Header.Get("Authorization")
	if strings.HasPrefix(auth, "Bearer ") && len(jwtSecret) > 0 {
		tokenStr := strings.TrimSpace(strings.TrimPrefix(auth, "Bearer "))
		claims := &WSClaims{}
		token, err := jwt.ParseWithClaims(tokenStr, claims, func(token *jwt.Token) (interface{}, error) {
			if _, ok := token.Method.(*jwt.SigningMethodHMAC); !ok {
				return nil, errors.New("unexpected signing method")
			}
			return jwtSecret, nil
		})

		if err == nil && token.Valid && claims.UserID != "" {
			return claims.UserID, nil
		}
	}

	// 2) fallback: session cookie containing user id
	if c, err := r.Cookie("bm_user_id"); err == nil && c.Value != "" {
		// @todo: verify signed/secured
		return c.Value, nil
	}

	return "", errors.New("unauthenticated")
}

func NewMetrics() *Metrics {
	return &Metrics{
		ByRoute: make(map[string]*RouteMetrics),
	}
}

func (m *Metrics) StartRequest(route string) {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.InFlight++
	m.TotalRequests++
	if _, ok := m.ByRoute[route]; !ok {
		m.ByRoute[route] = &RouteMetrics{}
	}
}

func (m *Metrics) EndRequest(route string, latency time.Duration, err bool) {
	m.mu.Lock()
	defer m.mu.Unlock()

	if m.InFlight > 0 {
		m.InFlight--
	}
	if err {
		m.TotalErrors++
	}

	rm := m.ByRoute[route]
	if rm == nil {
		rm = &RouteMetrics{}
		m.ByRoute[route] = rm
	}
	rm.Count++
	rm.TotalLatency += latency
}

func (m *Metrics) Snapshot() Metrics {
	m.mu.Lock()
	defer m.mu.Unlock()

	copy := Metrics{
		TotalRequests: m.TotalRequests,
		TotalErrors:   m.TotalErrors,
		InFlight:      m.InFlight,
		ByRoute:       make(map[string]*RouteMetrics, len(m.ByRoute)),
	}

	for route, rm := range m.ByRoute {
		rmCopy := *rm
		copy.ByRoute[route] = &rmCopy
	}

	return copy
}

func logRequestJSON(entry RequestLog) {
	b, err := json.Marshal(entry)
	if err != nil {
		log.Printf("error marshaling log entry: %v", err)
		return
	}
	log.Println(string(b))
}

//
// -------------------------------------------------------------
// STATIC FILE SERVING
// -------------------------------------------------------------
//

// tryServeStatic: serves static assets based on StaticRule in config
func tryServeStatic(w http.ResponseWriter, r *http.Request, projectRoot string, rules []StaticRule) bool {
	if r.Method != http.MethodGet && r.Method != http.MethodHead {
		return false
	}

	path := r.URL.Path

	for _, rule := range rules {
		if !strings.HasPrefix(path, rule.Prefix) {
			continue
		}

		relPath := strings.TrimPrefix(path, rule.Prefix)
		relPath = filepath.Clean(relPath)

		baseDir := filepath.Join(projectRoot, rule.Dir)
		fullPath := filepath.Join(baseDir, relPath)

		// Prevent ../../ escapes
		if !strings.HasPrefix(fullPath, baseDir) {
			http.Error(w, "Forbidden", http.StatusForbidden)
			return true
		}

		info, err := os.Stat(fullPath)
		if err != nil || info.IsDir() {
			continue
		}

		http.ServeFile(w, r, fullPath)
		return true
	}

	return false
}

//
// -------------------------------------------------------------
// REQUEST PAYLOAD TRANSFORM (HTTP → PHP Worker)
// -------------------------------------------------------------
//

func BuildPayload(r *http.Request) *server.RequestPayload {
	// Generate a request ID for logging + tracing
	reqID := uuid.New().String()

	// copy headers into map[string][]string with canonicalized names
	headers := make(map[string][]string, len(r.Header)+3)

	for name, values := range r.Header {
		canonical := http.CanonicalHeaderKey(name)

		// copy the slice so we don't share backing arrays with r.Header
		copied := make([]string, len(values))
		copy(copied, values)

		headers[canonical] = copied
	}

	// ensure Host is present
	host := r.Host
	if host == "" && r.URL != nil {
		host = r.URL.Host
	}
	if host != "" {
		headers["Host"] = []string{host}
	}

	// add / extend X-Forwarded-For with the direct client IP
	if ip, _, err := net.SplitHostPort(r.RemoteAddr); err == nil && ip != "" {
		if existing, ok := headers["X-Forwarded-For"]; ok && len(existing) > 0 {
			headers["X-Forwarded-For"] = []string{existing[0] + ", " + ip}
		} else {
			headers["X-Forwarded-For"] = []string{ip}
		}
	}

	// Attach X-Request-Id if the client didn't send one
	if _, ok := headers["X-Request-Id"]; !ok {
		headers["X-Request-Id"] = []string{reqID}
	}

	bodyBytes, err := io.ReadAll(r.Body)
	if err != nil {
		log.Printf("[request %s] error reading body: %v", reqID, err)
	}
	_ = r.Body.Close()

	// Preserve the full RequestURI (includes query string)
	path := r.URL.RequestURI()
	if path == "" {
		path = r.URL.Path
	}

	return &server.RequestPayload{
		ID:      reqID,
		Method:  r.Method,
		Path:    path,
		Headers: headers,
		Body:    string(bodyBytes),
	}
}

// mapWorkerErrorToStatus converts worker-level errors into HTTP status codes.
func mapWorkerErrorToStatus(err error) int {
	msg := err.Error()

	switch {
	case strings.Contains(msg, "timeout"):
		// the php worker timed out handling the request
		return http.StatusGatewayTimeout //' 504 Gateway Timeout
	case strings.Contains(msg, "unexpected EOF"),
		strings.Contains(msg, "broken pipe"),
		strings.Contains(msg, "connection reset"):
		// Connection to the worker died mid-request
		return http.StatusBadGateway // 502 Bad Gateway

	default:
		// Anything else is treated as an internal server error
		return http.StatusInternalServerError //500
	}
}

// writeWorkerError logs and sends an appropriate HTTP error to the client.
func writeWorkerError(w http.ResponseWriter, err error) {
	status := mapWorkerErrorToStatus(err)
	log.Printf("[worker] error (status=%d): %v", status, err)
	http.Error(w, http.StatusText(status), status)
}

//
// -------------------------------------------------------------
// PROJECT ROOT DISCOVERY (dir containing go.mod)
// -------------------------------------------------------------
//

func getProjectRoot() string {
	wd, err := os.Getwd()
	if err != nil {
		return "."
	}

	dir := wd
	for {
		if _, err := os.Stat(filepath.Join(dir, "go.mod")); err == nil {
			return dir
		}
		parent := filepath.Dir(dir)
		if parent == dir {
			return wd
		}
		dir = parent
	}
}

//
// -------------------------------------------------------------
// MAIN SERVER SETUP
// -------------------------------------------------------------
//

func main() {
	root := getProjectRoot()
	cfg := loadConfig(root)

	// Build server.Server instance
	slowCfg := server.SlowRequestConfig{
		RoutePrefixes: cfg.SlowRoutes,
		Methods:       cfg.SlowMethods,
		BodyThreshold: cfg.SlowBodyThreshold,
	}
	srv, err := server.NewServer(
		cfg.FastWorkers,
		cfg.SlowWorkers,
		cfg.MaxRequestsPerWorker,
		time.Duration(cfg.RequestTimeoutMs)*time.Millisecond,
		slowCfg,
	)
	if err != nil {
		log.Fatalf("failed to create server: %v", err)
	}

	metrics := NewMetrics()
	mux := http.NewServeMux()

	wsHub := server.NewWSHub()

	wsUpgrader := websocket.Upgrader{
		ReadBufferSize:  1024,
		WriteBufferSize: 1024,
		CheckOrigin: func(r *http.Request) bool {
			// TODO: lighten up for production
			return true
		},
	}

	mux.HandleFunc("/__ws/user", func(w http.ResponseWriter, r *http.Request) {
		userID, err := authenticateWS(r)
		if err != nil || userID == "" {
			http.Error(w, "unauthorized", http.StatusUnauthorized)
			return
		}

		channel := "user:" + userID

		conn, err := wsUpgrader.Upgrade(w, r, nil)
		if err != nil {
			log.Printf("[ws] upgrade error: %v", err)
			return
		}

		defer conn.Close()

		client := wsHub.Subscribe(channel)
		defer wsHub.Unsubscribe(channel, client)

		done := make(chan struct{})

		// writer goroutine
		go func() {
			defer close(done)

			for msg := range client.Send {
				if err := conn.WriteJSON(msg); err != nil {
					log.Printf("[ws] write error (user %s): %v", userID, err)
					return
				}
			}
		}()

		// reader loop, for now, echo messages back through the hub on the same channel
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
				log.Printf("[ws] read error (user %s): %v", userID, err)
				return
			}

			// Optional: allow client messages to be broadcast to their own channel
			wsHub.Publish(channel, "client", incoming)
		}
	})

	hub := server.NewSSEHub()

	// streaming routes: anything under /stream/ uses DispatchStream
	mux.HandleFunc("/stream/", func(w http.ResponseWriter, r *http.Request) {
		// tell php worker we want streaming
		r.Header.Set("X-Go-Stream", "1")
		payload := BuildPayload(r)
		start := time.Now()

		routeKey := r.URL.Path
		if routeKey == "" {
			routeKey = "/stream"
		}

		metrics.StartRequest(routeKey)

		if err := srv.DispatchStream(payload, w); err != nil {
			elapsed := time.Since(start)
			metrics.EndRequest(routeKey, elapsed, true)
			writeWorkerError(w, err)
			log.Printf("[req %s] %s %s -> stream error: %v", payload.ID, payload.Method, payload.Path, err)
			return
		}

		elapsed := time.Since(start)
		metrics.EndRequest(routeKey, elapsed, false)
		srv.RecordLatency(payload.Path, elapsed)

		log.Printf("[req %s] %s %s -> streamed (%v)", payload.ID, payload.Method, payload.Path, elapsed)
	})

	mux.HandleFunc("/__ws", func(w http.ResponseWriter, r *http.Request) {
		channel := r.URL.Query().Get("channel")
		if channel == "" {
			http.Error(w, "missing channel", http.StatusBadRequest)
			return
		}

		conn, err := wsUpgrader.Upgrade(w, r, nil)
		if err != nil {
			log.Printf("[ws] upgrade error: %v", err)
			return
		}

		defer conn.Close()

		client := wsHub.Subscribe(channel)
		defer wsHub.Unsubscribe(channel, client)

		// Writer goroutine: send hub messages to this websocket
		done := make(chan struct{})
		go func() {
			defer close(done)
			for msg := range client.Send {
				// send as JSON: {"type": "...", "data": {...} }
				if err := conn.WriteJSON(msg); err != nil {
					log.Printf("[ws] write error: %v", err)
					return
				}
			}
		}()

		// Reader Loop: for now, echo messages back through the hub on the same channel
		// @todo: change semantics
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
				log.Printf("[ws] read error: %v", err)
				return
			}

			wsHub.Publish(channel, "client", incoming)
		}
	})

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

	// Main application handler
	mux.HandleFunc("/", func(w http.ResponseWriter, r *http.Request) {
		// 1) Try static assets first
		if tryServeStatic(w, r, root, cfg.Static) {
			return
		}

		// 2) Transform request → payload for PHP worker
		payload := BuildPayload(r)
		start := time.Now()

		// Metrics: per-route tracking
		routeKey := r.URL.Path
		if routeKey == "" {
			routeKey = "/"
		}
		metrics.StartRequest(routeKey)

		// Optional: streaming path (guarded by header)
		if r.Header.Get("X-Go-Stream") == "1" {
			if err := srv.DispatchStream(payload, w); err != nil {
				elapsed := time.Since(start)
				metrics.EndRequest(routeKey, elapsed, true)
				writeWorkerError(w, err)
				log.Printf("[req %s] %s %s -> stream error: %v", payload.ID, payload.Method, payload.Path, err)
				return
			}

			elapsed := time.Since(start)
			metrics.EndRequest(routeKey, elapsed, false)
			srv.RecordLatency(payload.Path, elapsed)
			log.Printf("[req %s] %s %s -> streamed (%v)", payload.ID, payload.Method, payload.Path, elapsed)
			return
		}

		// 3) Normal non-streaming path
		resp, err := srv.Dispatch(payload)
		if err != nil {
			elapsed := time.Since(start)
			metrics.EndRequest(routeKey, elapsed, true)
			writeWorkerError(w, err)
			log.Printf("[req %s] %s %s -> worker error: %v", payload.ID, payload.Method, payload.Path, err)
			return
		}

		// If PHP returns 404, give static another chance
		if resp.Status == http.StatusNotFound {
			if tryServeStatic(w, r, root, cfg.Static) {
				elapsed := time.Since(start)
				metrics.EndRequest(routeKey, elapsed, false)
				return
			}
		}

		// Copy headers
		for k, v := range resp.Headers {
			w.Header().Set(k, v)
		}

		// Write status
		status := resp.Status
		if status == 0 {
			status = http.StatusOK
		}
		w.WriteHeader(status)

		// Write body
		_, _ = w.Write([]byte(resp.Body))

		// Final metrics + structured log
		elapsed := time.Since(start)
		metrics.EndRequest(routeKey, elapsed, false)

		entry := RequestLog{
			Time:       time.Now(),
			ID:         payload.ID,
			Method:     payload.Method,
			Path:       payload.Path,
			Status:     status,
			DurationMs: float64(elapsed.Milliseconds()),
			RemoteAddr: r.RemoteAddr,
			UserAgent:  r.UserAgent(),
		}
		logRequestJSON(entry)
	})

	// Health summary: worker pools etc.
	mux.HandleFunc("/__baremetal/health", func(w http.ResponseWriter, r *http.Request) {
		summary := srv.Health()
		w.Header().Set("Content-Type", "application/json")
		if err := json.NewEncoder(w).Encode(summary); err != nil {
			http.Error(w, "Failed to encode health summary", http.StatusInternalServerError)
			return
		}
	})

	// Force recycle: mark all workers dead so they respawn on next requests
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

		// initial comment so EventSource opens
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

	// SSE publish endpoint: POST /__sse/publish
	// Body: { "channel": "foo", "event", "update", "data": { ... } }
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

	// Hot reload (if enabled)
	if cfg.HotReload {
		if err := srv.EnableHotReload(root); err != nil {
			log.Println("Hot reload disabled:", err)
		} else {
			log.Println("Hot reload enabled")
		}
	}

	// Resolve listen address: APP_SERVER_ADDR env or default
	addr := os.Getenv("APP_SERVER_ADDR")
	if addr == "" {
		addr = ":8080"
	}

	httpSrv := &http.Server{
		Addr:    addr,
		Handler: mux,
	}

	// Graceful shutdown on SIGINT/SIGTERM
	shutdownCh := make(chan os.Signal, 1)
	signal.Notify(shutdownCh, syscall.SIGINT, syscall.SIGTERM)

	go func() {
		<-shutdownCh
		log.Println("[shutdown] signal received, draining workers and shutting down HTTP server...")

		// stop taking new requests
		ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
		defer cancel()

		// tell PHP workers to drain (no new jobs, finish in-flight)
		srv.DrainWorkers()

		if err := httpSrv.Shutdown(ctx); err != nil {
			log.Printf("[shutdown] http server shutdown error: %v", err)
		} else {
			log.Println("[shutdown] http server shut down cleanly")
		}
	}()

	// Startup banner / config summary
	log.Println("=============================================")
	log.Printf(" BareMetalPHP Go App Server listening on %s", addr)
	log.Println("=============================================")
	log.Printf(" Fast workers: %d", cfg.FastWorkers)
	log.Printf(" Slow workers: %d", cfg.SlowWorkers)
	log.Printf(" Timeout: %dms", cfg.RequestTimeoutMs)
	log.Printf(" Max requests/worker: %d", cfg.MaxRequestsPerWorker)
	log.Println(" Static rules:")
	for _, rule := range cfg.Static {
		log.Printf("   %s → %s", rule.Prefix, filepath.Join(root, rule.Dir))
	}
	log.Println("=============================================")

	// Start HTTP server (blocks until shutdown)
	if err := httpSrv.ListenAndServe(); err != nil && err != http.ErrServerClosed {
		log.Fatalf("[server] listen error: %v", err)
	}
}

type StaticRule struct {
	Prefix string `json:"prefix"`
	Dir    string `json:"dir"`
}

type AppServerConfig struct {
	FastWorkers          int          `json:"fast_workers"`
	SlowWorkers          int          `json:"slow_workers"`
	HotReload            bool         `json:"hot_reload"`
	RequestTimeoutMs     int          `json:"request_timeout_ms"`
	MaxRequestsPerWorker int          `json:"max_requests_per_worker"`
	Static               []StaticRule `json:"static"`

	SlowRoutes        []string `json:"slow_routes"`
	SlowMethods       []string `json:"slow_methods"`
	SlowBodyThreshold int      `json:"slow_body_threshold"`
}

// defaultConfig returns sane defaults when go_appserver.json
// is missing or invalid.
func defaultConfig() *AppServerConfig {
	return &AppServerConfig{
		FastWorkers:          4,
		SlowWorkers:          2,
		HotReload:            false,
		RequestTimeoutMs:     10000, // 10s
		MaxRequestsPerWorker: 1000,
		Static: []StaticRule{
			{Prefix: "/assets/", Dir: "public/assets"},
			{Prefix: "/build/", Dir: "public/build"},
			{Prefix: "/css/", Dir: "public/css"},
			{Prefix: "/js/", Dir: "public/js"},
			{Prefix: "/images/", Dir: "public/images"},
			{Prefix: "/img/", Dir: "public/img"},
		},
		SlowRoutes:        []string{"/reports/", "/admin/analytics"},
		SlowMethods:       []string{"PUT", "DELETE"},
		SlowBodyThreshold: 2_000_000,
	}
}

// loadConfig tries to read go_appserver.json from projectRoot;
// falls back to defaults on any error.
func loadConfig(projectRoot string) *AppServerConfig {
	cfgPath := filepath.Join(projectRoot, "go_appserver.json")

	data, err := os.ReadFile(cfgPath)
	if err != nil {
		log.Printf("[config] no go_appserver.json found at %s, using defaults: %v", cfgPath, err)
		return defaultConfig()
	}

	var cfg AppServerConfig
	if err := json.Unmarshal(data, &cfg); err != nil {
		log.Printf("[config] invalid go_appserver.json (%s), using defaults: %v", cfgPath, err)
		return defaultConfig()
	}

	// Pull a copy of defaults for use below
	def := defaultConfig()

	//
	// -------------------------
	// Core config validation
	// -------------------------
	//

	if cfg.FastWorkers <= 0 {
		log.Printf("[config] fast_workers=%d is invalid, falling back to %d", cfg.FastWorkers, def.FastWorkers)
		cfg.FastWorkers = def.FastWorkers
	}

	if cfg.SlowWorkers < 0 {
		log.Printf("[config] slow_workers=%d is invalid, falling back tp %d", cfg.SlowWorkers, def.SlowWorkers)
		cfg.SlowWorkers = def.SlowWorkers
	}

	if cfg.RequestTimeoutMs <= 0 {
		log.Printf("[config] request_timeout_ms=%d is invalid, falling back to %dms", cfg.RequestTimeoutMs, def.RequestTimeoutMs)
		cfg.RequestTimeoutMs = def.RequestTimeoutMs
	}

	if cfg.MaxRequestsPerWorker <= 0 {
		log.Printf("[config] max_requests_per_worker=%d is invalid, falling back to %d", cfg.MaxRequestsPerWorker, def.MaxRequestsPerWorker)
		cfg.MaxRequestsPerWorker = def.MaxRequestsPerWorker
	}

	//
	// -------------------------
	// Static rules validation
	// -------------------------
	//
	if len(cfg.Static) == 0 {
		log.Printf("[config] no static rules configured, using default static rules")
		cfg.Static = defaultConfig().Static
	} else {
		for i, rule := range cfg.Static {
			if !strings.HasPrefix(rule.Prefix, "/") {
				log.Printf("[config] static[%d].prefix=%q does not start with '/', fixing", i, rule.Prefix)
				cfg.Static[i].Prefix = "/" + rule.Prefix
			}

			if rule.Dir == "" {
				log.Printf("[config] static[%d].dir is empty, this rule will be ignored at runtime.", i)
			}
		}
	}

	//
	// -------------------------
	// Slow-request config
	// -------------------------
	//

	// Route prefixes
	if len(cfg.SlowRoutes) == 0 {
		cfg.SlowRoutes = def.SlowRoutes
		log.Printf("[config] stow_routes missing, using defaults: %v", cfg.SlowRoutes)
	}

	// Methods to treat as slow
	if len(cfg.SlowMethods) == 0 {
		cfg.SlowMethods = def.SlowMethods
		log.Printf("[config] slow_methods missing, using defaults: %v", cfg.SlowMethods)
	}

	// Body size threshold
	if cfg.SlowBodyThreshold <= 0 {
		cfg.SlowBodyThreshold = def.SlowBodyThreshold
		log.Printf("[config] slow_body_threshold invalid, using default: %d bytes", cfg.SlowBodyThreshold)
	}
	return &cfg
}
