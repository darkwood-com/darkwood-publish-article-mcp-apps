<?php

declare(strict_types=1);

namespace App\Model;

/**
 * Payload for the publish/request-changes workflow. Carries input (topic, draft, notes, action),
 * normalized values, and output (messageText, publishedUrl, done, revised draft text).
 */
final class PublishDraftPayload
{
    public const ACTION_ACCEPT = 'accept';
    public const ACTION_CORRECT = 'correct';

    public function __construct(
        private string $topic,
        private string $draft,
        private string $notes,
        private string $action,
        private ?string $normalizedTopic = null,
        private ?string $normalizedDraft = null,
        private ?string $normalizedNotes = null,
        private ?string $messageText = null,
        private ?string $publishedUrl = null,
        private bool $done = false,
        private ?string $draftText = null,
    ) {
    }

    public function getTopic(): string
    {
        return $this->topic;
    }

    public function getDraft(): string
    {
        return $this->draft;
    }

    public function getNotes(): string
    {
        return $this->notes;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function setNormalizedTopic(string $value): void
    {
        $this->normalizedTopic = $value;
    }

    public function getNormalizedTopic(): ?string
    {
        return $this->normalizedTopic;
    }

    public function setNormalizedDraft(string $value): void
    {
        $this->normalizedDraft = $value;
    }

    public function getNormalizedDraft(): ?string
    {
        return $this->normalizedDraft;
    }

    public function setNormalizedNotes(string $value): void
    {
        $this->normalizedNotes = $value;
    }

    public function getNormalizedNotes(): ?string
    {
        return $this->normalizedNotes;
    }

    public function setMessageText(string $value): void
    {
        $this->messageText = $value;
    }

    public function getMessageText(): ?string
    {
        return $this->messageText;
    }

    public function setPublishedUrl(?string $value): void
    {
        $this->publishedUrl = $value;
    }

    public function getPublishedUrl(): ?string
    {
        return $this->publishedUrl;
    }

    public function setDone(bool $value): void
    {
        $this->done = $value;
    }

    public function isDone(): bool
    {
        return $this->done;
    }

    public function setDraftText(?string $value): void
    {
        $this->draftText = $value;
    }

    /** Revised draft text (for correct path); for publish path may equal input draft. */
    public function getDraftText(): ?string
    {
        return $this->draftText;
    }
}
