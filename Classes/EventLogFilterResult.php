<?php
declare(strict_types=1);
namespace Wwwision\NodeEventLog;

use Doctrine\DBAL\Query\QueryBuilder;
use Neos\Flow\Annotations as Flow;
use Wwwision\NodeEventLog\Projection\NodeEvent;
use Wwwision\RelayPagination\Connection\Connection;
use Wwwision\RelayPagination\Loader\DbalLoader;
use Wwwision\RelayPagination\Paginator;

/**
 * The result of an EventLog::filter() call.
 *
 * This object can be used to count the total number of events matching the specified filter
 * and to navigate the log forwards and backwards:
 *
 * @Flow\Proxy(false)
 */
final class EventLogFilterResult implements \Countable
{
    private QueryBuilder $queryBuilder;
    private \Closure $nodeConverter;
    private ?int $count = null;
    private bool $reverse = false;

    public function __construct(QueryBuilder $queryBuilder)
    {
        $this->queryBuilder = $queryBuilder;
        $this->nodeConverter = static fn(array $row) => NodeEvent::fromDatabaseRow($row);
    }

    public function withNodeConverter(\Closure $nodeConverter): self
    {
        $newInstance = clone $this;
        $newInstance->nodeConverter = $nodeConverter;
        return $newInstance;
    }

    public function reversed(): self
    {
        $newInstance = clone $this;
        $newInstance->reverse = true;
        return $newInstance;
    }

    public function first(int $first, string $after = null): Connection
    {
        return $this->paginator()->first($first, $after);
    }

    public function last(int $last, string $before = null): Connection
    {
        return $this->paginator()->last($last, $before);
    }

    private function paginator(): Paginator
    {
        $paginator = new Paginator(new DbalLoader($this->queryBuilder, 'id'));
        $paginator = $paginator->withNodeConverter($this->nodeConverter);
        if ($this->reverse) {
            $paginator = $paginator->reversed();
        }
        return $paginator;
    }

    public function count(): int
    {
        if ($this->count !== null) {
            return $this->count;
        }
        $queryBuilder = clone $this->queryBuilder;
        $this->count = (int)$queryBuilder
            ->select('COUNT(*)')
            ->execute()
            ->fetchOne();
        return $this->count;
    }
}
