<?php

namespace Silk\Widgets;

use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Look\Metric;
use Silk\Core\Tree\Widget;
use Silk\Widgets\Concerns\MeasuresText;
use Surface\Contracts\HumanInput\MouseButton;

/** One title of a MenuBar: its text plus PaddingX each side (menu.c:701-704). The bar paints it. */
class MenuTitle extends Widget
{
    use MeasuresText;

    public function __construct(string $name, private readonly string $title, public readonly Menu $menu, private readonly MenuBar $bar)
    {
        parent::__construct($name);
    }

    public function title(): string
    {
        return $this->title;
    }

    protected function measureContent(Size $available): Size
    {
        return new Size(ceil($this->textSize($this->title)->w) + 2 * $this->look()->metric(Metric::PaddingX), 0.0);
    }

    public function handle(SilkEvent $e): bool
    {
        switch ($e->type) {
            case EventType::Enter:
            case EventType::MouseMove:
                $this->bar->hovered($this);

                return false;
            case EventType::Leave:
                $this->bar->left($this);

                return false;
            case EventType::MouseDown:
                if ($e->button !== MouseButton::LEFT) {
                    return false;
                }
                $this->bar->pressed($this);

                return true;
            default:
                return false;
        }
    }
}
