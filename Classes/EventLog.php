<?php
declare(strict_types=1);
namespace Wwwision\NodeEventLog;

use Neos\Flow\Annotations as Flow;
use Wwwision\NodeEventLog\Projection\EventLogRepository;

/**
 * @Flow\Scope("singleton")
 */
final class EventLog
{
    private EventLogRepository $repository;

    public function __construct(EventLogRepository $repository)
    {
        $this->repository = $repository;
    }


    public function filter(EventLogFilter $filter): EventLogFilterResult
    {
        return new EventLogFilterResult($this->repository->filter($filter));
    }

}
