<?php

declare(strict_types=1);

namespace App\Mcp;

/**
 * Stdio JSON-RPC transport for MCP: read line-delimited requests from STDIN,
 * write responses to STDOUT, log only to STDERR.
 */
final class StdioTransport
{
    private string $buffer = '';

    public function __construct(
        private $stdin,
        private $stdout,
        private $stderr = null,
    ) {
        $this->stderr = $stderr ?? STDERR;
    }

    public function setNonBlocking(bool $nonBlocking): void
    {
        stream_set_blocking($this->stdin, !$nonBlocking);
    }

    /**
     * Read available input; if a complete newline-delimited JSON-RPC request
     * is present, parse and return it (batch: first element only for MVP).
     * Returns null if no full line or parse error (caller should not respond for parse error; we log).
     */
    public function readOneRequest(): ?array
    {
        $chunk = @stream_get_contents($this->stdin, 65536);
        if ($chunk !== false && $chunk !== '') {
            $this->buffer .= $chunk;
        }

        $pos = strpos($this->buffer, "\n");
        if ($pos === false) {
            return null;
        }

        $line = substr($this->buffer, 0, $pos);
        $this->buffer = substr($this->buffer, $pos + 1);

        $line = trim($line);
        if ($line === '') {
            return $this->readOneRequest();
        }

        $request = json_decode($line, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log('Parse error: ' . json_last_error_msg());
            $this->writeResponse([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => [
                    'code' => -32700,
                    'message' => 'Parse error: ' . json_last_error_msg(),
                ],
            ]);
            return null;
        }

        if (!is_array($request)) {
            $this->log('Invalid Request: body must be a JSON object');
            $this->writeResponse([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => [
                    'code' => -32600,
                    'message' => 'Invalid Request: body must be a JSON object',
                ],
            ]);
            return null;
        }

        // Batch: MVP handle first element only
        if (isset($request[0]) && is_array($request[0])) {
            $request = $request[0];
        }

        // Notifications (no id) — do not respond
        if (!array_key_exists('id', $request)) {
            return null;
        }

        return $request;
    }

    public function writeResponse(array $response): void
    {
        $json = json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return;
        }
        fwrite($this->stdout, $json . "\n");
        fflush($this->stdout);
    }

    public function log(string $message): void
    {
        fwrite($this->stderr, '[MCP stdio] ' . $message . "\n");
    }
}
