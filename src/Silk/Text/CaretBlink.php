<?php

namespace Silk\Text;

use Closure;
use Silk\Core\Motion\Spring;
use Silk\Core\Motion\SpringPreset;

/**
 * The caret's blink timer: 0.5 s on, 0.5 s off from the last edit, read from a clock. It rides the frame loop
 * as a spring that is at rest except when the caret is due to toggle, so a focused idle field costs one paint
 * per toggle and the loop sees nothing moving in between.
 */
final class CaretBlink extends Spring
{
    public const float HALF = 0.5;

    /** Seconds, monotonic; tests set their own. */
    public static ?Closure $clock = null;

    private float $start = 0.0;

    private bool $running = false;

    /** The phase the last paint showed; null forces a paint. */
    private ?bool $shown = null;

    public function __construct()
    {
        parent::__construct(0.0, SpringPreset::Snappy);
    }

    public function start(): void
    {
        $this->running = true;
        $this->restart();
        $this->shown = null;
    }

    public function stop(): void
    {
        $this->running = false;
    }

    /** An edit shows the caret at once; its widget repaints for the edit itself. */
    public function restart(): void
    {
        $this->start = self::now();
        $this->shown = true;
    }

    public function visible(): bool
    {
        return $this->running && fmod(self::now() - $this->start, 2 * self::HALF) < self::HALF;
    }

    /** Takes the phase due now: the frame loop then repaints the field. */
    public function step(float $dt): static
    {
        $this->shown = $this->visible();

        return $this;
    }

    public function finish(): static
    {
        return $this->step(0.0);
    }

    public function atRest(): bool
    {
        return ! $this->running || $this->visible() === $this->shown;
    }

    private static function now(): float
    {
        return self::$clock !== null ? (self::$clock)() : hrtime(true) / 1e9;
    }
}
