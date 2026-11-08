<?php

namespace Silk\Widgets\Rows;

use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Look\Metric;
use Silk\Contracts\Look\Role;
use Silk\Contracts\Look\State;
use Silk\Contracts\Painter\Painter;
use Silk\Core\Motion\Spring;
use Silk\Core\Motion\SpringPreset;
use Silk\Core\Tree\Widget;
use Silk\Widgets\Concerns\MeasuresText;
use Surface\NutsAndBolts\Color;

/**
 * What Raven's ListView and TreeView share (list_view.c, tree_view.c): a frame (the widget's Body part: Input,
 * Border, Focus when focused), rows of RowHeight clipped 1 unit inside it, a scrollbar of their own (RowScroll)
 * on a track at the right, the wheel moving 3 rows a notch, a selection that slides SNAPPY and shows dimmer
 * without the keyboard focus (Selection mixed 0.45 to Input), and the hovered row. Only the rows in view are
 * drawn. Rows are the Row part: a plain rect in the flat looks; a look may inset and round them.
 */
abstract class ScrollingRows extends Widget
{
    use MeasuresText;

    protected RowScroll $bar;

    protected int $hot = -1;

    protected bool $hovered = false;

    protected bool $hasFocus = false;

    /** The pointer, for the bar's hot track. @var array{float, float} */
    private array $pointer = [-1.0, -1.0];

    protected Spring $selectY;

    private bool $selectStarted = false;

    public function __construct(string $name)
    {
        parent::__construct($name);
        $this->focusable = true;
        $this->bar = new RowScroll;
        $this->selectY = new Spring(0.0, SpringPreset::Snappy);
    }

    abstract public function rowCount(): int;

    /** The selected row (shown order), -1 for none. */
    abstract protected function selectedRow(): int;

    abstract protected function paintRowContent(Painter $p, int $row, Rect $line, Color $text): void;

    /** Raven's part name for the frame and rows (ListView, TreeView). */
    abstract protected function partName(): string;

    /** Room above the rows (a header); none for a list. */
    protected function headerHeight(): float
    {
        return 0.0;
    }

    public function rowHeight(): float
    {
        return $this->look()->metric(Metric::RowHeight);
    }

    /** The bar: offset, content and viewport, for tests and callers. */
    public function scroll(): RowScroll
    {
        return $this->bar;
    }

    public function springs(): array
    {
        return [...$this->bar->springs(), $this->selectY];
    }

    /** 160 wide, rows between $min and $max (list_view.c:66-70). */
    protected function measureRows(int $min, int $max): Size
    {
        return new Size(160.0, $this->rowHeight() * max($min, min($max, $this->rowCount())) + 2);
    }

    protected function measureContent(Size $available): Size
    {
        return $this->measureRows(3, 8);
    }

    protected function arrangeContent(Rect $r): void
    {
        $this->syncBar();
    }

    protected function syncBar(): void
    {
        $this->bar->content = $this->rowHeight() * $this->rowCount();
        $this->bar->viewport = max(0.0, $this->bounds()->h - 2 - $this->headerHeight());
        $this->bar->clamp();
    }

    /** The bar's track: inside the right edge (list_view.c:72-76). */
    public function track(): Rect
    {
        $b = $this->bounds();
        $s = $this->look()->metric(Metric::ScrollbarSize);

        $top = 1 + $this->headerHeight();

        return new Rect($b->x + $b->w - $s - 1, $b->y + $top, $s, max(0.0, $b->h - 1 - $top));
    }

    /** The row at layout $y, -1 for none (list_view.c:78-84). */
    public function rowAt(float $y): int
    {
        $b = $this->bounds();
        $top = $b->y + 1 + $this->headerHeight();
        if ($y < $top || $y >= $b->y + $b->h - 1) {
            return -1;
        }
        $row = (int) floor(($y - $top + $this->bar->offset) / $this->rowHeight());

        return $row >= 0 && $row < $this->rowCount() ? $row : -1;
    }

    /** Scrolls the least that shows row $row whole (list_view.c:86-96). */
    public function reveal(int $row): void
    {
        if ($row < 0) {
            return;
        }
        $this->syncBar();
        $h = $this->rowHeight();
        $top = $h * $row;
        if ($top < $this->bar->offset) {
            $this->bar->set($top);
        } elseif ($top + $h > $this->bar->offset + $this->bar->viewport) {
            $this->bar->set($top + $h - $this->bar->viewport);
        }
        $this->invalidatePaint();
    }

    /** The y the selection is drawn at, from the top of the rows. */
    public function highlightY(): float
    {
        return $this->selectY->value();
    }

    /** Rows a page moves: what the view holds, less one (list_view.c:259). */
    protected function page(): int
    {
        return max(1, (int) ($this->bar->viewport / $this->rowHeight()) - 1);
    }

    /** Down/Up/PageUp/PageDown/Home/End from $row (-1: none) over $count rows, or null for another key (list_view.c:260-275). */
    protected static function stepKey(\Surface\Contracts\HumanInput\Key $key, int $row, int $count, int $page): ?int
    {
        return RowKeys::step($key, $row, $count, $page);
    }

    /** The scrollbar and the hover, before the widget's own handling; true when the bar took it. */
    protected function common(SilkEvent $e): ?bool
    {
        if (in_array($e->type, [EventType::MouseDown, EventType::MouseMove, EventType::MouseUp], true)) {
            $this->pointer = [$e->x, $e->y];
            if ($this->bar->event($e, $this->track(), true)) {
                $this->invalidatePaint();

                return true;
            }
        }
        switch ($e->type) {
            case EventType::Enter:
                $this->hovered = true;

                return false;
            case EventType::MouseMove:
                $row = $this->rowAt($e->y);
                if ($row !== $this->hot) {
                    $this->hot = $row;
                    $this->invalidatePaint();
                }

                return null;
            case EventType::Leave:
                [$this->hot, $this->hovered] = [-1, false];
                $this->invalidatePaint();

                return false;
            case EventType::Wheel:
                // 3 rows a notch (list_view.c:247); at an end it bubbles
                if (! $this->bar->wheel($e->wheelY * $this->rowHeight() * 3)) {
                    $this->invalidatePaint();

                    return false;
                }
                $this->hot = $this->rowAt($e->y);
                $this->invalidatePaint();

                return true;
            case EventType::FocusGained:
            case EventType::FocusLost:
                $this->hasFocus = $e->type === EventType::FocusGained;
                $this->invalidatePaint();

                return false;
            default:
                return null;
        }
    }

    public function settleMotion(): void
    {
        parent::settleMotion();
        $this->bar->shown->atRest() || $this->invalidatePaint();
    }

    public function paint(Painter $p): void
    {
        $look = $this->look();
        $b = $this->bounds();
        $name = $this->partName();
        $enabled = $this->isEnabled();
        $body = $look->part($name, 'Body', $enabled ? State::Normal : State::Disabled);
        $focused = $look->part($name, 'Body', State::Focused);
        $p->rect($b, $body->background ?? $this->color(Role::Input, $look), $look->metric(Metric::CornerRadius),
            $this->hasFocus ? ($focused->border ?? $body->border) : $body->border, $look->metric(Metric::BorderWidth));
        $row = $this->rowHeight();
        $offset = $this->bar->drawn();
        $first = max(0, (int) floor($offset / $row));
        $last = min($this->rowCount(), $first + (int) ($this->bar->viewport / $row) + 3);
        $width = $b->w - 2 - ($this->bar->needed() ? $look->metric(Metric::ScrollbarSize) : 0.0);
        $text = $this->color($enabled ? Role::Text : Role::TextDisabled, $look);
        $top = $b->y + 1 + $this->headerHeight();
        $this->paintHeader($p, new Rect($b->x + 1, $b->y + 1, $b->w - 2, $this->headerHeight()));
        $p->pushClip(new Rect($b->x + 1, $top, $b->w - 2, max(0.0, $b->y + $b->h - 1 - $top)));
        $selected = $this->selectedRow();
        if ($selected >= 0) {
            // the selection slides from the previous row to the new one; a list without the focus shows it dimmer
            $this->selectStarted ? $this->selectY->target($row * $selected) : $this->selectY->snap($row * $selected);
            $this->selectStarted = true;
            $sel = $this->color(Role::Selection, $look);
            $this->paintRow($p, new Rect($b->x + 1, $top + $this->selectY->value() - $offset, $width, $row),
                $this->hasFocus ? $sel : self::mixColor($sel, $this->color(Role::Input, $look), 0.45));
        } else {
            $this->selectStarted = false;
        }
        for ($i = $first; $i < $last; $i++) {
            $line = new Rect($b->x + 1, $top + $row * $i - $offset, $width, $row);
            $this->paintRowBackground($p, $i, $line, $selected);
            $this->paintRowContent($p, $i, $line, $text);
        }
        $p->popClip();
        $this->bar->paint($p, $look, $this->track(), true, $this->hovered, $this->track()->contains(...$this->pointer));
    }

    /** Above the rows, in $r (headerHeight() high); nothing for a list. */
    protected function paintHeader(Painter $p, Rect $r): void {}

    /** The hovered row's background, under its content (list_view.c:162-165). */
    protected function paintRowBackground(Painter $p, int $i, Rect $line, int $selected): void
    {
        if ($i !== $selected && $i === $this->hot && $this->hovered && $this->isEnabled()) {
            $this->paintRow($p, $line, $this->color(Role::RowHovered, $this->look()));
        }
    }

    /** rvn_paint_row (common.c:180-186): the Row part's inset and radius (flat looks: none). */
    protected function paintRow(Painter $p, Rect $r, Color $c): void
    {
        $part = $this->look()->part($this->partName(), 'Row', State::Normal);
        $ix = (float) ($part->extra['insetX'] ?? 0.0);
        $iy = (float) ($part->extra['insetY'] ?? 0.0);
        $p->rect(new Rect($r->x + $ix, $r->y + $iy, $r->w - 2 * $ix, $r->h - 2 * $iy), $c, $part->radius);
    }

    protected static function mixColor(Color $a, Color $b, float $t): Color
    {
        return new Color($a->red + ($b->red - $a->red) * $t, $a->green + ($b->green - $a->green) * $t,
            $a->blue + ($b->blue - $a->blue) * $t, $a->alpha + ($b->alpha - $a->alpha) * $t);
    }

    /** Text from $x across the row, clipped short of its end. */
    protected function rowText(Painter $p, string $s, Rect $line, float $x, Color $c): void
    {
        $style = $this->textStyle();
        $p->pushClip(new Rect($x, $line->y, max(0.0, $line->x + $line->w - $x), $line->h));
        $p->text($s, $x, $this->baselineIn($line, $style), $c, $style);
        $p->popClip();
    }
}
