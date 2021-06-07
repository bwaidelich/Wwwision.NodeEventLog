<?php
declare(strict_types=1);
namespace Wwwision\NodeEventLog\Projection;

use Neos\ContentRepository\Domain\ContentStream\ContentStreamIdentifier;
use Neos\ContentRepository\Domain\NodeAggregate\NodeAggregateIdentifier;
use Neos\EventSourcedContentRepository\Domain\ValueObject\UserIdentifier;
use Neos\Flow\Annotations as Flow;

/**
 * The Node Event read model
 *
 * @Flow\Proxy(false)
 */
final class NodeEvent
{

    private string $id;
    private NodeAggregateIdentifier $nodeId;
    private ContentStreamIdentifier $contentStreamId;
    private string $dimensionSpacePointHash;
    private string $eventId;
    private string $eventType;
    private string $payloadEncoded;
    private UserIdentifier $initiatingUserId;
    private string $recordedAtEncoded;
    private bool $inherited;

    private function __construct(string $id, NodeAggregateIdentifier $nodeId, ContentStreamIdentifier $contentStreamId, string $dimensionSpacePointHash, string $eventId, string $eventType, string $payloadEncoded, UserIdentifier $initiatingUserId, string $recordedAtEncoded, bool $inherited) {
        $this->id = $id;
        $this->nodeId = $nodeId;
        $this->contentStreamId = $contentStreamId;
        $this->dimensionSpacePointHash = $dimensionSpacePointHash;
        $this->eventId = $eventId;
        $this->eventType = $eventType;
        $this->payloadEncoded = $payloadEncoded;
        $this->initiatingUserId = $initiatingUserId;
        $this->recordedAtEncoded = $recordedAtEncoded;
        $this->inherited = $inherited;
    }

    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            $row['id'],
            NodeAggregateIdentifier::fromString($row['nodeaggregateidentifier']),
            ContentStreamIdentifier::fromString($row['contentstreamidentifier']),
            $row['dimensionspacepointhash'],
            $row['eventid'],
            $row['eventtype'],
            $row['payload'],
            UserIdentifier::fromString($row['initiatinguserid']),
            $row['recordedat'],
            $row['inherited'] === '1'
        );
    }

    public function id(): string
    {
        return $this->id;
    }

    public function nodeId(): NodeAggregateIdentifier
    {
        return $this->nodeId;
    }

    public function contentStreamId(): ContentStreamIdentifier
    {
        return $this->contentStreamId;
    }

    public function dimensionSpacePointHash(): string
    {
        return $this->dimensionSpacePointHash;
    }

    public function eventId(): string
    {
        return $this->eventId;
    }

    public function eventType(): string
    {
        return $this->eventType;
    }

    public function payload(): array
    {
        try {
            return json_decode($this->payloadEncoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(sprintf('Failed to decode event payload: %s', $e->getMessage()), 1622816085, $e);
        }
    }

    public function initiatingUserId(): UserIdentifier
    {
        return $this->initiatingUserId;
    }

    public function recordedAt(): \DateTimeInterface
    {
        return \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $this->recordedAtEncoded);
    }

    public function isInherited(): bool
    {
        return $this->inherited;
    }

}
