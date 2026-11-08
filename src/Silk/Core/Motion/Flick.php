<?php

namespace Silk\Core\Motion;

/**
 * One axis of a flick (spec 7.3): velocity from moves, smoothed keeping 0.35 of the old; on lift it
 * coasts above 60 u/s, decelerating at 2400 u/s^2 until 15 u/s; a rest of 0.08 s before lift cancels it.
 */
final class Flick
{
    public const float KEEP = 0.35;

    public const float DECEL = 2400.0;

    public const float START = 60.0;

    public const float STOP = 15.0;

    public const float REST = 0.08;

    private float $velocity = 0.0;

    private ?float $last = null;

    private bool $coasting = false;

    /** A move of $delta units at time $t (seconds). */
    public function move(float $delta, float $t): void
    {
        $dt = $t - ($this->last ?? 0.0);
        $instant = $dt > 0 ? $delta / $dt : 0.0;
        $this->velocity = $this->last === null ? $instant : self::KEEP * $this->velocity + (1 - self::KEEP) * $instant;
        $this->last = $t;
        $this->coasting = false;
    }

    public function lift(float $t): void
    {
        if ($this->last === null || $t - $this->last >= self::REST - 1e-9) {
            $this->velocity = 0.0;
        }
        $this->coasting = abs($this->velocity) > self::START;
        $this->last = null;
    }

    /** @return float the distance coasted in $dt */
    public function step(float $dt): float
    {
        if (! $this->coasting) {
            return 0.0;
        }
        $moved = $this->velocity * $dt;
        $slow = self::DECEL * $dt;
        $this->velocity = $this->velocity > 0 ? $this->velocity - $slow : $this->velocity + $slow;
        if (abs($this->velocity) <= self::STOP) {
            $this->velocity = 0.0;
            $this->coasting = false;
        }

        return $moved;
    }

    public function cancel(): void
    {
        $this->velocity = 0.0;
        $this->coasting = false;
        $this->last = null;
    }

    public function coasting(): bool
    {
        return $this->coasting;
    }

    public function velocity(): float
    {
        return $this->velocity;
    }
}
