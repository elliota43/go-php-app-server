package server

import "sync/atomic"

type WorkerPool struct {
	workers []*Worker
	next    uint32
}

func NewPool(count int) (*WorkerPool, error) {
	workers := make([]*Worker, 0, count)

	for i := 0; i < count; i++ {
		w, err := NewWorker()
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
	// Round-robin
	i := atomic.AddUint32(&p.next, 1)
	w := p.workers[i%uint32(len(p.workers))]

	return w.Handle(req)
}
