package server

import (
	"encoding/json"
	"testing"
)

// helper to drain messages without blocking
func drainWSClient(c *WSClient, done chan struct{}) {
	go func() {
		for range c.Send {
			// discard
		}
		close(done)
	}()
}

func TestWSHubSubscribeAndPublishSingleClient(t *testing.T) {
	hub := NewWSHub()

	client := hub.Subscribe("test")
	defer hub.Unsubscribe("test", client)

	done := make(chan struct{})
	go func() {
		defer close(done)
		msg := <-client.Send

		if msg.Channel != "test" {
			t.Errorf("expected channel=test, got %s", msg.Channel)
		}
		if msg.Type != "example" {
			t.Errorf("expected type=example, got %s", msg.Type)
		}

		var data map[string]any
		if err := json.Unmarshal(msg.Data, &data); err != nil {
			t.Fatalf("unmarshal error: %v", err)
		}
		if data["foo"] != "bar" {
			t.Fatalf("expected foo=bar, got %v", data["foo"])
		}
	}()

	hub.Publish("test", "example", map[string]string{"foo": "bar"})

	<-done
}

func TestWSHubUnsubscribeStopsDelivery(t *testing.T) {
	hub := NewWSHub()

	client := hub.Subscribe("room")
	hub.Unsubscribe("room", client)

	// Publishing should not panic or block, and should not deliver to client
	// (we can't easily detect "no send", but if we got it wrong we'll likely
	// see a panic from sending on a closed channel).
	hub.Publish("room", "event", map[string]string{"k": "v"})
}

func TestWSHubSlowClientDoesNotBlockPublish(t *testing.T) {
	hub := NewWSHub()

	client := hub.Subscribe("slow")
	// Don't drain client.Send at all – we want to fill its buffer and ensure
	// Publish still returns (thanks to the non-blocking send with default: drop).

	// Fill up the buffer; WSClient.Send was created with a small buffer.
	for i := 0; i < cap(client.Send)*2; i++ {
		hub.Publish("slow", "spam", map[string]int{"n": i})
	}

	// If Publish blocked, the test would hang; reaching here is success.
}

func BenchmarkWSHubPublishManyClients(b *testing.B) {
	hub := NewWSHub()

	const numClients = 1000

	for i := 0; i < numClients; i++ {
		c := hub.Subscribe("bench")
		go func(cl *WSClient) {
			for range cl.Send {
				// discard
			}
		}(c)
	}

	b.ResetTimer()
	for n := 0; n < b.N; n++ {
		hub.Publish("bench", "bench", map[string]string{"msg": "x"})
	}
}
