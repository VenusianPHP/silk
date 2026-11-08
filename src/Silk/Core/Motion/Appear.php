<?php

namespace Silk\Core\Motion;

/** One transition in progress: its kind and its 0..1 spring (to 1 on appear, to 0 on disappear). */
final class Appear
{
    public readonly Spring $spring;

    public function __construct(public readonly Transition $transition, public readonly bool $leaving)
    {
        $this->spring = (new Spring($leaving ? 1.0 : 0.0, $transition->preset()))->target($leaving ? 0.0 : 1.0);
    }

    public function done(): bool
    {
        return $this->spring->atRest();
    }

    /** @return array{opacity: float, scale: float, scaleY: float, dx: float, dy: float} */
    public function state(): array
    {
        return $this->transition->state($this->spring->value());
    }
}
