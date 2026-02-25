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

use Darkwood\Flow\FlowEngine;
use Darkwood\Flow\Lock;
use Darkwood\Flow\RunRepository;
use Darkwood\Mcp\JsonRpcHandler;
use Darkwood\Mcp\McpServer;
use React\EventLoop\Loop;
use React\Http\Message\Response;
use React\Socket\SocketServer;
use React\Http\HttpServer;

$baseDir = dirname(__DIR__);
$varDir = $baseDir . '/var';
$dbPath = $varDir . '/flow.sqlite';
$lockPath = $varDir . '/flow.lock';

if (!is_dir($varDir)) {
    mkdir($varDir, 0755, true);
}

$pdo = new PDO('sqlite:' . $dbPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$repository = new RunRepository($pdo);
$repository->init();
$lock = new Lock($lockPath);
$flowEngine = new FlowEngine($repository, $lock);

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

$httpHandler = function (Psr\Http\Message\ServerRequestInterface $request) use ($jsonRpcHandler, $flowEngine, $responseWithCors, $corsHeaders): Response {
    try {
        $path = $request->getUri()->getPath();
        $method = strtoupper($request->getMethod());

        // CORS preflight: OPTIONS (browser sends this before GET/POST from another origin)
        if ($method === 'OPTIONS') {
            if ($path === '/mcp' || $path === '/flow/start') {
                return new Response(204, $corsHeaders, '');
            }
        }

        // Demo: GET /flow/start?flow=hello_flow to enqueue a run (see tick logs)
        if ($path === '/flow/start' && $method === 'GET') {
            $params = $request->getQueryParams();
            $flowName = $params['flow'] ?? 'hello_flow';
            try {
                $run = $flowEngine->startRun($flowName, []);
                return $responseWithCors(200, ['Content-Type' => 'application/json'], json_encode([
                    'ok' => true,
                    'run' => $run,
                    'message' => 'Run enqueued; watch STDERR for [Flow] tick stats.',
                ]));
            } catch (\Throwable $e) {
                return $responseWithCors(400, ['Content-Type' => 'application/json'], json_encode([
                    'ok' => false,
                    'error' => $e->getMessage(),
                ]));
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

$loop = Loop::get();
$loop->addPeriodicTimer(0.1, function () use ($flowEngine): void {
    $stats = $flowEngine->tick(10, 5000);
    if ($stats['runs'] > 0 || $stats['processed'] > 0) {
        file_put_contents('php://stderr', sprintf(
            "[Flow] tick processed=%d runs=%d duration_ms=%.2f\n",
            $stats['processed'],
            $stats['runs'],
            $stats['duration_ms']
        ), FILE_APPEND);
    }
});

file_put_contents('php://stderr', "MCP endpoint: http://127.0.0.1:{$port}/mcp\n", FILE_APPEND);
file_put_contents('php://stderr', "Flow worker ticking every 100ms. Press Ctrl+C to stop.\n", FILE_APPEND);

$loop->run();
