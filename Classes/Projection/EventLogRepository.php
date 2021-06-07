<?php
declare(strict_types=1);
namespace Wwwision\NodeEventLog\Projection;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ConnectionException;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\DBAL\Query\QueryBuilder;
use Neos\ContentRepository\DimensionSpace\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\DimensionSpace\DimensionSpace\DimensionSpacePointSet;
use Neos\ContentRepository\Domain\ContentStream\ContentStreamIdentifier;
use Neos\ContentRepository\Domain\NodeAggregate\NodeAggregateIdentifier;
use Neos\EventSourcedContentRepository\Domain\ValueObject\UserIdentifier;
use Neos\EventSourcedContentRepository\Domain\ValueObject\WorkspaceName;
use Neos\EventSourcing\EventStore\RawEvent;
use Neos\Flow\Annotations as Flow;
use Wwwision\NodeEventLog\EventLogFilter;

/**
 * @Flow\Scope("singleton")
 */
final class EventLogRepository
{
    private Connection $dbal;

    private const TABLE_NAME_HIERARCHY = 'wwwision_nodeeventlog_projection_hierarchy';
    private const TABLE_NAME_EVENT = 'wwwision_nodeeventlog_projection_event';
    private const TABLE_NAME_WORKSPACE = 'wwwision_nodeeventlog_projection_workspace';

    public function __construct(Connection $dbal)
    {
        $this->dbal = $dbal;
    }

    public function beginTransaction(): void
    {
        $this->dbal->beginTransaction();
    }

    public function commitTransaction(): void
    {
        try {
            $this->dbal->commit();
        } catch (ConnectionException $e) {
            throw new \RuntimeException(sprintf('Failed to commit transaction in %s: %s', __METHOD__, $e->getMessage()), 1622721251, $e);
        }
    }

    public function removeAll(): void
    {
        try {
            $this->dbal->executeStatement('TRUNCATE ' . self::TABLE_NAME_HIERARCHY);
            $this->dbal->executeStatement('TRUNCATE ' . self::TABLE_NAME_EVENT);
        } catch (DBALException $e) {
            throw new \RuntimeException(sprintf('Failed to truncate tables: %s', $e->getMessage()), 1622475311, $e);
        }
    }

    public function isNodeExplicitlyDisabled(Node $node): bool
    {
        if (!$node->isDisabled()) {
            return false;
        }
        $parentNode = $this->findNodeByIdAndDimensionSpacePointHash($node->getParentNodeAggregateIdentifier(), $node->getDimensionSpacePointHash());
        $parentDisabledLevel = $parentNode !== null ? $parentNode->getDisableLevel() : 0;
        return $node->getDisableLevel() - $parentDisabledLevel !== 0;
    }

    public function copyVariants(NodeAggregateIdentifier $nodeAggregateIdentifier, DimensionSpacePoint $sourceOrigin, DimensionSpacePoint $targetOrigin, DimensionSpacePointSet $coveredSpacePoints): void
    {
        $sourceNode = $this->findNodeByIdAndDimensionSpacePointHash($nodeAggregateIdentifier, $sourceOrigin->getHash());
        if ($sourceNode === null) {
            // Probably not a document node
            return;
        }
        foreach ($coveredSpacePoints as $coveredSpacePoint) {
            // Especially when importing a site it can happen that variants are created in a "non-deterministic" order, so we need to first make sure a target variant doesn't exist:
            $this->deleteNodeByIdAndDimensionSpacePointHash($nodeAggregateIdentifier, $coveredSpacePoint->getHash());

            $this->insertNode(
                $sourceNode
                    ->withDimensionSpacePoint($coveredSpacePoint)
                    ->withOriginDimensionSpacePoint($targetOrigin)
                    ->toArray()
            );
        }
    }

    public function deleteNodeByIdAndDimensionSpacePointHash(NodeAggregateIdentifier $nodeAggregateIdentifier, string $dimensionSpacePointHash): void
    {
        try {
            $this->dbal->delete(self::TABLE_NAME_HIERARCHY, compact('nodeAggregateIdentifier', 'dimensionSpacePointHash'));
        } catch (DBALException $e) {
            throw new \RuntimeException(sprintf('Failed to delete node "%s": %s', $nodeAggregateIdentifier, $e->getMessage()), 1622639758, $e);
        }
    }

    public function insertNode(array $data): void
    {
        try {
            $this->dbal->insert(self::TABLE_NAME_HIERARCHY, $data);
        } catch (DBALException $e) {
            throw new \RuntimeException(sprintf('Failed to insert node: %s', $e->getMessage()), 1599646694, $e);
        }
    }

    public function insertEvent(RawEvent $rawEvent, NodeAggregateIdentifier $nodeAggregateIdentifier, ContentStreamIdentifier $contentStreamIdentifier, string $dimensionSpacePointHash, UserIdentifier $initiatingUserIdentifier): void
    {
        $data = compact('nodeAggregateIdentifier', 'contentStreamIdentifier', 'dimensionSpacePointHash');
        $data['eventId'] = $rawEvent->getIdentifier();
        $data['eventType'] = $rawEvent->getType();
        $data['payload'] = $this->encode($rawEvent->getPayload());
        $data['initiatingUserId'] = $initiatingUserIdentifier;
        $data['recordedAt'] = $rawEvent->getRecordedAt()->format('Y-m-d H:i:s');
        try {
            $this->dbal->insert(self::TABLE_NAME_EVENT, $data);
        } catch (DBALException $e) {
            throw new \RuntimeException(sprintf('Failed to insert node: %s', $e->getMessage()), 1622632027, $e);
        }
    }

    public function insertInheritedEvents(RawEvent $rawEvent, UserIdentifier $initiatingUserIdentifier, Node $node, string $andWhere = null): void
    {
        try {
            $this->dbal->executeQuery('
                INSERT INTO ' . self::TABLE_NAME_EVENT . '
                    (nodeAggregateIdentifier, contentStreamIdentifier, dimensionSpacePointHash, eventId, eventType, payload, initiatingUserId, recordedAt, inherited)
                SELECT nodeAggregateIdentifier, contentStreamIdentifier, :dimensionSpacePointHash, :eventId, :eventType, :eventPayload, :initiatingUserId,  :recordedAt, 1
                FROM ' . self::TABLE_NAME_HIERARCHY . '
                WHERE
                    dimensionSpacePointHash = :dimensionSpacePointHash
                    AND nodeAggregateIdentifierPath LIKE :childNodeAggregateIdentifierPathPrefix
                    ' . ($andWhere !== null ? 'AND ' . $andWhere : '')
                , [
                    'nodeAggregateIdentifier' => $node->getNodeAggregateIdentifier(),
                    'dimensionSpacePointHash' => $node->getDimensionSpacePointHash(),
                    'childNodeAggregateIdentifierPathPrefix' => $node->getNodeAggregateIdentifierPath() . '/%',
                    'eventId' => $rawEvent->getIdentifier(),
                    'eventType' => $rawEvent->getType(),
                    'eventPayload' => $this->encode($rawEvent->getPayload()),
                    'initiatingUserId' => $initiatingUserIdentifier,
                    'recordedAt' => $rawEvent->getRecordedAt()->format('Y-m-d H:i:s'),
                ]);
        } catch (DBALException $e) {
            throw new \RuntimeException(sprintf('Failed to insert inherited events: %s', $e->getMessage()), 1622721880, $e);
        }
    }

    public function insertWorkspace(WorkspaceName $workspaceName, ContentStreamIdentifier $contentStreamIdentifier): void
    {
        try {
            $this->dbal->insert(self::TABLE_NAME_WORKSPACE, compact('workspaceName', 'contentStreamIdentifier'));
        } catch (DBALException $e) {
            throw new \RuntimeException(sprintf('Failed to insert workspace: %s', $e->getMessage()), 1623072264, $e);
        }
    }

    public function findNodeByIdAndDimensionSpacePointHash(NodeAggregateIdentifier $nodeAggregateIdentifier, string $dimensionSpacePointHash): ?Node
    {
        # NOTE: "LIMIT 1" in the following query is just a performance optimization since Connection::fetchAssoc() only returns the first result anyways
        try {
            $row = $this->dbal->fetchAssociative('SELECT * FROM ' . self::TABLE_NAME_HIERARCHY . ' WHERE nodeAggregateIdentifier = :nodeAggregateIdentifier AND dimensionSpacePointHash = :dimensionSpacePointHash LIMIT 1', compact('nodeAggregateIdentifier', 'dimensionSpacePointHash'));
        } catch (DBALException $e) {
            throw new \RuntimeException(sprintf('Failed to load node for id "%s" and dimension space point hash "%s": %s', $nodeAggregateIdentifier, $dimensionSpacePointHash, $e->getMessage()), 1622475855, $e);
        }
        if ($row === false) {
            return null;
        }
        return Node::fromDatabaseRow($row);
    }

    /**
     * @param NodeAggregateIdentifier $nodeAggregateIdentifier
     * @return \Iterator|Node[]
     */
    public function getNodeVariantsById(NodeAggregateIdentifier $nodeAggregateIdentifier): \Iterator
    {
        try {
            $iterator = $this->dbal->executeQuery('SELECT * FROM ' . self::TABLE_NAME_HIERARCHY . ' WHERE nodeAggregateIdentifier = :nodeAggregateIdentifier', ['nodeAggregateIdentifier' => $nodeAggregateIdentifier]);
        } catch (DBALException $e) {
            throw new \RuntimeException(sprintf('Failed to get node variants for id "%s": %s', $nodeAggregateIdentifier, $e->getMessage()), 1622633383, $e);
        }
        foreach ($iterator as $data) {
            yield Node::fromDatabaseRow($data);
        }
    }

    public function updateNodeQuery(string $query, array $parameters): void
    {
        try {
            $this->dbal->executeQuery('UPDATE ' . self::TABLE_NAME_HIERARCHY . ' ' . $query, $parameters);
        } catch (DBALException $e) {
            throw new \RuntimeException(sprintf('Failed to update node via custom query: %s', $e->getMessage()), 1622634601, $e);
        }
    }

    public function deleteNodeQuery(string $query, array $parameters): void
    {
        try {
            $this->dbal->executeQuery('DELETE FROM ' . self::TABLE_NAME_HIERARCHY . ' ' . $query, $parameters);
        } catch (DBALException $e) {
            throw new \RuntimeException(sprintf('Failed to delete node via custom query: %s', $e->getMessage()), 1622646030, $e);
        }
    }

    private function encode(array $data): string
    {
        try {
            return json_encode($data, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(sprintf('Failed to JSON encode data: %s', $e->getMessage()), 1622721782, $e);
        }
    }

    public function filter(EventLogFilter $filter): QueryBuilder
    {
        $filterData = $filter->toArray();
        $queryBuilder = new QueryBuilder($this->dbal);
        $queryBuilder = $queryBuilder
            ->select('*')
            ->from(self::TABLE_NAME_EVENT)
            ->orderBy('id', 'DESC');
        if (isset($filterData['nodeId'])) {
            if ($filterData['recursively'] === true) {
                $queryBuilder = $queryBuilder->andWhere($queryBuilder->expr()->in('nodeAggregateIdentifier', 'SELECT h.nodeAggregateIdentifier FROM ' . self::TABLE_NAME_HIERARCHY . ' h JOIN ' . self::TABLE_NAME_HIERARCHY . ' h2 ON (h2.nodeAggregateIdentifier = :nodeId) WHERE (h.nodeAggregateIdentifier = :nodeId OR h.nodeAggregateIdentifierPath LIKE CONCAT(h2.nodeAggregateIdentifierPath, "/%"))'));
            } else {
                $queryBuilder = $queryBuilder->andWhere('nodeAggregateIdentifier = :nodeId');
            }
            $queryBuilder = $queryBuilder->setParameter('nodeId', $filterData['nodeId']);
        }
        if (isset($filterData['contentStreamId'])) {
            $queryBuilder = $queryBuilder->andWhere('contentStreamIdentifier = :contentStreamId')->setParameter('contentStreamId', $filterData['contentStreamId']);
        } elseif (isset($filterData['workspaceName'])) {
            $queryBuilder = $queryBuilder->andWhere($queryBuilder->expr()->eq('contentStreamIdentifier', '(SELECT contentStreamIdentifier FROM ' . self::TABLE_NAME_WORKSPACE . ' WHERE workspaceName = :workspaceName)'))->setParameter('workspaceName', $filterData['workspaceName']);
        }
        if (isset($filterData['dimensionSpacePointHash'])) {
            $queryBuilder = $queryBuilder->andWhere('dimensionSpacePointHash = :dimensionSpacePointHash')->setParameter('dimensionSpacePointHash', $filterData['dimensionSpacePointHash']);
        }
        if (isset($filterData['initiatingUserId'])) {
            $queryBuilder = $queryBuilder->andWhere('initiatingUserId = :initiatingUserId')->setParameter('initiatingUserId', $filterData['initiatingUserId']);
        }
        if ($filterData['skipInheritedEvents'] === true) {
            $queryBuilder = $queryBuilder->andWhere('inherited = 0');
        }
        return $queryBuilder;
    }

}
