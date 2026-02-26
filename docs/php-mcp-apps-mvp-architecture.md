# PHP MCP Server + MCP Apps — Minimal MVP Architecture

This document describes the runtime flow for a **PHP MCP Server** that supports the MCP Apps extension (2026-01-26), with a host such as **basic-host** (from ext-apps) rendering a UI resource (`ui://darkwood/hello`) in a sandboxed iframe. Communication is JSON-RPC: UI ↔ host over `postMessage`, host ↔ server over HTTP (MCP).

---

## 1. Architecture diagram

```mermaid
sequenceDiagram
  participant UI as MCP App UI (iframe)
  participant Host as MCP Host (basic-host)
  participant Server as PHP MCP Server

  Note over Host,Server: Connection & discovery
  Host->>+Server: initialize (MCP, extensions: io.modelcontextprotocol/ui)
  Server-->>-Host: capabilities, serverInfo
  Host->>+Server: tools/list
  Server-->>-Host: tools (with _meta.ui.resourceUri where applicable)
  Host->>+Server: resources/list
  Server-->>-Host: resources (incl. ui://...)

  Note over Host,Server: Tool call with UI
  Host->>+Server: tools/call(name, arguments)
  Host->>+Server: resources/read(uri: ui://darkwood/hello)
  Server-->>-Host: contents[].text (HTML), _meta.ui (CSP, etc.)
  Server-->>-Host: tools/call result (content, structuredContent)

  Note over UI,Host: Load UI & postMessage (JSON-RPC)
  Host->>Host: Create iframe, load sandbox → inject HTML
  UI->>+Host: ui/initialize (appCapabilities, clientInfo)
  Host-->>-UI: McpUiInitializeResult (hostContext, hostCapabilities)
  UI->>Host: ui/notifications/initialized
  Host->>UI: ui/notifications/tool-input (arguments)
  Host->>UI: ui/notifications/tool-result (when tool completes)

  Note over UI,Server: UI triggers tool call back to server
  UI->>+Host: tools/call(name, arguments) [postMessage]
  Host->>+Server: tools/call(name, arguments)
  Server-->>-Host: CallToolResult
  Host-->>-UI: tools/call response (result/error)
```

---

## 2. Explanation

### When a tool with `_meta.ui.resourceUri` is called

- The host (e.g. basic-host) discovers UI-enabled tools from **tools/list**: each tool may have `_meta.ui.resourceUri` set to a `ui://` URI (e.g. `ui://darkwood/hello`).
- When the user or agent invokes that tool, the host:
  1. Sends **tools/call** to the PHP server with the tool name and arguments.
  2. In parallel (or immediately after), uses the tool’s `_meta.ui.resourceUri` to fetch the UI resource.

So “what happens” is: the host runs the tool on the server **and** loads the declared UI so it can show an interactive view tied to that tool call.

### How the host loads the UI via resources/read

- The host calls **resources/read** with the UI resource URI (e.g. `ui://darkwood/hello`).
- The PHP server responds with a **resources/read** result whose `contents[0]` has:
  - **uri**: same `ui://` URI
  - **mimeType**: `text/html;profile=mcp-app`
  - **text** or **blob**: the HTML document for the app
  - **_meta.ui** (optional): CSP (`connectDomains`, `resourceDomains`, etc.), `permissions`, `prefersBorder`, etc.
- The host then:
  - Renders that HTML in a sandboxed iframe (for web hosts, often via a sandbox proxy iframe that receives the raw HTML and injects it into an inner iframe with the right CSP).
  - Communicates with the UI over **postMessage** using JSON-RPC 2.0 (MCP Apps protocol).

So the UI is **not** loaded by navigating the iframe to an HTTP URL on the PHP server; it is loaded by the host after fetching the document via **resources/read** and injecting it into the sandbox.

### How the UI communicates with the host

- All UI ↔ host communication is **JSON-RPC 2.0 over postMessage**: the UI sends requests/notifications to `window.parent`, and the host sends responses/notifications back into the iframe.
- Lifecycle:
  - UI sends **ui/initialize**; host responds with **McpUiInitializeResult** (protocol version, host capabilities, host context: theme, dimensions, styles, etc.).
  - UI sends **ui/notifications/initialized** when ready.
  - Host sends **ui/notifications/tool-input** with the tool arguments (at most once, after init).
  - When the original **tools/call** completes, host sends **ui/notifications/tool-result** (or **ui/notifications/tool-cancelled**).
- The UI can also send **notifications/message**, **ui/message**, **ui/update-model-context**, **ui/open-link**, **ui/request-display-mode**, etc., as defined in the 2026-01-26 spec.

So the UI talks to the host only via this JSON-RPC/postMessage channel; it never talks to the PHP server directly.

### How the UI can trigger tool calls back to the MCP server

- The UI sends a **tools/call** **request** over the same postMessage channel (same as other MCP-style requests).
- The host (e.g. basic-host) uses **AppBridge** to handle these requests: it forwards **tools/call** to the MCP client that is connected to the PHP server, i.e. the host performs **tools/call** against the PHP server and returns the **CallToolResult** (or error) back to the UI as the JSON-RPC response.
- The server may expose tools with **visibility: ["app"]** (or **["model", "app"]**); only tools that include `"app"` in visibility are callable from the UI. So the PHP server controls which tools the UI is allowed to invoke.

End-to-end: **UI → (postMessage) → Host → (HTTP MCP) → PHP Server → response back along the same path.**

**Tool result shape:** The host returns the server’s `CallToolResult` to the UI. The generated text is in **`result.content[0].text`** (single text item; `content` is an array of `{ type, text }`).

---

## 3. References

- **MCP Apps spec:** `specification/2026-01-26/apps.mdx` in the [ext-apps](https://github.com/modelcontextprotocol/ext-apps) repo (extension `io.modelcontextprotocol/ui`).
- **basic-host:** reference host in `examples/basic-host` (loads UI via **resources/read**, sandbox proxy, AppBridge, and forwards **tools/call** from the UI to the MCP server).
- **Lifecycle:** Connection & discovery → UI initialization (including sandbox and **ui/initialize**) → tool-input / tool-result → interactive phase (UI-originated **tools/call**, **resources/read**, etc.) → **ui/resource-teardown** on cleanup.

This minimal MVP flow is enough to implement a PHP MCP server that serves UI resources (`ui://darkwood/hello`, `ui://darkwood/article`) and works with basic-host end-to-end. The article UI calls `GenerateDraft` via `tools/call` and displays `result.content[0].text` in the output area.
