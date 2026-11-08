<?php

namespace Silk\Contracts\Host;

/** A host's window, as a HeaderBar sees it: what it can do, its title, and its state. */
interface WindowActions
{
    /** @return list<WindowAction> what this host's window can do */
    public function windowActions(): array;

    public function performWindowAction(WindowAction $action): void;

    /** The window's own title (a HeaderBar without one shows it). */
    public function windowTitle(): string;

    public function isMaximized(): bool;

    /** Whether the window has the keyboard (a bar in the background is dimmer). */
    public function isActive(): bool;
}
