<?php

declare(strict_types=1);

namespace App\Flow;

use App\AsyncHandler\SyncHandler;
use App\Model\GenerateDraftPayload;
use App\Model\PublishDraftPayload;
use Flow\DriverInterface;
use Flow\Flow\Flow;
use Flow\IpStrategy\LinearIpStrategy;

/**
 * Flow that receives GenerateDraftPayload or PublishDraftPayload and produces PublishDraftPayload.
 * When input is GenerateDraftPayload (from upstream GenerateDraftFlow), maps then pass-through.
 * When input is PublishDraftPayload, normalizes and executes (accept or correct).
 *
 * @extends Flow<GenerateDraftPayload|PublishDraftPayload, PublishDraftPayload>
 */
final class PublishDraftFlow extends Flow
{
    public function __construct(?DriverInterface $driver = null)
    {
        $job = static function (mixed $p): PublishDraftPayload {
            $mapped = self::mapFromGenerateDraft($p);
            return self::normalizeAndExecute($mapped);
        };
        parent::__construct(
            $job,
            null,
            new LinearIpStrategy(),
            null,
            new SyncHandler(),
            $driver,
        );
    }

    /**
     * Map input to PublishDraftPayload. PublishDraftPayload return as-is; GenerateDraftPayload map to draft pass-through.
     *
     * @param GenerateDraftPayload|PublishDraftPayload|mixed $input
     */
    public static function mapFromGenerateDraft(mixed $input): PublishDraftPayload
    {
        if ($input instanceof PublishDraftPayload) {
            return $input;
        }
        if ($input instanceof GenerateDraftPayload) {
            $topic = $input->getTopic();
            $draft = $input->getDraftText() ?? '';
            return new PublishDraftPayload($topic, $draft, '', 'draft');
        }
        return new PublishDraftPayload('', '', '', PublishDraftPayload::ACTION_CORRECT);
    }

    /**
     * @param PublishDraftPayload|mixed $payload
     */
    public static function normalizeAndExecute(mixed $payload): PublishDraftPayload
    {
        if (!$payload instanceof PublishDraftPayload) {
            return new PublishDraftPayload('', '', '', PublishDraftPayload::ACTION_CORRECT);
        }
        $payload->setNormalizedTopic(trim($payload->getTopic()));
        $payload->setNormalizedDraft(is_string($payload->getDraft()) ? $payload->getDraft() : '');
        $payload->setNormalizedNotes(is_string($payload->getNotes()) ? $payload->getNotes() : '');
        self::execute($payload);
        return $payload;
    }

    public static function execute(PublishDraftPayload $payload): void
    {
        $topic = $payload->getNormalizedTopic() ?? '';
        $draft = $payload->getNormalizedDraft() ?? '';
        $notes = $payload->getNormalizedNotes() ?? '';

        if ($payload->getAction() === PublishDraftPayload::ACTION_ACCEPT) {
            $slug = preg_replace('/[^a-z0-9-]/', '-', strtolower($topic));
            $hash = substr(md5($draft), 0, 8);
            $publishedUrl = 'https://example.com/articles/' . $slug . '-' . $hash;
            $payload->setPublishedUrl($publishedUrl);
            $payload->setDone(true);
            $payload->setMessageText(
                "Accepted successfully.\n\npublishedUrl: " . $publishedUrl . "\n\n(MVP: no actual publish; URL is fake.)"
            );
            $payload->setDraftText($draft);
            return;
        }

        if ($payload->getAction() === 'draft') {
            $payload->setDraftText($draft);
            $payload->setDone(false);
            $payload->setMessageText('');
            $payload->setPublishedUrl(null);
            return;
        }

        // correct
        $revised = $draft . "\n\n[Revision — " . trim($notes ?: 'no notes') . "]\n\n"
            . "Changes requested. Your notes have been applied. (Placeholder revision.)\n\n— PHP MCP Server";
        $payload->setDraftText($revised);
        $payload->setDone(false);
        $payload->setMessageText('Changes requested. Revised draft below.');
        $payload->setPublishedUrl(null);
    }
}
