<?php

declare(strict_types=1);

namespace App\Bootstrap;

use App\Flow\GenerateDraftFlow;
use App\Mcp\JsonRpcHandler;
use App\Mcp\McpServer;
use App\Model\GenerateDraftPayload;
use Flow\Driver\FiberDriver;
use Flow\FlowFactory;
use Flow\Ip;

/**
 * Shared application bootstrap: MCP server + Flow wiring.
 * All entrypoints (flow-worker, server.php, public/index.php) use this so transport is the only difference.
 */
final class AppBootstrap
{
    /**
     * PHP settings applied in all entrypoints (no HTML errors, log to stderr).
     */
    public static function applyPhpSettings(): void
    {
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
    }

    /**
     * CORS headers shared by HTTP entrypoints (flow-worker, public/index.php).
     *
     * @return array<string, string>
     */
    public static function getCorsHeaders(): array
    {
        return [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, mcp-protocol-version, mcp-session-id',
            'Access-Control-Max-Age' => '86400',
        ];
    }

    /**
     * Creates the MCP server with Flow-backed GenerateDraft (same wiring as flow-worker).
     */
    public static function createMcpServer(): McpServer
    {
        $flowDriver = new FiberDriver();
        $generateDraftFlow = new GenerateDraftFlow($flowDriver);
        $flow = (new FlowFactory())->create(static function () use ($generateDraftFlow) {
            yield $generateDraftFlow;
        }, ['driver' => $flowDriver]);

        $generateDraftRunner = static function (string $topic) use ($flow): string {
            $payload = new GenerateDraftPayload($topic);
            $flow(new Ip($payload));
            $flow->await();
            return $payload->getDraftText() ?? '';
        };

        $mcpServer = new McpServer();
        $mcpServer->setGenerateDraftRunner($generateDraftRunner);

        return $mcpServer;
    }

    /**
     * Creates the JSON-RPC handler for the given MCP server.
     */
    public static function createJsonRpcHandler(McpServer $mcpServer): JsonRpcHandler
    {
        return new JsonRpcHandler($mcpServer);
    }
}
