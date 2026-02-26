<?php

declare(strict_types=1);

namespace Darkwood\Mcp;

/**
 * Minimal MCP server logic: initialize, tools/list, tools/call, resources/list, resources/read.
 * MCP Apps extension (2026-01-26): ui:// resource and tool _meta.ui.resourceUri.
 */
final class McpServer
{
    private const PROTOCOL_VERSION = '2024-11-05';
    private const SERVER_NAME = 'PHP MCP Apps MVP';
    private const SERVER_VERSION = '1.0.0';
    private const UI_RESOURCE_URI_HELLO = 'ui://darkwood/hello';
    private const UI_RESOURCE_URI_ARTICLE = 'ui://darkwood/article';
    private const RESOURCE_MIME_TYPE = 'text/html;profile=mcp-app';

    public function handleRequest(array $request): array
    {
        $method = $request['method'] ?? null;
        $params = $request['params'] ?? [];
        $id = $request['id'] ?? null;

        if (!$method) {
            return $this->error(-32600, 'Invalid Request: missing method', $id);
        }

        $this->log($method, $params);

        $result = match ($method) {
            'initialize' => $this->initialize($params),
            'tools/list' => $this->toolsList(),
            'tools/call' => $this->toolsCall($params),
            'resources/list' => $this->resourcesList(),
            'resources/read' => $this->resourcesRead($params),
            default => null,
        };

        if ($result === null) {
            return $this->error(-32601, "Method not found: {$method}", $id);
        }

        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ];
    }

    private function initialize(array $params): array
    {
        return [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => [
                'tools' => (object)[],
                'resources' => (object)[],
                'extensions' => [
                    'io.modelcontextprotocol/ui' => [
                        'mimeTypes' => [self::RESOURCE_MIME_TYPE],
                    ],
                ],
            ],
            'serverInfo' => [
                'name' => self::SERVER_NAME,
                'version' => self::SERVER_VERSION,
            ],
        ];
    }

    private function toolsList(): array
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
                            'resourceUri' => 'ui://darkwood/hello',
                        ],
                    ],
                ],
                [
                    'name' => 'GenerateDraft',
                    'description' => 'Generate an article draft from a topic',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'topic' => ['type' => 'string', 'description' => 'Topic for the draft'],
                        ],
                        'required' => ['topic'],
                    ],
                    '_meta' => [
                        'ui' => [
                            'resourceUri' => 'ui://darkwood/article',
                        ],
                    ],
                ],
            ],
        ];
    }

    private function toolsCall(array $params): array
    {
        $name = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];

        if ($name === 'hello_ui') {
            $text = 'Hello from PHP MCP Server UI Display';
            return [
                'content' => [
                    ['type' => 'text', 'text' => $text],
                ],
                'structuredContent' => (object)[],
            ];
        }

        if ($name === 'GenerateDraft') {
            $topic = $arguments['topic'] ?? '';
            $topic = is_string($topic) ? trim($topic) : '';
            $draft = $this->generateDraft($topic);
            return [
                'content' => [
                    ['type' => 'text', 'text' => $draft],
                ],
                'structuredContent' => (object)[],
            ];
        }

        throw new \InvalidArgumentException("Unknown tool: {$name}");
    }

    /** Dummy draft generator; returns template text based on topic. */
    private function generateDraft(string $topic): string
    {
        if ($topic === '') {
            return '(No topic provided. Enter a topic and click Generate draft.)';
        }
        return "Draft for topic: \"{$topic}\"\n\n"
            . "This is a placeholder draft. Replace with real generation later.\n\n"
            . "— PHP MCP Server";
    }

    private function resourcesList(): array
    {
        return [
            'resources' => [
                [
                    'uri' => 'ui://darkwood/hello',
                    'name' => 'hello',
                    'description' => 'Minimal MCP App UI for hello_ui',
                    'mimeType' => 'text/html;profile=mcp-app',
                ],
                [
                    'uri' => 'ui://darkwood/article',
                    'name' => 'article',
                    'description' => 'Article draft UI: enter topic and call GenerateDraft',
                    'mimeType' => 'text/html;profile=mcp-app',
                ],
            ],
        ];
    }

    private function resourcesRead(array $params): array
    {
        $uri = $params['uri'] ?? '';

        if ($uri === self::UI_RESOURCE_URI_HELLO) {
            $html = $this->getHelloHtml();
            return [
                'contents' => [
                    [
                        'uri' => self::UI_RESOURCE_URI_HELLO,
                        'mimeType' => self::RESOURCE_MIME_TYPE,
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

        if ($uri === self::UI_RESOURCE_URI_ARTICLE) {
            $html = $this->getArticleHtml();
            return [
                'contents' => [
                    [
                        'uri' => self::UI_RESOURCE_URI_ARTICLE,
                        'mimeType' => self::RESOURCE_MIME_TYPE,
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

        throw new \InvalidArgumentException("Unknown resource: {$uri}");
    }

    private function getHelloHtml(): string
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

    private function getArticleHtml(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Article Draft</title>
  <style>
    body { font-family: system-ui, sans-serif; padding: 1rem; margin: 0; }
    h1 { font-size: 1.25rem; margin: 0 0 0.5rem 0; }
    #topic { width: 100%; min-height: 4rem; padding: 0.5rem; box-sizing: border-box; margin: 0.5rem 0; }
    button { padding: 0.5rem 1rem; cursor: pointer; }
    #output { white-space: pre-wrap; margin: 0.5rem 0 0; padding: 0.5rem; background: #f5f5f5; border-radius: 4px; font-size: 0.875rem; min-height: 2rem; }
  </style>
</head>
<body>
  <h1>Article Draft</h1>
  <textarea id="topic" placeholder="Enter topic…"></textarea>
  <br>
  <button type="button" id="generateBtn">Generate draft</button>
  <pre id="output"></pre>
  <script>
(function () {
  var PROTOCOL_VERSION = '2026-01-26';
  var topicEl = document.getElementById('topic');
  var outputEl = document.getElementById('output');
  var btnEl = document.getElementById('generateBtn');
  var target = window.parent;
  var initId = 1;
  var callId = 2;
  var pending = {};

  function send(msg) {
    target.postMessage(msg, '*');
  }

  function onMessage(ev) {
    if (ev.source !== target) return;
    var data = ev.data;
    if (!data || data.jsonrpc !== '2.0') return;
    if (data.id != null && pending[data.id]) {
      pending[data.id](data.error ? new Error(data.error.message || 'Request failed') : null, data.result, data.error);
      delete pending[data.id];
    }
  }

  window.addEventListener('message', onMessage);

  pending[initId] = function (err, result, rpcError) {
    if (err || rpcError) {
      outputEl.textContent = 'Initialize failed: ' + (err ? err.message : (rpcError && rpcError.message) || String(rpcError));
      return;
    }
    send({ jsonrpc: '2.0', method: 'ui/notifications/initialized' });
  };

  send({
    jsonrpc: '2.0',
    id: initId,
    method: 'ui/initialize',
    params: {
      appInfo: { name: 'Article Draft', version: '1.0.0' },
      appCapabilities: {},
      protocolVersion: PROTOCOL_VERSION
    }
  });

  btnEl.addEventListener('click', function () {
    var topic = (topicEl.value || '').trim();
    var id = callId++;
    outputEl.textContent = 'Generating…';
    pending[id] = function (err, result, rpcError) {
      if (err || rpcError) {
        outputEl.textContent = 'Error: ' + (rpcError && rpcError.message ? rpcError.message : (err ? err.message : JSON.stringify(rpcError || err)));
        return;
      }
      var text = '';
      // Tool result shape: result.content[0].text (single text item)
      if (result && result.content && Array.isArray(result.content) && result.content[0]) {
        text = result.content[0].type === 'text' ? result.content[0].text : JSON.stringify(result.content[0]);
      }
      outputEl.textContent = text || '(empty result)';
    };
    send({
      jsonrpc: '2.0',
      id: id,
      method: 'tools/call',
      params: {
        name: 'GenerateDraft',
        arguments: { topic: topic }
      }
    });
  });
})();
  </script>
</body>
</html>
HTML;
    }

    private function log(string $method, array $params): void
    {
        $msg = '[MCP] ' . $method;
        if ($method === 'resources/read' && isset($params['uri'])) {
            $msg .= ' uri=' . $params['uri'];
        }
        file_put_contents('php://stderr', $msg . "\n", FILE_APPEND);
    }

    private function error(int $code, string $message, $id): array
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
}
