<?php

namespace Silk\Widgets;

use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Look\Metric;
use Silk\Contracts\Look\Role;
use Silk\Contracts\Look\State;
use Silk\Core\Motion\Spring;
use Silk\Core\Motion\SpringPreset;
use Silk\Contracts\Painter\Painter;
use Silk\Core\Tree\Widget;
use Surface\NutsAndBolts\Color;

/** `progressBar(name, fraction)` as TKProgressBar; null is indeterminate. Raven widgets/progress_bar.c. */
class ProgressBar extends Widget
{
    /** The fraction as drawn: it glides to the real one on SMOOTH, from 0 (progress_bar.c:16-17, 29). */
    private Spring $shown;

    public function __construct(string $name, private ?float $fraction = 0.0)
    {
        parent::__construct($name);
        $this->fraction = $fraction === null ? null : min(1.0, max(0.0, $fraction));
        $this->shown = (new Spring(0.0, SpringPreset::Smooth))->target($this->fraction ?? 0.0);
    }

    public function fraction(): ?float
    {
        return $this->fraction;
    }

    public function setFraction(?float $fraction): static
    {
        $this->fraction = $fraction === null ? null : min(1.0, max(0.0, $fraction));
        $this->shown->target($this->fraction ?? 0.0);

        return $this->invalidatePaint();
    }

    public function springs(): array
    {
        return [$this->shown];
    }

    /** 120 by 8 (progress_bar.c:20-23). */
    protected function measureContent(Size $available): Size
    {
        return new Size(120.0, 8.0);
    }

    /** shortcut: indeterminate (null) draws the track only: Raven has no indeterminate bar; TK's sweep waits for Angel's decision. */
    public function paint(Painter $p): void
    {
        $look = $this->look();
        $b = $this->bounds();
        $filled = floor($b->w * max(0.0, min(1.0, $this->shown->value())) + 0.5);
        $fill = $look->color(Role::Accent);
        if (! $this->isEnabled()) {
            $fill = new Color($fill->red, $fill->green, $fill->blue, $fill->alpha * 0.4);
        }
        $size = $look->part('ProgressBar', 'Body', State::Normal)->extra['size'] ?? null;
        if ($size !== null) {
            // the platforms: a groove (macOS 6, GNOME 8) in the middle, the fill as thick and round (progress_bar.c:33-54)
            $t = min((float) $size, $b->h);
            $middle = $b->y + floor($b->h / 2);
            $p->rect(new Rect($b->x, $middle - floor($t / 2), $b->w, max($t, 1.0)), $look->color(Role::Track), $t / 2);
            $filled > 0 && $p->rect(new Rect($b->x, $middle - $t / 2, max($filled, $t), $t), $fill, $t / 2);

            return;
        }
        // Raven's own: the full-size shape clipped to the progress keeps its rounded ends (progress_bar.c:75-81)
        $radius = min($b->h / 2, $look->metric(Metric::CornerRadius));
        $p->rect($b, $look->color(Role::Track), $radius);
        if ($filled > 0) {
            $p->pushClip(new Rect($b->x, $b->y, $filled, $b->h));
            $p->rect($b, $fill, $radius);
            $p->popClip();
        }
    }
}
