<?php

namespace Silk\Widgets\Concerns;

use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Look\Motion;
use Silk\Core\Motion\Spring;
use Silk\Core\Motion\SpringPreset;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\MouseButton;

/**
 * A press spring (SNAPPY down, BOUNCY up) and click handling: Down then Up inside activates,
 * as do Enter and Space when focused. activate() runs only for user action.
 */
trait Pressable
{
    public const float PRESS_KICK = -11.0;

    private ?Spring $press = null;

    private bool $pressed = false;

    /** Posts the widget's mail and changes its state; user action only. */
    abstract protected function activate(): void;

    public function press(): Spring
    {
        return $this->press ??= new Spring(0.0, SpringPreset::Snappy);
    }

    public function isPressed(): bool
    {
        return $this->pressed;
    }

    /** @return bool whether the event was this widget's */
    protected function pressEvent(SilkEvent $e): bool
    {
        switch ($e->type) {
            case EventType::MouseDown:
                if ($e->button !== MouseButton::LEFT) {
                    return false;
                }
                $this->pressed = true;
                $this->press()->preset = SpringPreset::Snappy;
                $this->press()->target(1.0);
                $this->invalidatePaint();

                return true;
            case EventType::MouseUp:
                if (! $this->pressed) {
                    return false;
                }
                $this->pressed = false;
                // Raven: release springs BOUNCY on springy looks, SNAPPY on platform looks (common.c:192-195)
                $this->press()->preset = $this->look()->motion() === Motion::Springy ? SpringPreset::Bouncy : SpringPreset::Snappy;
                $this->press()->target(0.0);
                $this->invalidatePaint();
                if ($this->bounds()->contains($e->x, $e->y)) {
                    $this->click();
                }

                return true;
            case EventType::KeyDown:
                if (! $e->repeat && ($e->key === Key::ENTER || $e->key === Key::SPACE)) {
                    $this->click();

                    return true;
                }

                return false;
            case EventType::FocusLost:
                $this->pressed = false;
                $this->press()->target(0.0);

                return false;
            default:
                return false;
        }
    }

    private function click(): void
    {
        $this->press()->kick(self::PRESS_KICK);
        $this->activate();
    }
}
