package server

import (
	"log"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"sync/atomic"
	"time"

	"github.com/fsnotify/fsnotify"
)

type SlowRequestConfig struct {
	RoutePrefixes []string
	Methods       []string
	BodyThreshold int
}

type Server struct {
	fastPool *WorkerPool
	slowPool *WorkerPool
	slowCfg  SlowRequestConfig
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
		fastPool: fp,
		slowPool: sp,
		slowCfg:  slowCfg,
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
