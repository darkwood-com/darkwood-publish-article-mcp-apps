#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Single long-running process: embedded HTTP server (MCP JSON-RPC on /mcp) + Flow worker tick loop.
 * Run: php bin/flow-worker.php
 * MCP endpoint: http://127.0.0.1:3000/mcp
 */

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    file_put_contents('php://stderr', "Run composer install first.\n");
    exit(1);
}
require_once $autoload;

// Prevent PHP errors from being sent as HTML (basic-host expects JSON only)
ini_set('display_errors', '0');
ini_set('log_errors', '1');

use Darkwood\Mcp\JsonRpcHandler;
use Darkwood\Mcp\McpServer;
use React\EventLoop\Loop;
use React\Http\Message\Response;
use React\Socket\SocketServer;
use React\Http\HttpServer;
use Flow\Driver\ReactDriver;
use Flow\FlowFactory;
use Flow\Ip;

$mcpServer = new McpServer();
$jsonRpcHandler = new JsonRpcHandler($mcpServer);

$corsHeaders = [
    'Access-Control-Allow-Origin' => '*',
    'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
    'Access-Control-Allow-Headers' => 'Content-Type, mcp-protocol-version, mcp-session-id',
    'Access-Control-Max-Age' => '86400',
];

$responseWithCors = static function (int $status, array $headers, string $body) use ($corsHeaders): Response {
    return new Response($status, $corsHeaders + $headers, $body);
};

$httpHandler = function (Psr\Http\Message\ServerRequestInterface $request) use ($jsonRpcHandler, $responseWithCors, $corsHeaders): Response {
    try {
        $path = $request->getUri()->getPath();
        $method = strtoupper($request->getMethod());

        // CORS preflight: OPTIONS (browser sends this before GET/POST from another origin)
        if ($method === 'OPTIONS') {
            if ($path === '/mcp') {
                return new Response(204, $corsHeaders, '');
            }
        }

        if ($path !== '/mcp') {
            return $responseWithCors(404, ['Content-Type' => 'application/json'], json_encode([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => ['code' => -32600, 'message' => 'Not found: use POST /mcp for JSON-RPC'],
            ]));
        }
        // Non-POST /mcp
        if ($method !== 'POST') {
            // Streamable HTTP client does GET with Accept: text/event-stream; it expects 405 (no SSE) and then uses POST for JSON-RPC.
            $accept = $request->getHeaderLine('Accept') ?? '';
            if (stripos($accept, 'text/event-stream') !== false) {
                return $responseWithCors(405, ['Content-Type' => 'application/json'], json_encode([
                    'jsonrpc' => '2.0',
                    'id' => null,
                    'error' => ['code' => -32600, 'message' => 'Method not allowed: use POST for JSON-RPC'],
                ]));
            }
            return $responseWithCors(200, ['Content-Type' => 'application/json'], json_encode([
                'mcp' => true,
                'message' => 'MCP endpoint: send POST with JSON-RPC body (method: initialize, tools/list, tools/call, resources/list, resources/read)',
            ]));
        }
        $body = $request->getBody()->getContents();
        $response = $jsonRpcHandler->handle($body);
        return $responseWithCors(200, ['Content-Type' => 'application/json; charset=utf-8'], json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    } catch (\Throwable $e) {
        file_put_contents('php://stderr', '[flow-worker] ' . $e->getMessage() . "\n", FILE_APPEND);
        return $responseWithCors(500, ['Content-Type' => 'application/json'], json_encode([
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => ['code' => -32603, 'message' => 'Internal error'],
        ]));
    }
};

$port = (int) (getenv('MCP_PORT') ?: '3000');
$http = new HttpServer($httpHandler);
$socket = new SocketServer('127.0.0.1:' . $port);
$http->listen($socket);

file_put_contents('php://stderr', "MCP endpoint: http://127.0.0.1:{$port}/mcp\n", FILE_APPEND);

$driver = new ReactDriver(Loop::get());
printf("Use %s\n", $driver::class);

$addOneJob = static function ($data) use ($driver) {
    printf("Client %d\n", $data);

    return $data;
};

$flow = (new FlowFactory())->create(static function () use (
    $addOneJob,
) {
    yield $addOneJob;
}, ['driver' => $driver]);
$flow(new Ip(1));

$driver->tick(1000, function() {
    //printf("coucou\n");
});
$flow->await();
