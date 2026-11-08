<?php

namespace Silk\Core\Motion;

/** Raven's appear transitions (anim.c:197-258), applied after paint so layout never moves. */
enum Transition
{
    case Fade;
    case Pop;
    case Zoom;
    case FlyUp;
    case FlyDown;
    case FlyLeft;
    case FlyRight;
    case Drop;
    case SlideLeft;
    case SlideRight;

    public function preset(): SpringPreset
    {
        return match ($this) {
            self::Fade, self::Zoom => SpringPreset::Smooth,
            self::Drop => SpringPreset::Wobbly,
            self::SlideLeft, self::SlideRight => SpringPreset::Snappy,
            default => SpringPreset::Bouncy,
        };
    }

    /**
     * What to paint at progress $t (0 away, 1 landed; springs may overshoot): scale is x's, scaleY y's.
     *
     * @return array{opacity: float, scale: float, scaleY: float, dx: float, dy: float}
     */
    public function state(float $t): array
    {
        $away = 1.0 - $t;
        $lift = 1.0 - 0.08 * min(1.0, max(0.0, $away));
        [$scale, $dx, $dy] = match ($this) {
            self::Fade => [1.0, 0.0, 0.0],
            self::Pop => [0.55 + 0.45 * $t, 0.0, 0.0],
            self::Zoom => [1.0 + 0.22 * $away, 0.0, 0.0],
            self::FlyUp => [$lift, 0.0, 56.0 * $away],
            self::FlyDown => [$lift, 0.0, -56.0 * $away],
            self::FlyLeft => [$lift, 72.0 * $away, 0.0],
            self::FlyRight => [$lift, -72.0 * $away, 0.0],
            self::Drop => [1.0 - 0.12 * min(0.6, max(-0.6, $away)), 0.0, -140.0 * $away],
            self::SlideLeft => [1.0, 28.0 * $away, 0.0],
            self::SlideRight => [1.0, -28.0 * $away, 0.0],
        };

        // visible well before it lands: the motion is what should be seen
        // Drop falls from above and lands with a squash, taller as it falls, wider as it lands (anim.c:249-254)
        $scaleY = $this === self::Drop ? 1.0 + 0.25 * min(0.6, max(-0.6, $away)) : $scale;

        return ['opacity' => min(1.0, max(0.0, $t * 2.2)), 'scale' => $scale, 'scaleY' => $scaleY, 'dx' => $dx, 'dy' => $dy];
    }
}
