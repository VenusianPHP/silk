<?php

namespace Silk\Widgets;

use Silk\Painter\Surfaces;
use Silk\Contracts\Look\SurfaceKind;
use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Look\Metric;
use Silk\Contracts\Look\Role;
use Silk\Contracts\Painter\Painter;
use Silk\Core\Overlay\Popup;
use Surface\NutsAndBolts\Color;

/**
 * A modal popup centred in the host over a veil (spec 7.2): veil black 0.42 x shown, card radius 10,
 * shadow y+6 blur 28, scale 0.9 + 0.1 x shown, rising from 60 units below.
 */
class Dialog extends Popup
{
    use Widgets;

    public const float RADIUS = 10.0;

    public const float SHADOW = 28.0;

    public const float RISE = 60.0;

    private Rect $host;

    public function __construct(string $name, float $spacing = 8, float $padding = 16)
    {
        parent::__construct($name, $spacing, $padding);
        $this->setModal(true);
        $this->host = new Rect(0, 0, 0, 0);
    }

    public function place(Rect $host): void
    {
        $this->host = $host;
        $want = $this->measure(new Size($host->w, $host->h));
        $w = min($want->w, $host->w);
        $h = min($want->h, $host->h);
        $this->arrange(new Rect($host->x + ($host->w - $w) / 2, $host->y + ($host->h - $h) / 2, $w, $h));
    }

    /** The veil covers the host; the card rises from 60 below and casts a 28 blur. */
    public function paintExtent(): Rect
    {
        return $this->host->union($this->bounds()->grow(self::SHADOW + self::OVERDRAW)->moved(0, self::RISE));
    }

    public function render(Painter $p): void
    {
        $shown = $this->shown();
        $veil = min(1.0, max(0.0, $shown));
        if ($this->look()->material()->isGlass()) {
            // window.c:806-816: the window goes out of focus behind the sheet
            $p->pushOpacity($veil);
            $p->glass($this->host, 0.0, new \Silk\Contracts\Painter\GlassLook(new Color(0, 0, 0, $this->look()->dark() ? 0.34 : 0.16), null, 1.0, 0.0, 1.0));
            $p->popOpacity();
        } else {
            $p->rect($this->host, new Color(0, 0, 0, 0.42 * $veil));
        }

        $o = min(1.0, max(0.0, $shown * 1.8));
        $s = 0.9 + 0.1 * $shown;
        $b = $this->bounds();
        $cx = $b->x + $b->w / 2;
        $cy = $b->y + $b->h / 2;
        $o < 1.0 && $p->pushOpacity($o);
        $p->pushTransform($s, $cx * (1 - $s), $cy * (1 - $s) + self::RISE * (1 - $shown));
        $this->paint($p);
        $p->popTransform();
        $o < 1.0 && $p->popOpacity();
    }

    protected function paintSelf(Painter $p): void
    {
        $look = $this->look();
        $b = $this->bounds();
        if ($look->material()->isGlass() || $look->liquid()) {
            // window.c:832-837: a sheet of glass, with the corners of the glass theme's windows
            Surfaces::paint($p, $look, $b, $look->material()->isGlass() ? 26.0 : 16.0, SurfaceKind::Popup);

            return;
        }
        // y+6 in all; the engine already moves a shadow down by blur/4
        $p->shadow($b, self::RADIUS, self::SHADOW, $look->color(Role::Shadow), 0.0, 6.0 - self::SHADOW / 4);
        $p->rect($b, $look->color(Role::Popup), self::RADIUS, $look->color(Role::PopupBorder), $look->metric(Metric::BorderWidth));
    }
}
