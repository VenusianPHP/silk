<?php

namespace Silk\Widgets\Concerns;

use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Core\Motion\Spring;
use Silk\Core\Motion\SpringPreset;
use Surface\NutsAndBolts\Color;

/** A SNAPPY hover spring: 0 away, 1 under the pointer. */
trait Hoverable
{
    private ?Spring $hover = null;

    public function hover(): Spring
    {
        return $this->hover ??= new Spring(0.0, SpringPreset::Snappy);
    }

    /** Enter and Leave move the spring; neither stops bubbling. */
    protected function hoverEvent(SilkEvent $e): void
    {
        if ($e->type === EventType::Enter || $e->type === EventType::Leave) {
            $this->hover()->target($e->type === EventType::Enter ? 1.0 : 0.0);
            $this->invalidatePaint();
        }
    }

    /** a to b by t, per channel; a missing end blends as the other at alpha 0 (Raven). */
    protected static function mix(?Color $a, ?Color $b, float $t): ?Color
    {
        return \Silk\Core\Motion\LookBlend::mix($a, $b, $t);
    }
}
