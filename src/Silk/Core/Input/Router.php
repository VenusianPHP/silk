<?php

namespace Silk\Core\Input;

use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Widget\Widget;
use Silk\Core\Overlay\Popup;
use Silk\Core\Tree\Tree;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\MouseButton;

/** Bubbles events from the target to its ancestors; owns mouse capture and the hover path. */
class Router
{
    private ?Widget $captured = null;

    /** @var list<Widget> deepest first */
    private array $hovered = [];

    public function __construct(
        private readonly Tree $tree,
        private readonly FocusManager $focus,
    ) {}

    public function focus(): FocusManager
    {
        return $this->focus;
    }

    public function dispatch(SilkEvent $e): bool
    {
        $this->focus->settle();
        if ($this->captured !== null && ! $this->live($this->captured)) {
            $this->captured = null;
        }
        if (($drag = $this->tree->drag()) !== null && ($done = $this->dragging($drag, $e)) !== null) {
            return $done;
        }

        return match (true) {
            $e->type === EventType::Leave => $this->tree->hideTooltip() || $this->hover([], $e) || true,
            $e->type->isPointer() => $this->pointer($e),
            $e->type->isKeyboard() => $this->keyboard($e),
            default => false,
        };
    }

    /** While a drag runs the pointer moves it, the release drops it, Escape and focus loss cancel it. @return bool|null null: route as usual */
    private function dragging(\Silk\Core\Drag\DragSession $drag, SilkEvent $e): ?bool
    {
        switch ($e->type) {
            case EventType::MouseMove:
                $drag->move($e->x, $e->y);

                return true;
            case EventType::MouseUp:
                $this->captured = null;
                $drag->release($e->x, $e->y);
                $this->hover($this->chain($this->tree->hitTest($e->x, $e->y)), $e);

                return true;
            case EventType::MouseDown:
                return true;
            case EventType::FocusLost:
                $this->captured = null;
                $drag->cancel();

                return false;
            case EventType::KeyDown:
                if ($e->key === Key::ESCAPE) {
                    $this->captured = null;
                    $drag->cancel();

                    return true;
                }

                return null;
            default:
                return null;
        }
    }

    private function pointer(SilkEvent $e): bool
    {
        $hit = $this->tree->hitTest($e->x, $e->y);
        if ($e->type === EventType::MouseDown) {
            $this->tree->hideTooltip();
        } elseif ($e->type === EventType::MouseMove && $this->captured === null) {
            $this->tree->pointerAt($hit, $e->x, $e->y);
        }
        if ($e->type === EventType::MouseUp) {
            $this->tree->cancelLongPress();
        } elseif ($e->type === EventType::MouseMove) {
            $this->tree->cancelLongPress($e->x, $e->y);
        }
        $e->type === EventType::MouseDown && $this->captured === null && $this->tree->pressed($hit, $e->x, $e->y);
        if ($e->type === EventType::MouseDown && $this->captured === null && $this->closeAbove($hit)) {
            return true; // the click that closed a popup reaches nothing else (Raven eats it)
        }
        if ($this->captured === null) {
            $this->hover($this->chain($hit), $e);
        }

        if ($e->type === EventType::MouseDown && $e->button === MouseButton::RIGHT && ($owner = $this->tree->contextOwner($hit))) {
            $this->focus->hideRing();
            $this->tree->openContextMenu($owner, $e->x, $e->y);

            return true;
        }
        if ($e->type === EventType::MouseDown && $e->button === MouseButton::LEFT) {
            $this->tree->armLongPress($hit, $e->x, $e->y);
        }
        if ($e->type === EventType::MouseDown) {
            $this->focus->hideRing();
            for ($w = $hit; $w !== null; $w = $w->parent()) {
                if ($this->focus->canFocus($w)) {
                    $this->focus->focus($w);
                    break;
                }
            }
        }

        $target = $this->captured ?? $hit;
        $handler = $this->bubble($target, $e);

        if ($e->type === EventType::MouseDown && $handler !== null) {
            $this->captured = $handler;
        } elseif ($e->type === EventType::MouseUp) {
            $this->captured = null;
            $this->hover($this->chain($this->tree->hitTest($e->x, $e->y)), $e);
        }

        return $handler !== null;
    }

    private function keyboard(SilkEvent $e): bool
    {
        if ($e->type === EventType::KeyDown && $e->key === Key::ESCAPE && ($top = $this->tree->overlays()->topDismissable()) !== null) {
            $top->close();

            return true;
        }
        if ($e->type === EventType::KeyDown && $e->key === Key::TAB && ! $e->modifiers->ctrl) {
            $this->focus->next($e->modifiers->shift);

            return true;
        }

        return $this->bubble($this->focus->focused(), $e) !== null;
    }

    /**
     * Closes the open non-modal popups stacked above the one the press landed in (all of them for a press
     * on the root). @return bool whether any closed
     */
    private function closeAbove(?Widget $hit): bool
    {
        $owner = $hit;
        while ($owner !== null && $owner->parent() !== null) {
            $owner = $owner->parent();
        }
        $closed = false;
        foreach (array_reverse($this->tree->overlays()->open()) as $popup) {
            if ($popup === $owner) {
                break;
            }
            if (! $popup->isModal() && $popup->isDismissable()) {
                $popup->close();
                $closed = true;
            }
        }

        return $closed;
    }

    /** @return Widget|null the widget that handled the event */
    private function bubble(?Widget $from, SilkEvent $e): ?Widget
    {
        for ($w = $from; $w !== null; $w = $w->parent()) {
            if ($w->isEnabled() && $w->handle($this->local($w, $e))) {
                return $w;
            }
        }

        return null;
    }

    /** $e in $w's layout coordinates: a scrolled parent's shift (and a zooming one's scale) taken off. */
    private function local(Widget $w, SilkEvent $e): SilkEvent
    {
        [$x, $y] = $this->tree->toLocal($w, $e->x, $e->y);

        return $e->moved($x - $e->x, $y - $e->y);
    }

    /** @param list<Widget> $path deepest first */
    private function hover(array $path, SilkEvent $e): bool
    {
        foreach ($this->hovered as $w) {
            if (! in_array($w, $path, true) && $w->isEnabled()) {
                $w->handle($this->local($w, $e->as(EventType::Leave)));
            }
        }
        foreach (array_reverse($path) as $w) {
            if (! in_array($w, $this->hovered, true)) {
                $w->handle($this->local($w, $e->as(EventType::Enter)));
            }
        }
        $this->hovered = $path;

        return false;
    }

    /** @return list<Widget> the widget and its ancestors, deepest first */
    private function chain(?Widget $w): array
    {
        $out = [];
        for (; $w !== null; $w = $w->parent()) {
            $out[] = $w;
        }

        return $out;
    }

    private function live(Widget $w): bool
    {
        for ($at = $w; $at !== null; $at = $at->parent()) {
            if (! $at->isVisible() || ! $at->isEnabled()) {
                return false;
            }
        }

        return true;
    }
}
