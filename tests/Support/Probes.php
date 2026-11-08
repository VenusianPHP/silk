<?php

namespace Venusian\Silk\Tests\Support;

use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Core\Layout\Column;
use Silk\Core\Layout\Spacer;

/** Records every event; handles (returns true for) the types in $handles. */
trait RecordsEvents
{
    /** @var list<SilkEvent> */
    public array $events = [];

    /** @var list<EventType> */
    public array $handles = [];

    public function handle(SilkEvent $e): bool
    {
        $this->events[] = $e;

        return in_array($e->type, $this->handles, true);
    }

    /** @return list<string> the recorded event type names */
    public function types(): array
    {
        return array_map(fn (SilkEvent $e) => $e->type->name, $this->events);
    }
}

class ProbeLeaf extends Spacer
{
    use RecordsEvents;
}

class ProbeColumn extends Column
{
    use RecordsEvents;
}
