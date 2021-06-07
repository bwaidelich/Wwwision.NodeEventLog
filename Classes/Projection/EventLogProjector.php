<?php
declare(strict_types=1);
namespace Wwwision\NodeEventLog\Projection;

use Neos\EventSourcedContentRepository\Domain\Context\NodeAggregate\Event\NodeAggregateNameWasChanged;
use Neos\EventSourcedContentRepository\Domain\Context\NodeAggregate\Event\NodeAggregateTypeWasChanged;
use Neos\EventSourcedContentRepository\Domain\Context\NodeAggregate\Event\NodeAggregateWasDisabled;
use Neos\EventSourcedContentRepository\Domain\Context\NodeAggregate\Event\NodeAggregateWasEnabled;
use Neos\EventSourcedContentRepository\Domain\Context\NodeAggregate\Event\NodeAggregateWasMoved;
use Neos\EventSourcedContentRepository\Domain\Context\NodeAggregate\Event\NodeAggregateWasRemoved;
use Neos\EventSourcedContentRepository\Domain\Context\NodeAggregate\Event\NodeAggregateWithNodeWasCreated;
use Neos\EventSourcedContentRepository\Domain\Context\NodeAggregate\Event\NodeGeneralizationVariantWasCreated;
use Neos\EventSourcedContentRepository\Domain\Context\NodeAggregate\Event\NodePeerVariantWasCreated;
use Neos\EventSourcedContentRepository\Domain\Context\NodeAggregate\Event\NodePropertiesWereSet;
use Neos\EventSourcedContentRepository\Domain\Context\NodeAggregate\Event\NodeReferencesWereSet;
use Neos\EventSourcedContentRepository\Domain\Context\NodeAggregate\Event\NodeSpecializationVariantWasCreated;
use Neos\EventSourcedContentRepository\Domain\Context\NodeAggregate\Event\RootNodeAggregateWithNodeWasCreated;
use Neos\EventSourcedContentRepository\Domain\ValueObject\UserIdentifier;
use Neos\EventSourcing\EventListener\AfterInvokeInterface;
use Neos\EventSourcing\EventListener\BeforeInvokeInterface;
use Neos\EventSourcing\EventStore\EventEnvelope;
use Neos\EventSourcing\EventStore\RawEvent;
use Neos\EventSourcing\Projection\ProjectorInterface;

final class EventLogProjector implements ProjectorInterface, BeforeInvokeInterface, AfterInvokeInterface
{
    private EventLogRepository $repository;

    public function __construct(EventLogRepository $repository)
    {
        $this->repository = $repository;
    }

    public function beforeInvoke(EventEnvelope $eventEnvelope): void
    {
        $this->repository->beginTransaction();
    }

    public function afterInvoke(EventEnvelope $_): void
    {
        $this->repository->commitTransaction();
    }

    public function whenNodeAggregateNameWasChanged(NodeAggregateNameWasChanged $event, RawEvent $rawEvent): void
    {
        foreach ($this->repository->getNodeVariantsById($event->getNodeAggregateIdentifier()) as $node) {
            $this->repository->insertEvent($rawEvent, $event->getNodeAggregateIdentifier(), $event->getContentStreamIdentifier(), $node->getDimensionSpacePointHash(), $event->getInitiatingUserIdentifier());
        }
    }

    public function whenNodeAggregateTypeWasChanged(NodeAggregateTypeWasChanged $event, RawEvent $rawEvent): void
    {
        foreach ($this->repository->getNodeVariantsById($event->getNodeAggregateIdentifier()) as $node) {
            // TODO this event misses a getInitiatingUserIdentifier()
            $this->repository->insertEvent($rawEvent, $event->getNodeAggregateIdentifier(), $event->getContentStreamIdentifier(), $node->getDimensionSpacePointHash(), UserIdentifier::fromString('unknown'));
        }
    }

    public function whenNodeAggregateWasDisabled(NodeAggregateWasDisabled $event, RawEvent $rawEvent): void
    {
        foreach ($event->getAffectedDimensionSpacePoints() as $dimensionSpacePoint) {
            $this->repository->insertEvent($rawEvent, $event->getNodeAggregateIdentifier(), $event->getContentStreamIdentifier(), $dimensionSpacePoint->getHash(), $event->getInitiatingUserIdentifier());
            $node = $this->repository->findNodeByIdAndDimensionSpacePointHash($event->getNodeAggregateIdentifier(), $dimensionSpacePoint->getHash());
            if ($node === null) {
                // This should not happen
                continue;
            }

            # node is already explicitly disabled
            if ($this->repository->isNodeExplicitlyDisabled($node)) {
                return;
            }

            $this->repository->insertInheritedEvents($rawEvent, $event->getInitiatingUserIdentifier(), $node, 'disabled = ' . $node->getDisableLevel());

            $this->repository->updateNodeQuery('SET disabled = disabled + 1 WHERE dimensionSpacePointHash = :dimensionSpacePointHash AND (nodeAggregateIdentifier = :nodeAggregateIdentifier OR nodeAggregateIdentifierPath LIKE :childNodeAggregateIdentifierPathPrefix)',
                [
                    'dimensionSpacePointHash' => $dimensionSpacePoint->getHash(),
                    'nodeAggregateIdentifier' => $event->getNodeAggregateIdentifier(),
                    'childNodeAggregateIdentifierPathPrefix' => $node->getNodeAggregateIdentifierPath() . '/%',
                ]);
        }
    }

    public function whenNodeAggregateWasEnabled(NodeAggregateWasEnabled $event, RawEvent $rawEvent): void
    {
        foreach ($event->getAffectedDimensionSpacePoints() as $dimensionSpacePoint) {

            $this->repository->insertEvent($rawEvent, $event->getNodeAggregateIdentifier(), $event->getContentStreamIdentifier(), $dimensionSpacePoint->getHash(), $event->getInitiatingUserIdentifier());

            $node = $this->repository->findNodeByIdAndDimensionSpacePointHash($event->getNodeAggregateIdentifier(), $dimensionSpacePoint->getHash());
            if ($node === null) {
                // This should not happen
                continue;
            }
            # node is not explicitly disabled, so we must not re-enable it
            if (!$this->repository->isNodeExplicitlyDisabled($node)) {
                return;
            }

            $this->repository->insertInheritedEvents($rawEvent, $event->getInitiatingUserIdentifier(), $node, 'disabled = ' . $node->getDisableLevel());

            $this->repository->updateNodeQuery('SET disabled = disabled - 1 WHERE dimensionSpacePointHash = :dimensionSpacePointHash AND (nodeAggregateIdentifier = :nodeAggregateIdentifier OR nodeAggregateIdentifierPath LIKE :childNodeAggregateIdentifierPathPrefix)',
                [
                    'dimensionSpacePointHash' => $dimensionSpacePoint->getHash(),
                    'nodeAggregateIdentifier' => $event->getNodeAggregateIdentifier(),
                    'childNodeAggregateIdentifierPathPrefix' => $node->getNodeAggregateIdentifierPath() . '/%',
                ]);
        }
    }

    public function whenNodeAggregateWasMoved(NodeAggregateWasMoved $event, RawEvent $rawEvent): void
    {
        foreach ($event->getNodeMoveMappings() as $moveMapping) {
            foreach ($this->repository->getNodeVariantsById($event->getNodeAggregateIdentifier()) as $node) {
                $parentAssignment = $moveMapping->getNewParentAssignments()->getAssignments()[$node->getDimensionSpacePointHash()] ?? null;
                $newParentNodeAggregateIdentifier = $parentAssignment !== null ? $parentAssignment->getNodeAggregateIdentifier() : $node->getParentNodeAggregateIdentifier();

                $newParentNode = $this->repository->findNodeByIdAndDimensionSpacePointHash($newParentNodeAggregateIdentifier, $node->getDimensionSpacePointHash());
                if ($newParentNode === null) {
                    // This should never happen really..
                    return;
                }

                $this->repository->insertEvent($rawEvent, $node->getNodeAggregateIdentifier(), $event->getContentStreamIdentifier(), $node->getDimensionSpacePointHash(), $event->getInitiatingUserIdentifier());
                $this->repository->insertInheritedEvents($rawEvent, $event->getInitiatingUserIdentifier(), $node);


                $this->repository->updateNodeQuery(
                    'SET
                        nodeAggregateIdentifierPath = TRIM(TRAILING "/" FROM CONCAT(:newParentNodeAggregateIdentifierPath, "/", TRIM(LEADING "/" FROM SUBSTRING(nodeAggregateIdentifierPath, :sourceNodeAggregateIdentifierPathOffset))))
                    WHERE
                        dimensionSpacePointHash = :dimensionSpacePointHash
                        AND (nodeAggregateIdentifier = :nodeAggregateIdentifier OR nodeAggregateIdentifierPath LIKE :childNodeAggregateIdentifierPathPrefix)
                    ',
                    [
                        'nodeAggregateIdentifier' => $node->getNodeAggregateIdentifier(),
                        'newParentNodeAggregateIdentifierPath' => $newParentNode->getNodeAggregateIdentifierPath(),
                        'sourceNodeAggregateIdentifierPathOffset' => (int)strrpos($node->getNodeAggregateIdentifierPath(), '/') + 1,
                        'dimensionSpacePointHash' => $node->getDimensionSpacePointHash(),
                        'childNodeAggregateIdentifierPathPrefix' => $node->getNodeAggregateIdentifierPath() . '/%',
                    ]
                );

            }
        }
    }

    public function whenNodeAggregateWasRemoved(NodeAggregateWasRemoved $event, RawEvent $rawEvent): void
    {
        foreach ($event->getAffectedCoveredDimensionSpacePoints() as $dimensionSpacePoint) {

            $this->repository->insertEvent($rawEvent, $event->getNodeAggregateIdentifier(), $event->getContentStreamIdentifier(), $dimensionSpacePoint->getHash(), $event->getInitiatingUserIdentifier());

            $node = $this->repository->findNodeByIdAndDimensionSpacePointHash($event->getNodeAggregateIdentifier(), $dimensionSpacePoint->getHash());
            if ($node === null) {
                // Probably not a document node
                continue;
            }

            $this->repository->insertInheritedEvents($rawEvent, $event->getInitiatingUserIdentifier(), $node);

            $this->repository->deleteNodeQuery('WHERE dimensionSpacePointHash = :dimensionSpacePointHash AND (nodeAggregateIdentifier = :nodeAggregateIdentifier OR nodeAggregateIdentifierPath LIKE :childNodeAggregateIdentifierPathPrefix)', [
                'dimensionSpacePointHash' => $dimensionSpacePoint->getHash(),
                'nodeAggregateIdentifier' => $node->getNodeAggregateIdentifier(),
                'childNodeAggregateIdentifierPathPrefix' => $node->getNodeAggregateIdentifierPath() . '/%',
            ]);
        }
    }

    public function whenNodeAggregateWithNodeWasCreated(NodeAggregateWithNodeWasCreated $event, RawEvent $rawEvent): void
    {
        foreach ($event->getCoveredDimensionSpacePoints() as $dimensionSpacePoint) {
            $parentNode = $this->repository->findNodeByIdAndDimensionSpacePointHash($event->getParentNodeAggregateIdentifier(), $dimensionSpacePoint->getHash());
            if ($parentNode === null) {
                // this should not happen
                continue;
            }
            $nodeAggregateIdentifierPath = $parentNode->getNodeAggregateIdentifierPath() . '/' . $event->getNodeAggregateIdentifier();
            $this->repository->insertNode([
                'nodeAggregateIdentifier' => $event->getNodeAggregateIdentifier(),
                'contentStreamIdentifier' => $event->getContentStreamIdentifier(),
                'nodeAggregateIdentifierPath' => $nodeAggregateIdentifierPath,
                'dimensionSpacePointHash' => $dimensionSpacePoint->getHash(),
                'originDimensionSpacePointHash' => $event->getOriginDimensionSpacePoint()->getHash(),
                'parentNodeAggregateIdentifier' => $parentNode->getNodeAggregateIdentifier(),
            ]);
            $this->repository->insertEvent($rawEvent, $event->getNodeAggregateIdentifier(), $event->getContentStreamIdentifier(), $dimensionSpacePoint->getHash(), $event->getInitiatingUserIdentifier());
        }
    }

    public function whenNodeGeneralizationVariantWasCreated(NodeGeneralizationVariantWasCreated $event, RawEvent $rawEvent): void
    {
        foreach ($event->getGeneralizationCoverage() as $dimensionSpacePoint) {
            $this->repository->insertEvent($rawEvent, $event->getNodeAggregateIdentifier(), $event->getContentStreamIdentifier(), $dimensionSpacePoint->getHash(), $event->getInitiatingUserIdentifier());
        }
        $this->repository->copyVariants($event->getNodeAggregateIdentifier(), $event->getSourceOrigin(), $event->getGeneralizationOrigin(), $event->getGeneralizationCoverage());
    }

    public function whenNodePeerVariantWasCreated(NodePeerVariantWasCreated $event, RawEvent $rawEvent): void
    {
        foreach ($event->getPeerCoverage() as $dimensionSpacePoint) {
            $this->repository->insertEvent($rawEvent, $event->getNodeAggregateIdentifier(), $event->getContentStreamIdentifier(), $dimensionSpacePoint->getHash(), $event->getInitiatingUserIdentifier());
        }
        $this->repository->copyVariants($event->getNodeAggregateIdentifier(), $event->getSourceOrigin(), $event->getPeerOrigin(), $event->getPeerCoverage());
    }

    public function whenNodePropertiesWereSet(NodePropertiesWereSet $event, RawEvent $rawEvent): void
    {
        $this->repository->insertEvent($rawEvent, $event->getNodeAggregateIdentifier(), $event->getContentStreamIdentifier(), $event->getOriginDimensionSpacePoint()->getHash(), $event->getInitiatingUserIdentifier());
    }

    public function whenNodeReferencesWereSet(NodeReferencesWereSet $event, RawEvent $rawEvent): void
    {
        $this->repository->insertEvent($rawEvent, $event->getSourceNodeAggregateIdentifier(), $event->getContentStreamIdentifier(), $event->getSourceOriginDimensionSpacePoint()->getHash(), $event->getInitiatingUserIdentifier());
        foreach ($event->getDestinationNodeAggregateIdentifiers() as $destinationNodeAggregateIdentifier) {
            $this->repository->insertEvent($rawEvent, $destinationNodeAggregateIdentifier, $event->getContentStreamIdentifier(), $event->getSourceOriginDimensionSpacePoint()->getHash(), $event->getInitiatingUserIdentifier());
        }
    }

    public function whenNodeSpecializationVariantWasCreated(NodeSpecializationVariantWasCreated $event, RawEvent $rawEvent): void
    {
        foreach ($event->getSpecializationCoverage() as $dimensionSpacePoint) {
            $this->repository->insertEvent($rawEvent, $event->getNodeAggregateIdentifier(), $event->getContentStreamIdentifier(), $dimensionSpacePoint->getHash(), $event->getInitiatingUserIdentifier());
        }
        $this->repository->copyVariants($event->getNodeAggregateIdentifier(), $event->getSourceOrigin(), $event->getSpecializationOrigin(), $event->getSpecializationCoverage());
    }

    public function whenRootNodeAggregateWithNodeWasCreated(RootNodeAggregateWithNodeWasCreated $event, RawEvent $rawEvent): void
    {
        foreach ($event->getCoveredDimensionSpacePoints() as $dimensionSpacePoint) {
            $this->repository->insertNode([
                'nodeaggregateidentifier' => $event->getNodeAggregateIdentifier(),
                'contentstreamidentifier' => $event->getContentStreamIdentifier(),
                'dimensionspacepointhash' => $dimensionSpacePoint->getHash(),
                'nodeaggregateidentifierpath' => $event->getNodeAggregateIdentifier(),
            ]);
            $this->repository->insertEvent($rawEvent, $event->getNodeAggregateIdentifier(), $event->getContentStreamIdentifier(), $dimensionSpacePoint->getHash(), $event->getInitiatingUserIdentifier());
        }
    }

    public function reset(): void
    {
        $this->repository->removeAll();
    }

}
