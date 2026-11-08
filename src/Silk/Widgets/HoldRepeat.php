<?php

namespace Silk\Widgets;

use Closure;
use Silk\Core\Motion\Spring;
use Silk\Core\Motion\SpringPreset;

/**
 * Repeats an action while a part is held: after DELAY, then every INTERVAL. It rides the frame loop as a
 * spring that moves only while held.
 */
final class HoldRepeat extends Spring
{
    public const float DELAY = 0.4;

    public const float INTERVAL = 0.05;

    private ?Closure $action = null;

    private float $held = 0.0;

    private float $next = self::DELAY;

    public function __construct()
    {
        parent::__construct(0.0, SpringPreset::Snappy);
    }

    public function hold(Closure $action): void
    {
        $this->action = $action;
        $this->held = 0.0;
        $this->next = self::DELAY;
    }

    public function release(): void
    {
        $this->action = null;
    }

    public function step(float $dt): static
    {
        if ($this->action === null) {
            return $this;
        }
        $this->held += $dt;
        while ($this->action !== null && $this->held >= $this->next) {
            ($this->action)();
            $this->next += self::INTERVAL;
        }

        return $this;
    }

    /** Reduced motion keeps repeating: a repeat is not motion. */
    public function finish(): static
    {
        return $this->step(1 / 60);
    }

    public function atRest(): bool
    {
        return $this->action === null;
    }
}
