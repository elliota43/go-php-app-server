package main

import (
	"encoding/json"
	"io"
	"log"
	"net"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"time"

	"go-php/server" // IMPORTANT: change this if your module path differs

	"github.com/google/uuid"
)

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
	projectRoot := getProjectRoot()

	// Load go_appserver.json (or defaults)
	cfg := loadConfig(projectRoot)

	timeout := time.Duration(cfg.RequestTimeoutMs) * time.Millisecond

	slowCfg := server.SlowRequestConfig{
		RoutePrefixes: cfg.SlowRoutes,
		Methods:       cfg.SlowMethods,
		BodyThreshold: cfg.SlowBodyThreshold,
	}

	// Create worker pools
	srv, err := server.NewServer(
		cfg.FastWorkers,
		cfg.SlowWorkers,
		cfg.MaxRequestsPerWorker,
		timeout,
		slowCfg,
	)
	if err != nil {
		log.Fatal("Failed creating worker pools:", err)
	}

	// Hot reload (if enabled)
	if cfg.HotReload {
		if err := srv.EnableHotReload(projectRoot); err != nil {
			log.Println("Hot reload disabled:", err)
		} else {
			log.Println("Hot reload enabled")
		}
	}

	log.Println("=============================================")
	log.Println(" BareMetalPHP Go App Server Started :8080")
	log.Println("=============================================")
	log.Printf(" Fast workers: %d", cfg.FastWorkers)
	log.Printf(" Slow workers: %d", cfg.SlowWorkers)
	log.Printf(" Timeout: %dms", cfg.RequestTimeoutMs)
	log.Printf(" Max requests/worker: %d", cfg.MaxRequestsPerWorker)
	log.Println(" Static rules:")
	for _, rule := range cfg.Static {
		log.Printf("   %s → %s", rule.Prefix, filepath.Join(projectRoot, rule.Dir))
	}
	log.Println("=============================================")

	http.HandleFunc("/", func(w http.ResponseWriter, r *http.Request) {

		// 1) Try static assets first
		if tryServeStatic(w, r, projectRoot, cfg.Static) {
			return
		}

		// 2) Transform request → payload for PHP worker
		payload := BuildPayload(r)
		start := time.Now()

		// TEMP streaming demo toggle by request header
		if r.Header.Get("X-Go-Stream") == "1" {
			if err := srv.DispatchStream(payload, w); err != nil {
				writeWorkerError(w, err)
				log.Printf("[req %s] %s %s -> stream error: %v", payload.ID, payload.Method, payload.Path, err)
				return
			}
			elapsed := time.Since(start)
			log.Printf("[req %s] %s %s -> streamed (%v)", payload.ID, payload.Method, payload.Path, elapsed)
			return
		}

		// 2) Normal non-streaming path (unchanged)
		resp, err := srv.Dispatch(payload)
		if err != nil {
			writeWorkerError(w, err)
			log.Println("[req %s] %s %s -> worker error: %v", payload.ID, payload.Method, payload.Path, err)
			return
		}

		if resp.Status == http.StatusNotFound {
			if tryServeStatic(w, r, projectRoot, cfg.Static) {
				return
			}
		}

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

		// 5) Log successful request
		elapsed := time.Since(start)
		log.Printf("[req %s] %s %s -> %d (%v)",
			payload.ID, payload.Method, payload.Path, status, elapsed)
	})

	addr := os.Getenv("APP_SERVER_ADDR")
	if addr == "" {
		addr = ":8080"
	}

	log.Printf("Go PHP app server listening on %s", addr)
	if err := http.ListenAndServe(addr, nil); err != nil {
		log.Fatal("HTTP Server failed:", err)
	}

	// Start actual Go HTTP server
	if err := http.ListenAndServe(":8080", nil); err != nil {
		log.Fatal("HTTP Server failed:", err)
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
