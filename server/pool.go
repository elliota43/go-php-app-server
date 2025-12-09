package server

import (
	"sync/atomic"
	"time"
)

type WorkerPool struct {
	workers []*Worker
	next    uint32
}

// NewPool creates a pool with count workers, each configured
// with maxRequests and requestTimeout.
func NewPool(count int, maxRequests int, requestTimeout time.Duration) (*WorkerPool, error) {
	workers := make([]*Worker, 0, count)

	for i := 0; i < count; i++ {
		w, err := NewWorker(maxRequests, requestTimeout)
		if err != nil {
			return nil, err
		}
		workers = append(workers, w)
	}

	return &WorkerPool{
		workers: workers,
	}, nil
}

func (p *WorkerPool) Dispatch(req *RequestPayload) (*ResponsePayload, error) {
	i := atomic.AddUint32(&p.next, 1)
	w := p.workers[i%uint32(len(p.workers))]

	return w.Handle(req)
}

func (p *WorkerPool) Stats() PoolStats {
	stats := PoolStats{}
	if p == nil {
		return stats
	}

	stats.Workers = len(p.workers)
	for _, w := range p.workers {
		if w.isDead() {
			stats.DeadWorkers++
		}
	}

	return stats
}
