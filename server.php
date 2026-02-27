#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Stdio MCP server + Flow worker (single process).
 * Reads newline-delimited JSON-RPC from STDIN, writes responses to STDOUT.
 * For Claude Desktop: run as local extension (no HTTP).
 *
 *   php server.php
 */

$autoload = __DIR__ . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Run composer install first.\n");
    exit(1);
}
require_once $autoload;

ini_set('display_errors', '0');
ini_set('log_errors', '1');

use Darkwood\Mcp\JsonRpcHandler;
use Darkwood\Mcp\McpServer;
use Darkwood\Mcp\StdioTransport;
use Flow\Driver\ReactDriver;
use Flow\FlowFactory;
use Flow\Ip;
use React\EventLoop\Loop;

$mcpServer = new McpServer();
$jsonRpcHandler = new JsonRpcHandler($mcpServer);

$stdin = STDIN;
$stdout = STDOUT;
$transport = new StdioTransport($stdin, $stdout);
$transport->setNonBlocking(true);

$loop = Loop::get();
$driver = new ReactDriver($loop);

$flow = (new FlowFactory())->create(static function () {
    yield static function ($data) {
        return $data;
    };
}, ['driver' => $driver]);

$flow(new Ip(1));

$driver->tick(1.0, static function (): void {
    // Flow periodic tick (keeps loop alive; add real work here if needed)
});

$loop->addReadStream($stdin, static function () use ($transport, $jsonRpcHandler): void {
    while (($request = $transport->readOneRequest()) !== null) {
        $response = $jsonRpcHandler->handleParsedRequest($request);
        $transport->writeResponse($response);
    }
});

fwrite(STDERR, "MCP stdio server + Flow worker started. Send JSON-RPC (one object per line) to STDIN.\n");

$flow->await();
