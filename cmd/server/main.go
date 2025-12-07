package main

import (
	"encoding/json"
	"io"
	"log"
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

	// Create worker pools
	srv, err := server.NewServer(
		cfg.FastWorkers,
		cfg.SlowWorkers,
		cfg.MaxRequestsPerWorker,
		timeout,
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

		// 3) Dispatch to either fast or slow pool
		resp, err := srv.Dispatch(payload)
		if err != nil {
			log.Println("Worker error:", err)
			http.Error(w, "Worker error: "+err.Error(), 500)
			return
		}

		// 4) If PHP returns 404 → last-chance static fallback
		if resp.Status == http.StatusNotFound {
			if tryServeStatic(w, r, projectRoot, cfg.Static) {
				return
			}
		}

		// Write headers
		for k, v := range resp.Headers {
			w.Header().Set(k, v)
		}

		// Write status
		status := resp.Status
		if status == 0 {
			status = 200
		}
		w.WriteHeader(status)

		// Write body
		_, _ = w.Write([]byte(resp.Body))
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

	// Apply defaults / clamps with logging so misconfigurations are obvious.
	if cfg.FastWorkers <= 0 {
		log.Printf("[config] fast_workers=%d is invalid, falling back to 4", cfg.FastWorkers)
		cfg.FastWorkers = 4
	}
	if cfg.SlowWorkers < 0 {
		log.Printf("[config] slow_workers=%d is invalid, falling back to 2", cfg.SlowWorkers)
		cfg.SlowWorkers = 2
	}
	if cfg.RequestTimeoutMs <= 0 {
		log.Printf("[config] request_timeout_ms=%d is invalid, falling back to 10000ms", cfg.RequestTimeoutMs)
		cfg.RequestTimeoutMs = 10000
	}
	if cfg.MaxRequestsPerWorker <= 0 {
		log.Printf("[config] max_requests_per_worker=%d is invalid, falling back to 1000", cfg.MaxRequestsPerWorker)
		cfg.MaxRequestsPerWorker = 1000
	}
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

	return &cfg
}
