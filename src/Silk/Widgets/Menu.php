<?php

namespace Silk\Widgets;

use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Look\Role;
use Silk\Contracts\Look\State;
use Silk\Core\Motion\Spring;
use Silk\Core\Motion\SpringPreset;
use Silk\Core\Overlay\Placement;
use Silk\Core\Overlay\Popup;
use Silk\Contracts\Painter\Painter;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\Windows\Mail\MenuActivated;
use Surface\Contracts\Windows\Mail\MenuToggled;

/**
 * A popup menu. Items post Surface's menu mail (MenuActivated, MenuToggled for a checkable item), as a
 * TK context menu does. Up and Down move a highlight that glides on a SNAPPY spring, skipping separators
 * and disabled items; Right opens a submenu, Left closes it; Enter activates and closes the whole chain.
 */
class Menu extends Popup
{
    private ?MenuItem $highlighted = null;

    private Spring $glide;

    private ?Menu $parentMenu = null;

    private int $separators = 0;

    public function __construct(string $name)
    {
        parent::__construct($name, 0, 4);
        $this->focusable = true;
        $this->glide = new Spring(0.0, SpringPreset::Snappy);
    }

    public function item(string $name, string $label, ?string $shortcut = null): MenuItem
    {
        $this->add($item = new MenuItem($name, $label, $shortcut));

        return $item;
    }

    public function separator(): static
    {
        return $this->add(new MenuSeparator($this->name().'-separator-'.++$this->separators));
    }

    /** Adds an item that opens a new menu, and returns that menu. */
    public function submenu(string $name, string $label): Menu
    {
        $menu = new Menu($name);
        $menu->parentMenu = $this;
        $this->item($name, $label)->setSubmenu($menu);

        return $menu;
    }

    /** Whether any item is checkable: GNOME keeps a column for the checks (menu.c:362-369). */
    public function hasChecks(): bool
    {
        foreach ($this->items() as $item) {
            if ($item->isCheckable()) {
                return true;
            }
        }

        return false;
    }

    /** Raven's width: check gutter, text, gap, shortcut, room for the arrow; rows from 4 in (menu.c:252-269). */
    protected function measureContent(Size $available): Size
    {
        [$text, $shortcut, $height] = [0.0, 0.0, 8.0];
        foreach ($this->children() as $child) {
            if (! $child->isVisible()) {
                continue;
            }
            $height += $child->measure($available)->h;
            if ($child instanceof MenuItem) {
                [$text, $shortcut] = [max($text, $child->labelWidth()), max($shortcut, $child->shortcutWidth())];
            }
        }

        return new Size(ceil(24 + $text + 28 + $shortcut + 20), ceil($height));
    }

    public function highlighted(): ?MenuItem
    {
        return $this->highlighted;
    }

    public function springs(): array
    {
        return [...parent::springs(), $this->glide];
    }

    public function highlightItem(?MenuItem $item): void
    {
        $first = $this->highlighted === null;
        $this->highlighted = $item;
        if ($item !== null) {
            $first ? $this->glide->snap($item->bounds()->y) : $this->glide->target($item->bounds()->y);
        }
        $this->invalidatePaint();
    }

    /** A chosen item: a submenu opens; a checkable item flips; then the mail, and the whole chain closes. */
    public function activate(MenuItem $item): void
    {
        if (! $item->isEnabled()) {
            return;
        }
        if ($item->submenu() !== null) {
            $this->openSubmenu($item);

            return;
        }
        $window = $this->tree()?->window ?? 'window';
        if ($item->isCheckable()) {
            $item->setChecked(! $item->isChecked());
            $this->post(new MenuToggled($window, $item->name(), $item->isChecked()));
        } else {
            $this->post(new MenuActivated($window, $item->name()));
        }
        $this->closeChain();
    }

    public function closeChain(): void
    {
        $root = $this;
        while ($root->parentMenu !== null) {
            $root = $root->parentMenu;
        }
        $root->close();
    }

    /** Closes open submenus first. */
    public function close(): static
    {
        foreach ($this->items() as $item) {
            $item->submenu()?->close();
        }
        // a closed menu forgets its highlight (Raven rvn_menu_popup_closed, menu.c:589-591)
        $this->highlighted = null;

        return parent::close();
    }

    public function handle(SilkEvent $e): bool
    {
        if ($e->type !== EventType::KeyDown) {
            return false;
        }

        return match ($e->key) {
            Key::DOWN => $this->move(1),
            Key::UP => $this->move(-1),
            // Right, and Enter on a submenu item, open it on its first item (Raven menu.c:541-561)
            Key::RIGHT => $this->highlighted?->submenu() !== null && $this->openSubmenu($this->highlighted, true),
            Key::LEFT => $this->parentMenu !== null && $this->close() !== null,
            Key::ENTER, Key::SPACE => $this->highlighted !== null && ($this->highlighted->submenu() !== null && $this->highlighted->isEnabled()
                ? $this->openSubmenu($this->highlighted, true)
                : ($this->activate($this->highlighted) ?? true)),
            default => false,
        };
    }

    /** @return list<MenuItem> */
    private function items(): array
    {
        return array_values(array_filter($this->children(), fn ($c) => $c instanceof MenuItem));
    }

    private function move(int $by): bool
    {
        $items = array_values(array_filter($this->items(), fn (MenuItem $i) => $i->isEnabled() && $i->isVisible()));
        if ($items === []) {
            return true;
        }
        $at = $this->highlighted === null ? ($by > 0 ? -1 : count($items)) : (int) array_search($this->highlighted, $items, true);
        $this->highlightItem($items[(($at + $by) % count($items) + count($items)) % count($items)]);

        return true;
    }

    private function openSubmenu(MenuItem $item, bool $firstItem = false): bool
    {
        $this->highlightItem($item);
        $item->submenu()->open($item, Placement::Right);
        $firstItem && $item->submenu()->move(1);

        return true;
    }

    protected function paintSelf(Painter $p): void
    {
        parent::paintSelf($p);
        // the row of an open submenu keeps a highlight of its own (menu.c:401-403, 481-483)
        foreach ($this->items() as $item) {
            if ($item !== $this->highlighted && $item->isEnabled() && $item->submenu()?->isOpen()) {
                $this->paintRow($p, $item, State::Selected, $item->bounds()->y, (float) ($this->style()['openY'] ?? 0.0));
            }
        }
        // the highlight slides under the pointer from row to row (SNAPPY)
        if ($this->highlighted !== null) {
            $this->paintRow($p, $this->highlighted, State::Normal, $this->glide->value(), (float) ($this->style()['highlightY'] ?? 0.0));
        }
    }

    /** @return array<string, float|string> the menu's style numbers */
    private function style(): array
    {
        return $this->look()->part('Menu', 'Body', State::Normal)->extra;
    }

    /** A row's highlight, inset by the style (Raven row, or the platforms' rows inset; menu.c:374-382, 459-466). */
    private function paintRow(Painter $p, MenuItem $item, State $state, float $y, float $insetY): void
    {
        $part = $this->look()->part('Menu', 'Highlight', $state);
        if ($part->background === null) {
            return;
        }
        $b = $item->bounds();
        $dx = (float) ($this->style()['highlightX'] ?? 0.0);
        // menu.c:437: the rows of a glass menu are rounder
        $p->rect(new Rect($b->x + $dx, $y + $insetY, $b->w - 2 * $dx, $b->h - 2 * $insetY), $part->background, $this->look()->material()->isGlass() ? 9.0 : $part->radius);
    }
}
