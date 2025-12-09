// cmd/server/main_test.go
package main

import (
	"bytes"
	"encoding/json"
	"errors"
	"io"
	"net"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

func TestTryServeStaticServesFile(t *testing.T) {
	root := t.TempDir()
	staticDir := filepath.Join(root, "public", "assets")
	if err := os.MkdirAll(staticDir, 0o755); err != nil {
		t.Fatalf("mkdir: %v", err)
	}

	const fileContent = "hello world"
	if err := os.WriteFile(filepath.Join(staticDir, "test.txt"), []byte(fileContent), 0o644); err != nil {
		t.Fatalf("write file: %v", err)
	}

	r := httptest.NewRequest(http.MethodGet, "/assets/test.txt", nil)
	w := httptest.NewRecorder()

	rules := []StaticRule{
		{Prefix: "/assets/", Dir: "public/assets"},
	}

	served := tryServeStatic(w, r, root, rules)
	if !served {
		t.Fatalf("expected tryServeStatic to return true")
	}

	resp := w.Result()
	body, _ := io.ReadAll(resp.Body)
	if string(body) != fileContent {
		t.Fatalf("unexpected body: %q", string(body))
	}
}

func TestTryServeStaticWrongMethod(t *testing.T) {
	root := t.TempDir()
	r := httptest.NewRequest(http.MethodPost, "/assets/test.txt", nil)
	w := httptest.NewRecorder()

	served := tryServeStatic(w, r, root, []StaticRule{
		{Prefix: "/assets/", Dir: "public/assets"},
	})
	if served {
		t.Fatalf("expected tryServeStatic to return false for non-GET/HEAD")
	}
}

func TestBuildPayloadCopiesHeadersAndRequestURI(t *testing.T) {
	body := bytes.NewBufferString("payload")
	r := httptest.NewRequest(http.MethodPost, "/foo/bar?x=1", body)
	r.RemoteAddr = net.IPv4(127, 0, 0, 1).String() + ":12345"
	r.Header.Set("X-Custom", "val")

	payload := BuildPayload(r)
	if payload.Method != http.MethodPost {
		t.Fatalf("expected method %s, got %s", http.MethodPost, payload.Method)
	}
	if payload.Path != "/foo/bar?x=1" {
		t.Fatalf("expected full RequestURI, got %q", payload.Path)
	}
	if payload.Body != "payload" {
		t.Fatalf("unexpected body: %q", payload.Body)
	}
	if payload.Headers["X-Custom"][0] != "val" {
		t.Fatalf("expected X-Custom header to be copied")
	}
	if _, ok := payload.Headers["Host"]; !ok {
		t.Fatalf("expected Host header to be set")
	}
	if xf, ok := payload.Headers["X-Forwarded-For"]; !ok || len(xf) == 0 {
		t.Fatalf("expected X-Forwarded-For to be populated")
	}
	if _, ok := payload.Headers["X-Request-Id"]; !ok {
		t.Fatalf("expected X-Request-Id to be injected")
	}
}

func TestGetProjectRootFindsGoMod(t *testing.T) {
	tmp := t.TempDir()
	// fake module root
	if err := os.WriteFile(filepath.Join(tmp, "go.mod"), []byte("module example.com/test"), 0o644); err != nil {
		t.Fatalf("write go.mod: %v", err)
	}

	// create subdir and chdir into it
	sub := filepath.Join(tmp, "sub")
	if err := os.Mkdir(sub, 0o755); err != nil {
		t.Fatalf("mkdir: %v", err)
	}
	oldWD, _ := os.Getwd()
	defer os.Chdir(oldWD)
	if err := os.Chdir(sub); err != nil {
		t.Fatalf("chdir: %v", err)
	}

	root := getProjectRoot()

	// macOS /var is a symlink to /private/var, which breaks the equality check.
	resolvedRoot, err := filepath.EvalSymlinks(root)
	if err != nil {
		t.Fatalf("EvalSymlinks: %v", err)
	}
	resolvedTmp, err := filepath.EvalSymlinks(tmp)
	if err != nil {
		t.Fatalf("EvalSymlinks(tmp): %v", err)
	}
	if resolvedRoot != resolvedTmp {
		t.Fatalf("expected project root %q, got %q", resolvedTmp, resolvedRoot)
	}
}

func TestDefaultConfigAndLoadConfigFallback(t *testing.T) {
	tmp := t.TempDir()
	cfg := loadConfig(tmp) // no go_appserver.json → defaults
	def := defaultConfig()

	if cfg.FastWorkers != def.FastWorkers ||
		cfg.SlowWorkers != def.SlowWorkers ||
		cfg.RequestTimeoutMs != def.RequestTimeoutMs {
		t.Fatalf("loadConfig did not fall back to defaults correctly: %#v", cfg)
	}
}

func TestLoadConfigValidationAndDefaults(t *testing.T) {
	tmp := t.TempDir()
	cfgPath := filepath.Join(tmp, "go_appserver.json")

	// Intentionally invalid / weird values to trigger validation logic.
	raw := AppServerConfig{
		FastWorkers:          -1,
		SlowWorkers:          -5,
		RequestTimeoutMs:     0,
		MaxRequestsPerWorker: 0,
		Static: []StaticRule{
			{Prefix: "assets", Dir: ""}, // missing leading slash, empty dir
		},
		SlowRoutes:        nil,
		SlowMethods:       nil,
		SlowBodyThreshold: 0,
	}
	data, _ := json.Marshal(raw)
	if err := os.WriteFile(cfgPath, data, 0o644); err != nil {
		t.Fatalf("write config: %v", err)
	}

	cfg := loadConfig(tmp)
	if cfg.FastWorkers <= 0 {
		t.Fatalf("FastWorkers not fixed up: %d", cfg.FastWorkers)
	}
	if cfg.SlowWorkers < 0 {
		t.Fatalf("SlowWorkers not fixed up: %d", cfg.SlowWorkers)
	}
	if cfg.RequestTimeoutMs <= 0 {
		t.Fatalf("RequestTimeoutMs not fixed up: %d", cfg.RequestTimeoutMs)
	}
	if cfg.MaxRequestsPerWorker <= 0 {
		t.Fatalf("MaxRequestsPerWorker not fixed up: %d", cfg.MaxRequestsPerWorker)
	}

	if len(cfg.Static) == 0 {
		t.Fatalf("expected static rules to be non-empty after validation")
	}
	for _, rule := range cfg.Static {
		if !strings.HasPrefix(rule.Prefix, "/") {
			t.Fatalf("static prefix still missing leading slash: %q", rule.Prefix)
		}
	}
	if len(cfg.SlowRoutes) == 0 {
		t.Fatalf("expected SlowRoutes to fall back to defaults")
	}
	if len(cfg.SlowMethods) == 0 {
		t.Fatalf("expected SlowMethods to fall back to defaults")
	}
	if cfg.SlowBodyThreshold <= 0 {
		t.Fatalf("expected SlowBodyThreshold to fall back to defaults")
	}
}

func TestMapWorkerErrorToStatus(t *testing.T) {
	if got := mapWorkerErrorToStatus(errors.New("timeout")); got != http.StatusGatewayTimeout {
		t.Fatalf("timeout → %d, want %d", got, http.StatusGatewayTimeout)
	}
	if got := mapWorkerErrorToStatus(errors.New("broken pipe")); got != http.StatusBadGateway {
		t.Fatalf("broken pipe → %d, want %d", got, http.StatusBadGateway)
	}
	if got := mapWorkerErrorToStatus(errors.New("something else")); got != http.StatusInternalServerError {
		t.Fatalf("other error → %d, want %d", got, http.StatusInternalServerError)
	}
}

func TestWriteWorkerErrorWritesStatus(t *testing.T) {
	rr := httptest.NewRecorder()
	writeWorkerError(rr, errors.New("timeout"))
	resp := rr.Result()
	if resp.StatusCode != http.StatusGatewayTimeout {
		t.Fatalf("expected 504, got %d", resp.StatusCode)
	}
}

func TestMetricsStartEndSnapshot(t *testing.T) {
	m := NewMetrics()

	m.StartRequest("/foo")
	m.StartRequest("/foo")
	m.StartRequest("/bar")

	m.EndRequest("/foo", 10*time.Millisecond, false)
	m.EndRequest("/foo", 20*time.Millisecond, true)
	m.EndRequest("/bar", 5*time.Millisecond, false)

	snap := m.Snapshot()

	if snap.TotalRequests != 3 {
		t.Fatalf("TotalRequests = %d, want 3", snap.TotalRequests)
	}
	if snap.TotalErrors != 1 {
		t.Fatalf("TotalErrors = %d, want 1", snap.TotalErrors)
	}
	if snap.InFlight != 0 {
		t.Fatalf("InFlight = %d, want 0", snap.InFlight)
	}

	foo := snap.ByRoute["/foo"]
	if foo == nil || foo.Count != 2 {
		t.Fatalf("foo stats - %#v, want Count=2", foo)
	}
	if foo.TotalLatency <= 0 {
		t.Fatalf("foo.TotalLatency should be > 0")
	}
}
