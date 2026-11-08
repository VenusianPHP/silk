<?php

namespace Silk\Widgets;

use Silk\Painter\Surfaces;
use Silk\Contracts\Look\SurfaceKind;
use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Look\Metric;
use Silk\Contracts\Look\Role;
use Silk\Contracts\Look\State;
use Silk\Contracts\Painter\Painter;
use Silk\Core\Motion\Spring;
use Silk\Core\Motion\SpringPreset;
use Silk\Core\Overlay\Placement;
use Silk\Core\Tree\Container;
use Silk\Widgets\Concerns\MeasuresText;

/**
 * `menuBar(name)`: Raven's strip of menu titles (menu.c:694-875). A press on a title opens its menu below it;
 * with a menu open, moving over another title switches to it. The mark of the open menu slides from title to
 * title, x and width on BOUNCY springs. Titles start 2 units in, inset 2 top and bottom. Inside a HeaderBar the
 * bar draws no background. The looks are the MenuBar parts: Body (the strip), Title in Hover and Pressed (open).
 */
class MenuBar extends Container
{
    use MeasuresText;

    /** @var list<MenuTitle> */
    private array $titles = [];

    private ?MenuTitle $open = null;

    private ?MenuTitle $hot = null;

    private Spring $markX;

    private Spring $markW;

    public function __construct(string $name)
    {
        parent::__construct($name);
        $this->markX = new Spring(0.0, SpringPreset::Bouncy);
        $this->markW = new Spring(0.0, SpringPreset::Bouncy);
    }

    /** Adds a title and returns its (new, empty) menu. */
    public function menu(string $name, string $title): Menu
    {
        $menu = new Menu($name);
        $this->titles[] = $t = new MenuTitle($name.'-title', $title, $menu, $this);
        $this->add($t);

        return $menu;
    }

    /** @return list<MenuTitle> */
    public function titles(): array
    {
        return $this->titles;
    }

    /** The menu open from this bar, or null. */
    public function openMenu(): ?Menu
    {
        return $this->open?->menu->isOpen() ? $this->open->menu : null;
    }

    public function springs(): array
    {
        return [$this->markX, $this->markW];
    }

    /** @internal a press on a title opens its menu (menu.c:846-851) */
    public function pressed(MenuTitle $t): void
    {
        $this->openTitle($t);
    }

    /** @internal the pointer is on a title: it is hot; with a menu open, its menu takes over (menu.c:833-842) */
    public function hovered(MenuTitle $t): void
    {
        if ($this->hot !== $t) {
            $this->hot = $t;
            $this->invalidatePaint();
        }
        $this->openMenu() !== null && $this->open !== $t && $this->openTitle($t);
    }

    /** @internal */
    public function left(MenuTitle $t): void
    {
        if ($this->hot === $t) {
            $this->hot = null;
            $this->invalidatePaint();
        }
    }

    private function openTitle(MenuTitle $t): void
    {
        if (! $t->isEnabled()) {
            return;
        }
        $this->open?->menu->close();
        $this->open = $t;
        $t->menu->open($t, Placement::Below);
        $this->invalidatePaint();
    }

    /** 4 + the titles, ControlHeight high (menu.c:710-719). */
    protected function measureContent(Size $available): Size
    {
        $w = 4.0;
        foreach ($this->visibleChildren() as $c) {
            $w += $c->measure($available)->w;
        }

        return new Size(min($available->w, $w), $this->look()->metric(Metric::ControlHeight));
    }

    /** Titles from 2 units in, inset 2 top and bottom (menu.c:721-735). */
    protected function arrangeContent(Rect $r): void
    {
        $x = $r->x + 2;
        foreach ($this->visibleChildren() as $c) {
            $w = $c->measure(new Size($r->w, $r->h))->w;
            $c->arrange(new Rect($x, $r->y + 2, $w, max(0.0, $r->h - 4)));
            $x += $w;
        }
    }

    public function paint(Painter $p): void
    {
        $look = $this->look();
        $b = $this->bounds();
        $body = $look->part('MenuBar', 'Body', State::Normal);
        // inside a header bar, the bar is the background (menu.c:788-789)
        $glass = $look->material()->isGlass();
        if ($this->parent() instanceof HeaderBar) {
            // (the bar is the background)
        } elseif ($glass) {
            // menu.c:765-767: a strip of glass across the window
            Surfaces::paint($p, $look, $b, 0.0, SurfaceKind::Bar);
        } elseif ($body->background !== null) {
            $p->rect($b, $body->background);
        }
        // menu.c:741, 775, 789: in glass the titles are capsules, 3 in from the top and bottom
        $title = fn (Rect $r): Rect => $glass ? new Rect($r->x, $r->y + 3, $r->w, $r->h - 6) : $r;
        $radius = fn (float $r): float => $glass ? 100.0 : $r;
        // the mark of the open menu slides from title to title
        if ($this->openMenu() !== null) {
            $r = $title($this->open->bounds());
            $first = $this->markW->value() <= 0.0;
            $first ? $this->markX->snap($r->x) : $this->markX->target($r->x);
            $first ? $this->markW->snap($r->w) : $this->markW->target($r->w);
            $mark = $look->part('MenuBar', 'Title', State::Pressed);
            $p->rect(new Rect($this->markX->value(), $r->y, $this->markW->value(), $r->h), $mark->background, $radius($mark->radius));
        } else {
            $this->markW->snap(0.0);
        }
        $hover = $look->part('MenuBar', 'Title', State::Hover);
        $style = $this->textStyle();
        foreach ($this->titles as $t) {
            if (! $t->isVisible()) {
                continue;
            }
            $r = $title($t->bounds());
            $enabled = $this->isEnabled() && $t->isEnabled();
            if ($t !== $this->open && $t === $this->hot && $enabled) {
                $p->rect($r, $hover->background, $radius($hover->radius));
            }
            $p->text($t->title(), $r->x + ($r->w - $this->textSize($t->title(), $style)->w) / 2, $this->baselineIn($r, $style),
                $this->color($enabled ? Role::Text : Role::TextDisabled, $look), $style);
        }
    }
}
