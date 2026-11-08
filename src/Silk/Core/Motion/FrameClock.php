<?php

namespace Silk\Core\Motion;

/**
 * Turns hrtime() stamps into the time a frame covers: the real gap, between 0.001 s and 0.25 s. A frame that takes
 * longer than a spring step must not stretch the animation (a slow frame would then make the animation last longer,
 * and the next frame slower still), so the gap is cut into steps of at most 1/30 s for the springs.
 */
class FrameClock
{
    public const float MIN_DT = 0.001;

    /** The most one spring step covers. */
    public const float MAX_DT = 1 / 30;

    /** The most one frame covers: a longer gap is a pause, not an animation. */
    public const float MAX_FRAME = 0.25;

    private ?int $last = null;

    public function tick(int $nowNs): float
    {
        $dt = $this->last === null ? self::MIN_DT : ($nowNs - $this->last) / 1e9;
        $this->last = $nowNs;

        return min(self::MAX_FRAME, max(self::MIN_DT, $dt));
    }

    /** After an idle frame: the next frame starts from now, so an animation begins at its start and not part way. */
    public function forget(): void
    {
        $this->last = null;
    }

    /** @return list<float> $dt in equal steps of at most MAX_DT */
    public static function steps(float $dt): array
    {
        $n = max(1, (int) ceil($dt / self::MAX_DT - 1e-9));

        return array_fill(0, $n, $dt / $n);
    }
}
