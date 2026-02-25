<?php

declare(strict_types=1);

namespace Darkwood\Flow;

use PDO;

/**
 * SQLite persistence for flow runs.
 */
final class RunRepository
{
    private const TABLE = 'runs';

    public function __construct(
        private PDO $pdo
    ) {
    }

    public function init(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS runs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                flow_name TEXT NOT NULL,
                payload TEXT NOT NULL,
                state TEXT NOT NULL,
                step_index INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )
            SQL
        );
    }

    /** @return array{id: int, flow_name: string, payload: string, state: string, step_index: int} */
    public function insert(string $flowName, string $payload, string $state = 'queued'): array
    {
        $now = $this->now();
        $stmt = $this->pdo->prepare(
            'INSERT INTO runs (flow_name, payload, state, step_index, created_at, updated_at) VALUES (?, ?, ?, 0, ?, ?)'
        );
        $stmt->execute([$flowName, $payload, $state, $now, $now]);
        $id = (int) $this->pdo->lastInsertId();
        return [
            'id' => $id,
            'flow_name' => $flowName,
            'payload' => $payload,
            'state' => $state,
            'step_index' => 0,
        ];
    }

    /** @return list<array{id: int, flow_name: string, payload: string, state: string, step_index: int}> */
    public function findRunnable(): array
    {
        $stmt = $this->pdo->query(
            "SELECT id, flow_name, payload, state, step_index FROM runs WHERE state IN ('queued', 'running') ORDER BY id ASC"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static fn (array $r) => [
            'id' => (int) $r['id'],
            'flow_name' => $r['flow_name'],
            'payload' => $r['payload'],
            'state' => $r['state'],
            'step_index' => (int) $r['step_index'],
        ], $rows);
    }

    public function setState(int $id, string $state, int $stepIndex): void
    {
        $now = $this->now();
        $stmt = $this->pdo->prepare('UPDATE runs SET state = ?, step_index = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$state, $stepIndex, $now, $id]);
    }

    private function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
