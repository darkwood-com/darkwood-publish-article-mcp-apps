#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Stdio MCP server. Same MCP + Flow wiring as flow-worker; transport is STDIN/STDOUT only.
 * For Claude Desktop: run as local extension (no HTTP).
 *
 *   php server.php
 *
 * For HTTP MCP use: php bin/flow-worker.php (or symfony serve with public/index.php).
 */

$autoload = __DIR__ . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Run composer install first.\n");
    exit(1);
}
require_once $autoload;

App\Bootstrap\AppBootstrap::applyPhpSettings();

use App\Bootstrap\AppBootstrap;
use App\Mcp\StdioTransport;
use React\EventLoop\Loop;

$mcpServer = AppBootstrap::createMcpServer();
$jsonRpcHandler = AppBootstrap::createJsonRpcHandler($mcpServer);

$stdin = STDIN;
$stdout = STDOUT;
$transport = new StdioTransport($stdin, $stdout);
$transport->setNonBlocking(true);

$loop = Loop::get();

$loop->addReadStream($stdin, static function () use ($transport, $jsonRpcHandler): void {
    while (($request = $transport->readOneRequest()) !== null) {
        $response = $jsonRpcHandler->handleParsedRequest($request);
        $transport->writeResponse($response);
    }
});

fwrite(STDERR, "MCP stdio server started. Send JSON-RPC (one object per line) to STDIN.\n");

$loop->run();
