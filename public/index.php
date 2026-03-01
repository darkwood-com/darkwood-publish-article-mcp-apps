<?php

declare(strict_types=1);

/**
 * HTTP MCP front controller. Same MCP + Flow wiring as flow-worker and server.php; transport is classic HTTP.
 * Suitable for Symfony local server: symfony serve (document root: public/).
 *
 * MCP endpoint: POST /mcp with JSON-RPC body.
 */

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Run composer install first.']);
    exit(1);
}
require_once $autoload;

App\Bootstrap\AppBootstrap::applyPhpSettings();

use App\Bootstrap\AppBootstrap;

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$corsHeaders = AppBootstrap::getCorsHeaders();

if ($uri !== '/mcp') {
    foreach ($corsHeaders as $name => $value) {
        header("{$name}: {$value}");
    }
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode([
        'jsonrpc' => '2.0',
        'id' => null,
        'error' => ['code' => -32600, 'message' => 'Not found: use POST /mcp for JSON-RPC'],
    ]);
    exit;
}

if ($method === 'OPTIONS') {
    foreach ($corsHeaders as $name => $value) {
        header("{$name}: {$value}");
    }
    http_response_code(204);
    exit;
}

if ($method === 'GET') {
    foreach ($corsHeaders as $name => $value) {
        header("{$name}: {$value}");
    }
    header('Content-Type: application/json');
    http_response_code(200);
    echo json_encode([
        'mcp' => true,
        'message' => 'MCP endpoint: send POST with JSON-RPC body (method: initialize, tools/list, tools/call, resources/list, resources/read)',
    ]);
    exit;
}

if ($method !== 'POST') {
    foreach ($corsHeaders as $name => $value) {
        header("{$name}: {$value}");
    }
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode([
        'jsonrpc' => '2.0',
        'id' => null,
        'error' => ['code' => -32600, 'message' => 'Method not allowed: use POST for JSON-RPC'],
    ]);
    exit;
}

$mcpServer = AppBootstrap::createMcpServer();
$handler = AppBootstrap::createJsonRpcHandler($mcpServer);

$body = (string) file_get_contents('php://input');
$response = $handler->handle($body);

foreach ($corsHeaders as $name => $value) {
    header("{$name}: {$value}");
}
header('Content-Type: application/json; charset=utf-8');
echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
