<?php
declare(strict_types=1);
namespace Wwwision\NodeEventLog\Command;

use Neos\ContentRepository\DimensionSpace\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Domain\ContentStream\ContentStreamIdentifier;
use Neos\ContentRepository\Domain\NodeAggregate\NodeAggregateIdentifier;
use Neos\EventSourcedContentRepository\Domain\ValueObject\UserIdentifier;
use Neos\EventSourcedContentRepository\Domain\ValueObject\WorkspaceName;
use Neos\Flow\Cli\CommandController;
use Wwwision\NodeEventLog\EventLog;
use Wwwision\NodeEventLog\EventLogFilter;

final class NodeEventLogCommandController extends CommandController
{
    private EventLog $eventLog;

    public function __construct(EventLog $eventLog)
    {
        parent::__construct();
        $this->eventLog = $eventLog;
    }

    /**
     * Output activities with the specified filters applied
     *
     * @param string|null $node id of the node to fetch activities for (NodeAggregateIdentifier)
     * @param string|null $contentStream id of the content stream to filter for (ContentStreamIdentifier)
     * @param string|null $workspace name of the workspace to filter for (WorkspaceName)
     * @param string|null $user id of the initiating user to filter for (UserIdentifier)
     * @param string|null $dimension JSON string representing the dimensions space point to filter for (DimensionSpacePoint)
     * @param bool $recursively If set activities for all child nodes will be fetched as well (recursively) – this is only evaluated if "--node" is specified, too
     * @param bool $skipInheritedEvents If set only explicit events are considered. Otherwise "inherited" events, e.g. for disabled/removed/moved nodes are included in the result
     * @param bool $reverse If set the order of the event log is reversed to show the events in the order they occured
     * @param int|null $first How many events to display at once (default: 10)
     * @param string|null $after Only fetch events after the specified cursor (only applicable in conjunction with --first)
     * @param int|null $last How many events to display at once (default: 10)
     * @param string|null $before Only fetch events before the specified cursor (only applicable in conjunction with --first)
     */
    public function showCommand(string $node = null, string $contentStream = null, string $workspace = null, string $user = null, string $dimension = null, bool $recursively = false, bool $skipInheritedEvents = false, bool $reverse = false, int $first = null, string $after = null, int $last = null, string $before = null): void
    {
        $filter = EventLogFilter::create();
        if ($node !== null) {
            $filter = $filter->forNode(NodeAggregateIdentifier::fromString($node), $recursively);
        }
        if ($contentStream !== null) {
            $filter = $filter->inContentStream(ContentStreamIdentifier::fromString($contentStream));
        }
        if ($workspace !== null) {
            $filter = $filter->inWorkspace(new WorkspaceName($workspace));
        }
        if ($user !== null) {
            $filter = $filter->forInitiatingUser(UserIdentifier::fromString($user));
        }
        if ($dimension !== null) {
            $filter = $filter->forDimension(DimensionSpacePoint::fromJsonString($dimension));
        }
        if ($skipInheritedEvents) {
            $filter = $filter->skipInheritedEvents();
        }

        $defaultEdgesPerPage = 15;
        $result = $this->eventLog->filter($filter);
        $result = $result->withNodeConverter(fn(array $row) => [
            'id' => $row['id'],
            'node' => $row['nodeaggregateidentifier'],
            'event' => substr($row['eventtype'], strrpos($row['eventtype'], ':') + 1),
            'contentstream' => $row['contentstreamidentifier'],
            'dimension' => $row['dimensionspacepointhash'],
            'recordedat' => $row['recordedat'],
            'user' => $row['initiatinguserid']
        ]);
        if ($reverse) {
            $result = $result->reversed();
        }
        if ($last !== null) {
            $connection = $result->last($first, $before);
        } else {
            $connection = $result->first($first ?? $defaultEdgesPerPage, $after);
        }

        $this->outputLine('Found <b>%d</b> result(s)', [$result->count()]);
        do {
            $this->output->outputTable($connection->toNodeArray(), ['#', 'Node', 'Event', 'Content Stream', 'Dimension', 'Recorded at', 'User']);

            $choices = ['cancel'];
            if ($connection->pageInfo()->hasPreviousPage()) {
                $choices[] = 'prev';
            }
            if ($connection->pageInfo()->hasNextPage()) {
                $choices[] = 'next';
            }
            $choice = $this->output->select('Paginate', $choices);
            if ($choice === 'cancel') {
                $this->quit();
            }
            if ($choice === 'next') {
                $connection = $result->first($first ?? $defaultEdgesPerPage, $connection->pageInfo()->endCursor());
            } elseif ($choice === 'prev') {
                $connection = $result->last($last ?? $defaultEdgesPerPage, $connection->pageInfo()->startCursor());
            }
        } while (true);
    }
}
