<?php

namespace Silk\Widgets;

use Silk\Painter\Surfaces;
use Silk\Contracts\Look\SurfaceKind;
use InvalidArgumentException;
use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Look\Metric;
use Silk\Contracts\Look\Role;
use Silk\Contracts\Look\State;
use Silk\Contracts\Painter\Painter;
use Silk\Core\Tree\Widget;
use Silk\Painter\Icons;
use Silk\Painter\PartBox;
use Silk\Widgets\Concerns\Hoverable;
use Silk\Widgets\Concerns\MeasuresText;
use Silk\Widgets\Concerns\Pressable;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\Windows\Mail\View\SelectionChanged;

/**
 * One choice from a list of strings: `dropdown(name, options, selected)` as TKDropdown. A click,
 * Enter or Space opens the list with the selected row over the field (Raven); choosing posts
 * SelectionChanged. Up and Down on the closed, focused field step the selection.
 */
class Dropdown extends Widget implements Chooses
{
    use Hoverable, MeasuresText, Pressable;

    /** @var list<string> */
    private array $options = [];

    private int $selected = -1;

    private ?DropdownList $list = null;

    /** @param list<string> $options */
    public function __construct(string $name, array $options, int $selected = 0)
    {
        parent::__construct($name);
        $this->focusable = true;
        $this->options = array_values($options);
        $this->selected = $options === [] ? -1 : max(0, min($selected, count($options) - 1));
    }

    /** @return list<string> */
    public function options(): array
    {
        return $this->options;
    }

    /** The selection is kept when still in range, else the first option (-1 when empty). */
    public function setOptions(array $options): static
    {
        $this->options = array_values($options);
        $this->selected = $this->options === [] ? -1 : ($this->selected >= 0 && $this->selected < count($this->options) ? $this->selected : 0);

        return $this->invalidateLayout();
    }

    public function selected(): int
    {
        return $this->selected;
    }

    public function selectedOption(): ?string
    {
        return $this->options[$this->selected] ?? null;
    }

    /** Posts nothing: the app changed it, it knows. */
    public function select(int $index): static
    {
        if ($index < 0 || $index >= count($this->options)) {
            throw new InvalidArgumentException("Dropdown '{$this->name}' has no option {$index}.");
        }
        $this->selected = $index;

        return $this->invalidatePaint();
    }

    public function list(): ?DropdownList
    {
        return $this->list;
    }

    /** A user's choice: selects, closes the list, posts SelectionChanged when it changed. */
    public function choose(int $index): void
    {
        $this->list?->close();
        if ($index === $this->selected || ! isset($this->options[$index])) {
            return;
        }
        $this->select($index);
        $this->post(new SelectionChanged($this->window(), $this->path(), $this->uuid(), $index, $this->options[$index]));
    }

    public function springs(): array
    {
        return [$this->hover(), $this->press()];
    }

    protected function measureContent(Size $available): Size
    {
        $look = $this->look();
        $widest = 0.0;
        foreach ($this->options as $o) {
            $widest = max($widest, $this->textSize($o)->w);
        }

        // text + padding + room for the arrow (Raven combo_box.c:304-314)
        $height = $look->metric(Metric::ControlHeight);

        return new Size(ceil(max($widest, 40.0) + 2 * $look->metric(Metric::PaddingX) + $height * 0.7), $height);
    }

    public function handle(SilkEvent $e): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }
        $this->hoverEvent($e);
        if ($e->type === EventType::KeyDown && ($e->key === Key::DOWN || $e->key === Key::UP) && $this->options !== []) {
            $this->choose(max(0, min(count($this->options) - 1, $this->selected + ($e->key === Key::DOWN ? 1 : -1))));

            return true;
        }

        return $this->pressEvent($e);
    }

    /** Opens the list. */
    protected function activate(): void
    {
        if ($this->options === [] || $this->tree() === null) {
            return;
        }
        // below the field, as Raven (combo_box.c:284-297); the list places itself against the field
        $this->list = (new DropdownList($this->name().'-list', $this, $this->options, $this->selected))->in($this->tree());
        $this->list->openAt($this->bounds()->x, $this->bounds()->y + $this->bounds()->h + 2);
    }

    public function paint(Painter $p): void
    {
        $look = $this->look();
        $b = $this->bounds();
        $enabled = $this->isEnabled();
        $body = $look->part('Dropdown', 'Body', $enabled ? State::Normal : State::Disabled);
        // the field stays pressed while its list is open (Raven rvn_widget_press(widget, painter, open))
        if ($this->list?->isOpen()) {
            $this->press()->target(1.0);
        }
        $bg = $body->background;
        if ($enabled) {
            $bg = self::mix($bg, $look->part('Dropdown', 'Body', State::Hover)->background, $this->hover()->value());
            $bg = self::mix($bg, $look->part('Dropdown', 'Body', State::Pressed)->background, max(0.0, $this->press()->value()));
        }
        if ($look->material()->isGlass()) {
            // combo_box.c:371: a control of glass; it stays pressed while the list is open
            Surfaces::paint($p, $look, $b, $b->h / 2, SurfaceKind::Control, max(0.0, min(1.0, $this->hover()->value())), max(0.0, $this->press()->value()), $enabled);
        } else {
            if ($body->shadow > 0 && $body->shadowColor !== null) {
                $p->shadow($b, $body->radius, $body->shadow, $body->shadowColor);
            }
            PartBox::paint($p, $b, $bg, $body->radius, $body->border, $body->borderWidth, $body->borderTop, $body->borderBottom);
        }

        // the text before the arrow's room (combo_box.c:348, 378)
        $x = $body->extra;
        $pad = $look->metric(Metric::PaddingX);
        $room = $x['arrowRoom'] ?? $b->h * 0.7;
        $ink = $body->text ?? $this->color($enabled ? Role::Text : Role::TextDisabled, $look);
        $area = new Rect($b->x + $pad, $b->y, max(0.0, $b->w - $pad - $room), $b->h);
        $p->pushClip($area);
        $p->text($this->selectedOption() ?? '', $area->x, $this->baselineIn($b), $ink, $this->textStyle());
        $p->popClip();

        $cy = $b->y + $b->h / 2;
        match ($x['arrow'] ?? 'icon') {
            // the pop-up button's square of the accent, its two little chevrons (combo_box.c:352-363)
            'popup' => (function () use ($p, $look, $b, $enabled, $ink) {
                $square = new Rect($b->x + $b->w - 19, $b->y + floor(($b->h - 16) / 2), 16.0, 16.0);
                $a = $look->part('Dropdown', 'Arrow', $enabled ? State::Normal : State::Disabled);
                if ($a->background !== null) {
                    PartBox::paint($p, $square, $a->background, $a->radius, $a->border, $a->borderWidth);
                }
                Icons::chevron($p, $square->x + 8, $square->y + 5.5, 5.0, -M_PI_2, $a->text ?? $ink);
                Icons::chevron($p, $square->x + 8, $square->y + 10.5, 5.0, M_PI_2, $a->text ?? $ink);
            })(),
            // the symbolic chevron of the platform (combo_box.c:366-370, platform_looks.c:309)
            'chevron' => Icons::chevron($p, $b->x + $b->w - ($x['chevronRight'] ?? 18.0), $cy, ($x['chevronSize'] ?? 12.0) * 0.6, M_PI_2, $ink),
            // Raven's chevron icon in the arrow's room (combo_box.c:386)
            default => Icons::paint($p, Icons::CHEVRON_DOWN, $b->x + $b->w - $room * 0.5 - 2, $cy, 7.0, $ink),
        };
    }
}
