# PHP MCP Server — MCP Apps MVP

Minimal PHP MCP server that supports the **MCP Apps** extension (2026-01-26). It can run over **stdio** or **HTTP** and is packaged as a **Claude Desktop extension** (.mcpb). The same server exposes tools (e.g. `hello_ui`, `GenerateDraft`, `PublishDraft`) with optional **embedded UI** (MCP App) for hosts that support it.

## Requirements

- PHP 8.1+
- Composer (for autoload and dependencies)
- For packaging the Claude Desktop extension: Node.js (for `npx mcpb pack` only)

---

## Project overview

This project is a single **MCP server** (PHP) that:

- Exposes **tools** and **resources** (including `ui://` resources for MCP Apps).
- Can be used via **stdio** (e.g. Claude Desktop) or **HTTP** (e.g. [ext-apps](https://github.com/modelcontextprotocol/ext-apps) basic-host, or a browser hitting an HTTP MCP endpoint).
- Optionally runs an **HTTP endpoint** via the built-in flow-worker process or via **Symfony CLI** serving `public/index.php`.

The same MCP logic and tools are used in all modes; only the **transport** (stdio vs HTTP) and **how the process is started** (standalone script vs web server) change.

---

## What MCP Apps add to this project

MCP servers traditionally expose **tools** (and resources) that return text or structured data. The **MCP Apps** extension (spec: `specification/2026-01-26/apps.mdx` in the ext-apps repo) allows servers to attach an **interactive UI** to a tool:

- The server declares a **tool** and links it to a **UI resource** via `_meta.ui.resourceUri` (a `ui://` URI).
- When a host supports MCP Apps, it can **run the tool** and **fetch and render** that resource as an iframe (the “View”). The View communicates with the host over `postMessage` (JSON-RPC), not with the PHP server directly.
- Compared to text-only tools, this gives **embedded UIs** (forms, previews, controls) next to the tool result in the conversation.

In this project, the MCP App is used as a **tool enhanced with a UI**: e.g. `hello_ui` (demo) and article-related tools (`GenerateDraft`, `PublishDraft`, `RequestChanges`) each have an optional UI resource (`ui://darkwood/hello`, `ui://darkwood/article`). The host (e.g. basic-host or Claude Desktop, when it supports MCP Apps) shows both the tool result and the interactive View when available.

---

## Different usage modes of the MCP App

The same MCP server is used in several ways:

| Mode | Transport | Entry point | Typical use |
|------|------------|-------------|-------------|
| **Stdio** | STDIN/STDOUT (line-delimited JSON-RPC) | `php server.php` | Claude Desktop extension (.mcpb), local CLI clients |
| **HTTP (flow-worker)** | HTTP POST /mcp | `php bin/flow-worker.php` | Single process: MCP endpoint + optional Flow tick; basic-host |
| **HTTP (Symfony server)** | HTTP POST /mcp | `symfony serve` (document root: `public/`) | MCP endpoint served by Symfony CLI; no Flow worker in this process |
| **Claude Desktop extension** | Stdio (under the hood) | Packaged as .mcpb, runs `php server.php` | Use the MCP App inside Claude / Claude Desktop |
| **MCP App as tool with UI** | Any of the above | Depends on host | basic-host or any MCP Apps–capable host; tool call + UI resource rendered in iframe |

### 1. Stdio

- **Command:** `php server.php`
- **Behaviour:** Listens on STDIN for line-delimited JSON-RPC requests; writes one JSON-RPC response per line to STDOUT. No HTTP. Same MCP + Flow wiring as the rest of the project; only the transport is stdio.
- **Use case:** Claude Desktop extension (the .mcpb runs `php server.php`), or any client that talks MCP over stdio.

### 2. HTTP (flow-worker)

- **Command:** `php bin/flow-worker.php`
- **Behaviour:** Starts an HTTP server (default: `http://127.0.0.1:3000`) and exposes **POST /mcp** for JSON-RPC. Same MCP handler as stdio; transport is HTTP. The process also runs a React event loop (e.g. for a periodic tick). Port can be overridden with `MCP_PORT`.
- **Use case:** Running the MCP endpoint for [basic-host](https://github.com/modelcontextprotocol/ext-apps/tree/main/examples/basic-host) or other HTTP MCP clients without a separate web server.

### 3. Symfony server

- **Command:** `symfony serve` (with document root pointing at `public/`, e.g. `public` or `public/`).
- **Behaviour:** Serves the app via Symfony CLI; **POST /mcp** is handled by `public/index.php`. Same MCP logic as flow-worker and server.php; no Flow worker loop in this process. Each request is a separate PHP run (classic request/response).
- **Use case:** Local development or deployment where you want a standard web server in front of `public/index.php` instead of the embedded flow-worker.

**Alternative (no Symfony CLI):** `php -S localhost:3000 public/index.php` — PHP built-in server, same MCP endpoint.

### 4. Claude Desktop extension

- **How:** Package the project as a .mcpb (see [Claude Desktop extension (.mcpb)](#claude-desktop-extension-mcpb) below). The manifest runs the server via **stdio** (`php server.php`).
- **Behaviour:** Claude Desktop starts the server as a subprocess and communicates over STDIN/STDOUT. Users see the extension’s tools (e.g. `hello_ui`) and, when the client supports MCP Apps, the embedded UI.
- **Use case:** Using this MCP App directly inside Claude / Claude Desktop without running a separate HTTP server.

### 5. MCP App as tool with embedded UI

- **How:** Use any of the above (stdio or HTTP) with an **MCP Apps–capable host** (e.g. ext-apps basic-host, or Claude when it supports MCP Apps). The host calls the tool and, using `_meta.ui.resourceUri`, fetches the UI resource and renders it in an iframe.
- **Behaviour:** Tool result (text/content) plus interactive View; the View can call back to the server via the host’s MCP client (e.g. `tools/call` forwarded by the host).

---

## Transport and orchestration differences

- **Stdio and HTTP (flow-worker):** A single long-lived process. Async capabilities (e.g. React event loop) can be used; the flow-worker runs a tick loop in the same process as the MCP HTTP handler. For stdio, the same process handles both STDIN reads and any periodic work.
- **Symfony server (or `php -S` with `public/index.php`):** Each HTTP request is a new PHP process (or request). The model is **synchronous** per request; there is no in-process Flow worker. Orchestration of multi-step workflows (e.g. article flows) is intended to be handled by **Flow** when running under flow-worker or by external scheduling when using Symfony server.
- **Claude Desktop (stdio):** One subprocess per session; no HTTP. All MCP traffic is on STDIN/STDOUT.

**Summary:** Use **flow-worker** when you want one process for MCP + optional Flow tick; use **Symfony server** (or PHP built-in) when you want a standard HTTP stack and may rely on external orchestration or separate workers.

---

## High-level architecture

- **MCP host** (e.g. basic-host, Claude Desktop): Connects to the MCP server (stdio or HTTP), lists tools/resources, calls tools, and for MCP Apps fetches UI resources and renders them in an iframe.
- **MCP App UI (View):** HTML/JS loaded from `resources/read` for `ui://` URIs; runs in the host’s iframe; talks to the host via `postMessage` (JSON-RPC), not to the PHP server directly.
- **PHP MCP server:** This project. Handles `initialize`, `tools/list`, `tools/call`, `resources/list`, `resources/read`; serves tool implementations and UI resource contents.
- **Flow:** Optional orchestration layer (used when running under flow-worker) for multi-step workflows.

A detailed sequence diagram and protocol flow are in [docs/php-mcp-apps-mvp-architecture.md](docs/php-mcp-apps-mvp-architecture.md).

---

## Useful run commands

```bash
# Install PHP dependencies
composer install

# --- Stdio (e.g. for manual testing or .mcpb) ---
php server.php

# --- HTTP: single process with MCP endpoint ---
php bin/flow-worker.php
# MCP endpoint: http://127.0.0.1:3000/mcp (or set MCP_PORT)

# --- HTTP: Symfony server (document root = public/) ---
symfony serve
# Then POST /mcp to the URL Symfony prints (e.g. http://127.0.0.1:8000/mcp)

# --- HTTP: PHP built-in server ---
php -S localhost:3000 public/index.php
# MCP endpoint: http://localhost:3000/mcp

# --- Claude Desktop extension: pack .mcpb ---
composer install --no-dev
npm run mcpb:pack
# Install the generated .mcpb in Claude Desktop (Settings → Extensions → Install Extension)
```

**Using basic-host (ext-apps):** Start the MCP server (e.g. `php bin/flow-worker.php`), then from ext-apps: `SERVERS='["http://127.0.0.1:3000/mcp"]' npx tsx serve.ts` in `examples/basic-host`, and open http://localhost:8080.

---

## Claude Desktop extension (.mcpb)

Package the server as an MCPB bundle and install it in Claude Desktop to get tools (e.g. **hello_ui**) with MCP App UI when the client supports it.

### 1. Install MCPB (dev) and pack

```bash
cd /path/to/darkwood-publish-article-mcp-apps
npm install
composer install --no-dev
npm run mcpb:pack
```

This produces a `.mcpb` file (e.g. `darkwood-php-mcp-apps-1.0.0.mcpb`). The repo includes a valid `manifest.json` (server type: binary, entry: `server.php`, command: `php server.php`).

### 2. Install in Claude Desktop

1. Open **Claude Desktop** (macOS or Windows).
2. Go to **Settings → Extensions → Advanced → Install Extension**.
3. Select the `.mcpb` file (or drag and drop).
4. Confirm; the extension appears in your list.

### 3. Use the MCP App

1. Start a conversation; enable the **PHP MCP Apps (Hello UI)** extension.
2. Ask Claude to call **hello_ui** (or another tool). You get the tool result and, if the client supports MCP Apps, the embedded UI.

**Note:** The .mcpb runs the server via **stdio** (`php server.php`). PHP must be on your `PATH` where Claude Desktop runs.

---

## Implemented JSON-RPC methods

| Method | Description |
|--------|-------------|
| `initialize` | Returns `protocolVersion`, `capabilities` (tools, resources, `io.modelcontextprotocol/ui`), `serverInfo`. |
| `tools/list` | Returns tools: `hello_ui`, `GenerateDraft`, `PublishDraft`, `RequestChanges` (with `_meta.ui.resourceUri` for app UIs). |
| `tools/call` | Returns `content` (array of `{ type, text }`); supports hello_ui, GenerateDraft, PublishDraft, RequestChanges. |
| `resources/list` | Returns resources: `ui://darkwood/hello`, `ui://darkwood/article`. |
| `resources/read` | Returns `contents` with `text/html;profile=mcp-app` for the requested URI (hello or article app HTML). |

---

## Project layout

```
darkwood-publish-article-mcp-apps/
├── manifest.json       # MCPB manifest (server.type=binary, php server.php)
├── server.php          # Stdio MCP server (for .mcpb and stdio clients)
├── package.json        # npm scripts: mcpb:init, mcpb:pack
├── composer.json
├── README.md
├── docs/
│   └── php-mcp-apps-mvp-architecture.md
├── bin/
│   └── flow-worker.php # HTTP MCP server + optional Flow tick (single process)
├── public/
│   └── index.php       # HTTP front controller: POST /mcp (Symfony serve or php -S)
├── var/                # Flow SQLite (flow.sqlite), lock (flow.lock)
└── src/
    ├── Mcp/
    │   ├── McpServer.php
    │   ├── JsonRpcHandler.php
    │   └── StdioTransport.php
    ├── Flow/
    │   ├── FlowEngine.php
    │   ├── RunRepository.php
    │   └── Lock.php
    └── ...
```

---

## Current limitations and caveats

- **Stdio:** Only one JSON-RPC request per line; if a line is a batch array, only the first element is handled. **Never** write anything but JSON-RPC response lines to STDOUT; use STDERR for logs.
- **Notifications:** Notifications (e.g. `ui/notifications/initialized`) have no `id` — do not send a response for them.
- **HTTP (this server):** POST /mcp returns a **single** JSON-RPC response body (no Streamable HTTP/SSE). basic-host may still connect; for full Streamable HTTP you would need a different transport.
- **Symfony server:** No Flow worker in the same process; orchestration is synchronous per request or must be handled elsewhere.
- **Claude Desktop:** MCP App UI rendering depends on Claude supporting the MCP Apps extension; tool results always work.
- **Implemented today:** Stdio and HTTP transports; tools and UI resources; .mcpb packaging; basic-host compatibility; Flow integration in flow-worker. **Possible future:** Full batch JSON-RPC, Streamable HTTP transport, deeper editorial workflow docs.

---

## Spec reference

- MCP Apps: `specification/2026-01-26/apps.mdx` in the [ext-apps](https://github.com/modelcontextprotocol/ext-apps) repo.
- Quickstart (concept: tool + UI resource, View in iframe): [MCP Apps Quickstart](https://apps.extensions.modelcontextprotocol.io/api/documents/Quickstart.html).
