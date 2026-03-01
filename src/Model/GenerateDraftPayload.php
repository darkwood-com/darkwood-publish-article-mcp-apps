<?php

declare(strict_types=1);

namespace App\Model;

/**
 * Payload carried by Ip through GenerateDraftFlow. Mutable: jobs set normalizedTopic and draftText.
 */
final class GenerateDraftPayload
{
    public function __construct(
        private string $topic,
        private ?string $normalizedTopic = null,
        private ?string $draftText = null,
    ) {
    }

    public function getTopic(): string
    {
        return $this->topic;
    }

    public function setNormalizedTopic(string $value): void
    {
        $this->normalizedTopic = $value;
    }

    public function getNormalizedTopic(): ?string
    {
        return $this->normalizedTopic;
    }

    public function setDraftText(string $value): void
    {
        $this->draftText = $value;
    }

    public function getDraftText(): ?string
    {
        return $this->draftText;
    }
}
