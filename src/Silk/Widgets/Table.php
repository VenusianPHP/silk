<?php

namespace Silk\Widgets;

use Closure;
use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Look\Metric;
use Silk\Contracts\Look\Role;
use Silk\Contracts\Look\State;
use Silk\Contracts\Mail\ColumnSorted;
use Silk\Contracts\Mail\RowActivated;
use Silk\Contracts\Painter\Painter;
use Silk\Core\Motion\Spring;
use Silk\Core\Motion\SpringPreset;
use Silk\Core\Tree\Widget;
use Silk\Widgets\Concerns\MeasuresText;
use Silk\Widgets\Rows\RowKeys;
use Silk\Widgets\Rows\RowScroll;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\MouseButton;
use Surface\Contracts\Windows\Mail\View\RowSelected;
use Surface\Contracts\Windows\Primitives\TableColumn;
use Surface\Contracts\Windows\WindowException;
use Surface\NutsAndBolts\Color;

/**
 * `table(name, columns, rows)` as TKTable, drawn and driven as Raven's table (table.c): rows of cells under a
 * header of columns the user resizes (a drag within 4 units of an edge; a double click there fits the column) and
 * sorts (a release on the header pressed: up, then the order turned around; numbers by value before texts, texts
 * without regard to case, ties in the order given). TKTable owns the API: rows() is the rows as given and the mail
 * carries the given index; the sort is a view, an index order. Rows can come from the app (setVirtualRows). The
 * table scrolls itself both ways (RowScroll); only the visible rows are drawn. One copy of the rows is kept.
 */
class Table extends Widget
{
    use MeasuresText;

    public const float GRIP = 4.0;

    public const float MIN_COLUMN = 24.0;

    public const float DEFAULT_COLUMN = 120.0;

    public const int FIT_ROWS = 2000;

    /** @var list<TableColumn> */
    private array $columns;

    /** Per column id: width (> 0 given), fill share, alignment 0..1, sortable, and the width laid out. @var array<string, array{width: float, fill: float, align: float, sortable: bool, shown: float}> */
    private array $layout = [];

    /** @var list<array<string, scalar|null>> the rows exactly as given: one copy, shared with the caller's array */
    private array $rows = [];

    private ?int $virtualCount = null;

    private ?Closure $virtualCell = null;

    /** @var list<int>|null display position => row; null: the order given */
    private ?array $order = null;

    /** The selected row's display position, kept so no inverse of the order is needed. */
    private int $selectedDisplay = -1;

    private int $selected = -1;

    private int $hot = -1;

    private ?string $hotHeader = null;

    private ?string $pressedHeader = null;

    private ?string $resizing = null;

    private float $resizeFromX = 0.0;

    private float $resizeFromWidth = 0.0;

    private ?string $sortColumn = null;

    private bool $descending = false;

    private bool $showHeader = true;

    private bool $gridLines = false;

    private bool $striped = false;

    private bool $hasFocus = false;

    private bool $hovered = false;

    /** @var array{float, float} */
    private array $pointer = [-1.0, -1.0];

    private RowScroll $bar;

    private RowScroll $hbar;

    private Spring $selectY;

    private bool $selectStarted = false;

    /** @param list<TableColumn> $columns @param list<array<string, scalar|null>> $rows */
    public function __construct(string $name, array $columns, array $rows = [])
    {
        parent::__construct($name);
        $this->focusable = true;
        $this->columns = array_values($columns);
        foreach ($this->columns as $c) {
            $this->layout[$c->id] = ['width' => self::DEFAULT_COLUMN, 'fill' => 0.0, 'align' => 0.0, 'sortable' => true, 'shown' => self::DEFAULT_COLUMN];
        }
        $this->bar = new RowScroll;
        $this->hbar = new RowScroll;
        $this->selectY = new Spring(0.0, SpringPreset::Snappy);
        $this->setRows($rows);
    }

    /** @return list<TableColumn> */
    public function columns(): array
    {
        return $this->columns;
    }

    /** Every row as given, as column id => cell text, one key per column (made on each call). @return list<array<string, string>> */
    public function rows(): array
    {
        return array_map(fn ($r) => $this->cells($r), $this->rows);
    }

    public function rowCount(): int
    {
        return $this->virtualCount ?? count($this->rows);
    }

    /** Replaces every row; clears the selection. Posts nothing. */
    public function setRows(array $rows): static
    {
        $this->rows = array_is_list($rows) ? $rows : array_values($rows);
        [$this->virtualCount, $this->virtualCell] = [null, null];
        $this->selected = $this->hot = -1;
        $this->bar->offset = 0.0;

        return $this->reorder();
    }

    public function appendRow(array $row): static
    {
        $this->rows[] = $row;

        return $this->reorder();
    }

    public function clearRows(): static
    {
        return $this->setRows([]);
    }

    /** Rows pulled from the app when drawn: $cell(int $row, string $columnId): string. A virtual table is not sorted by the table; ColumnSorted tells the app. */
    public function setVirtualRows(int $count, Closure $cell): static
    {
        [$this->rows, $this->virtualCount, $this->virtualCell] = [[], max(0, $count), $cell];
        $this->selected = $this->hot = -1;

        return $this->reorder();
    }

    public function cell(int $row, string $columnId): string
    {
        if ($row < 0 || $row >= $this->rowCount()) {
            return '';
        }

        return $this->virtualCell !== null ? (string) ($this->virtualCell)($row, $columnId) : (string) ($this->rows[$row][$columnId] ?? '');
    }

    public function selectedRow(): ?int
    {
        return $this->selected < 0 ? null : $this->selected;
    }

    /** Selects (the index as given) and scrolls it into view. Posts nothing. @throws WindowException When the row is outside 0..count(rows)-1. */
    public function selectRow(?int $row): static
    {
        if ($row !== null && ($row < 0 || $row >= $this->rowCount())) {
            throw new WindowException("Table '{$this->name}' has no row {$row}.");
        }
        $this->setSelected($row ?? -1, false);

        return $this;
    }

    /** Sorts the view by a column; null for the order given. Posts nothing. */
    public function sortBy(?string $columnId, bool $ascending = true): static
    {
        [$this->sortColumn, $this->descending] = [$columnId, ! $ascending];
        $this->reorder();
        $this->selected >= 0 && $this->reveal($this->displayOf($this->selected));

        return $this;
    }

    /** @return array{?string, bool} the sort column and whether ascending */
    public function sort(): array
    {
        return [$this->sortColumn, ! $this->descending];
    }

    public function columnWidth(string $id): float
    {
        $this->layColumns();

        return $this->layout[$id]['shown'];
    }

    /** A given width (at least 24); the column no longer fills. */
    public function setColumnWidth(string $id, float $w): static
    {
        $this->layout[$id]['width'] = max(self::MIN_COLUMN, round($w));
        $this->layout[$id]['fill'] = 0.0;

        return $this->invalidateLayout();
    }

    /** > 0: a share of the room the other columns leave (Fill); the width is then a minimum. */
    public function setColumnFill(string $id, float $share): static
    {
        $this->layout[$id]['fill'] = max(0.0, $share);

        return $this->invalidateLayout();
    }

    /** 0 left, 0.5 centre, 1 right. */
    public function setColumnAlign(string $id, float $align): static
    {
        $this->layout[$id]['align'] = max(0.0, min(1.0, $align));

        return $this->invalidatePaint();
    }

    public function setColumnSortable(string $id, bool $sortable): static
    {
        $this->layout[$id]['sortable'] = $sortable;

        return $this->invalidatePaint();
    }

    public function setShowHeader(bool $show): static
    {
        $this->showHeader = $show;

        return $this->invalidateLayout();
    }

    public function setGridLines(bool $on): static
    {
        $this->gridLines = $on;

        return $this->invalidatePaint();
    }

    public function setStriped(bool $on): static
    {
        $this->striped = $on;

        return $this->invalidatePaint();
    }

    /** The vertical and the horizontal scroll. @return array{RowScroll, RowScroll} */
    public function scrolls(): array
    {
        return [$this->bar, $this->hbar];
    }

    public function springs(): array
    {
        return [...$this->bar->springs(), ...$this->hbar->springs(), $this->selectY];
    }

    public function settleMotion(): void
    {
        parent::settleMotion();
        ($this->bar->shown->atRest() && $this->hbar->shown->atRest()) || $this->invalidatePaint();
    }

    public function headerHeight(): float
    {
        return $this->showHeader && $this->columns !== [] ? $this->look()->metric(Metric::RowHeight) + 4 : 0.0;
    }

    /**
     * Where the parts are (table.c:142-169): the header, the body, and each bar's track; a bar takes room from the
     * other direction, so both are decided together. @return array{header: float, body: Rect, vtrack: Rect, htrack: Rect}
     */
    public function geometry(): array
    {
        $b = $this->bounds();
        $size = $this->look()->metric(Metric::ScrollbarSize);
        $header = $this->headerHeight();
        $innerW = max(0.0, $b->w - 2);
        $innerH = max(0.0, $b->h - 2 - $header);
        $content = $this->look()->metric(Metric::RowHeight) * $this->rowCount();
        $vertical = $content > $innerH + 0.5;
        $total = $this->layColumns($innerW - ($vertical ? $size : 0.0));
        $horizontal = $total > $innerW - ($vertical ? $size : 0.0) + 0.5;
        if ($horizontal && ! $vertical && $content > $innerH - $size + 0.5) {
            $vertical = true;
            $total = $this->layColumns($innerW - $size);
        }
        $body = new Rect($b->x + 1, $b->y + 1 + $header, max(0.0, $innerW - ($vertical ? $size : 0.0)), max(0.0, $innerH - ($horizontal ? $size : 0.0)));
        [$this->bar->content, $this->bar->viewport, $this->hbar->content, $this->hbar->viewport] = [$content, $body->h, $total, $body->w];

        return [
            'header' => $header,
            'body' => $body,
            'vtrack' => new Rect($b->x + $b->w - 1 - $size, $body->y, $vertical ? $size : 0.0, $body->h),
            'htrack' => new Rect($b->x + 1, $body->y + $body->h, $body->w, $horizontal ? $size : 0.0),
        ];
    }

    /** clamp(Σwidths + 2, 160, 640) × (header + 3..8 rows + 2) (table.c:171-179). */
    protected function measureContent(Size $available): Size
    {
        $w = 0.0;
        foreach ($this->layout as $l) {
            $w += max($l['width'], self::MIN_COLUMN);
        }

        return new Size(max(160.0, min(640.0, $w + 2)),
            $this->headerHeight() + $this->look()->metric(Metric::RowHeight) * max(3, min(8, $this->rowCount())) + 2);
    }

    protected function arrangeContent(Rect $r): void
    {
        $this->geometry();
        $this->bar->clamp();
        $this->hbar->clamp();
    }

    /** The display position at layout $x, $y, -1 for none. */
    public function rowAt(float $x, float $y): int
    {
        $body = $this->geometry()['body'];
        if (! $body->contains($x, $y)) {
            return -1;
        }
        $row = (int) floor(($y - $body->y + $this->bar->offset) / $this->look()->metric(Metric::RowHeight));

        return $row >= 0 && $row < $this->rowCount() ? $row : -1;
    }

    /** The header column under $x, and the column whose right edge is within GRIP of $x. @return array{?string, ?string} */
    public function headerAt(float $x, float $y): array
    {
        ['header' => $header, 'body' => $body] = $this->geometry();
        $b = $this->bounds();
        if ($header <= 0 || $y < $b->y + 1 || $y >= $b->y + 1 + $header || $x < $b->x + 1 || $x >= $b->x + 1 + $body->w) {
            return [null, null];
        }
        [$found, $edge] = [null, null];
        $left = $b->x + 1 - $this->hbar->offset;
        foreach ($this->columns as $c) {
            $right = $left + $this->layout[$c->id]['shown'];
            abs($x - $right) <= self::GRIP && $edge = $c->id;
            $x >= $left && $x < $right && $found = $c->id;
            $left = $right;
        }

        return [$found, $edge];
    }

    public function handle(SilkEvent $e): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }
        $g = $this->geometry();
        if ($this->resizing === null && in_array($e->type, [EventType::MouseDown, EventType::MouseMove, EventType::MouseUp], true)) {
            $this->pointer = [$e->x, $e->y];
            if (($g['vtrack']->w > 0 || $this->bar->dragging) && $this->bar->event($e, $g['vtrack'], true)
                || ($g['htrack']->h > 0 || $this->hbar->dragging) && $this->hbar->event($e, $g['htrack'], false)) {
                $this->invalidatePaint();

                return true;
            }
        }
        $row = $this->look()->metric(Metric::RowHeight);
        switch ($e->type) {
            case EventType::Enter:
                $this->hovered = true;

                return false;
            case EventType::MouseMove:
                if ($this->resizing !== null) {
                    $l = &$this->layout[$this->resizing];
                    $l['width'] = max(self::MIN_COLUMN, round($this->resizeFromWidth + $e->x - $this->resizeFromX));
                    $l['fill'] = 0.0;
                    $this->invalidateLayout();

                    return true;
                }
                [$header] = $this->headerAt($e->x, $e->y);
                $hot = $this->rowAt($e->x, $e->y);
                if ($hot !== $this->hot || $header !== $this->hotHeader) {
                    [$this->hot, $this->hotHeader] = [$hot, $header];
                    $this->invalidatePaint();
                }

                return false;
            case EventType::Leave:
                [$this->hot, $this->hotHeader, $this->hovered] = [-1, null, false];
                $this->invalidatePaint();

                return false;
            case EventType::MouseDown:
                if ($e->button !== MouseButton::LEFT) {
                    return false;
                }
                [$header, $edge] = $this->headerAt($e->x, $e->y);
                if ($edge !== null) {
                    if ($e->clicks === 2) {
                        $this->fitColumn($edge);

                        return true;
                    }
                    [$this->resizing, $this->resizeFromX, $this->resizeFromWidth] = [$edge, $e->x, $this->layout[$edge]['shown']];
                    $this->invalidatePaint();

                    return true;
                }
                if ($header !== null) {
                    $this->pressedHeader = $this->layout[$header]['sortable'] ? $header : null;
                    $this->invalidatePaint();

                    return true;
                }
                $at = $this->rowAt($e->x, $e->y);
                if ($at >= 0) {
                    $given = $this->givenOf($at);
                    $this->setSelected($given, true);
                    $e->clicks === 2 && $this->activate();
                }

                return true;
            case EventType::MouseUp:
                if ($e->button !== MouseButton::LEFT) {
                    return false;
                }
                if ($this->resizing !== null) {
                    $this->resizing = null;
                    $this->invalidatePaint();

                    return true;
                }
                if ($this->pressedHeader !== null) {
                    $pressed = $this->pressedHeader;
                    $this->pressedHeader = null;
                    $this->invalidatePaint();
                    // a first click sorts up, the next ones turn the order around
                    if ($this->headerAt($e->x, $e->y)[0] === $pressed) {
                        $down = $pressed === $this->sortColumn ? ! $this->descending : false;
                        $this->sortBy($pressed, ! $down);
                        $this->post(new ColumnSorted($this->window(), $this->path(), $this->uuid(), $pressed, ! $down));
                    }

                    return true;
                }

                return false;
            case EventType::Wheel:
                // the wheel scrolls the rows; with Shift, or sideways, the columns
                [$along, $across] = $e->modifiers->shift ? [0.0, $e->wheelY] : [$e->wheelY, $e->wheelX];
                $changed = false;
                $along != 0.0 && $changed = $this->bar->wheel($along * $row * 3) || $changed;
                $across != 0.0 && $changed = $this->hbar->wheel($across * 40) || $changed;
                $changed && $this->hot = $this->rowAt($e->x, $e->y);
                $this->invalidatePaint();

                return $changed;
            case EventType::FocusGained:
            case EventType::FocusLost:
                $this->hasFocus = $e->type === EventType::FocusGained;
                $this->invalidatePaint();

                return false;
            case EventType::KeyDown:
                if ($e->key === Key::LEFT || $e->key === Key::RIGHT) {
                    if (! $this->hbar->needed()) {
                        return false;
                    }
                    $this->hbar->set($this->hbar->offset + ($e->key === Key::LEFT ? -40 : 40));
                    $this->invalidatePaint();

                    return true;
                }
                $count = $this->rowCount();
                if ($count === 0) {
                    return false;
                }
                if ($e->key === Key::ENTER || $e->key === Key::NUMPAD_ENTER) {
                    $this->activate();

                    return true;
                }
                $page = max(1, (int) ($this->bar->viewport / $row) - 1);
                $to = $e->key === null ? null : RowKeys::step($e->key, $this->selected < 0 ? -1 : $this->displayOf($this->selected), $count, $page);
                if ($to === null) {
                    return false;
                }
                $this->setSelected($this->givenOf($to), true);

                return true;
            default:
                return false;
        }
    }

    public function paint(Painter $p): void
    {
        $look = $this->look();
        $b = $this->bounds();
        $g = $this->geometry();
        $row = $look->metric(Metric::RowHeight);
        $pad = $look->metric(Metric::PaddingX) * 0.8;
        $enabled = $this->isEnabled();
        $text = $this->color($enabled ? Role::Text : Role::TextDisabled, $look);
        $separator = $this->color(Role::Separator, $look);
        $style = $this->textStyle();
        $offset = $this->bar->drawn();
        $left = $g['body']->x - $this->hbar->drawn();
        $body = $look->part('Table', 'Body', $enabled ? State::Normal : State::Disabled);
        $focused = $look->part('Table', 'Body', State::Focused);
        $p->rect($b, $body->background ?? $this->color(Role::Input, $look), $look->metric(Metric::CornerRadius),
            $this->hasFocus ? ($focused->border ?? $body->border) : $body->border, $look->metric(Metric::BorderWidth));

        // the header (table.c:364-397)
        if ($g['header'] > 0) {
            $strip = new Rect($b->x + 1, $b->y + 1, $b->w - 2, $g['header']);
            $p->pushClip($strip);
            $p->rect($strip, $this->color(Role::Header, $look));
            $x = $left;
            foreach ($this->columns as $c) {
                $l = $this->layout[$c->id];
                $cell = new Rect($x, $b->y + 1, $l['shown'], $g['header']);
                $arrow = $c->id === $this->sortColumn ? 14.0 : 0.0;
                if (($c->id === $this->hotHeader && $this->hovered && $l['sortable'] && $enabled) || $c->id === $this->pressedHeader) {
                    $p->rect($cell, $this->color(Role::HeaderHovered, $look));
                }
                $this->cellText($p, $c->label, new Rect($cell->x + $pad, $cell->y, max(0.0, $cell->w - $pad * 2 - $arrow), $cell->h), $l['align'], $text);
                if ($c->id === $this->sortColumn) {
                    $this->chevron($p, $cell->x + $cell->w - $pad - 5, $cell->y + $cell->h / 2, $this->descending, new Color($text->red, $text->green, $text->blue, $text->alpha * 0.8));
                }
                // the edge the user drags
                $p->rect(new Rect($cell->x + $cell->w - 1, $cell->y + 5, 1, max(0.0, $cell->h - 10)),
                    $c->id === $this->resizing ? $this->color(Role::Accent, $look) : $separator);
                $x += $l['shown'];
            }
            $p->rect(new Rect($b->x + 1, $b->y + $g['header'], $b->w - 2, 1), $separator);
            $p->popClip();
        }

        // the rows (table.c:399-447)
        $p->pushClip($g['body']);
        $first = max(0, (int) floor($offset / $row));
        $last = min($this->rowCount(), $first + (int) ($g['body']->h / $row) + 3);
        if ($this->striped) {
            $hover = $this->color(Role::RowHovered, $look);
            $stripe = new Color($hover->red, $hover->green, $hover->blue, $hover->alpha * 0.45);
            for ($i = $first; $i < $last; $i++) {
                $i % 2 === 1 && $p->rect(new Rect($g['body']->x, $g['body']->y + $row * $i - $offset, $g['body']->w, $row), $stripe);
            }
        }
        if ($this->selected >= 0) {
            $y = $row * $this->displayOf($this->selected);
            $this->selectStarted ? $this->selectY->target($y) : $this->selectY->snap($y);
            $this->selectStarted = true;
            $sel = $this->color(Role::Selection, $look);
            $in = $this->color(Role::Input, $look);
            $p->rect(new Rect($g['body']->x, $g['body']->y + $this->selectY->value() - $offset, $g['body']->w, $row),
                $this->hasFocus ? $sel : new Color($sel->red + ($in->red - $sel->red) * 0.45, $sel->green + ($in->green - $sel->green) * 0.45,
                    $sel->blue + ($in->blue - $sel->blue) * 0.45, $sel->alpha + ($in->alpha - $sel->alpha) * 0.45));
        } else {
            $this->selectStarted = false;
        }
        for ($i = $first; $i < $last; $i++) {
            $y = $g['body']->y + $row * $i - $offset;
            $given = $this->givenOf($i);
            if ($given !== $this->selected && $i === $this->hot && $this->hovered && $enabled) {
                $p->rect(new Rect($g['body']->x, $y, $g['body']->w, $row), $this->color(Role::RowHovered, $look));
            }
            $x = $left;
            foreach ($this->columns as $c) {
                $w = $this->layout[$c->id]['shown'];
                if ($x + $w >= $g['body']->x && $x <= $g['body']->x + $g['body']->w) {
                    $this->cellText($p, $this->cell($given, $c->id), new Rect($x + $pad, $y, max(0.0, $w - $pad * 2), $row), $this->layout[$c->id]['align'], $text);
                }
                $x += $w;
            }
            if ($this->gridLines) {
                $p->rect(new Rect($g['body']->x, $y + $row - 1, $g['body']->w, 1), new Color($separator->red, $separator->green, $separator->blue, $separator->alpha * 0.6));
            }
        }
        if ($this->gridLines) {
            $x = $left;
            foreach (array_slice($this->columns, 0, -1) as $c) {
                $x += $this->layout[$c->id]['shown'];
                $p->rect(new Rect($x - 1, $g['body']->y, 1, min($g['body']->h, $row * $this->rowCount() - $offset)),
                    new Color($separator->red, $separator->green, $separator->blue, $separator->alpha * 0.6));
            }
        }
        $p->popClip();
        $g['vtrack']->w > 0 && $this->bar->paint($p, $look, $g['vtrack'], true, $this->hovered, $g['vtrack']->contains(...$this->pointer));
        $g['htrack']->h > 0 && $this->hbar->paint($p, $look, $g['htrack'], false, $this->hovered, $g['htrack']->contains(...$this->pointer));
    }

    /** A cell's text, clipped to $r, aligned 0..1 across it. */
    private function cellText(Painter $p, string $s, Rect $r, float $align, Color $c): void
    {
        $style = $this->textStyle();
        $p->pushClip($r);
        $p->text($s, $r->x + max(0.0, $r->w - $this->textSize($s, $style)->w) * ($align > 0 ? $align : 0.0), $this->baselineIn($r, $style), $c, $style);
        $p->popClip();
    }

    /** A 6-unit chevron at $x, $y: up for ascending, down for descending (RVN_ICON_CHEVRON_UP/DOWN). */
    private function chevron(Painter $p, float $x, float $y, bool $down, Color $c): void
    {
        $s = 3.0;
        $d = $down ? 1 : -1;
        $w = max(1.0, $this->look()->metric(Metric::BorderWidth) * 1.5);
        $p->line($x - $s, $y - $d * $s / 2, $x, $y + $d * $s / 2, $c, $w);
        $p->line($x, $y + $d * $s / 2, $x + $s, $y - $d * $s / 2, $c, $w);
    }

    /** Lays the columns in $room (the fill columns share what the others leave); returns their total (table.c:121-140). */
    private function layColumns(?float $room = null): float
    {
        $room ??= max(0.0, $this->bounds()->w - 2);
        [$fixed, $fills] = [0.0, 0.0];
        foreach ($this->layout as $id => $l) {
            $this->layout[$id]['shown'] = max($l['width'], self::MIN_COLUMN);
            $fills += $l['fill'];
            $fixed += $this->layout[$id]['shown'];
        }
        $left = $room - $fixed;
        $total = 0.0;
        foreach ($this->layout as $id => $l) {
            if ($l['fill'] > 0 && $left > 0 && $fills > 0) {
                $this->layout[$id]['shown'] += floor($left * $l['fill'] / $fills);
            }
            $total += $this->layout[$id]['shown'];
        }

        return $total;
    }

    /** The width that shows the header and the first 2000 cells of a column (table.c:322-333). */
    private function fitColumn(string $id): void
    {
        $label = '';
        foreach ($this->columns as $c) {
            $c->id === $id && $label = $c->label;
        }
        $width = $this->textSize($label)->w + ($this->layout[$id]['sortable'] ? 14.0 : 0.0);
        for ($i = 0, $n = min($this->rowCount(), self::FIT_ROWS); $i < $n; $i++) {
            $width = max($width, $this->textSize($this->cell($i, $id))->w);
        }
        $this->layout[$id]['width'] = ceil($width + $this->look()->metric(Metric::PaddingX) * 0.8 * 2 + 2);
        $this->layout[$id]['fill'] = 0.0;
        $this->invalidateLayout();
    }

    /** @param array<string, scalar|null> $row @return array<string, string> one key per column, '' when missing */
    private function cells(array $row): array
    {
        $out = [];
        foreach ($this->columns as $c) {
            $out[$c->id] = (string) ($row[$c->id] ?? '');
        }

        return $out;
    }

    /**
     * The view's order: the given one, or sorted by the sort column (stored rows only; a virtual table is the app's
     * to order). Raven's comparison (table.c:262-292): numbers by value before texts, texts without regard to case,
     * then exactly; ties keep the order given; descending turns all of it around but the ties.
     */
    private function reorder(): static
    {
        // the old order goes first: two orders never live at once
        $this->order = null;
        if ($this->sortColumn !== null && $this->virtualCell === null && $this->rowCount() >= 2) {
            $this->order = $this->sorted($this->sortColumn, $this->descending);
        }
        $this->selectedDisplay = $this->selected < 0 ? -1 : ($this->order === null ? $this->selected : (int) array_search($this->selected, $this->order, true));
        $this->selectStarted = false;
        $this->invalidateLayout();

        return $this->invalidatePaint();
    }

    /**
     * The rows' indices in the order of $col. PHP's sort is stable, so the sort runs in place on two keyed arrays,
     * with no key columns and no compare closure: the numbers by value; the texts first exactly, then without case
     * (so ties fall back to the exact order, then to the order given). Numbers come before texts; descending turns
     * that around, and turns each sort around, ties still in the order given. @return list<int>
     */
    private function sorted(string $col, bool $descending): array
    {
        [$numbers, $texts] = [[], []];
        foreach ($this->rows as $i => $r) {
            $t = (string) ($r[$col] ?? '');
            $n = rtrim(ltrim($t, ' '), ' %');
            if ($n !== '' && is_numeric($n)) {
                $numbers[$i] = (float) $n;
            } else {
                $texts[$i] = $t;
            }
        }
        $descending ? arsort($numbers, SORT_NUMERIC) : asort($numbers, SORT_NUMERIC);
        $descending ? arsort($texts, SORT_STRING) : asort($texts, SORT_STRING);
        $descending ? arsort($texts, SORT_STRING | SORT_FLAG_CASE) : asort($texts, SORT_STRING | SORT_FLAG_CASE);

        [$first, $then] = $descending ? [$texts, $numbers] : [$numbers, $texts];
        unset($numbers, $texts);
        if ($then === []) {
            return array_keys($first);
        }
        $order = array_keys($first);
        unset($first);
        foreach ($then as $i => $_) {
            $order[] = $i;
        }

        return $order;
    }

    private function givenOf(int $display): int
    {
        return $this->order[$display] ?? $display;
    }

    /** The display position of the selected row is kept; any other is looked up (rare: the app asking). */
    private function displayOf(int $given): int
    {
        if ($given === $this->selected && $this->selectedDisplay >= 0) {
            return $this->selectedDisplay;
        }

        return $this->order === null ? $given : (int) array_search($given, $this->order, true);
    }

    private function reveal(int $display): void
    {
        if ($display < 0) {
            return;
        }
        $this->geometry();
        $h = $this->look()->metric(Metric::RowHeight);
        $top = $h * $display;
        if ($top < $this->bar->offset) {
            $this->bar->set($top);
        } elseif ($top + $h > $this->bar->offset + $this->bar->viewport) {
            $this->bar->set($top + $h - $this->bar->viewport);
        }
        $this->invalidatePaint();
    }

    private function setSelected(int $given, bool $notify): void
    {
        $given = $given < -1 || $given >= $this->rowCount() ? -1 : $given;
        if ($given === $this->selected) {
            return;
        }
        $this->selected = $given;
        $this->selectedDisplay = $given < 0 ? -1 : ($this->order === null ? $given : (int) array_search($given, $this->order, true));
        $this->reveal($this->selectedDisplay);
        $this->invalidatePaint();
        $notify && $this->post(new RowSelected($this->window(), $this->path(), $this->uuid(), $given < 0 ? null : $given,
            $given < 0 ? null : ($this->virtualCell !== null ? array_combine(array_map(fn ($c) => $c->id, $this->columns),
                array_map(fn ($c) => $this->cell($given, $c->id), $this->columns)) : $this->cells($this->rows[$given]))));
    }

    private function activate(): void
    {
        $this->selected >= 0 && $this->post(new RowActivated($this->window(), $this->path(), $this->uuid(), $this->selected));
    }
}
