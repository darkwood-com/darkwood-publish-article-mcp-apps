# PHP MCP Server — MCP Apps MVP

Minimal PHP MCP server that supports the **MCP Apps** extension (2026-01-26). Works with [ext-apps](https://github.com/modelcontextprotocol/ext-apps) **basic-host** to display a simple UI in an iframe.

## Requirements

- PHP 8.1+
- Composer (for autoload only; no external dependencies)

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
├── composer.json
├── README.md
├── public/
│   └── router.php      # Routes /mcp to MCP handler
└── src/
    ├── McpServer.php       # MCP logic (initialize, tools, resources)
    └── JsonRpcHandler.php  # JSON-RPC parse + dispatch
```

## Spec reference

- MCP Apps: `specification/2026-01-26/apps.mdx` in the ext-apps repo.
- basic-host expects Streamable HTTP or SSE; this server responds to **POST /mcp** with a single JSON-RPC response body.
