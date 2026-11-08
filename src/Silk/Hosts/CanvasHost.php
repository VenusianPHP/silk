<?php

namespace Silk\Hosts;

use Surface\Contracts\Windows\Mail\Services\ClipboardText;
use Closure;
use Silk\Core\Tree\Tree;
use Surface\Contracts\Drawing\RenderingEngine;
use Surface\Contracts\Windows\Mail\View\PrimitiveMail;
use Surface\Contracts\Windows\Mail\View\ViewResized;
use Surface\Contracts\Windows\Primitives\TKCanvas;

/**
 * A Silk tree inside a TKCanvas: watches the pointer, keys and size, and draws through any engine made
 * for the canvas as its output (`renderer(<engine>, ['output' => $canvas])`), Velvet or a GPU engine.
 * An engine that draws into the canvas's own framebuffer is made anew when the canvas resizes; one that
 * owns its target (a GPU engine on the view's lent layer) follows the size itself in begin(). The canvas
 * presents either. Feed it view mail through handles(); overlays stay inside the canvas.
 */
class CanvasHost extends Host implements \Silk\Contracts\Host\WindowActions
{
    private bool $active = true;

    private CanvasInputAdapter $input;

    /** @var Closure(TKCanvas, ?string): RenderingEngine */
    private Closure $engineFor;

    private readonly ?string $engineName;

    /**
     * @param  ?Closure(TKCanvas, ?string): RenderingEngine  $engineFor  defaults to app('drawing')->renderer($name, ['output' => $canvas])
     * @param  ?string  $engine  the engine's name; null leaves it to Surface's drawing.default
     */
    public function __construct(
        private readonly TKCanvas $canvas,
        Tree $tree,
        ?Closure $engineFor = null,
        float $uiScale = 1.0,
        bool $takesTab = true,
        FrameLoop $frames = new FrameLoop,
        ?string $engine = null,
    ) {
        parent::__construct($tree, $frames, $uiScale);
        $this->input = new CanvasInputAdapter($uiScale);
        $this->engineName = $engine;
        $this->engineFor = $engineFor ?? fn (TKCanvas $c, ?string $name) => app('drawing')->renderer($name, ['output' => $c]);
        $canvas->watchPointer();
        $canvas->setFocusable(true, $takesTab);
        $canvas->watchSize();
        $tree->setWindowActions($this);
    }

    /**
     * A toolkit window can be closed; dragging, minimizing and maximizing it from a HeaderBar are not in Surface's
     * ToolkitWindow (blocked: see the roadmap's Blocked list).
     */
    public function windowActions(): array
    {
        return [\Silk\Contracts\Host\WindowAction::Close];
    }

    public function performWindowAction(\Silk\Contracts\Host\WindowAction $action): void
    {
        $action === \Silk\Contracts\Host\WindowAction::Close && $this->canvas->window()->close();
    }

    public function windowTitle(): string
    {
        return $this->canvas->window()->title();
    }

    public function isMaximized(): bool
    {
        return false;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    /** The engine asked for; null when Surface's drawing.default picks it. */
    public function engineName(): ?string
    {
        return $this->engineName;
    }

    /** @return bool whether the mail was this canvas's */
    public function handles(object $mail): bool
    {
        if ($mail instanceof ClipboardText) {
            return $this->offerToWidgets($mail);
        }
        if (($mail instanceof \Surface\Contracts\Windows\Mail\WindowFocused || $mail instanceof \Surface\Contracts\Windows\Mail\WindowFocusLost)
            && ! $this->canvas->isRemoved() && $mail->window() === $this->canvas->window()->name()) {
            // the window's focus dims a HeaderBar; the mail stays the app's too
            $this->active = $mail instanceof \Surface\Contracts\Windows\Mail\WindowFocused;
            $this->tree->root->invalidatePaint();

            return false;
        }
        if (! $mail instanceof PrimitiveMail || $mail->uuid() !== $this->canvas->uuid()) {
            return false;
        }
        if ($this->canvas->isRemoved()) {
            return true; // its window closed: late mail for it is dropped
        }
        if ($mail instanceof ViewResized) {
            $this->rebind();
        } elseif ($event = $this->input->map($mail)) {
            $this->enqueue($event);
        }

        return true;
    }

    /**
     * Follows the canvas's size. The first size makes the engine. Later, an engine drawing into the
     * canvas's own framebuffer is made anew at the new size (its framebuffer is the old one); any other
     * keeps going, as it follows its output in begin(). An empty canvas pauses the host.
     */
    public function rebind(): void
    {
        if ($this->canvas->isRemoved()) {
            return;
        }
        [$pw, $ph] = $this->canvas->pixelSize();
        [$w, $h] = $this->canvas->size() + [0, 0];
        if ($pw < 1 || $ph < 1 || $w < 1 || $h < 1) {
            $this->bind($this->engine, 0, 0, $this->pixelScale);

            return;
        }
        $engine = $this->engine;
        $stale = $engine !== null
            && $this->canvas->lent() === null // a borrower (GPU) follows its lent surface; never make a second one
            && $engine->framebuffer() === $this->canvas->boundFramebuffer()
            && [$engine->width(), $engine->height()] !== [$pw, $ph];
        if ($engine === null || $stale) {
            $engine = ($this->engineFor)($this->canvas, $this->engineName);
        }
        $this->bind($engine, $w, $h, $pw / $w);
        $this->tree->repaintAll();
    }

    /**
     * None once the canvas is removed. A frame can remove it midway (a HeaderBar close is dispatched while
     * the frame drains its events): the frame then has no engine to paint with and stops there.
     */
    public function engine(): ?RenderingEngine
    {
        return $this->canvas->isRemoved() ? null : parent::engine();
    }

    /** Nothing once the canvas is removed: a closed window can still be ticked before the app stops. */
    public function frame(): void
    {
        if (! $this->canvas->isRemoved()) {
            parent::frame();
        }
    }

    public function present(): void
    {
        if (! $this->canvas->isRemoved()) {
            $this->canvas->present();
        }
    }
}
