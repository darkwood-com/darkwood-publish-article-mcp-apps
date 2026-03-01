<?php

declare(strict_types=1);

namespace App\Mcp;

/**
 * Minimal MCP server logic: initialize, tools/list, tools/call, resources/list, resources/read.
 * MCP Apps extension (2026-01-26): ui:// resource and tool _meta.ui.resourceUri.
 */
final class McpServer
{
    private const PROTOCOL_VERSION = '2024-11-05';
    private const SERVER_NAME = 'PHP MCP Apps MVP';

    /** @var null|callable(string): string */
    private $generateDraftRunner = null;

    /** @var null|callable(string, string, string, string): \App\Model\PublishDraftPayload */
    private $publishDraftRunner = null;

    /** @param callable(string): string $runner */
    public function setGenerateDraftRunner(callable $runner): void
    {
        $this->generateDraftRunner = $runner;
    }

    /** @param callable(string, string, string, string): \App\Model\PublishDraftPayload $runner */
    public function setPublishDraftRunner(callable $runner): void
    {
        $this->publishDraftRunner = $runner;
    }
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
                [
                    'name' => 'AcceptDraft',
                    'description' => 'Accept the draft (topic + draft + optional notes)',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'topic' => ['type' => 'string', 'description' => 'Topic of the article'],
                            'draft' => ['type' => 'string', 'description' => 'Draft text to accept'],
                            'notes' => ['type' => 'string', 'description' => 'Optional feedback or approval notes'],
                        ],
                        'required' => ['topic', 'draft'],
                    ],
                    '_meta' => [
                        'ui' => [
                            'resourceUri' => 'ui://darkwood/article',
                        ],
                    ],
                ],
                [
                    'name' => 'RequestChanges',
                    'description' => 'Request changes to the draft (placeholder for correction loop)',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'topic' => ['type' => 'string', 'description' => 'Topic of the article'],
                            'draft' => ['type' => 'string', 'description' => 'Current draft text'],
                            'notes' => ['type' => 'string', 'description' => 'Optional feedback or correction notes'],
                        ],
                        'required' => ['topic', 'draft'],
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
            $draft = $this->generateDraftRunner !== null
                ? ($this->generateDraftRunner)($topic)
                : $this->generateDraft($topic);
            return [
                'content' => [
                    ['type' => 'text', 'text' => $draft],
                ],
                'structuredContent' => (object)[],
            ];
        }

        if ($name === 'AcceptDraft' || $name === 'RequestChanges') {
            $topic = $arguments['topic'] ?? '';
            $draft = $arguments['draft'] ?? '';
            $notes = $arguments['notes'] ?? '';
            $topic = is_string($topic) ? trim($topic) : '';
            $draft = is_string($draft) ? $draft : '';
            $notes = is_string($notes) ? $notes : '';
            $action = $name === 'AcceptDraft'
                ? \App\Model\PublishDraftPayload::ACTION_ACCEPT
                : \App\Model\PublishDraftPayload::ACTION_CORRECT;

            if ($this->publishDraftRunner === null) {
                throw new \RuntimeException('AcceptDraft runner not configured');
            }
            $payload = ($this->publishDraftRunner)($topic, $draft, $notes, $action);

            $text = $payload->getMessageText() ?? '';
            $structuredContent = (object)[
                'done' => $payload->isDone(),
            ];
            if ($payload->getPublishedUrl() !== null) {
                $structuredContent->publishedUrl = $payload->getPublishedUrl();
            }
            if ($payload->getDraftText() !== null && $action === \App\Model\PublishDraftPayload::ACTION_CORRECT) {
                $structuredContent->draftText = $payload->getDraftText();
            }

            return [
                'content' => [
                    ['type' => 'text', 'text' => $text],
                ],
                'structuredContent' => $structuredContent,
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
    body { font-family: system-ui, sans-serif; padding: 1rem; margin: 0; max-width: 48rem; }
    h1 { font-size: 1.25rem; margin: 0 0 0.5rem 0; }
    label { display: block; font-weight: 600; margin: 0.75rem 0 0.25rem 0; font-size: 0.875rem; }
    textarea { width: 100%; min-height: 3rem; padding: 0.5rem; box-sizing: border-box; margin: 0; font-size: 0.875rem; }
    #draft { min-height: 8rem; }
    #notes { min-height: 4rem; }
    .buttons { margin: 0.75rem 0; display: flex; gap: 0.5rem; flex-wrap: wrap; }
    button { padding: 0.5rem 1rem; cursor: pointer; font-size: 0.875rem; }
    button:disabled { cursor: not-allowed; opacity: 0.7; }
    #error { color: #c00; background: #fee; padding: 0.5rem; border-radius: 4px; margin: 0.5rem 0; font-size: 0.875rem; min-height: 1.5rem; }
    #message { color: #070; background: #efe; padding: 0.5rem; border-radius: 4px; margin: 0.5rem 0; font-size: 0.875rem; min-height: 1.5rem; white-space: pre-wrap; }
  </style>
</head>
<body>
  <h1>Article Draft</h1>
  <label for="topic">Topic</label>
  <textarea id="topic" placeholder="Enter topic…"></textarea>
  <div class="buttons">
    <button type="button" id="generateBtn">Generate draft</button>
  </div>
  <label for="draft">Draft</label>
  <textarea id="draft" placeholder="(Generate a draft first or paste your own)"></textarea>
  <label for="notes">Feedback / Notes</label>
  <textarea id="notes" placeholder="Corrections or approval rationale…"></textarea>
  <div class="buttons">
    <button type="button" id="acceptBtn" disabled>Accept</button>
    <button type="button" id="correctBtn" disabled>Correct</button>
  </div>
  <div id="error" role="alert" aria-live="polite"></div>
  <div id="message"></div>
  <script>
(function () {
  var PROTOCOL_VERSION = '2026-01-26';
  var topicEl = document.getElementById('topic');
  var draftEl = document.getElementById('draft');
  var notesEl = document.getElementById('notes');
  var generateBtn = document.getElementById('generateBtn');
  var acceptBtn = document.getElementById('acceptBtn');
  var correctBtn = document.getElementById('correctBtn');
  var errorEl = document.getElementById('error');
  var messageEl = document.getElementById('message');
  var target = window.parent;
  var initId = 1;
  var callId = 2;
  var pending = {};

  function send(msg) {
    target.postMessage(msg, '*');
  }

  function clearFeedback() {
    errorEl.textContent = '';
    messageEl.textContent = '';
  }

  function showError(msg) {
    errorEl.textContent = msg;
    messageEl.textContent = '';
  }

  function showMessage(msg) {
    errorEl.textContent = '';
    messageEl.textContent = msg;
  }

  function setActionsEnabled(enabled) {
    acceptBtn.disabled = !enabled;
    correctBtn.disabled = !enabled;
  }

  function getResultText(result) {
    if (!result || !result.content || !Array.isArray(result.content) || !result.content[0]) return '';
    return result.content[0].type === 'text' ? result.content[0].text : JSON.stringify(result.content[0]);
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
      showError('Initialize failed: ' + (err ? err.message : (rpcError && rpcError.message) || String(rpcError)));
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

  generateBtn.addEventListener('click', function () {
    var topic = (topicEl.value || '').trim();
    clearFeedback();
    var id = callId++;
    showMessage('Generating…');
    pending[id] = function (err, result, rpcError) {
      if (err || rpcError) {
        showError('Error: ' + (rpcError && rpcError.message ? rpcError.message : (err ? err.message : String(rpcError || err))));
        return;
      }
      var text = getResultText(result);
      draftEl.value = text || '';
      setActionsEnabled(!!draftEl.value.trim());
      showMessage('');
    };
    send({
      jsonrpc: '2.0',
      id: id,
      method: 'tools/call',
      params: { name: 'GenerateDraft', arguments: { topic: topic } }
    });
  });

  function doAccept() {
    var topic = (topicEl.value || '').trim();
    var draft = (draftEl.value || '').trim();
    var notes = (notesEl.value || '').trim();
    clearFeedback();
    var id = callId++;
    showMessage('Accepting…');
    pending[id] = function (err, result, rpcError) {
      if (err || rpcError) {
        showError('Error: ' + (rpcError && rpcError.message ? rpcError.message : (err ? err.message : String(rpcError || err))));
        return;
      }
      var text = getResultText(result);
      var st = result && result.structuredContent ? result.structuredContent : {};
      var url = st.publishedUrl || (st.done ? 'done' : '');
      showMessage(text + (url ? '\n\npublishedUrl: ' + url : ''));
    };
    send({
      jsonrpc: '2.0',
      id: id,
      method: 'tools/call',
      params: { name: 'AcceptDraft', arguments: { topic: topic, draft: draft, notes: notes } }
    });
  }

  function doCorrect() {
    var topic = (topicEl.value || '').trim();
    var draft = (draftEl.value || '').trim();
    var notes = (notesEl.value || '').trim();
    clearFeedback();
    var id = callId++;
    showMessage('Requesting changes…');
    pending[id] = function (err, result, rpcError) {
      if (err || rpcError) {
        showError('Error: ' + (rpcError && rpcError.message ? rpcError.message : (err ? err.message : String(rpcError || err))));
        return;
      }
      var text = getResultText(result);
      var st = result && result.structuredContent ? result.structuredContent : {};
      if (st.draftText != null) {
        draftEl.value = st.draftText;
      }
      setActionsEnabled(!!draftEl.value.trim());
      showMessage(text || 'Changes requested. Revised draft updated above.');
    };
    send({
      jsonrpc: '2.0',
      id: id,
      method: 'tools/call',
      params: { name: 'RequestChanges', arguments: { topic: topic, draft: draft, notes: notes } }
    });
  }

  acceptBtn.addEventListener('click', doAccept);
  correctBtn.addEventListener('click', doCorrect);

  draftEl.addEventListener('input', function () {
    setActionsEnabled(!!draftEl.value.trim());
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
