<?php

namespace Silk\Widgets\Concerns;

use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Look\FontRole;
use Silk\Contracts\Look\FontSpec;
use Silk\Text\TextMeasurer;

/** Text size and placement from the tree's TextMeasurer (real font metrics, or the GFXFont fallback). */
trait MeasuresText
{
    protected function textStyle(FontRole $role = FontRole::Ui): FontSpec
    {
        return $this->look()->font($role);
    }

    protected function measurer(): TextMeasurer
    {
        return $this->tree()?->measurer() ?? TextMeasurer::default();
    }

    protected function textSize(string $text, ?FontSpec $style = null): Size
    {
        return $this->measurer()->measure($text, $style ?? $this->textStyle());
    }

    /** The baseline that centres the font box (ascent + descent) in $r; the painter rounds it to device pixels. */
    protected function baselineIn(Rect $r, ?FontSpec $style = null): float
    {
        $m = $this->measurer()->metrics($style ?? $this->textStyle());

        return $r->y + ($r->h - ($m['ascent'] + $m['descent'])) / 2 + $m['ascent'];
    }
}
