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
    private const UI_RESOURCE_URI = 'ui://darkwood/hello';
    private const RESOURCE_MIME_TYPE = 'text/html;profile=mcp-app';

    public function handleRequest(array $request): array
    {
        $method = $request['method'] ?? null;
        $params = $request['params'] ?? [];
        $id = $request['id'] ?? null;

        if (!$method) {
            return $this->error(-32600, 'Invalid Request: missing method', $id);
        }

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
                            'resourceUri' => self::UI_RESOURCE_URI,
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

        if ($name !== 'hello_ui') {
            throw new \InvalidArgumentException("Unknown tool: {$name}");
        }

        $text = 'Hello from PHP MCP Server';
        return [
            'content' => [
                ['type' => 'text', 'text' => $text],
            ],
            'structuredContent' => (object)[],
        ];
    }

    private function resourcesList(): array
    {
        return [
            'resources' => [
                [
                    'uri' => self::UI_RESOURCE_URI,
                    'name' => 'hello',
                    'description' => 'Minimal MCP App UI for hello_ui',
                    'mimeType' => self::RESOURCE_MIME_TYPE,
                ],
            ],
        ];
    }

    private function resourcesRead(array $params): array
    {
        $uri = $params['uri'] ?? '';

        if ($uri !== self::UI_RESOURCE_URI) {
            throw new \InvalidArgumentException("Unknown resource: {$uri}");
        }

        $html = $this->getHelloHtml();
        return [
            'contents' => [
                [
                    'uri' => self::UI_RESOURCE_URI,
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
    p { color: #666; margin: 0; }
  </style>
</head>
<body>
  <h1>Hello MCP App</h1>
  <p>This UI is served by the PHP MCP server. The host will send the tool result here when ready.</p>
</body>
</html>
HTML;
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
