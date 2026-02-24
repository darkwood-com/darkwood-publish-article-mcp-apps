<?php

declare(strict_types=1);

namespace Darkwood\Mcp;

/**
 * Handles HTTP request: parse JSON-RPC body, dispatch to McpServer, return JSON response.
 */
final class JsonRpcHandler
{
    public function __construct(
        private McpServer $server
    ) {
    }

    public function handle(string $body): array
    {
        $request = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => [
                    'code' => -32700,
                    'message' => 'Parse error: ' . json_last_error_msg(),
                ],
            ];
        }

        if (!is_array($request)) {
            return [
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => [
                    'code' => -32600,
                    'message' => 'Invalid Request: body must be a JSON object',
                ],
            ];
        }

        // JSON-RPC batch: use first request (MVP)
        if (isset($request[0])) {
            $request = $request[0];
            if (!is_array($request)) {
                return [
                    'jsonrpc' => '2.0',
                    'id' => null,
                    'error' => [
                        'code' => -32600,
                        'message' => 'Invalid Request: batch element must be object',
                    ],
                ];
            }
        }

        try {
            return $this->server->handleRequest($request);
        } catch (\Throwable $e) {
            $id = $request['id'] ?? null;
            return [
                'jsonrpc' => '2.0',
                'id' => $id,
                'error' => [
                    'code' => -32603,
                    'message' => 'Internal error: ' . $e->getMessage(),
                ],
            ];
        }
    }
}
