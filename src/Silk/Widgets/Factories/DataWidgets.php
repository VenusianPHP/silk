<?php

namespace Silk\Widgets\Factories;

use Silk\Widgets\ColorPicker;
use Silk\Widgets\DatePicker;
use Silk\Widgets\HeaderBar;
use Silk\Widgets\ListView;
use Silk\Widgets\MenuBar;
use Silk\Widgets\Table;
use Silk\Widgets\TileView;
use Silk\Widgets\TimePicker;
use Silk\Widgets\TreeView;

/** Lane B's M2 creation methods (data views, pickers, navigation). */
trait DataWidgets
{
    /** @param list<string> $items */
    public function listView(string $name, array $items = []): ListView
    {
        $this->add($w = new ListView($name, $items));

        return $w;
    }

    /** As TKTable. @param list<\Surface\Contracts\Windows\Primitives\TableColumn> $columns */
    public function table(string $name, array $columns, array $rows = []): Table
    {
        $this->add($w = new Table($name, $columns, $rows));

        return $w;
    }

    /** As TKTree's tree(): the name `tree` is Widget::tree() in Silk. @param list<\Surface\Contracts\Windows\Primitives\TableColumn> $columns */
    public function treeView(string $name, array $columns, array $nodes = []): TreeView
    {
        $this->add($w = new TreeView($name, $columns, $nodes));

        return $w;
    }

    public function headerBar(string $name, string $title = ''): HeaderBar
    {
        $this->add($w = new HeaderBar($name, $title));

        return $w;
    }

    public function menuBar(string $name): MenuBar
    {
        $this->add($w = new MenuBar($name));

        return $w;
    }

    public function tileView(string $name): TileView
    {
        $this->add($w = new TileView($name));

        return $w;
    }

    /** As TKDatepicker. */
    public function datepicker(string $name, ?\DateTimeImmutable $date = null): DatePicker
    {
        $this->add($w = new DatePicker($name, $date));

        return $w;
    }

    /** A null $second shows HH:MM. */
    public function timePicker(string $name, int $hour = 0, int $minute = 0, ?int $second = null): TimePicker
    {
        $this->add($w = new TimePicker($name, $hour, $minute, $second));

        return $w;
    }

    public function colorPicker(string $name, ?\Surface\NutsAndBolts\Color $color = null, bool $alpha = true): ColorPicker
    {
        $this->add($w = new ColorPicker($name, $color, $alpha));

        return $w;
    }

    /** Raven's snackbar, placed by the layout (usually at the bottom of an Overlay). */
    public function toast(string $name): \Silk\Widgets\Toast
    {
        $this->add($w = new \Silk\Widgets\Toast($name));

        return $w;
    }

    /** @param list<string> $items */
    public function breadcrumb(string $name, array $items = []): \Silk\Widgets\Breadcrumb
    {
        $this->add($w = new \Silk\Widgets\Breadcrumb($name, $items));

        return $w;
    }

    /** Pages count from 0. */
    public function pagination(string $name, int $count = 1, int $page = 0): \Silk\Widgets\Pagination
    {
        $this->add($w = new \Silk\Widgets\Pagination($name, $count, $page));

        return $w;
    }

    public function rating(string $name, float $value = 0.0, int $max = 5): \Silk\Widgets\Rating
    {
        $this->add($w = new \Silk\Widgets\Rating($name, $value, $max));

        return $w;
    }
}
