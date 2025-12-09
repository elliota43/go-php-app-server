package server

import (
	"encoding/json"
	"log"
	"sync"
)

// WSMessage is a generic message traveling through the hub
type WSMessage struct {
	Channel string          `json:"channel"`
	Type    string          `json:"type,omitempty"`
	Data    json.RawMessage `json:"data"`
}

type WSClient struct {
	Send chan WSMessage
}

type WSHub struct {
	mu      sync.RWMutex
	clients map[string]map[*WSClient]struct{} // channel -> clients
}

func NewWSHub() *WSHub {
	return &WSHub{
		clients: make(map[string]map[*WSClient]struct{}),
	}
}

// Subscribe registers a new client for the given channel.
func (h *WSHub) Subscribe(channel string) *WSClient {
	c := &WSClient{
		Send: make(chan WSMessage, 16),
	}

	h.mu.Lock()
	defer h.mu.Unlock()

	if h.clients[channel] == nil {
		h.clients[channel] = make(map[*WSClient]struct{})
	}
	h.clients[channel][c] = struct{}{}
	return c
}

// Unsubscribe removes a client from the given channel and closes its send channel.
func (h *WSHub) Unsubscribe(channel string, c *WSClient) {
	h.mu.Lock()
	defer h.mu.Unlock()

	subs := h.clients[channel]
	if subs == nil {
		return
	}

	delete(subs, c)
	close(c.Send)
	if len(subs) == 0 {
		delete(h.clients, channel)
	}
}

// Publish broadcasts a message to all clients on the given channel.
func (h *WSHub) Publish(channel, msgType string, payload any) {
	data, err := json.Marshal(payload)
	if err != nil {
		log.Printf("[ws] marshal error: %v", err)
		return
	}

	ev := WSMessage{
		Channel: channel,
		Type:    msgType,
		Data:    data,
	}

	h.mu.RLock()
	subs := h.clients[channel]
	for c := range subs {
		select {
		case c.Send <- ev:

		default:
			// client is slow / buffer full, drop message

		}
	}

	h.mu.RUnlock()
}
