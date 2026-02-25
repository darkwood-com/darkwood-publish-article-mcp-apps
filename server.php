<?php

declare(strict_types=1);

/**
 * Minimal PHP MCP server (stdio transport).
 * Reads newline-delimited JSON-RPC from STDIN, writes responses to STDOUT.
 * MCP Apps: hello_ui tool with ui://darkwood/hello resource.
 */

const PROTOCOL_VERSION = '2024-11-05';
const SERVER_NAME = 'PHP MCP Apps (stdio)';
const SERVER_VERSION = '1.0.0';
const UI_RESOURCE_URI = 'ui://darkwood/hello';
const RESOURCE_MIME_TYPE = 'text/html;profile=mcp-app';

$stdin = fopen('php://stdin', 'r');
$stdout = fopen('php://stdout', 'w');
if ($stdin === false || $stdout === false) {
    fwrite(STDERR, "Failed to open stdio streams\n");
    exit(1);
}

while (($line = fgets($stdin)) !== false) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }

    $request = json_decode($line, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        writeResponse($stdout, errorResponse(null, -32700, 'Parse error: ' . json_last_error_msg()));
        continue;
    }

    if (!is_array($request)) {
        writeResponse($stdout, errorResponse(null, -32600, 'Invalid Request: body must be a JSON object'));
        continue;
    }

    // JSON-RPC batch: handle first element only (minimal)
    if (isset($request[0]) && is_array($request[0])) {
        $request = $request[0];
    }

    $id = $request['id'] ?? null;
    $method = $request['method'] ?? null;
    $params = $request['params'] ?? [];

    // Notifications omit "id" — do not send a response
    if (!array_key_exists('id', $request)) {
        continue;
    }
    if ($id === null) {
        writeResponse($stdout, errorResponse(null, -32600, 'Invalid Request: id must be non-null for request'));
        continue;
    }

    if ($method === null || $method === '') {
        writeResponse($stdout, errorResponse($id, -32600, 'Invalid Request: missing method'));
        continue;
    }

    try {
        $result = handleMethod($method, $params);
        if ($result === null) {
            writeResponse($stdout, errorResponse($id, -32601, "Method not found: {$method}"));
            continue;
        }
        writeResponse($stdout, [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ]);
    } catch (Throwable $e) {
        writeResponse($stdout, errorResponse($id, -32603, 'Internal error: ' . $e->getMessage()));
    }
}

function writeResponse($stream, array $response): void
{
    $json = json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return;
    }
    fwrite($stream, $json . "\n");
}

function errorResponse($id, int $code, string $message): array
{
    return [
        'jsonrpc' => '2.0',
        'id' => $id,
        'error' => [
            'code' => $code,
            'message' => $message,
        ],
    ];
}

function handleMethod(string $method, array $params): ?array
{
    return match ($method) {
        'initialize' => initialize($params),
        'tools/list' => toolsList(),
        'tools/call' => toolsCall($params),
        'resources/list' => resourcesList(),
        'resources/read' => resourcesRead($params),
        default => null,
    };
}

function initialize(array $params): array
{
    return [
        'protocolVersion' => PROTOCOL_VERSION,
        'capabilities' => [
            'tools' => (object)[],
            'resources' => (object)[],
            'extensions' => [
                'io.modelcontextprotocol/ui' => [
                    'mimeTypes' => [RESOURCE_MIME_TYPE],
                ],
            ],
        ],
        'serverInfo' => [
            'name' => SERVER_NAME,
            'version' => SERVER_VERSION,
        ],
    ];
}

function toolsList(): array
{
    return [
        'tools' => [
            [
                'name' => 'hello_ui',
                'description' => 'Minimal hello tool with MCP App UI',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => (object)[],
                ],
                '_meta' => [
                    'ui' => [
                        'resourceUri' => UI_RESOURCE_URI,
                    ],
                ],
            ],
        ],
    ];
}

function toolsCall(array $params): array
{
    $name = $params['name'] ?? '';
    if ($name !== 'hello_ui') {
        throw new InvalidArgumentException("Unknown tool: {$name}");
    }
    return [
        'content' => [
            ['type' => 'text', 'text' => 'Hello from PHP MCP Server'],
        ],
        'structuredContent' => (object)[],
    ];
}

function resourcesList(): array
{
    return [
        'resources' => [
            [
                'uri' => UI_RESOURCE_URI,
                'name' => 'hello',
                'description' => 'Minimal MCP App UI for hello_ui',
                'mimeType' => RESOURCE_MIME_TYPE,
            ],
        ],
    ];
}

function resourcesRead(array $params): array
{
    $uri = $params['uri'] ?? '';
    if ($uri !== UI_RESOURCE_URI) {
        throw new InvalidArgumentException("Unknown resource: {$uri}");
    }
    $html = getHelloHtml();
    return [
        'contents' => [
            [
                'uri' => UI_RESOURCE_URI,
                'mimeType' => RESOURCE_MIME_TYPE,
                'text' => $html,
                '_meta' => [
                    'ui' => [
                        'prefersBorder' => true,
                    ],
                ],
            ],
        ],
    ];
}

function getHelloHtml(): string
{
    return <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Hello MCP App</title>
  <style>
    body { font-family: system-ui, sans-serif; padding: 1rem; margin: 0; }
    h1 { font-size: 1.25rem; margin: 0 0 0.5rem 0; }
    #toolResult { white-space: pre-wrap; margin: 0.5rem 0 0; padding: 0.5rem; background: #f5f5f5; border-radius: 4px; font-size: 0.875rem; }
  </style>
</head>
<body>
  <h1>Hello MCP App</h1>
  <pre id="toolResult">(waiting for tool result…)</pre>
  <script>
(function () {
  var PROTOCOL_VERSION = '2026-01-26';
  var pre = document.getElementById('toolResult');
  var target = window.parent;
  var initId = 1;
  var pending = {};

  function send(msg) {
    target.postMessage(msg, '*');
  }

  function onMessage(ev) {
    if (ev.source !== target) return;
    var data = ev.data;
    if (!data || data.jsonrpc !== '2.0') return;
    if (data.id != null && pending[data.id]) {
      pending[data.id](data.error ? new Error(data.error.message || 'Request failed') : null, data.result);
      delete pending[data.id];
    }
    if (data.method === 'ui/notifications/tool-result' && data.params) {
      var p = data.params;
      if (p.content && Array.isArray(p.content)) {
        var text = p.content.map(function (b) { return b.type === 'text' ? b.text : ''; }).filter(Boolean).join('\n');
        pre.textContent = text || '(empty)';
      } else {
        pre.textContent = JSON.stringify(p, null, 2);
      }
    }
  }

  window.addEventListener('message', onMessage);

  pending[initId] = function (err, result) {
    if (err) {
      pre.textContent = 'Initialize failed: ' + (err.message || String(err));
      return;
    }
    send({ jsonrpc: '2.0', method: 'ui/notifications/initialized' });
  };

  send({
    jsonrpc: '2.0',
    id: initId,
    method: 'ui/initialize',
    params: {
      appInfo: { name: 'Hello MCP App', version: '1.0.0' },
      appCapabilities: {},
      protocolVersion: PROTOCOL_VERSION
    }
  });
})();
  </script>
</body>
</html>
HTML;
}
