<?php

namespace Silk\Widgets;

use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Look\Look;
use Silk\Contracts\Look\Role;
use Silk\Contracts\Look\State;
use Silk\Contracts\Painter\Painter;
use Surface\NutsAndBolts\Color;

/**
 * Raven's scrollbar (rvn_scrollbar_thumb and rvn_scrollbar_paint, common.c:553-643), for every widget that
 * scrolls. The style's named variant: 'track' (the Raven look: a Track at 0.6 under a thumb inset 2) or
 * 'overlay' (the platforms: a thumb thin then thick, from the ScrollBar part's thin and thick, over a light
 * back that shows as it widens; the whole bar fades). The caller owns the fade and widen springs.
 */
final class ScrollBars
{
    /** The thumb along a track: [start, length], length max(track x viewport / content, min(24, track)). */
    public static function thumb(float $track, float $viewport, float $content, float $offset): array
    {
        $ratio = $content > 0 ? min(1.0, max(0.0, $viewport / $content)) : 1.0;
        $max = max(0.0, $content - $viewport);
        $length = max($track * $ratio, min(24.0, $track));

        return [$max > 0 ? ($track - $length) * min(1.0, max(0.0, $offset / $max)) : 0.0, $length];
    }

    /**
     * The thumb as drawn: it follows the drawn offset $shown, and is squeezed (to 12 at least) against the end
     * the content is pulled past (common.c:568-575). @return array{float, float} start, length
     */
    public static function drawn(float $track, float $viewport, float $content, float $shown): array
    {
        [, $length] = self::thumb($track, $viewport, $content, 0.0);
        $max = max(0.0, $content - $viewport);
        $start = $max > 0 ? ($track - $length) * min(1.0, max(0.0, $shown / $max)) : 0.0;
        if ($shown < 0) {
            $length = max($length + $shown, 12.0);
        } elseif ($shown > $max) {
            $squeezed = max($length - ($shown - $max), 12.0);
            $start += $length - $squeezed;
            $length = $squeezed;
        }

        return [$start, $length];
    }

    /** 'track' or 'overlay'. */
    public static function variant(Look $look): string
    {
        $v = $look->part('ScrollBar', 'Body', State::Normal)->extra['bars'] ?? 'track';

        return is_string($v) ? $v : 'track';
    }

    /**
     * One bar: $start and $length of the thumb along $track (from thumb()); $hot: the pointer is on the
     * track or the thumb is held; $visible and $wide: the fade and the widen (0..1), overlay only.
     */
    public static function paint(Painter $p, Look $look, Rect $track, bool $vertical, float $start, float $length, bool $hot, float $visible = 1.0, float $wide = 0.0): void
    {
        $thickness = $vertical ? $track->w : $track->h;
        $thumb = $vertical
            ? new Rect($track->x + 2, $track->y + $start + 2, $thickness - 4, $length - 4)
            : new Rect($track->x + $start + 2, $track->y + 2, $length - 4, $thickness - 4);
        $grip = $look->color($hot ? Role::HandleHovered : Role::Handle);
        if ($look->material()->isGlass()) {
            // common.c:623-634: no track; a thin capsule over the content, thicker under the pointer, gone when nothing moves
            if ($visible < 0.02) {
                return;
            }
            $slim = $hot ? 1.0 : 2.0;
            $thumb = $vertical ? new Rect($thumb->x + $slim, $thumb->y, $thumb->w - 2 * $slim, $thumb->h) : new Rect($thumb->x, $thumb->y + $slim, $thumb->w, $thumb->h - 2 * $slim);
            $p->rect($thumb, new Color($grip->red, $grip->green, $grip->blue, $grip->alpha * $visible), $thickness);

            return;
        }
        if (self::variant($look) !== 'overlay') {
            $t = $look->color(Role::Track);
            $p->rect($track, new Color($t->red, $t->green, $t->blue, $t->alpha * 0.6));
            $p->rect($thumb, $grip, ($thickness - 4) / 2);

            return;
        }
        if ($visible < 0.02) {
            return;
        }
        $x = $look->part('ScrollBar', 'Body', State::Normal)->extra;
        [$thin, $thick] = [(float) ($x['thin'] ?? 3.0), (float) ($x['thick'] ?? 8.0)];
        $size = $thin + ($thick - $thin) * $wide;
        $p->pushOpacity($visible);
        if ($wide > 0.01) {
            $back = $look->part('ScrollBar', 'Track', State::Normal)->background;
            if ($back !== null) {
                $p->rect($vertical ? new Rect($track->x + 1, $track->y + 1, $thickness - 2, $track->h - 2) : new Rect($track->x + 1, $track->y + 1, $track->w - 2, $thickness - 2),
                    new Color($back->red, $back->green, $back->blue, $back->alpha * 0.9 * $wide), $thickness / 2);
            }
        }
        // the hairline keeps to the edge; the thumb is centred on the track as it widens
        $across = $thickness - 3 - $size - ($thickness / 2 - 3 - $size / 2) * $wide;
        $thumb = $vertical ? new Rect($track->x + $across, $thumb->y, $size, $thumb->h) : new Rect($thumb->x, $track->y + $across, $thumb->w, $size);
        $p->rect($thumb, $grip, $size / 2);
        $p->popOpacity();
    }
}
