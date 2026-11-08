<?php

namespace Silk\Hosts;

use Closure;
use Silk\Core\Motion\FrameClock;
use Silk\Painter\EnginePainter;

/**
 * One frame (spec 5.3): drain input and route it, step springs, lay out if dirty, paint if dirty
 * or moving, present. An idle tree costs no layout, no paint and no present.
 */
class FrameLoop
{
    private readonly Closure $now;

    public function __construct(
        private readonly FrameClock $clock = new FrameClock,
        ?Closure $now = null,
    ) {
        $this->now = $now ?? fn (): int => hrtime(true);
    }

    /** @return bool whether the frame painted */
    public function frame(Host $host): bool
    {
        $tree = $host->tree();
        foreach ($host->drain() as $event) {
            $host->router()->dispatch($event);
        }
        // shortcut: timers (tooltip delay, caret blink, long press) join here with the widgets that need them (Plan 2)
        foreach (FrameClock::steps($this->clock->tick(($this->now)())) as $step) {
            $tree->advance($step);
        }

        $engine = $host->engine();
        $viewport = $host->viewport();
        if ($engine === null || $viewport->w < 1 || $viewport->h < 1) {
            return false;
        }
        $tree->layout($viewport);
        if (! $tree->needsPaint() && ! $tree->wantsPaint()) {
            $this->clock->forget();

            return false;
        }

        $engine->frame(function () use ($engine, $host, $tree, $viewport) {
            // the tree paints the background of the region it repaints
            $tree->paintAll(new EnginePainter($engine, $host->scale(), $host->font, $tree->measurer()));
        });
        $host->present();

        return true;
    }
}
