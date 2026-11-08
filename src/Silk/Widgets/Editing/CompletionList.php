<?php

namespace Silk\Widgets\Editing;

use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Look\FontRole;
use Silk\Contracts\Look\FontSpec;
use Silk\Contracts\Look\Metric;
use Silk\Contracts\Look\Role;
use Silk\Contracts\Painter\Painter;
use Silk\Core\Motion\Spring;
use Silk\Core\Motion\SpringPreset;
use Silk\Core\Overlay\Popup;
use Silk\Widgets\Concerns\MeasuresText;
use Silk\Widgets\Rows\RowScroll;
use Surface\Contracts\HumanInput\MouseButton;
use Surface\NutsAndBolts\Color;

/**
 * A TextEditor's proposals (Raven text_editor.c:1515-1760): a popup that never takes the keyboard; the editor
 * hands it the keys that move in the list or choose from it, and every character typed narrows it down.
 */
final class CompletionList extends Popup
{
    use MeasuresText;

    /** text_editor.c:1524-1525 */
    public const int MAX_ITEMS = 400;

    public const int MAX_ROWS = 8;

    /** @var list<array{text: string, detail: ?string, rank: int, order: int}> rank 0: given by the app; 1: starts as typed; 2: so in any case */
    public array $items = [];

    public int $selected = 0;

    /** @var array{int, int} where the word being completed starts */
    public array $start = [0, 0];

    public int $prefixLength = 0;

    public readonly RowScroll $bar;

    private Spring $selectY;

    private bool $selectPlaced = false;

    /** @param \Closure(int): void $accept */
    public function __construct(string $name, private readonly \Closure $accept)
    {
        parent::__construct($name, 0, 0);
        $this->bar = new RowScroll;
        $this->selectY = new Spring(0.0, SpringPreset::Snappy);
    }

    public function takesFocus(): bool
    {
        return false;
    }

    public function addItem(string $text, ?string $detail, int $rank): void
    {
        if (count($this->items) < self::MAX_ITEMS && $text !== '') {
            $this->items[] = ['text' => $text, 'detail' => $detail, 'rank' => $rank, 'order' => count($this->items)];
        }
    }

    /** Sorted as rvn_edc_compare, then each word once (text_editor.c:1571-1582, 1853-1869). */
    public function finish(): void
    {
        usort($this->items, function ($x, $y) {
            if ($x['rank'] !== $y['rank']) {
                return $x['rank'] <=> $y['rank'];
            }
            if ($x['rank'] === 0) {
                return $x['order'] <=> $y['order'];
            }
            // the same word twice: the one that says what it is comes first, and is kept
            return strcasecmp($x['text'], $y['text']) ?: strcmp($x['text'], $y['text']) ?: ($x['detail'] === null ? 1 : 0) - ($y['detail'] === null ? 1 : 0);
        });
        $kept = [];
        $given = [];
        foreach ($this->items as $item) {
            $last = end($kept);
            if (($last !== false && $last['text'] === $item['text']) || ($item['rank'] !== 0 && isset($given[$item['text']]))) {
                continue;
            }
            $item['rank'] === 0 && $given[$item['text']] = true;
            $kept[] = $item;
        }
        $this->items = $kept;
        $this->selected = 0;
        $this->bar->set(0.0);
        $this->selectPlaced = false;
        $this->invalidateLayout();
    }

    private function rowHeight(): float
    {
        return $this->look()->metric(Metric::RowHeight);
    }

    /** Raven's flat looks pad 1, glass 5 (text_editor.c:1589). */
    private function pad(): float
    {
        return $this->look()->material()->isGlass() ? 5.0 : 1.0;
    }

    private function mono(): FontSpec
    {
        return $this->textStyle(FontRole::Mono);
    }

    /** rvn_edc_measure (text_editor.c:1592-1604). */
    protected function measureContent(Size $available): Size
    {
        $width = 0.0;
        foreach (array_slice($this->items, 0, 64) as $item) {
            $w = $this->measurer()->measure($item['text'], $this->mono())->w;
            $item['detail'] !== null && $w += $this->textSize($item['detail'])->w + 28.0;
            $width = max($width, $w);
        }
        $width = max(180.0, min(460.0, $width + $this->look()->metric(Metric::PaddingX) * 2.0 + 14.0));
        $rows = min(count($this->items), self::MAX_ROWS);

        return new Size(ceil($width), $this->rowHeight() * max($rows, 1) + $this->pad() * 2.0);
    }

    private function track(): Rect
    {
        $b = $this->bounds();
        $bar = $this->look()->metric(Metric::ScrollbarSize);

        return new Rect($b->x + $b->w - $bar - 1, $b->y + $this->pad(), $bar, max(0.0, $b->h - $this->pad() * 2));
    }

    private function sync(): void
    {
        $this->bar->content = $this->rowHeight() * count($this->items);
        $this->bar->viewport = max(0.0, $this->bounds()->h - $this->pad() * 2);
        $this->bar->clamp();
    }

    /** rvn_edc_reveal */
    private function reveal(): void
    {
        $this->sync();
        $row = $this->rowHeight();
        $top = $row * $this->selected;
        if ($top < $this->bar->offset) {
            $this->bar->set($top);
        } elseif ($top + $row > $this->bar->offset + $this->bar->viewport) {
            $this->bar->set($top + $row - $this->bar->viewport);
        }
    }

    /** The keys the editor hands over: 'up', 'down', 'pageUp', 'pageDown' move; 'accept' chooses (rvn_edc_event). */
    public function key(string $key): bool
    {
        $count = count($this->items);
        if ($count === 0) {
            return false;
        }
        if ($key === 'accept') {
            ($this->accept)($this->selected);

            return true;
        }
        $this->selected = match ($key) {
            'up' => $this->selected <= 0 ? $count - 1 : $this->selected - 1,
            'down' => $this->selected + 1 >= $count ? 0 : $this->selected + 1,
            'pageUp' => max($this->selected - self::MAX_ROWS + 1, 0),
            'pageDown' => min($this->selected + self::MAX_ROWS - 1, $count - 1),
            default => $this->selected,
        };
        $this->reveal();
        $this->invalidatePaint();

        return true;
    }

    public function handle(SilkEvent $e): bool
    {
        $this->sync();
        if (in_array($e->type, [EventType::MouseDown, EventType::MouseMove, EventType::MouseUp], true) && $this->bar->event($e, $this->track(), true)) {
            $this->invalidatePaint();

            return true;
        }
        switch ($e->type) {
            case EventType::MouseDown:
                if ($e->button === MouseButton::LEFT) {
                    $row = (int) floor(($e->y - $this->bounds()->y - $this->pad() + $this->bar->offset) / $this->rowHeight());
                    $row >= 0 && $row < count($this->items) && ($this->accept)($row);
                }

                return true;
            case EventType::Wheel:
                $this->bar->wheel($e->wheelY * $this->rowHeight() * 3.0);
                $this->invalidatePaint();

                return true;
            default:
                return false;
        }
    }

    public function springs(): array
    {
        return [...parent::springs(), ...$this->bar->springs(), $this->selectY];
    }

    /** rvn_edc_paint (text_editor.c:1626-1668): what is typed in AccentHovered, the rest in Text, the detail at the right. */
    protected function paintSelf(Painter $p): void
    {
        parent::paintSelf($p);
        $this->sync();
        $look = $this->look();
        $b = $this->bounds();
        $row = $this->rowHeight();
        $pad = $this->pad();
        $padding = $look->metric(Metric::PaddingX) * 0.8;
        $offset = $this->bar->drawn();
        $mono = $this->mono();
        $ui = $this->textStyle();
        $small = new FontSpec($ui->family, $ui->size - 1.0, $ui->weight, $ui->italic);
        $text = $look->color(Role::Text);
        $accent = $look->color(Role::AccentHovered);
        $fade = new Color($text->red, $text->green, $text->blue, $text->alpha * 0.5);
        $p->pushClip(new Rect($b->x + 1, $b->y + $pad, $b->w - 2, $b->h - $pad * 2));
        if ($this->selected >= 0 && $this->selected < count($this->items)) {
            $target = $row * $this->selected;
            $this->selectPlaced || $this->selectY->snap($target);
            $this->selectPlaced = true;
            $this->selectY->target($target);
            $p->rect(new Rect($b->x + 1, $b->y + $pad + $this->selectY->value() - $offset, $b->w - 2, $row), $look->color(Role::Selection)); // rvn_paint_row: a flat row (common.c:180-186)
        }
        $first = max(0, (int) floor($offset / $row));
        $last = min(count($this->items), $first + (int) ($this->bar->viewport / $row) + 3);
        for ($i = $first; $i < $last; $i++) {
            $item = $this->items[$i];
            $y = $b->y + $pad + $row * $i - $offset;
            $x = $b->x + $padding;
            $typed = min($this->prefixLength, strlen($item['text']));
            $m = $this->measurer()->metrics($mono);
            $baseline = $y + ($row - ($m['ascent'] + $m['descent'])) / 2 + $m['ascent'];
            // what is already typed stands out from what choosing the word would add
            $typed > 0 && $p->text(substr($item['text'], 0, $typed), $x, $baseline, $accent, $mono);
            $p->text(substr($item['text'], $typed), $x + $this->measurer()->measure(substr($item['text'], 0, $typed), $mono)->w, $baseline, $text, $mono);
            if ($item['detail'] !== null) {
                $w = $this->measurer()->measure($item['detail'], $small)->w;
                $p->text($item['detail'], $b->x + $b->w - $padding - 6.0 - $w, $baseline, $fade, $small);
            }
        }
        $p->popClip();
        $this->bar->paint($p, $look, $this->track(), true, false, false);
    }
}
