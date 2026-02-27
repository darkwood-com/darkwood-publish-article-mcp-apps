# PHP MCP Server — MCP Apps MVP

Minimal PHP MCP server that supports the **MCP Apps** extension (2026-01-26). Two ways to run:

1. **Claude Desktop extension (.mcpb)** — stdio transport; install the bundle and call `hello_ui` to see the UI in Claude.
2. **HTTP + basic-host** — run the built-in PHP server and use [ext-apps](https://github.com/modelcontextprotocol/ext-apps) basic-host in a browser.

## Requirements

- PHP 8.1+
- For HTTP mode: Composer (autoload only)
- For .mcpb: Node.js (for `npx mcpb pack` only)
---

## Claude Desktop extension (.mcpb)

Package the server as an MCPB bundle and install it in Claude Desktop to get the **hello_ui** tool with MCP App UI rendering.

### 1. Install MCPB locally (no global install)

```bash
cd /path/to/darkwood-publish-article-mcp-apps
npm install
```

This installs `@anthropic-ai/mcpb` as a dev dependency.

### 2. Create or update manifest (optional)

```bash
npm run mcpb:init
```

Use this to generate or adjust `manifest.json`. The repo already includes a valid `manifest.json` for the PHP stdio server.

### 3. Pack the extension

Install PHP dependencies so `vendor/` and `src/` are included in the bundle (required for the server to run when installed):

```bash
composer install --no-dev
npm run mcpb:pack
```

Or with npx directly:

```bash
npx mcpb pack
```

This produces a `.mcpb` file in the project directory (e.g. `darkwood-php-mcp-apps-1.0.0.mcpb`).

### 4. Install in Claude Desktop

1. Open **Claude Desktop** (macOS or Windows).
2. Go to **Settings → Extensions → Advanced → Install Extension**.
3. Select the `.mcpb` file you created (or drag and drop it).
4. Confirm installation; the extension will appear in your list.

### 5. Test MCP App UI

1. Start a conversation with Claude.
2. Ensure the **PHP MCP Apps (Hello UI)** extension is enabled.
3. Ask Claude to call the **hello_ui** tool (e.g. “Call the hello_ui tool”).
4. You should see the tool result and a small **Hello MCP App from PHP** UI rendered in the conversation (if the client supports MCP Apps).

**Note:** The .mcpb runs the server via **stdio** (`php server.php`). PHP must be installed and on your `PATH` where Claude Desktop runs.

### Stdio transport (how it works)

`server.php` uses **line-delimited JSON-RPC** on STDIN/STDOUT:

- **STDIN:** One JSON-RPC request per line (single object or batch array; batch MVP: first element only).
- **STDOUT:** One JSON-RPC response per line. **Nothing else** must be written to STDOUT (or the host will see invalid messages).
- **STDERR:** Logs and diagnostics only. Safe for debugging.

The same process runs the Flow engine (React event loop) and the MCP handler: when STDIN is readable, requests are read, dispatched via `McpServer`, and responses written to STDOUT; the Flow tick runs periodically in the same loop. Suitable for Claude Desktop extension packaging (e.g. `npx mcpb pack`); stdio mode uses no HTTP.

### Manual test (stdio)

Send one JSON-RPC request and capture the response (server runs until you kill it or close STDIN):

```bash
# In one terminal, start the server (it will wait for input):
php server.php

# In another, send a request (e.g. with netcat or a pipe). Example:
echo '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}' | php server.php 2>/dev/null &
sleep 1
kill %1 2>/dev/null
# You should see one line on stdout: the initialize result.
```

Or run and type one line then Ctrl+D:

```bash
php server.php
# Type: {"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}
# Press Enter. Response appears on the next line. Ctrl+C to stop.
```

### Pitfalls (stdio)

- **Never print to STDOUT** except the exact JSON-RPC response lines (one JSON object per line, no extra whitespace or debug output). Any extra byte breaks the protocol.
- **Log only to STDERR** (`fwrite(STDERR, ...)` or `file_put_contents('php://stderr', ...)`).
- **Notifications** (e.g. `ui/notifications/initialized`) have no `id` — do not send a response.
- **Batch:** This server handles a single request per line; if the line is a batch array, only the first element is handled and one response is written.

---

## HTTP mode (basic-host)

### Setup

```bash
cd /path/to/darkwood-publish-article-mcp-apps
composer install
```

### Run the MCP server (single process: HTTP + Flow worker)

Start the embedded HTTP server and Flow worker in one process (no `php -S`, no separate process manager):

```bash
php bin/flow-worker.php
```

You should see on STDERR:

```
MCP endpoint: http://127.0.0.1:3000/mcp
Flow worker ticking every 100ms. Press Ctrl+C to stop.
```

- **MCP endpoint:** **http://127.0.0.1:3000/mcp** (POST JSON-RPC)
- **Demo flow:** `GET http://127.0.0.1:3000/flow/start?flow=hello_flow` enqueues a run; watch STDERR for `[Flow] tick` stats.

### Run basic-host (ext-apps)

1. **Clone and build ext-apps** (if not already):

   ```bash
   git clone https://github.com/modelcontextprotocol/ext-apps.git /path/to/ext-apps
   cd /path/to/ext-apps
   npm install
   ```

2. **Start the flow-worker** (in one terminal):

   ```bash
   cd /path/to/darkwood-publish-article-mcp-apps
   php bin/flow-worker.php
   ```

3. **Start basic-host** with the PHP server URL:

   ```bash
   cd /path/to/ext-apps/examples/basic-host
   npm run build
   SERVERS='["http://127.0.0.1:3000/mcp"]' npx tsx serve.ts
   ```

4. **Open** http://localhost:8080 in a browser.

5. **Test:** Select the server (e.g. **PHP MCP Apps MVP**), run the **hello_ui** tool with default args. You should see the tool result (text) and the **Hello MCP App** UI rendered in an iframe.

**Alternative (legacy):** To use the PHP built-in web server instead of the flow-worker:

```bash
php -S localhost:3000 public/router.php
```

Then use `SERVERS='["http://localhost:3000/mcp"]'` when starting basic-host.

## Implemented JSON-RPC methods

| Method             | Description |
|--------------------|-------------|
| `initialize`       | Returns `protocolVersion`, `capabilities` (tools, resources, `io.modelcontextprotocol/ui`), `serverInfo`. |
| `tools/list`       | Returns tools: `hello_ui`, `GenerateDraft`, `PublishDraft`, `RequestChanges` (with `_meta.ui.resourceUri` for app UIs). |
| `tools/call`       | Returns `content` (array of `{ type, text }`); supports hello_ui, GenerateDraft, PublishDraft, RequestChanges. |
| `resources/list`   | Returns resources: `ui://darkwood/hello`, `ui://darkwood/article`. |
| `resources/read`   | Returns `contents` with `text/html;profile=mcp-app` for the requested URI (hello or article app HTML). |

## Project layout

```
darkwood-publish-article-mcp-apps/
├── manifest.json       # MCPB manifest (server.type=binary, php server.php)
├── server.php          # Stdio MCP server (for .mcpb)
├── package.json        # npm scripts: mcpb:init, mcpb:pack
├── composer.json
├── README.md
├── bin/
│   └── flow-worker.php # Entrypoint: embedded HTTP server + Flow tick loop
├── public/
│   └── router.php      # Legacy: routes /mcp for php -S
├── var/                # Flow SQLite (flow.sqlite), lock (flow.lock)
└── src/
    ├── McpServer.php       # MCP logic (initialize, tools/list, tools/call, resources/list, resources/read)
    ├── JsonRpcHandler.php  # JSON-RPC parse + dispatch (handle for HTTP, handleParsedRequest for stdio)
    ├── StdioTransport.php  # Stdio JSON-RPC: read line-delimited from STDIN, write to STDOUT, log to STDERR
    └── Flow/
        ├── FlowEngine.php   # startRun, tick (minimal orchestration)
        ├── RunRepository.php
        └── Lock.php
```

## Spec reference

- MCP Apps: `specification/2026-01-26/apps.mdx` in the ext-apps repo.
- basic-host expects Streamable HTTP or SSE; this server responds to **POST /mcp** with a single JSON-RPC response body.

## Pitfalls (stdio / JSON-RPC / basic-host)

- **Stdio framing:** MCP stdio transport uses **newline-delimited JSON**: one JSON-RPC message per line. No `Content-Length` header; read until `\n`, decode, respond with one line.
- **JSON-RPC `id`:** Requests must include `id`; responses must echo the same `id`. **Notifications** (e.g. `notifications/initialized`) have no `id` — do not send any response for them, or the client can get out of sync.
- **Batch:** This server handles only single requests (or the first element of a batch). Full batch handling would require responding with an array of responses.
- **PHP CLI:** When running as a subprocess, ensure PHP outputs only the JSON-RPC lines on stdout; use `stderr` for logs so the host does not mix them with messages.
- **basic-host:** CORS is not required when the host is served from the same machine; the flow-worker listens on `127.0.0.1:3000`. Tool results use **content** (array of `{ type, text }`); **resources/read** uses **contents** (array of `{ uri, mimeType, text }`). Keep these shapes compatible with ext-apps/examples/basic-host.
