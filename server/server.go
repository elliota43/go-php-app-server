package server

import (
	"log"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"sync/atomic"
	"time"

	"github.com/fsnotify/fsnotify"
)

// PoolStats describes the state of a worker pool.
type PoolStats struct {
	Workers     int `json:"workers"`
	DeadWorkers int `json:"dead_workers"`
}

type routeStats struct {
	count        uint64
	totalLatency time.Duration
}

// HealthSummary returns the health of the fast and slow pools.
type HealthSummary struct {
	Fast PoolStats `json:"fast_pool"`
	Slow PoolStats `json:"slow_pool"`
}
type SlowRequestConfig struct {
	RoutePrefixes []string
	Methods       []string
	BodyThreshold int
}

type Server struct {
	fastPool *WorkerPool
	slowPool *WorkerPool
	slowCfg  SlowRequestConfig

	routeMu    sync.Mutex
	routeStats map[string]*routeStats
}

// NewServer builds fast and slow pools with shared settings.
func NewServer(fastCount, slowCount, maxRequests int, requestTimeout time.Duration, slowCfg SlowRequestConfig) (*Server, error) {
	fp, err := NewPool(fastCount, maxRequests, requestTimeout)
	if err != nil {
		return nil, err
	}

	sp, err := NewPool(slowCount, maxRequests, requestTimeout)
	if err != nil {
		return nil, err
	}

	// Apply defaults if caller leaves fields empty.
	if slowCfg.BodyThreshold <= 0 {
		slowCfg.BodyThreshold = 2_000_000
	}

	if len(slowCfg.Methods) == 0 {
		slowCfg.Methods = []string{"PUT", "DELETE"}
	}

	return &Server{
		fastPool:   fp,
		slowPool:   sp,
		slowCfg:    slowCfg,
		routeStats: make(map[string]*routeStats),
	}, nil
}

// Simple heuristics to decide if a request should go to the "slow" pool. -- driven by SlowRequestConfig
func (s *Server) IsSlowRequest(r *RequestPayload) bool {
	// Route Prefixes
	for _, prefix := range s.slowCfg.RoutePrefixes {
		if prefix != "" && strings.HasPrefix(r.Path, prefix) {
			return true
		}
	}

	// Body size threshold
	if s.slowCfg.BodyThreshold > 0 && len(r.Body) > s.slowCfg.BodyThreshold {
		return true
	}

	// HTTP methods
	method := strings.ToUpper(r.Method)
	for _, m := range s.slowCfg.Methods {
		if method == strings.ToUpper(m) {
			return true
		}
	}

	return false
}

func (s *Server) Health() HealthSummary {
	return HealthSummary{
		Fast: s.fastPool.Stats(),
		Slow: s.slowPool.Stats(),
	}
}

func (s *Server) RecordLatency(path string, d time.Duration) {
	// Use the first path segment as the "prefix" key: /users/1 -> /users
	prefix := path
	if strings.HasPrefix(prefix, "/") {
		slash := strings.Index(prefix[1:], "/")
		if slash != -1 {
			prefix = prefix[:slash+1]
		}
	}

	s.routeMu.Lock()
	defer s.routeMu.Unlock()

	rs := s.routeStats[prefix]
	if rs == nil {
		rs = &routeStats{}
		s.routeStats[prefix] = rs
	}

	rs.count++
	rs.totalLatency += d

	// Very naive promotion: if avg latency > 500ms and not already in slowCfg.RoutePrefixes, add it
	if rs.count >= 10 { // need some samples
		avg := rs.totalLatency / time.Duration(rs.count)
		if avg > 500*time.Millisecond && !s.hasSlowPrefix(prefix) {
			s.slowCfg.RoutePrefixes = append(s.slowCfg.RoutePrefixes, prefix)
			log.Printf("[adaptive] promoting prefix %q to slow pool (avg=%v, count=%d)", prefix, avg, rs.count)
		}

	}
}

func (s *Server) hasSlowPrefix(prefix string) bool {
	for _, p := range s.slowCfg.RoutePrefixes {
		if p == prefix {
			return true
		}
	}

	return false
}

func (s *Server) Dispatch(req *RequestPayload) (*ResponsePayload, error) {
	if s.IsSlowRequest(req) {
		return s.slowPool.Dispatch(req)
	}
	return s.fastPool.Dispatch(req)
}

func (s *Server) DispatchStream(req *RequestPayload, rw http.ResponseWriter) error {
	var pool *WorkerPool
	if s.IsSlowRequest(req) {
		pool = s.slowPool
	} else {
		pool = s.fastPool
	}

	w := pool.NextWorker() // you may need to add this helper

	return w.Stream(req, rw)
}

func (p *WorkerPool) NextWorker() *Worker {
	i := atomic.AddUint32(&p.next, 1)
	return p.workers[i%uint32(len(p.workers))]
}

// -------------------------------------------------------------
// Hot reload support
// -------------------------------------------------------------

// markAllWorkersDead forces both pools to recreate workers on next request.
func (s *Server) markAllWorkersDead() {
	for _, w := range s.fastPool.workers {
		w.markDead()
	}
	for _, w := range s.slowPool.workers {
		w.markDead()
	}
}

func (s *Server) ForceRecycleWorkers() {
	s.markAllWorkersDead()
}

// EnableHotReload watches php/ and routes/ under projectRoot and marks all
// workers dead when changes are detected, so they restart lazily on next request.
func (s *Server) EnableHotReload(projectRoot string) error {
	watcher, err := fsnotify.NewWatcher()
	if err != nil {
		return err
	}

	// Directories to watch
	watchDirs := []string{
		filepath.Join(projectRoot, "php"),
		filepath.Join(projectRoot, "routes"),
	}

	for _, dir := range watchDirs {
		info, err := os.Stat(dir)
		if err != nil || !info.IsDir() {
			continue
		}
		if err := watcher.Add(dir); err != nil {
			log.Println("hot reload: failed to watch", dir, ":", err)
		} else {
			log.Println("hot reload: watching", dir)
		}
	}

	go func() {
		for {
			select {
			case ev, ok := <-watcher.Events:
				if !ok {
					return
				}
				if ev.Op&(fsnotify.Write|fsnotify.Create|fsnotify.Remove|fsnotify.Rename) != 0 {
					log.Println("hot reload: change detected in", ev.Name, "- recycling workers...")
					s.markAllWorkersDead()
				}

			case err, ok := <-watcher.Errors:
				if !ok {
					return
				}
				log.Println("hot reload watcher error:", err)
			}
		}
	}()

	return nil
}
