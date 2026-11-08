<?php

namespace Silk\Contracts\Mail;

use Surface\Contracts\Windows\Mail\View\PrimitiveEventOccurred;
use Surface\Contracts\Windows\Mail\View\PrimitiveMail;

/** The user zoomed a TextEditor (Raven RTextEditorZoomCallback): to zoom others the same, or keep it for next time. */
readonly class ZoomChanged extends PrimitiveEventOccurred implements PrimitiveMail
{
    public function __construct(string $window, string $path, string $uuid, public float $zoom)
    {
        parent::__construct($window, $path, $uuid);
    }

    public function name(): string
    {
        return "view.zoom-changed.{$this->window}.{$this->path}";
    }
}
