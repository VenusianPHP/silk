<?php

namespace Silk\Core\Layout;

use Silk\Contracts\Geometry\Size;
use Silk\Core\Tree\Widget;

/** Empty space of a fixed size; give it a grow slot to take what is left. */
class Spacer extends Widget
{
    public function __construct(string $name, private float $w = 0, private float $h = 0)
    {
        parent::__construct($name);
    }

    public function setSize(float $w, float $h): static
    {
        [$this->w, $this->h] = [$w, $h];

        return $this->invalidateLayout();
    }

    protected function measureContent(Size $available): Size
    {
        return new Size($this->w, $this->h);
    }
}
