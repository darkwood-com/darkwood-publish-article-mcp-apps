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

```bash
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

---

## HTTP mode (basic-host)

## Setup

```bash
cd /path/to/darkwood-publish-article-mcp-apps
composer install
```

## Run the MCP server

Start the server on **http://localhost:3000** with the **router** so that `/mcp` is handled:

```bash
php -S localhost:3000 public/router.php
```

You should see:

```
PHP 8.x Development Server (http://localhost:3000) started
```

The MCP endpoint is: **http://localhost:3000/mcp**

> **Important:** Use `public/router.php` as the router script. If you run `php -S localhost:3000 -t public` without a router, requests to `/mcp` will not reach the handler.

## Point basic-host to this server

1. **Clone and build ext-apps** (if not already):

   ```bash
   git clone https://github.com/modelcontextprotocol/ext-apps.git /path/to/ext-apps
   cd /path/to/ext-apps
   npm install
   ```

2. **Configure basic-host** to use the PHP server. Default is `http://localhost:3000/mcp`. Either leave it or set:

   ```bash
   export SERVERS='["http://localhost:3000/mcp"]'
   ```

3. **Start the examples server** (host on 8080, sandbox on 8081):

   ```bash
   cd /path/to/ext-apps
   npm run examples:start
   ```

   Or run only basic-host (after building it):

   ```bash
   cd /path/to/ext-apps/examples/basic-host
   npm run build
   SERVERS='["http://localhost:3000/mcp"]' npx tsx serve.ts
   ```

4. **Open the host** in a browser: **http://localhost:8080**

5. **Test the PHP server:**
   - Select the server that shows **PHP MCP Apps MVP** (or the one pointing to `http://localhost:3000/mcp`).
   - Pick the **hello_ui** tool and run it (default args `{}`).
   - You should see the tool result and the minimal **Hello MCP App** UI in an iframe.

## Implemented JSON-RPC methods

| Method             | Description |
|--------------------|-------------|
| `initialize`       | Returns `protocolVersion`, `capabilities` (tools, resources, `io.modelcontextprotocol/ui`), `serverInfo`. |
| `tools/list`       | Returns one tool: `hello_ui` with `_meta.ui.resourceUri = "ui://darkwood/hello"`. |
| `tools/call`       | For `name === "hello_ui"` returns text: "Hello from PHP MCP Server". |
| `resources/list`   | Returns one resource: `ui://darkwood/hello`. |
| `resources/read`   | For `uri === "ui://darkwood/hello"` returns HTML with mimeType `text/html;profile=mcp-app`. |

## Project layout

```
darkwood-publish-article-mcp-apps/
├── manifest.json       # MCPB manifest (server.type=binary, php server.php)
├── server.php          # Stdio MCP server (for .mcpb)
├── package.json        # npm scripts: mcpb:init, mcpb:pack
├── composer.json
├── README.md
├── public/
│   └── router.php      # Routes /mcp to MCP handler (HTTP mode)
└── src/
    ├── McpServer.php       # MCP logic (HTTP mode)
    └── JsonRpcHandler.php  # JSON-RPC parse + dispatch (HTTP mode)
```

## Spec reference

- MCP Apps: `specification/2026-01-26/apps.mdx` in the ext-apps repo.
- basic-host expects Streamable HTTP or SSE; this server responds to **POST /mcp** with a single JSON-RPC response body.

## Pitfalls (stdio / JSON-RPC)

- **Stdio framing:** MCP stdio transport uses **newline-delimited JSON**: one JSON-RPC message per line. No `Content-Length` header; read until `\n`, decode, respond with one line.
- **JSON-RPC `id`:** Requests must include `id`; responses must echo the same `id`. **Notifications** (e.g. `notifications/initialized`) have no `id` — do not send any response for them, or the client can get out of sync.
- **Batch:** This server handles only single requests (or the first element of a batch). Full batch handling would require responding with an array of responses.
- **PHP CLI:** When running as a subprocess, ensure PHP outputs only the JSON-RPC lines on stdout; use `stderr` for logs so the host does not mix them with messages.
