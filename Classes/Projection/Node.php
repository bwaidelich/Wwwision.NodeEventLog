<?php
declare(strict_types=1);
namespace Wwwision\NodeEventLog\Projection;

use Neos\ContentRepository\DimensionSpace\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Domain\NodeAggregate\NodeAggregateIdentifier;
use Neos\Flow\Annotations as Flow;

/**
 * Node read model (only used for the internal state of the EventLogProjector)
 *
 * @Flow\Proxy(false)
 * @internal
 */
final class Node
{
    private array $source;

    public function __construct(array $source)
    {
        $this->source = $source;
    }

    public static function fromDatabaseRow(array $row): self
    {
        return new self($row);
    }

    public function withDimensionSpacePoint(DimensionSpacePoint $dimensionSpacePoint): self
    {
        $source = $this->source;
        $source['dimensionspacepointhash'] = $dimensionSpacePoint->getHash();
        return new self($source);
    }

    public function withOriginDimensionSpacePoint(DimensionSpacePoint $originDimensionSpacePoint): self
    {
        $source = $this->source;
        $source['origindimensionspacepointhash'] = $originDimensionSpacePoint->getHash();
        return new self($source);
    }

    public function getNodeAggregateIdentifier(): NodeAggregateIdentifier
    {
        return NodeAggregateIdentifier::fromString($this->source['nodeaggregateidentifier']);
    }

    public function isRoot(): bool
    {
        return $this->source['parentnodeaggregateidentifier'] === null;
    }

    public function getParentNodeAggregateIdentifier(): NodeAggregateIdentifier
    {
        return NodeAggregateIdentifier::fromString($this->source['parentnodeaggregateidentifier']);
    }

    public function getDimensionSpacePointHash(): string
    {
        return $this->source['dimensionspacepointhash'];
    }

    /**
     * This is NOT the node path; but the "nodeAggregateIdentifiers on the hierarchy; separated by /"
     *
     * @return string
     */
    public function getNodeAggregateIdentifierPath(): string
    {
        return $this->source['nodeaggregateidentifierpath'];
    }

    public function isDisabled(): bool
    {
        return $this->getDisableLevel() > 0;
    }

    public function getDisableLevel(): int
    {
        return (int)$this->source['disabled'];
    }

    public function toArray(): array
    {
        return $this->source;
    }

    public function __toString(): string
    {
        return ($this->source['nodeaggregateidentifier'] ?? '<unknown nodeAggregateIdentifier>') . '@' . ($this->source['dimensionspacepointhash'] ?? '<unkown dimensionSpacePointHash>');
    }
}
