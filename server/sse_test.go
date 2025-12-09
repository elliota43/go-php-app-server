package server

import (
	"encoding/json"
	"testing"
)

func TestSSEHubSubscribeAndPublish(t *testing.T) {
	hub := NewSSEHub()

	client := hub.Subscribe("test")
	defer hub.Unsubscribe("test", client)

	done := make(chan struct{})
	go func() {
		defer close(done)
		ev := <-client.ch

		if ev.Channel != "test" {
			t.Errorf("expected channel=test, got %s", ev.Channel)
		}
		if ev.Event != "ping" {
			t.Errorf("expected event=ping, got %s", ev.Event)
		}

		var data map[string]any
		if err := json.Unmarshal(ev.Data, &data); err != nil {
			t.Fatalf("unmarshal error: %v", err)
		}
		if data["hello"] != "world" {
			t.Fatalf("expected hello=world, got %v", data["hello"])
		}
	}()

	hub.Publish("test", "ping", map[string]string{"hello": "world"})
	<-done
}

func TestSSEHubUnsubscribeRemovesClient(t *testing.T) {
	hub := NewSSEHub()

	client := hub.Subscribe("chan")
	hub.Unsubscribe("chan", client)

	// Should not panic or block if we publish after unsubscribe.
	hub.Publish("chan", "event", map[string]string{"k": "v"})
}

func TestSSEHubPublishWithNoSubscribers(t *testing.T) {
	hub := NewSSEHub()
	// Publish to a channel with no subscribers - should not panic
	hub.Publish("empty", "test", map[string]string{"key": "value"})
}

func TestSSEHubPublishWithUnmarshalableData(t *testing.T) {
	hub := NewSSEHub()
	client := hub.Subscribe("test")
	defer hub.Unsubscribe("test", client)

	// Create data that can't be marshaled (channel)
	unmarshalable := make(chan int)
	hub.Publish("test", "test", unmarshalable)

	// Should not panic, error should be logged
	// We can't easily test the log output, but we can ensure it doesn't crash
}

func BenchmarkSSEHubPublish(b *testing.B) {
	hub := NewSSEHub()

	const numClients = 500

	for i := 0; i < numClients; i++ {
		c := hub.Subscribe("bench")
		go func(cl *sseClient) {
			for range cl.ch {
				// discard
			}
		}(c)
	}

	b.ResetTimer()
	for n := 0; n < b.N; n++ {
		hub.Publish("bench", "bench", map[string]string{"msg": "x"})
	}
}
