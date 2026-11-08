<?php

namespace Silk\Widgets;

use Closure;
use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Look\Metric;
use Silk\Contracts\Mail\RowActivated;
use Silk\Contracts\Painter\Painter;
use Silk\Widgets\Rows\ScrollingRows;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\MouseButton;
use Surface\Contracts\Windows\Mail\View\SelectionChanged;
use Surface\NutsAndBolts\Color;

/**
 * `listView(name, items)`: Raven's list of text rows with one selection (list_view.c). The rows are stored, or
 * pulled from the app when drawn (setVirtualItems); only the visible ones are drawn. A click or a row key
 * selects (Up with none selected goes to the last row) and posts SelectionChanged; selecting scrolls the row
 * into view; Enter or a double click posts RowActivated.
 */
class ListView extends ScrollingRows
{
    /** @var list<string> */
    private array $items;

    private int $selected = -1;

    private ?int $virtualCount = null;

    private ?Closure $virtualText = null;

    /** @param list<string> $items */
    public function __construct(string $name, array $items = [])
    {
        parent::__construct($name);
        $this->items = array_values($items);
    }

    /** @return list<string> the stored items ([] in virtual mode) */
    public function items(): array
    {
        return $this->items;
    }

    /** Stored items; leaves virtual mode. A selection past them is cleared, without mail. */
    public function setItems(array $items): static
    {
        $this->items = array_values($items);
        [$this->virtualCount, $this->virtualText] = [null, null];
        $this->selected >= count($this->items) && $this->selected = -1;
        $this->hot = -1;

        return $this->invalidateLayout();
    }

    /** Rows pulled from the app when drawn: $text(int $index): string (Rlist_view_set_virtual_items). */
    public function setVirtualItems(int $count, Closure $text): static
    {
        [$this->items, $this->virtualCount, $this->virtualText] = [[], max(0, $count), $text];
        $this->selected >= $this->virtualCount && $this->selected = -1;

        return $this->invalidateLayout();
    }

    public function rowCount(): int
    {
        return $this->virtualCount ?? count($this->items);
    }

    public function text(int $index): string
    {
        if ($index < 0 || $index >= $this->rowCount()) {
            return '';
        }

        return $this->virtualText !== null ? (string) ($this->virtualText)($index) : $this->items[$index];
    }

    public function selected(): ?int
    {
        return $this->selected < 0 ? null : $this->selected;
    }

    /** Selects and scrolls the row into view. Posts nothing. */
    public function select(?int $index): static
    {
        $this->setSelected($index ?? -1, false);

        return $this;
    }

    public function scrollToRow(int $i): static
    {
        $this->reveal($i);

        return $this;
    }

    protected function selectedRow(): int
    {
        return $this->selected;
    }

    protected function partName(): string
    {
        return 'ListView';
    }

    public function handle(SilkEvent $e): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }
        if (($done = $this->common($e)) !== null) {
            return $done;
        }
        $count = $this->rowCount();
        switch ($e->type) {
            case EventType::MouseDown:
                if ($e->button !== MouseButton::LEFT) {
                    return false;
                }
                $row = $this->rowAt($e->y);
                if ($row >= 0) {
                    $this->setSelected($row, true);
                    $e->clicks === 2 && $this->activate();
                }

                return true;
            case EventType::MouseMove:
                return false;
            case EventType::KeyDown:
                if ($count === 0) {
                    return false;
                }
                if ($e->key === Key::ENTER || $e->key === Key::NUMPAD_ENTER) {
                    $this->activate();

                    return true;
                }
                $to = $e->key === null ? null : self::stepKey($e->key, $this->selected, $count, $this->page());
                if ($to === null) {
                    return false;
                }
                $this->setSelected($to, true);

                return true;
            default:
                return false;
        }
    }

    protected function paintRowContent(Painter $p, int $row, Rect $line, Color $text): void
    {
        $pad = $this->look()->metric(Metric::PaddingX) * 0.8;
        $this->rowText($p, $this->text($row), new Rect($line->x, $line->y, $line->w - $pad, $line->h), $line->x + $pad, $text);
    }

    /** list_view.c:88-95: any change reveals the row; the user's posts SelectionChanged. */
    private function setSelected(int $index, bool $notify): void
    {
        $index = $index < -1 || $index >= $this->rowCount() ? -1 : $index;
        if ($index === $this->selected) {
            return;
        }
        $this->selected = $index;
        $this->reveal($index);
        $this->invalidatePaint();
        $notify && $this->post(new SelectionChanged($this->window(), $this->path(), $this->uuid(), $index, $index < 0 ? null : $this->text($index)));
    }

    private function activate(): void
    {
        $this->selected >= 0 && $this->post(new RowActivated($this->window(), $this->path(), $this->uuid(), $this->selected));
    }
}
