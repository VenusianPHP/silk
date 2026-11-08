<?php

namespace Silk\Core\Layout;

use InvalidArgumentException;
use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Widget\Slot;
use Silk\Contracts\Widget\Widget as WidgetContract;
use Silk\Core\Tree\Container;

/**
 * Raven's UniformGrid: every cell has one size, the largest child's. With $columns > 0 children flow in that
 * many columns; with 0 each child takes the cell at() gave it. Arranged, the cells share the panel equally.
 */
class UniformGrid extends Container
{
    /** @var array{int, int}|null */
    private ?array $next = null;

    /** @var array<string, array{int, int}> row, column by child uuid */
    private array $cells = [];

    public function __construct(string $name, private float $spacing = 0, private int $columns = 0)
    {
        parent::__construct($name);
    }

    public function at(int $row, int $column): static
    {
        if ($row < 0 || $column < 0) {
            throw new InvalidArgumentException("A cell needs row and column >= 0, not ({$row}, {$column}).");
        }
        $this->next = [$row, $column];

        return $this;
    }

    public function add(WidgetContract $child, ?Slot $slot = null): static
    {
        $this->cells[$child->uuid()] = $this->next ?? [0, 0];
        $this->next = null;

        return parent::add($child, $slot);
    }

    public function setColumns(int $columns): static
    {
        $this->columns = max(0, $columns);

        return $this->invalidateLayout();
    }

    protected function measureContent(Size $available): Size
    {
        [$cell, $columns, $rows] = $this->cells($available);
        if ($columns === 0) {
            return new Size(0, 0);
        }

        return new Size($cell->w * $columns + $this->spacing * ($columns - 1), $cell->h * $rows + $this->spacing * ($rows - 1));
    }

    protected function arrangeContent(Rect $r): void
    {
        [, $columns, $rows] = $this->cells(new Size($r->w, $r->h));
        if ($columns === 0) {
            return;
        }
        $w = ($r->w - $this->spacing * ($columns - 1)) / $columns;
        $h = ($r->h - $this->spacing * ($rows - 1)) / $rows;
        foreach ($this->visibleChildren() as $i => $child) {
            [$row, $column] = $this->place($i, $child);
            $child->arrange(new Rect($r->x + $column * ($w + $this->spacing), $r->y + $row * ($h + $this->spacing), $w, $h));
        }
    }

    /** @return array{Size, int, int} the cell size and the column and row counts */
    private function cells(Size $available): array
    {
        $cw = $ch = 0.0;
        $columns = $rows = 0;
        foreach ($this->visibleChildren() as $i => $child) {
            $s = $child->measure($available);
            [$cw, $ch] = [max($cw, $s->w), max($ch, $s->h)];
            [$row, $column] = $this->place($i, $child);
            [$columns, $rows] = [max($columns, $column + 1), max($rows, $row + 1)];
        }

        return [new Size($cw, $ch), $columns, $rows];
    }

    /** @return array{int, int} row, column */
    private function place(int $visibleIndex, WidgetContract $child): array
    {
        return $this->columns > 0
            ? [intdiv($visibleIndex, $this->columns), $visibleIndex % $this->columns]
            : $this->cells[$child->uuid()];
    }
}
