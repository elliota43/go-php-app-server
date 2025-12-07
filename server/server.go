package server

import (
	"log"
	"os"
	"path/filepath"
	"strings"
	"time"

	"github.com/fsnotify/fsnotify"
)

type Server struct {
	fastPool *WorkerPool
	slowPool *WorkerPool
}

// NewServer builds fast and slow pools with shared settings.
func NewServer(fastCount, slowCount, maxRequests int, requestTimeout time.Duration) (*Server, error) {
	fp, err := NewPool(fastCount, maxRequests, requestTimeout)
	if err != nil {
		return nil, err
	}

	sp, err := NewPool(slowCount, maxRequests, requestTimeout)
	if err != nil {
		return nil, err
	}

	return &Server{
		fastPool: fp,
		slowPool: sp,
	}, nil
}

// Simple heuristics to decide if a request should go to the "slow" pool.
func (s *Server) IsSlowRequest(r *RequestPayload) bool {
	// explicit slow routes
	if strings.HasPrefix(r.Path, "/reports/") {
		return true
	}
	if strings.HasPrefix(r.Path, "/admin/analytics") {
		return true
	}

	// big uploads
	if len(r.Body) > 2_000_000 {
		return true
	}

	// heavier verbs
	if r.Method == "PUT" || r.Method == "DELETE" {
		return true
	}

	return false
}

func (s *Server) Dispatch(req *RequestPayload) (*ResponsePayload, error) {
	if s.IsSlowRequest(req) {
		return s.slowPool.Dispatch(req)
	}
	return s.fastPool.Dispatch(req)
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
