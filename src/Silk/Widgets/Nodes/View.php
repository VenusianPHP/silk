<?php

namespace Silk\Widgets\Nodes;

use Silk\Core\Motion\Spring;
use Silk\Core\Motion\SpringPreset;

/**
 * What a NodeGraph shows (node_graph.c:162-187, 506-640). Three spaces: the canvas (where nodes are), the widget
 * (from its corner) and the layer (the canvas shifted so that scaling by the zoom about the widget's corner puts
 * it where it shows): widget = pan + zoom * canvas, layer = canvas + pan / zoom. The view asked for is a centre
 * and a zoom; what is drawn glides to it (SMOOTH), and the mouse works with what is drawn.
 */
final class View
{
    public const float ZOOM_MIN = 0.2;

    public const float ZOOM_MAX = 2.0;

    /** The zooms the wheel stops at (g_ng_stops). */
    public const array STOPS = [0.2, 0.25, 0.35, 0.5, 0.65, 0.8, 1.0, 1.25, 1.5, 2.0];

    public float $centerX = 0.0;

    public float $centerY = 0.0;

    public float $zoom = 1.0;

    /** Until the view is moved, the canvas's corner is at the widget's corner, whatever its size. */
    public bool $pinned = true;

    public Spring $shownX;

    public Spring $shownY;

    public Spring $shownZoom;

    /** The view drawn. */
    public float $panX = 0.0;

    public float $panY = 0.0;

    public float $drawZoom = 1.0;

    /** The widget's size, as last laid out. */
    public float $w = 0.0;

    public float $h = 0.0;

    public function __construct()
    {
        $this->shownX = new Spring(0.0, SpringPreset::Smooth);
        $this->shownY = new Spring(0.0, SpringPreset::Smooth);
        $this->shownZoom = new Spring(1.0, SpringPreset::Smooth);
    }

    /** @return list<Spring> */
    public function springs(): array
    {
        return [$this->shownX, $this->shownY, $this->shownZoom];
    }

    /** The view asked for: what the middle of the widget shows (rvn_ng_target). @return array{float, float} */
    public function target(): array
    {
        return $this->pinned ? [$this->w * 0.5 / $this->zoom, $this->h * 0.5 / $this->zoom] : [$this->centerX, $this->centerY];
    }

    /** Works out the view drawn from the springs where they are (rvn_ng_sync). */
    public function sync(): void
    {
        $zoom = max(self::ZOOM_MIN * 0.5, min(self::ZOOM_MAX * 1.5, $this->shownZoom->value()));
        if ($this->pinned) {
            [$this->panX, $this->panY] = [0.0, 0.0];
        } else {
            $this->panX = $this->w * 0.5 - $zoom * $this->shownX->value();
            $this->panY = $this->h * 0.5 - $zoom * $this->shownY->value();
        }
        $this->drawZoom = $zoom;
    }

    /** From now on the view is where it was put, not hung from the corner (rvn_ng_unpin). */
    public function unpin(): void
    {
        if (! $this->pinned) {
            return;
        }
        [$this->centerX, $this->centerY] = $this->target();
        $this->pinned = false;
        $this->shownX->snap($this->centerX);
        $this->shownY->snap($this->centerY);
    }

    /** rvn_ng_set_view: $animate glides there (only once painted). */
    public function set(float $x, float $y, float $zoom, bool $animate): void
    {
        $this->unpin();
        [$this->centerX, $this->centerY] = [$x, $y];
        $this->zoom = max(self::ZOOM_MIN, min(self::ZOOM_MAX, $zoom));
        $this->shownX->target($this->centerX);
        $this->shownY->target($this->centerY);
        $this->shownZoom->target($this->zoom);
        if (! $animate) {
            $this->shownX->snap($this->centerX);
            $this->shownY->snap($this->centerY);
            $this->shownZoom->snap($this->zoom);
        }
        $this->sync();
    }

    /** A point of the widget (from its corner) on the canvas, with the view drawn. @return array{float, float} */
    public function toCanvas(float $x, float $y): array
    {
        return [($x - $this->panX) / $this->drawZoom, ($y - $this->panY) / $this->drawZoom];
    }

    /** @return array{float, float} */
    public function fromCanvas(float $x, float $y): array
    {
        return [$this->panX + $x * $this->drawZoom, $this->panY + $y * $this->drawZoom];
    }

    /** Zooms keeping what is under a point of the widget in place (rvn_ng_zoom_at). */
    public function zoomAt(float $x, float $y, float $zoom, bool $animate = true): void
    {
        $zoom = max(self::ZOOM_MIN, min(self::ZOOM_MAX, $zoom));
        $this->unpin();
        if ($zoom === $this->zoom) {
            return;
        }
        [$hx, $hy] = [$this->w * 0.5, $this->h * 0.5];
        $ux = $this->centerX + ($x - $hx) / $this->zoom;
        $uy = $this->centerY + ($y - $hy) / $this->zoom;
        $this->set($ux - ($x - $hx) / $zoom, $uy - ($y - $hy) / $zoom, $zoom, $animate);
    }

    /** The next stop of the wheel from $zoom, up (1) or down (-1) (rvn_ng_next_stop). */
    public static function nextStop(float $zoom, int $direction): float
    {
        if ($direction > 0) {
            foreach (self::STOPS as $s) {
                if ($s > $zoom + 0.001) {
                    return $s;
                }
            }

            return self::ZOOM_MAX;
        }
        foreach (array_reverse(self::STOPS) as $s) {
            if ($s < $zoom - 0.001) {
                return $s;
            }
        }

        return self::ZOOM_MIN;
    }
}
