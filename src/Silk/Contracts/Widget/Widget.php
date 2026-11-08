<?php

namespace Silk\Contracts\Widget;

use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Painter\Painter;

/** A self-drawn widget: TKPrimitive's identity, plus measure, arrange, paint and handle. */
interface Widget
{
    public function name(): string;

    /** Dotted, as TK primitives: `window.parent.child`. */
    public function path(): string;

    public function uuid(): string;

    public function parent(): ?Container;

    /** Called by a container on add() and remove(); not for app code. */
    public function setParent(?Container $parent): static;

    /** The size the widget wants within $available; cached until invalidated. */
    public function measure(Size $available): Size;

    public function arrange(Rect $r): void;

    public function paint(Painter $p): void;

    /** paint() inside the widget's appear transition, if one is playing; what containers and the tree call. */
    public function render(Painter $p): void;

    /** True stops bubbling. */
    public function handle(SilkEvent $e): bool;

    public function setVisible(bool $visible): static;

    public function isVisible(): bool;

    public function setEnabled(bool $enabled): static;

    public function isEnabled(): bool;

    /** Takes keyboard focus (Tab order and clicks). */
    public function setFocusable(bool $focusable): static;

    public function isFocusable(): bool;

    /** Marks the widget and every ancestor for measure and arrange. */
    public function invalidateLayout(): static;

    /** Marks the widget and every ancestor for paint. */
    public function invalidatePaint(): static;

    public function isDirty(): bool;

    public function needsLayout(): bool;

    public function needsPaint(): bool;

    /** Clears the paint mark on this widget and its subtree. */
    public function markPainted(): void;

    public function bounds(): Rect;

    /** The menu a right press or a long press opens, or null. */
    public function contextMenu(): ?\Silk\Core\Overlay\Popup;

    /** The tooltip text, or null for none. */
    public function tooltip(): ?string;

    /** @return list<\Silk\Core\Motion\Spring> the springs the frame loop steps for this widget */
    public function springs(): array;

    /** springs() plus the appear transition's spring while one plays. @return list<\Silk\Core\Motion\Spring> */
    public function motionSprings(): array;

    /** Called by the frame loop after the springs moved: ends a finished transition. */
    public function settleMotion(): void;
}
