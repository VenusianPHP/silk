<?php

namespace Silk\Widgets\Factories;

use Silk\Widgets\Fixed;
use Silk\Widgets\BackgroundBlur;
use Silk\Widgets\Border;
use Silk\Widgets\Grid;
use Silk\Widgets\SizeBox;
use Silk\Widgets\Stack;
use Silk\Widgets\Switcher;
use Silk\Widgets\UniformGrid;
use Silk\Widgets\WrapBox;
use Closure;
use Silk\Widgets\Image;
use Silk\Widgets\Sketch;
use Silk\Widgets\SpinBox;
use Silk\Widgets\TextInput;

/** Lane B's creation methods (editing widgets and panels), named and shaped as the TK twins'. Task 13 joins this into Widgets. */
trait EditingWidgets
{
    public function textInput(string $name, string $value = '', ?string $placeholder = null, bool $secret = false): TextInput
    {
        $this->add($w = new TextInput($name, $value, $placeholder, $secret));

        return $w;
    }

    public function spinBox(string $name, float $value = 0.0, float $minimum = 0.0, float $maximum = 100.0, float $step = 1.0): SpinBox
    {
        $this->add($w = new SpinBox($name, $value, $minimum, $maximum, $step));

        return $w;
    }

    /** As TKGrid. */
    public function grid(string $name, float $spacing = 0, float $padding = 0): Grid
    {
        $this->add($w = new Grid($name, $spacing, $padding));

        return $w;
    }

    public function uniformGrid(string $name, float $spacing = 0, int $columns = 0): UniformGrid
    {
        $this->add($w = new UniformGrid($name, $spacing, $columns));

        return $w;
    }

    public function stack(string $name): Stack
    {
        $this->add($w = new Stack($name));

        return $w;
    }

    public function sizeBox(string $name, float $aspectRatio = 0.0): SizeBox
    {
        $this->add($w = new SizeBox($name, $aspectRatio));

        return $w;
    }

    public function switcher(string $name, int $index = 0): Switcher
    {
        $this->add($w = new Switcher($name, $index));

        return $w;
    }

    public function wrapBox(string $name, float $spacing = 0): WrapBox
    {
        $this->add($w = new WrapBox($name, $spacing));

        return $w;
    }

    /** As TKFixed: Raven's Canvas, anchored placement. */
    public function fixed(string $name): Fixed
    {
        $this->add($w = new Fixed($name));

        return $w;
    }

    public function border(string $name, float $padding = 0.0): Border
    {
        $this->add($w = new Border($name, $padding));

        return $w;
    }

    /** Raven's BackgroundBlur: one child over a blurred view of what is behind it. */
    public function backgroundBlur(string $name): BackgroundBlur
    {
        $this->add($w = new BackgroundBlur($name));

        return $w;
    }

    /** As TKImage. */
    public function image(string $name, ?string $file = null): Image
    {
        $this->add($w = new Image($name, $file));

        return $w;
    }

    /** $draw(RenderingEngine $engine, Rect $deviceRect, float $scale) */
    public function sketch(string $name, Closure $draw): Sketch
    {
        $this->add($w = new Sketch($name, $draw));

        return $w;
    }
}
