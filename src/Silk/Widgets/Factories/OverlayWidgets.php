<?php

namespace Silk\Widgets\Factories;

use Silk\Widgets\ComboBox;
use Silk\Widgets\Dropdown;
use Silk\Widgets\Expander;
use Silk\Widgets\ScrollBar;
use Silk\Widgets\ScrollBox;
use Silk\Widgets\Slider;
use Silk\Widgets\Splitter;
use Silk\Widgets\TabView;

/** Lane A's creation methods, named and shaped as the TK twins'. Each adds the widget and returns it. */
trait OverlayWidgets
{
    /** @param list<string> $options */
    public function dropdown(string $name, array $options, int $selected = 0): Dropdown
    {
        $this->add($w = new Dropdown($name, $options, $selected));

        return $w;
    }

    /** @param list<string> $options */
    public function comboBox(string $name, string $value = '', array $options = []): ComboBox
    {
        $this->add($w = new ComboBox($name, $value, $options));

        return $w;
    }

    public function slider(string $name, float $min, float $max, float $value): Slider
    {
        $this->add($w = new Slider($name, $min, $max, $value));

        return $w;
    }

    public function scrollView(string $name): ScrollBox
    {
        $this->add($w = new ScrollBox($name));

        return $w;
    }

    public function scrollBar(string $name, bool $vertical = true): ScrollBar
    {
        $this->add($w = new ScrollBar($name, $vertical));

        return $w;
    }

    public function split(string $name, bool $horizontal = true): Splitter
    {
        $this->add($w = new Splitter($name, $horizontal));

        return $w;
    }

    public function tabView(string $name): TabView
    {
        $this->add($w = new TabView($name));

        return $w;
    }

    public function expander(string $name, string $title, bool $open = false): Expander
    {
        $this->add($w = new Expander($name, $title, $open));

        return $w;
    }
}
