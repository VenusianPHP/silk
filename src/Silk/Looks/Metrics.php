<?php

namespace Silk\Looks;

use InvalidArgumentException;
use Silk\Contracts\Look\Metric;

/** Metric => logical units. */
class Metrics
{
    /** @var array<string, float> */
    private array $values = [];

    public function __construct(array $values = [])
    {
        $this->merge($values);
    }

    public function merge(array $values): static
    {
        foreach ($values as $name => $v) {
            $name = $name instanceof Metric ? $name->name : $name;
            if (! defined(Metric::class.'::'.$name)) {
                throw new InvalidArgumentException("'{$name}' is not a metric.");
            }
            $this->values[$name] = (float) $v;
        }

        return $this;
    }

    public function get(Metric $m): float
    {
        return $this->values[$m->name] ?? throw new InvalidArgumentException("The metrics have no '{$m->name}'.");
    }
}
