# 🚀 BareMetalPHP App Server  
### _High-performance Go + PHP worker bridge for the BareMetalPHP framework_

[![Go Version](https://img.shields.io/github/go-mod/go-version/baremetalphp/app-server)](#)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](#)
[![Build Status](https://img.shields.io/badge/build-passing-brightgreen.svg)](#)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB3.svg)](#)
[![BareMetalPHP](https://img.shields.io/badge/framework-BareMetalPHP-black.svg)](#)
[![Go](https://img.shields.io/badge/Go-1.22%2B-00ADD8.svg)](#)

A lightning-fast Go application server that runs **BareMetalPHP** via a persistent pool of PHP workers — similar to FrankenPHP and RoadRunner, but lightweight, transparent, and tailored for your framework.

---

## ✨ Features

- ⚡ **Persistent PHP workers** — no cold boots  
- 🧵 **Fast + slow worker pools** with request classification  
- 📁 **Static file serving** (Go handles assets before PHP)  
- 🔥 **Hot Reload** (automatic worker restart on PHP file changes)  
- 🧩 **Config file support** (`go_appserver.json`)  
- 🛠 **Automatic project root detection**  
- 🚦 **Graceful worker recycling** (timeouts + max request count)  
- 🔒 **Binary protocol** between Go and PHP workers  

---

## 📦 Installation

### 1. Initialize a Go module

```bash
go mod init my-app
```

### 2. Install dependencies

```bash
go get github.com/google/uuid
go get github.com/fsnotify/fsnotify
```

### 3. Place the app server code

Expected directory layout:

```
my-app/
├── go.mod
├── cmd/
│   └── server/
│       ├── main.go
│       └── config.go
├── server/
│   ├── worker.go
│   ├── pool.go
│   └── server.go
├── php/
│   ├── worker.php
│   ├── bridge.php
│   └── bootstrap_app.php
├── routes/
│   └── web.php
└── public/
```

### 4. Add the configuration file:

`go_appserver.json`

---

## ⚙️ Configuration (go_appserver.json)

```json
{
  "fast_workers": 4,
  "slow_workers": 2,
  "hot_reload": true,
  "request_timeout_ms": 10000,
  "max_requests_per_worker": 1000,
  "static": [
    { "prefix": "/assets/", "dir": "public/assets" },
    { "prefix": "/css/",    "dir": "public/css" },
    { "prefix": "/js/",     "dir": "public/js" }
  ]
}
```

If the file is missing, defaults are automatically applied.

---

## ▶️ Running the Server

From project root:

```bash
go run ./cmd/server
```

> **Important:**  
> Do **not** run `go run cmd/server/main.go` — Go will ignore `config.go` and break the build.  
> Instead run the whole package:  
> `go run ./cmd/server`

Server will start on:

```
http://localhost:8080
```

---

## 🧩 How It Works

```
┌──────────────┐
│ Go HTTP Host │
└──────┬───────┘
       │
       ├─► tryServeStatic() → serves /assets/, /css/, /js/ directly
       │
       └─► BuildPayload() → JSON message → WorkerPool
               │
               ▼
      ┌────────────────────┐
      │ PHP Worker (hot)   │   ← Boots BareMetalPHP ONCE
      │ handle_bridge_req  │
      └────────────────────┘
               │
               ▼
      JSON response → Go → Browser
```

Go = router + static host + supervisor  
PHP = long-running application kernel

---

## 🔥 Hot Reload (Dev Mode)

Enable via config:

```json
{ "hot_reload": true }
```

or environment variable:

```bash
export GO_PHP_HOT_RELOAD=1
```

Hot reload watches for changes in:

- `php/`
- `routes/`

When a file changes → workers marked dead → automatically restarted on next request.

---

## 📁 Example Project Structure

```
my-app/
├── cmd/server
│   ├── main.go
│   └── config.go
├── server
│   ├── worker.go
│   ├── pool.go
│   └── server.go
├── php
│   ├── bootstrap_app.php
│   ├── bridge.php
│   └── worker.php
├── routes
│   └── web.php
├── public
│   └── index.html
└── go_appserver.json
```

---

## 🐛 Troubleshooting

### ❌ Error: `undefined: StaticRule` or `undefined: loadConfig`

You're running:

```
go run cmd/server/main.go
```

Correct:

```
go run ./cmd/server
```

Go ignores files in the package if you run a *single* file.

---

### ❌ Error: `write |1: broken pipe`

This means:

- Worker crashed  
- Worker hit max request limit  
- Hot reload replaced workers  

The pool recovers automatically.

---

### ❌ Static files not served

Check:

- Your `prefix` routes  
- `public/` exists  
- `go_appserver.json` has correct directory names  
- No directory traversal (`../`) errors  

---

## 🚀 Production Configuration Example

```json
{
  "fast_workers": 12,
  "slow_workers": 4,
  "hot_reload": false,
  "request_timeout_ms": 30000,
  "max_requests_per_worker": 5000,
  "static": [
    { "prefix": "/",        "dir": "public" },
    { "prefix": "/assets/", "dir": "public/assets" }
  ]
}
```

---

## 🧭 Roadmap

- WebSocket support  
- HTTP/2 + QUIC  
- Native TLS termination  
- Worker dashboard + metrics endpoint  
- Fiber-style async adapters  
- Zero-downtime worker rotation  

---

## 📄 License

MIT License.  
Do whatever you want, just don’t sue us.

