package server

import "strings"

type Server struct {
	fastPool *WorkerPool
	slowPool *WorkerPool
}

func NewServer(fastCount, slowCount int) (*Server, error) {
	fp, err := NewPool(fastCount)
	if err != nil {
		return nil, err
	}

	sp, err := NewPool(slowCount)
	if err != nil {
		return nil, err
	}

	return &Server{
		fastPool: fp,
		slowPool: sp,
	}, nil
}

// Classification logic -----------------------

func (s *Server) IsSlowRequest(r *RequestPayload) bool {
	// example heuristics

	//explicit slow routes (reports, exports)
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

	// PUT/DELETE often heavier
	if r.Method == "PUT" || r.Method == "DELETE" {
		return true
	}

	return false
}

// Dispatch -----------------------
func (s *Server) Dispatch(req *RequestPayload) (*ResponsePayload, error) {
	if s.IsSlowRequest(req) {
		return s.slowPool.Dispatch(req)
	}
	return s.fastPool.Dispatch(req)
}
