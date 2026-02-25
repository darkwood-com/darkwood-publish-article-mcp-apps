<?php

declare(strict_types=1);

/**
 * Router for PHP built-in server: route /mcp to MCP JSON-RPC handler.
 * Run: php -S localhost:3000 public/router.php
 * Then: http://localhost:3000/mcp
 */

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
if ($uri !== '/mcp') {
    return false; // let built-in server try to serve file (e.g. 404)
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, mcp-protocol-version');
    header('Access-Control-Max-Age: 86400');
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    http_response_code(200);
    echo json_encode([
        'mcp' => true,
        'message' => 'MCP endpoint: send POST with JSON-RPC body (method: initialize, tools/list, tools/call, resources/list, resources/read)',
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    echo json_encode([
        'jsonrpc' => '2.0',
        'id' => null,
        'error' => ['code' => -32600, 'message' => 'Method not allowed: use POST for JSON-RPC'],
    ]);
    exit;
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Darkwood\Mcp\McpServer;
use Darkwood\Mcp\JsonRpcHandler;

$body = (string) file_get_contents('php://input');
$server = new McpServer();
$handler = new JsonRpcHandler($server);
$response = $handler->handle($body);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
echo json_encode($response);
