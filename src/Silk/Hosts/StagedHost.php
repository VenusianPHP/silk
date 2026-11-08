<?php

namespace Silk\Hosts;

use Surface\Contracts\Windows\Mail\Services\ClipboardText;
use Silk\Core\Tree\Tree;
use Surface\Contracts\Drawing\RenderingEngine;
use Surface\Contracts\Windows\Mail\WindowMail;
use Surface\Contracts\Windows\Mail\WindowResized;
use Surface\Contracts\Windows\Mail\WindowScaleChanged;
use Surface\Contracts\Windows\StagedWindow;
use Silk\Contracts\Host\WindowAction;
use Silk\Contracts\Host\WindowActions;
use Silk\Widgets\HeaderBar;
use Surface\Contracts\Windows\HitArea;
use Surface\Contracts\Windows\WindowCapability;
use Surface\Contracts\Windows\WindowMode;

/**
 * A Silk tree that owns a staged window. Input arrives as the staged input mail the stager posts while
 * the window watches input. The stager's session must have joined the event loop, or nothing pumps it.
 */
class StagedHost extends Host implements WindowActions
{
    /** Raven window.c:1122-1123: the edge band that resizes a custom-header window, and the corner length. */
    public const float RESIZE_BAND = 6.0;

    public const float RESIZE_CORNER = 18.0;

    private bool $active = true;

    private StagedInputAdapter $input;

    public function __construct(
        private readonly StagedWindow $window,
        Tree $tree,
        RenderingEngine $engine,
        float $uiScale = 1.0,
        FrameLoop $frames = new FrameLoop,
    ) {
        parent::__construct($tree, $frames, $uiScale);
        $this->input = new StagedInputAdapter($uiScale);
        $window->watchInput();
        [$w, $h] = $window->size() + [0, 0];
        $this->bind($engine, $w, $h, $window->scale());
        $tree->setWindowActions($this);
        if ($window->supports(WindowCapability::HitTest)) {
            $window->hitTest(fn (int $x, int $y): HitArea => $this->hitArea($x / $this->uiScale, $y / $this->uiScale));
        }
    }

    /**
     * What the window does with a press at logical $x, $y: with a HeaderBar in the tree, the edges resize
     * (Raven's custom header) and the bar drags; everything else is the app's.
     */
    public function hitArea(float $x, float $y): HitArea
    {
        $bar = null;
        for ($w = $this->tree->hitTest($x, $y); $w !== null && $bar === null; $w = $w->parent()) {
            $w instanceof HeaderBar && $bar = $w;
        }
        $v = $this->viewport();
        $band = self::RESIZE_BAND;
        $corner = self::RESIZE_CORNER;
        [$left, $right, $top, $bottom] = [$x < $band, $x >= $v->w - $band, $y < $band, $y >= $v->h - $band];
        if ($left || $right || $top || $bottom) {
            // near a corner along either edge: diagonal
            $l = $x < $corner;
            $r = $x >= $v->w - $corner;
            $t = $y < $corner;
            $b = $y >= $v->h - $corner;

            return match (true) {
                ($top && $l) || ($left && $t) => HitArea::ResizeTopLeft,
                ($top && $r) || ($right && $t) => HitArea::ResizeTopRight,
                ($bottom && $l) || ($left && $b) => HitArea::ResizeBottomLeft,
                ($bottom && $r) || ($right && $b) => HitArea::ResizeBottomRight,
                $top => HitArea::ResizeTop,
                $bottom => HitArea::ResizeBottom,
                $left => HitArea::ResizeLeft,
                default => HitArea::ResizeRight,
            };
        }

        return $bar?->hitArea($x, $y) ?? HitArea::Normal;
    }

    public function windowActions(): array
    {
        return WindowAction::cases();
    }

    public function windowTitle(): string
    {
        return $this->window->title();
    }

    public function isMaximized(): bool
    {
        return $this->window->mode() === WindowMode::Maximized;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    /** Maximize toggles back to windowed. */
    public function performWindowAction(WindowAction $action): void
    {
        match ($action) {
            WindowAction::Minimize => $this->window->setMode(WindowMode::Minimized),
            WindowAction::Maximize => $this->window->setMode($this->window->mode() === WindowMode::Maximized ? WindowMode::Windowed : WindowMode::Maximized),
            WindowAction::Close => $this->window->close(),
        };
    }

    /** @return bool whether the mail was this window's */
    public function handles(object $mail): bool
    {
        if ($mail instanceof ClipboardText) {
            return $this->offerToWidgets($mail);
        }
        if (! $mail instanceof WindowMail || $mail->window() !== $this->window->name()) {
            return false;
        }
        if ($mail instanceof \Surface\Contracts\Windows\Mail\WindowFocused || $mail instanceof \Surface\Contracts\Windows\Mail\WindowFocusLost) {
            $this->active = $mail instanceof \Surface\Contracts\Windows\Mail\WindowFocused;
            $this->tree->root->invalidatePaint();
        } elseif ($mail instanceof WindowResized) {
            $this->bind($this->engine, $mail->width, $mail->height, $this->pixelScale);
        } elseif ($mail instanceof WindowScaleChanged) {
            $this->setPixelScale($mail->scale);
        } elseif ($event = $this->input->map($mail)) {
            $this->enqueue($event);
        }

        return true;
    }

    /**
     * Shows the window what the engine drew over its framebuffer or its lent surface (a window's present() is
     * the engine's last step, as a canvas's is). Nothing for an engine that draws elsewhere, or once closed.
     */
    public function present(): void
    {
        if ($this->window->isOpen() && ! is_null($this->window->boundFramebuffer())) {
            $this->window->present();
        }
    }
}
