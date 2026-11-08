<?php

namespace Silk\Widgets;

use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Look\Metric;
use Silk\Contracts\Look\Role;
use Silk\Contracts\Painter\Painter;
use Silk\Contracts\Look\FontSpec;
use Silk\Contracts\Look\State;
use Silk\Core\Motion\LookBlend;
use Silk\Core\Tree\Widget;
use Silk\Painter\Icons;
use Silk\Widgets\Concerns\MeasuresText;
use Surface\Contracts\HumanInput\MouseButton;

/** One row of a Menu: a label, an optional shortcut, a check, or a submenu chevron. */
class MenuItem extends Widget
{
    use MeasuresText;

    private bool $checkable = false;

    private bool $checked = false;

    private bool $pressed = false;

    private ?Menu $submenu = null;

    public function __construct(string $name, private string $label, private readonly ?string $shortcut = null)
    {
        parent::__construct($name);
    }

    public function label(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this->invalidateLayout();
    }

    public function shortcut(): ?string
    {
        return $this->shortcut;
    }

    /** A checkable item flips and posts MenuToggled instead of MenuActivated. */
    public function setCheckable(bool $on = true): static
    {
        $this->checkable = $on;

        return $this->invalidatePaint();
    }

    public function isCheckable(): bool
    {
        return $this->checkable;
    }

    public function setChecked(bool $checked): static
    {
        $this->checked = $checked;

        return $this->invalidatePaint();
    }

    public function isChecked(): bool
    {
        return $this->checked;
    }

    public function setSubmenu(?Menu $menu): static
    {
        $this->submenu = $menu;

        return $this->invalidatePaint();
    }

    public function submenu(): ?Menu
    {
        return $this->submenu;
    }

    private function menu(): ?Menu
    {
        return $this->parent() instanceof Menu ? $this->parent() : null;
    }

    /** A row RowHeight + 2 tall (menu.c:243-245); the menu measures the width for all its rows. */
    protected function measureContent(Size $available): Size
    {
        return new Size(0, $this->look()->metric(Metric::RowHeight) + 2);
    }

    /** The label's width in the menu's font. */
    public function labelWidth(): float
    {
        return $this->textSize($this->label)->w;
    }

    /** The shortcut's width in the menu's font, 0 without one. */
    public function shortcutWidth(): float
    {
        return $this->shortcut === null ? 0.0 : $this->textSize($this->shortcut)->w;
    }

    public function handle(SilkEvent $e): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }
        switch ($e->type) {
            case EventType::Enter:
                $this->menu()?->highlightItem($this);

                return false;
            case EventType::MouseDown:
                $this->pressed = $e->button === MouseButton::LEFT;

                return $this->pressed;
            case EventType::MouseUp:
                $was = $this->pressed;
                $this->pressed = false;
                if ($was && $this->bounds()->contains($e->x, $e->y)) {
                    $this->menu()?->activate($this);
                }

                return $was;
            default:
                return false;
        }
    }

    /** The row's text, check, shortcut and chevron, placed by the menu's style (menu.c:386-428, 467-503). */
    public function paint(Painter $p): void
    {
        $look = $this->look();
        $b = $this->bounds();
        $menu = $this->menu();
        $x = $look->part('Menu', 'Body', State::Normal)->extra;
        $enabled = $this->isEnabled();
        $state = ! $enabled ? State::Disabled : ($menu?->highlighted() === $this ? State::Hover : ($this->submenu?->isOpen() ? State::Selected : State::Normal));
        $ink = $enabled ? ($look->part('Menu', 'Item', $state)->text ?? $look->color(Role::Text)) : $look->color(Role::TextDisabled);
        $secondary = ! $enabled ? $ink : ($look->part('Menu', 'Secondary', $state)->text
            ?? LookBlend::mix($ink, $look->color(Role::TextDisabled), (float) ($x['shortcutMix'] ?? 0.6)));
        $font = $this->textStyle();
        $cy = $b->y + $b->h / 2;

        $textX = (float) ($x['textX'] ?? 20.0) + ($menu?->hasChecks() ? (float) ($x['checkColumn'] ?? 0.0) : 0.0);
        $p->text($this->label, $b->x + $textX, $this->baselineIn($b), $ink, $font);
        if ($this->submenu !== null) {
            $cx = $b->x + $b->w - (float) ($x['chevronRight'] ?? 10.0);
            ($x['chevron'] ?? 'icon') === 'chevron'
                ? Icons::chevron($p, $cx, $cy, 12.0 * 0.6, 0.0, $secondary)
                : Icons::paint($p, Icons::CHEVRON_RIGHT, $cx, $cy, 7.0, $ink);

            return;
        }
        if ($this->checked) {
            Icons::paint($p, Icons::CHECK, $b->x + (float) ($x['checkX'] ?? 10.0), $cy, (float) ($x['checkSize'] ?? 8.0), $ink);
        }
        if ($this->shortcut !== null) {
            $small = new FontSpec($font->family, $font->size - (float) ($x['shortcutShrink'] ?? 0.0), $font->weight, $font->italic);
            $w = $this->textSize($this->shortcut, $small)->w;
            $p->text($this->shortcut, $b->x + $b->w - (float) ($x['shortcutRight'] ?? 10.0) - $w, $this->baselineIn($b, $small), $secondary, $small);
        }
    }
}
