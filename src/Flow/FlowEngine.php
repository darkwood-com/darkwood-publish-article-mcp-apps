<?php

declare(strict_types=1);

namespace Darkwood\Flow;

/**
 * Minimal tick-based flow orchestration.
 * Run states: queued, running, paused, success, failed.
 */
final class FlowEngine
{
    /** @var array<string, int> flow_name => number of steps */
    private const FLOWS = [
        'hello_flow' => 2,
    ];

    public function __construct(
        private RunRepository $repository,
        private Lock $lock
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function startRun(string $flowName, array $payload): array
    {
        if (!isset(self::FLOWS[$flowName])) {
            throw new \InvalidArgumentException("Unknown flow: {$flowName}");
        }
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        return $this->repository->insert($flowName, $encoded, 'queued');
    }

    /**
     * Process up to maxSteps run steps within maxDurationMs.
     * Returns stats for logging.
     *
     * @return array{processed: int, runs: int, duration_ms: float}
     */
    public function tick(int $maxSteps = 10, int $maxDurationMs = 5000): array
    {
        $start = microtime(true);
        $processed = 0;
        $runs = 0;

        if (!$this->lock->acquire()) {
            return ['processed' => 0, 'runs' => 0, 'duration_ms' => (microtime(true) - $start) * 1000];
        }

        try {
            $list = $this->repository->findRunnable();
            $runs = count($list);
            $deadline = $start + ($maxDurationMs / 1000);

            foreach ($list as $run) {
                if (microtime(true) > $deadline || $processed >= $maxSteps) {
                    break;
                }
                $steps = self::FLOWS[$run['flow_name']] ?? 0;
                $next = $run['step_index'] + 1;
                if ($next >= $steps) {
                    $this->repository->setState($run['id'], 'success', $next);
                } else {
                    $this->repository->setState($run['id'], 'running', $next);
                }
                $processed++;
            }
        } finally {
            $this->lock->release();
        }

        return [
            'processed' => $processed,
            'runs' => $runs,
            'duration_ms' => (microtime(true) - $start) * 1000,
        ];
    }
}
