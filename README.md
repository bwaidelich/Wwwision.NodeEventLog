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

## Attribution

The development of this package has been kindly supported by "Swiss Army Knife Cloud Solutions B.V."
