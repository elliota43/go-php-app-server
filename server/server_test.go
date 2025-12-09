package server

import (
	"net/http"
	"testing"
	"time"
)

func TestIsSlowRequestByPrefix(t *testing.T) {
	s := &Server{
		slowCfg: SlowRequestConfig{
			RoutePrefixes: []string{"/slow", "/admin"},
			Methods:       []string{},
			BodyThreshold: 0,
		},
	}

	req := &RequestPayload{
		Method: "GET",
		Path:   "/slow/report",
		Body:   "",
	}

	if !s.IsSlowRequest(req) {
		t.Fatalf("expected IsSlowRequest to be true for slow route prefix")
	}
}

func TestIsSlowRequestByMethod(t *testing.T) {
	s := &Server{
		slowCfg: SlowRequestConfig{
			RoutePrefixes: nil,
			Methods:       []string{"PUT", "DELETE"},
			BodyThreshold: 0,
		},
	}

	req := &RequestPayload{
		Method: "delete", //lower-case should still match
		Path:   "/anything",
		Body:   "",
	}

	if !s.IsSlowRequest(req) {
		t.Fatalf("expected IsSlowRequest to be true for slow method")
	}
}

func TestIsSlowRequestByBodyThreshold(t *testing.T) {
	s := &Server{
		slowCfg: SlowRequestConfig{
			RoutePrefixes: nil,
			Methods:       nil,
			BodyThreshold: 10,
		},
	}

	req := &RequestPayload{
		Method: "POST",
		Path:   "/upload",
		Body:   "0123456789ABCDEF", // 10 bytes
	}

	if !s.IsSlowRequest(req) {
		t.Fatalf("expected IsSlowRequest to be true for large body")
	}
}

func TestDispatchUsesFastAndSlowPools(t *testing.T) {
	fast := newFakePool(t, 1, time.Second)
	slow := newFakePool(t, 1, time.Second)

	s := &Server{
		fastPool: fast,
		slowPool: slow,
		slowCfg: SlowRequestConfig{
			RoutePrefixes: []string{"/slow"},
			Methods:       []string{},
			BodyThreshold: 0,
		},
	}

	fastReq := &RequestPayload{
		ID:     "1",
		Method: "GET",
		Path:   "/fast",
		Body:   "",
	}

	slowReq := &RequestPayload{
		ID:     "2",
		Method: "GET",
		Path:   "/slow/task",
		Body:   "",
	}

	fastResp, err := s.Dispatch(fastReq)
	if err != nil {
		t.Fatalf("Dispatch(fast) error: %v", err)
	}

	if fastResp.Status != http.StatusOK || fastResp.Body == "" {
		t.Fatalf("unexpected fast response: %#v", fastResp)
	}

	slowResp, err := s.Dispatch(slowReq)
	if err != nil {
		t.Fatalf("Dispatch(slow) error: %v", err)
	}

	if slowResp.Status != http.StatusOK || slowResp.Body == "" {
		t.Fatalf("unexpected slow response: %#v", slowResp)
	}
}

func TestMarkAllWorkersDead(t *testing.T) {
	fast := newFakePool(t, 2, time.Second)
	slow := newFakePool(t, 1, time.Second)

	s := &Server{
		fastPool: fast,
		slowPool: slow,
	}

	s.markAllWorkersDead()

	for _, w := range fast.workers {
		if !w.isDead() {
			t.Fatal("expected fast worker to be marked dead")
		}
	}

	for _, w := range slow.workers {
		if !w.isDead() {
			t.Fatalf("expected slow worker to be marked dead")
		}
	}
}

func TestEnableHotReloadMissingDirs(t *testing.T) {
	tmp := t.TempDir()

	s := &Server{
		fastPool: newFakePool(t, 1, time.Second),
		slowPool: newFakePool(t, 1, time.Second),
	}

	// hot reload should succeed even if the directories are missing
	if err := s.EnableHotReload(tmp); err != nil {
		t.Fatalf("expected no error when php/ and routes/ are missing: got %v", err)
	}
}

func TestHealthSummaryAndForceRecycle(t *testing.T) {
	fast := newFakePool(t, 2, time.Second)
	slow := newFakePool(t, 1, time.Second)

	s := &Server{
		fastPool: fast,
		slowPool: slow,
	}

	health := s.Health()
	if health.Fast.Workers != 2 || health.Slow.Workers != 1 {
		t.Fatalf("unexpected worker counts: %#v", health)
	}
	if health.Fast.DeadWorkers != 0 || health.Slow.DeadWorkers != 0 {
		t.Fatalf("expected no dead workers initially: %#v", health)
	}

	s.ForceRecycleWorkers()

	health2 := s.Health()
	if health2.Fast.DeadWorkers != 2 || health2.Slow.DeadWorkers != 1 {
		t.Fatalf("expected all workers dead after ForceRecycleWorkers: %#v", health2)
	}

}

func TestRecordLatencyPromotesSlowPrefix(t *testing.T) {
	s := &Server{
		slowCfg: SlowRequestConfig{
			RoutePrefixes: []string{},
		},
		routeStats: make(map[string]*routeStats),
	}

	// feed enough slow samples to cross threshold
	for i := 0; i < 20; i++ {
		s.RecordLatency("/reports/daily", 600*time.Millisecond)
	}

	found := false
	for _, p := range s.slowCfg.RoutePrefixes {
		if p == "/reports" || p == "/reports/" {
			found = true
			break
		}
	}
	if !found {
		t.Fatalf("expected /reports to be promoted to slow pool; got %+v", s.slowCfg.RoutePrefixes)
	}
}
