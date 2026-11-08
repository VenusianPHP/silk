<?php

namespace Silk\Core\Overlay;

use Surface\NutsAndBolts\Color;
use Silk\Looks\GlassStyle;
use Silk\Contracts\Painter\GlassLook;
use Silk\Contracts\Look\SurfaceKind;
use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Look\FontSpec;
use Silk\Contracts\Look\Motion;
use Silk\Contracts\Look\PartLook;
use Silk\Contracts\Look\Role;
use Silk\Contracts\Look\State;
use Silk\Core\Motion\SpringPreset;
use Silk\Contracts\Painter\Painter;

/**
 * Raven's tooltip (widget/window.c:186-240): text +16 wide, lines x line height +10 tall, at the
 * pointer +12, +20, flipped above (-height -6) when there is no room; BOUNCY, opacity min(1, shown x 2),
 * a 4-radius box in the Tooltip role with a shadow y+2 blur 8. Passive: never hit or focused.
 */
class Tooltip extends Popup
{
    public function __construct(string $name, private readonly string $text)
    {
        parent::__construct($name, 0, 0);
    }

    public function text(): string
    {
        return $this->text;
    }

    public function isPassive(): bool
    {
        return true;
    }

    /** @return list<string> */
    private function lines(): array
    {
        return explode("\n", $this->text);
    }

    /** The tooltip's look; its extra: padX, padY, lead (above and below the text), round (ceil the text box), fontSize, drop, shadowDy. */
    private function tip(): PartLook
    {
        return $this->look()->part('Tooltip', 'Body', State::Normal);
    }

    protected function appearPreset(): SpringPreset
    {
        return $this->look()->motion() === Motion::Springy ? SpringPreset::Bouncy : SpringPreset::Smooth;
    }

    private function tipFont(): FontSpec
    {
        $font = $this->look()->font();
        $size = $this->tip()->extra['fontSize'] ?? null;

        return $size === null ? $font : new FontSpec($font->family, $size, $font->weight, $font->italic);
    }

    protected function measureContent(Size $available): Size
    {
        $font = $this->tipFont();
        $x = $this->tip()->extra;
        $m = $this->tree()->measurer();
        $w = 0.0;
        foreach ($this->lines() as $line) {
            $w = max($w, $m->measure($line, $font)->w);
        }
        $h = $m->metrics($font)['lineHeight'] * count($this->lines());
        if (($x['round'] ?? 0) > 0) {
            [$w, $h] = [ceil($w), ceil($h)];
        }

        return new Size($w + 2 * ($x['padX'] ?? 8.0), $h + 2 * ($x['padY'] ?? 5.0) + 2 * ($x['lead'] ?? 0.0));
    }

    public function place(Rect $host): void
    {
        $size = $this->measure(new Size($host->w, $host->h));
        [$px, $py] = $this->point();
        $x = min(max($px + 12, $host->x), max($host->x, $host->x + $host->w - $size->w));
        $y = $py + ($this->tip()->extra['drop'] ?? 20.0);
        if ($y + $size->h > $host->y + $host->h) {
            $y = $py - $size->h - 6;
        }
        $this->arrange(new Rect($x, max($host->y, $y), $size->w, $size->h));
    }

    public function paintExtent(): Rect
    {
        return $this->bounds()->grow(self::OVERDRAW + 2);
    }

    public function render(Painter $p): void
    {
        // Raven: springy looks pop in at twice the opacity, platform looks fade (window.c:139-140, 201-202)
        $o = min(1.0, max(0.0, $this->shown() * ($this->look()->motion() === Motion::Springy ? 2 : 1)));
        $o < 1.0 && $p->pushOpacity($o);
        $this->paint($p);
        $o < 1.0 && $p->popOpacity();
    }

    protected function paintSelf(Painter $p): void
    {
        $part = $this->tip();
        $x = $part->extra;
        $b = $this->bounds();
        $look = $this->look();
        if ($look->material()->isGlass()) {
            // window.c:216-228: a drop of glass in the colour of tooltips
            $rim = new Color(1, 1, 1, $look->dark() ? 0.5 : 0.8);
            $p->glass($b, min($b->h / 2, 12.0), new GlassLook($look->color(Role::Tooltip), $rim, 1.0, 8.0, 1.6, 0.0, 0.0, 12.0, $look->color(Role::Shadow)));
        } elseif ($look->liquid()) {
            // window.c:158-170 (macOS 26): the popup glass tinted with the tooltip's colour, at most 210 of 255 opaque
            $g = GlassStyle::of($look, SurfaceKind::Popup);
            $tint = $part->background ?? $g->tint;
            $tint = new Color($tint->red, $tint->green, $tint->blue, min($tint->alpha, 210 / 255));
            $p->glass($b, $part->radius, new GlassLook($tint, $g->rim, $g->frost, $g->refraction, $g->saturation, $g->glow, $g->bevel, 8.0, $g->shadowColor));
        } elseif ($part->shadow > 0 && $part->shadowColor !== null) {
            // the shadow sits shadowDy below; the engine itself offsets it by blur/4
            $p->shadow($b, $part->radius, $part->shadow, $part->shadowColor, 0.0, ($x['shadowDy'] ?? 0.0) - $part->shadow / 4);
        }
        if (! $look->material()->isGlass() && ! $look->liquid() && $part->background !== null) {
            $p->rect($b, $part->background, $part->radius, $part->border, $part->borderWidth);
        }
        $font = $this->tipFont();
        $m = $this->tree()->measurer()->metrics($font);
        $y = $b->y + ($x['padY'] ?? 5.0) + ($x['lead'] ?? 0.0) + $m['ascent'];
        foreach ($this->lines() as $line) {
            $p->text($line, $b->x + ($x['padX'] ?? 8.0), $y, $part->text ?? $this->look()->color(Role::TooltipText), $font);
            $y += $m['lineHeight'];
        }
    }
}
