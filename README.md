# Wwwision.NodeEventLog

Log for nodes in the Event Sourced Content Repository

## Installation

Install via [composer](https://getcomposer.org/):

    composer require wwwision/node-event-log

Afterwards make sure to execute doctrine migrations:

    ./flow doctrine:migrate

The `EventLogProjector` is registered as event listener for the "ContentRepository" event store and
will be updated whenever new events are published.

To initialize the projector with already published events, the `projection:replay` command can be used:

    ./flow projection:replay eventlog

## Usage

### Filtering

The event log can be filtered by numerous attributes including:
* The affected **node** (recursively or not)
* The **content stream**
* The **initiating user**
* The **dimension space point**

#### Example:

```php
$recursively = true;
$filter = EventLogFilter::create()
    ->forNode(NodeAggregateIdentifier::fromString('some-node-id'), $recursively)
    ->inContentStream(ContentStreamIdentifier::fromString('some-content-stream-id'))
    ->forInitiatingUser(UserIdentifier::fromString('some-user-id'))
    ->skipInheritedEvents();
```

### Render first 5 event types

```php
$result = $this->eventLog->filter(EventLogFilter::create());
echo 'Total number of results: ' . $result->count() . PHP_EOL;
/** @var NodeEvent $event */
foreach ($result->first(5)->toNodeArray() as $event) {
    echo $event->eventType() . PHP_EOL;
}
```

#### Output (depending on the actual events)

```
Total number of results: 123
Neos.EventSourcedContentRepository:RootNodeAggregateWithNodeWasCreated
Neos.EventSourcedContentRepository:NodeAggregateWithNodeWasCreated
Neos.EventSourcedContentRepository:NodeAggregateWithNodeWasCreated
Neos.EventSourcedContentRepository:NodeAggregateWithNodeWasCreated
Neos.EventSourcedContentRepository:NodePropertiesWereSet
```

### Paginate results in descending order

```php
$result = $this->eventLog->filter(EventLogFilter::create())->reversed();
$after = null;
do {
    $page = $result->first(3, $after);
    foreach ($page as $edge) {
        /** @var NodeEvent $event */
        $event = $edge->node();
        echo $event->id() . PHP_EOL;
    }
    echo '----' . PHP_EOL;
    $after = $page->pageInfo()->endCursor();
} while ($page->pageInfo()->hasNextPage());
```

#### Output (depending on the actual events)

```
8
7
6
----
5
4
3
----
2
1
----
```

### Custom event renderer

```php
$result = $this->eventLog->filter(EventLogFilter::create())
    ->withNodeConverter(fn(array $event) => $event['id']);
echo implode(',', $result->first(10)->toNodeArray());
```

#### Output

```
1,2,3,4,5,6,7,8,9,10
```

## CLI

This package comes with a `nodeeventlog:show` command that allows for filtering & rendering the event log in the CLI:

```
./flow help nodeeventlog:show

Output activities with the specified filters applied

COMMAND:
  wwwision.nodeeventlog:nodeeventlog:show

USAGE:
  ./flow nodeeventlog:show [<options>]

OPTIONS:
  --node               id of the node to fetch activities for
                       (NodeAggregateIdentifier)
  --content-stream     id of the content stream to filter for
                       (ContentStreamIdentifier)
  --user               id of the initiating user to filter for (UserIdentifier)
  --dimension          JSON string representing the dimensions space point to
                       filter for (DimensionSpacePoint)
  --recursively        If set activities for all child nodes will be fetched as
                       well (recursively) – this is only evaluated if
                       "--node" is specified, too
  --skip-inherited-events If set only explicit events are considered. Otherwise
                       "inherited" events, e.g. for disabled/removed/moved
                       nodes are included in the result
  --reverse            If set the order of the event log is reversed to show
                       the events in the order they occured
  --first              How many events to display at once (default: 10)
  --after              Only fetch events after the specified cursor (only
                       applicable in conjunction with --first)
  --last               How many events to display at once (default: 10)
  --before             Only fetch events before the specified cursor (only
                       applicable in conjunction with --first)
```

## Attribution

The development of this package has been kindly supported by "Swiss Army Knife Cloud Solutions B.V."
