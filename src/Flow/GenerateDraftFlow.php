<?php

declare(strict_types=1);

namespace App\Flow;

use App\AsyncHandler\SyncHandler;
use App\Model\GenerateDraftPayload;
use Flow\DriverInterface;
use Flow\Flow\Flow;
use Flow\IpStrategy\LinearIpStrategy;

/**
 * Flow for draft generation. Owns its jobs and configures its own linear IP strategy.
 * Ip carries GenerateDraftPayload; __invoke(Ip) PUSHes into the strategy; after await() the payload holds the result.
 *
 * @extends Flow<GenerateDraftPayload, GenerateDraftPayload>
 */
final class GenerateDraftFlow extends Flow
{
    private const EMPTY_TOPIC_MESSAGE = '(No topic provided. Enter a topic and click Generate draft.)';

    public function __construct(?DriverInterface $driver = null)
    {
        $ipStrategy = new LinearIpStrategy();
        $normalizeJob = static function (mixed $p): mixed {
            return self::normalizeJob($p);
        };
        parent::__construct(
            $normalizeJob,
            null,
            $ipStrategy,
            null,
            new SyncHandler(),
            $driver,
        );
        $this->fn(static function (mixed $p): mixed {
            return self::buildJob($p);
        })->fn(static function (mixed $p): mixed {
            return self::formatJob($p);
        });
    }

    /**
     * @param GenerateDraftPayload|mixed $payload
     * @return GenerateDraftPayload|mixed
     */
    public static function normalizeJob(mixed $payload): mixed
    {
        if (!$payload instanceof GenerateDraftPayload) {
            return $payload;
        }
        $value = trim($payload->getTopic());
        $payload->setNormalizedTopic($value);
        return $payload;
    }

    /**
     * @param GenerateDraftPayload|mixed $payload
     * @return GenerateDraftPayload|mixed
     */
    public static function buildJob(mixed $payload): mixed
    {
        if (!$payload instanceof GenerateDraftPayload) {
            return $payload;
        }
        $topic = $payload->getNormalizedTopic() ?? '';
        $raw = $topic === ''
            ? self::EMPTY_TOPIC_MESSAGE
            : "Draft for topic: \"{$topic}\"\n\n"
                . "This is a placeholder draft. Replace with real generation later.\n\n"
                . "— PHP MCP Server";
        $payload->setDraftText($raw);
        return $payload;
    }

    /**
     * @param GenerateDraftPayload|mixed $payload
     * @return GenerateDraftPayload
     */
    /**
     * @param GenerateDraftPayload|mixed $payload
     * @return GenerateDraftPayload|mixed
     */
    public static function formatJob(mixed $payload): mixed
    {
        if (!$payload instanceof GenerateDraftPayload) {
            return $payload;
        }
        $raw = $payload->getDraftText() ?? '';
        $payload->setDraftText(trim($raw) . "\n");
        return $payload;
    }
}
