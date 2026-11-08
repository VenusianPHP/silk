<?php

namespace Silk\Core\Overlay;

use Closure;
use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Painter\Painter;
use Silk\Contracts\Widget\Widget;
use Silk\Core\Tree\Tree;

/**
 * The popups of one tree, above its root, in push order (the last is top-most). Popups are hit
 * top-down before the root; closing ones are painted but never hit; all are clamped to the host.
 */
class OverlayLayer
{
    /** @var list<Popup> */
    private array $popups = [];

    private ?Rect $host = null;

    private ?Size $viewport = null;

    /** @var list<Closure(Popup, bool): void> */
    private array $listeners = [];

    public function __construct(private readonly Tree $tree) {}

    /** Called with (popup, true) when one opens and (popup, false) when it closes. */
    public function onChange(Closure $listener): void
    {
        $this->listeners[] = $listener;
    }

    public function push(Popup $popup): void
    {
        $this->popups = array_values(array_filter($this->popups, fn (Popup $p) => $p !== $popup));
        $this->popups[] = $popup;
        $this->tree->adopt($popup);
        foreach ($popup->takesFocus() ? $this->listeners : [] as $l) {
            $l($popup, true);
        }
    }

    /** A popup started closing: it stays until it has shrunk away. */
    public function closed(Popup $popup): void
    {
        foreach ($popup->takesFocus() ? $this->listeners : [] as $l) {
            $l($popup, false);
        }
    }

    public function remove(Popup $popup): void
    {
        $popup->lastPainted() !== null && $this->tree->addDamage($popup->lastPainted());
        $this->popups = array_values(array_filter($this->popups, fn (Popup $p) => $p !== $popup));
    }

    /** The top-most open popup. */
    /** The top open popup that Escape closes, or null. */
    public function topDismissable(): ?Popup
    {
        $open = array_values(array_filter($this->open(), fn (Popup $p) => $p->isDismissable()));

        return $open === [] ? null : $open[array_key_last($open)];
    }

    public function top(): ?Popup
    {
        $open = $this->open();

        return $open === [] ? null : $open[array_key_last($open)];
    }

    /** @return list<Popup> open popups that take input, bottom first */
    public function open(): array
    {
        return array_values(array_filter($this->popups, fn (Popup $p) => $p->isOpen() && ! $p->isPassive()));
    }

    /** @return list<Popup> every popup still painting, bottom first */
    public function all(): array
    {
        return $this->popups;
    }

    /** The deepest widget hit in an open popup, top-down; a modal popup takes every point. */
    public function hitTest(float $x, float $y): ?Widget
    {
        foreach (array_reverse($this->open()) as $popup) {
            $hit = $this->tree->hitWithin($popup, $x, $y);
            if ($hit !== null) {
                return $hit;
            }
            if ($popup->isModal()) {
                return $popup;
            }
        }

        return null;
    }

    public function clampTo(Rect $host): void
    {
        $this->host = $host;
        foreach ($this->popups as $p) {
            $p->invalidateLayout();
        }
    }

    public function layout(Size $viewport): void
    {
        $changed = $this->viewport != $viewport;
        $this->viewport = $viewport;
        $host = $this->host ?? new Rect(0, 0, $viewport->w, $viewport->h);
        foreach ($this->popups as $p) {
            if ($changed || $p->needsLayout()) {
                $p->place($host);
            }
        }
    }

    public function paint(Painter $p): void
    {
        foreach ($this->popups as $popup) {
            $this->tree->paints($popup) && $popup->render($p);
        }
    }
}
