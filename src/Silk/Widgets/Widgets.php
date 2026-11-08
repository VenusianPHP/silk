<?php

namespace Silk\Widgets;

use Silk\Core\Layout\Spacer;
use Silk\Widgets\Factories\DataWidgets;
use Silk\Widgets\Factories\EditingWidgets;
use Silk\Widgets\Factories\OverlayWidgets;

/** The creation methods, named and shaped as the TK twins'. Each adds the widget here and returns it. */
trait Widgets
{
    use DataWidgets, EditingWidgets, OverlayWidgets;

    public function label(string $name, string $text): Label
    {
        $this->add($w = new Label($name, $text));

        return $w;
    }

    public function button(string $name, string $label): Button
    {
        $this->add($w = new Button($name, $label));

        return $w;
    }

    public function checkbox(string $name, string $label, bool $checked = false): Checkbox
    {
        $this->add($w = new Checkbox($name, $label, $checked));

        return $w;
    }

    public function radio(string $name, string $label, bool $checked = false): Radio
    {
        $this->add($w = new Radio($name, $label, $checked));

        return $w;
    }

    public function toggle(string $name, bool $on = false): Toggle
    {
        $this->add($w = new Toggle($name, $on));

        return $w;
    }

    public function progressBar(string $name, ?float $fraction = 0.0): ProgressBar
    {
        $this->add($w = new ProgressBar($name, $fraction));

        return $w;
    }

    public function separator(string $name, bool $horizontal = true): Separator
    {
        $this->add($w = new Separator($name, $horizontal));

        return $w;
    }

    public function spacer(string $name, float $width = 0, float $height = 0): Spacer
    {
        $this->add($w = new Spacer($name, $width, $height));

        return $w;
    }

    public function column(string $name, int $spacing = 0, int $padding = 0): Column
    {
        $this->add($w = new Column($name, $spacing, $padding));

        return $w;
    }

    public function row(string $name, int $spacing = 0, int $padding = 0): Row
    {
        $this->add($w = new Row($name, $spacing, $padding));

        return $w;
    }
}
