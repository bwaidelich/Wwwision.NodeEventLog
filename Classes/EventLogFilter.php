<?php
/** @noinspection PhpPropertyOnlyWrittenInspection */
declare(strict_types=1);
namespace Wwwision\NodeEventLog;

use Neos\ContentRepository\DimensionSpace\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Domain\ContentStream\ContentStreamIdentifier;
use Neos\ContentRepository\Domain\NodeAggregate\NodeAggregateIdentifier;
use Neos\EventSourcedContentRepository\Domain\ValueObject\UserIdentifier;
use Neos\EventSourcedContentRepository\Domain\ValueObject\WorkspaceName;
use Neos\Flow\Annotations as Flow;
use Webmozart\Assert\Assert;

/**
 * This DTO allows to specify filter criteria to limit the event log to the matching set.
 *
 * @Flow\Proxy(false)
 */
final class EventLogFilter
{

    private bool $recursively = false;
    private ?NodeAggregateIdentifier $nodeId = null;
    private ?WorkspaceName $workspaceName = null;
    private ?ContentStreamIdentifier $contentStreamId = null;
    private ?UserIdentifier $initiatingUserId = null;
    private ?string $dimensionSpacePointHash = null;
    private bool $skipInheritedEvents = false;

    private function __construct()
    {
    }

    public static function create(): self
    {
        return new self();
    }

    public function forNode(NodeAggregateIdentifier $nodeId, bool $recursively = false): self
    {
        $newInstance = clone $this;
        $newInstance->nodeId = $nodeId;
        $newInstance->recursively = $recursively;
        return $newInstance;
    }

    public function inWorkspace(WorkspaceName $workspaceName): self
    {
        Assert::null($this->contentStreamId, 'content stream and workspace filter must not be combined');
        $newInstance = clone $this;
        $newInstance->workspaceName = $workspaceName;
        return $newInstance;
    }

    public function inContentStream(ContentStreamIdentifier $contentStreamId): self
    {
        Assert::null($this->workspaceName, 'content stream and workspace filter must not be combined');
        $newInstance = clone $this;
        $newInstance->contentStreamId = $contentStreamId;
        return $newInstance;
    }

    public function forInitiatingUser(UserIdentifier $userId): self
    {
        $newInstance = clone $this;
        $newInstance->initiatingUserId = $userId;
        return $newInstance;
    }

    public function forDimension(DimensionSpacePoint $dimensionSpacePoint): self
    {
        $newInstance = clone $this;
        $newInstance->dimensionSpacePointHash = $dimensionSpacePoint->getHash();
        return $newInstance;
    }

    public function skipInheritedEvents(): self
    {
        $newInstance = clone $this;
        $newInstance->skipInheritedEvents = true;
        return $newInstance;
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }

}
