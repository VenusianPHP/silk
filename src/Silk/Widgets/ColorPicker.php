<?php

namespace Silk\Widgets;

use Silk\Painter\Surfaces;
use Silk\Contracts\Look\SurfaceKind;
use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Look\Metric;
use Silk\Contracts\Look\PartLook;
use Silk\Contracts\Look\State;
use Silk\Contracts\Mail\ColorChanged;
use Silk\Contracts\Painter\Painter;
use Silk\Core\Overlay\Placement;
use Silk\Core\Tree\Container;
use Silk\Widgets\ColorPicking\HexField;
use Silk\Widgets\ColorPicking\ModeButton;
use Silk\Widgets\ColorPicking\NumberField;
use Silk\Widgets\ColorPicking\PickerPopup;
use Silk\Widgets\Concerns\Hoverable;
use Silk\Widgets\Concerns\Pressable;
use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Contracts\Framebuffers\Framebuffer;
use Surface\Contracts\HumanInput\MouseButton;
use Surface\Framebuffers\Native\NativeFullFramebuffer;
use Surface\NutsAndBolts\Color;

/**
 * `colorPicker(name, color, alpha)`: Raven's colour picker (color_picker.c). A square of saturation and value,
 * a strip of hues, the colour before and the colour now (a click on "before" goes back to it), a code, four
 * numbers (RGB or HSV) and swatches. The colour is kept as hue, saturation, value and opacity, so a grey keeps
 * its hue while the user drags. The square is the pure hue under two fades that never change. With setPopup()
 * it is a swatch that opens a second picker in a popup; the two keep each other up to date and the swatch
 * posts the mail. Every change the user makes posts ColorChanged, every step of a drag included. All colours
 * and sizes come from the ColorPicker part looks (Raven's values in the shared styles).
 *
 * shortcut: the alpha strip is not drawn (blocked: Surface image tint) and images have square corners
 * (blocked: Surface image radius); add both when Surface's image() takes a tint colour and a radius.
 */
class ColorPicker extends Container
{
    use Hoverable, Pressable;

    private const int NONE = 0;

    private const int SQUARE = 1;

    private const int HUE = 2;

    /** @var array{?Framebuffer, ?Framebuffer, ?Framebuffer} white fade, black fade, hues: made once */
    private static array $textures = [null, null, null];

    /** How many textures were ever made (they never change). */
    public static int $built = 0;

    private float $h = 217.0;

    private float $s = 0.76;

    private float $v = 0.96;

    private float $a = 1.0;

    /** @var array{float, float, float, float} the colour before: rgb and opacity */
    private array $previous;

    private bool $popupMode = false;

    private bool $compact = false;

    private bool $hsvFields = false;

    /** @var list<Color> */
    private array $swatches = [];

    private ?HexField $hex = null;

    private ?ModeButton $mode = null;

    /** @var list<NumberField> */
    private array $fields = [];

    private bool $syncing = false;

    private int $drag = self::NONE;

    private int $hotSwatch = -1;

    private Rect $square;

    private Rect $hueStrip;

    private Rect $preview;

    private Rect $swatchArea;

    private ?PickerPopup $panel = null;

    private ?ColorPicker $inner = null;

    private ?ColorPicker $owner = null;

    /** The popup picker's width (Raven fixed_size.x 252, color_picker.c:420); null: as measured. */
    private ?float $fixedWidth = null;

    public function __construct(string $name, ?Color $color = null, private bool $alpha = true)
    {
        parent::__construct($name);
        $this->square = $this->hueStrip = $this->preview = $this->swatchArea = new Rect(0, 0, 0, 0);
        $color === null ? null : $this->takeRgb($color->red, $color->green, $color->blue);
        $this->a = $alpha ? ($color->alpha ?? 1.0) : 1.0;
        $this->previous = [...$this->rgb(), $this->a];
        $this->makeFields();
    }

    public function color(\Silk\Contracts\Look\Role|null $role = null, ?\Silk\Contracts\Look\Look $look = null): Color
    {
        if ($role !== null) {
            return parent::color($role, $look);
        }
        [$r, $g, $b] = $this->rgb();

        return new Color($r, $g, $b, $this->alpha ? $this->a : 1.0);
    }

    /** Posts nothing; the colour given is also the one "before". */
    public function setColor(\Silk\Contracts\Look\Role|Color $role, Color|\Silk\Contracts\Look\Role|null $color = null): static
    {
        if ($role instanceof \Silk\Contracts\Look\Role) {
            return parent::setColor($role, $color);
        }
        $this->takeRgb($role->red, $role->green, $role->blue);
        $this->a = $this->alpha ? $role->alpha : 1.0;
        $this->previous = [...$this->rgb(), $this->a];
        $this->changed(null, false);

        return $this;
    }

    /** @return array{float, float, float} hue 0..360, saturation and value 0..1 */
    public function hsv(): array
    {
        return [$this->h, $this->s, $this->v];
    }

    /** Posts nothing. */
    public function setHsv(float $h, float $s, float $v): static
    {
        // as Rcolor_picker_set_hsv: 360 itself stays 360 (the right end of the strip)
        $this->h = $h < 0 ? fmod($h, 360.0) + 360.0 : ($h > 360.0 ? fmod($h, 360.0) : $h);
        [$this->s, $this->v] = [$s, $v];
        $this->changed(null, false);

        return $this;
    }

    /** @param list<Color> $swatches */
    public function setSwatches(array $swatches): static
    {
        $this->swatches = array_values($swatches);

        return $this->invalidateLayout();
    }

    public function setPopup(bool $popup): static
    {
        if ($popup === $this->popupMode) {
            return $this;
        }
        $this->popupMode = $popup;
        $this->focusable = $popup;
        $popup ? $this->removeFields() : ($this->compact || $this->makeFields());

        return $this->invalidateLayout();
    }

    public function setCompact(bool $compact): static
    {
        $this->compact = $compact;
        $compact || $this->popupMode ? $this->removeFields() : $this->makeFields();

        return $this->invalidateLayout();
    }

    /** RGB or HSV numbers. */
    public function setNumbers(string $mode): static
    {
        $this->hsvFields = strtoupper($mode) === 'HSV';
        $this->fieldRanges();
        $this->syncFields();

        return $this;
    }

    public function hexField(): ?HexField
    {
        return $this->hex;
    }

    /** @return list<NumberField> */
    public function numberFields(): array
    {
        return $this->fields;
    }

    public function modeButton(): ?ModeButton
    {
        return $this->mode;
    }

    /** The popup picker of a popup-mode swatch, once opened. */
    public function inner(): ?ColorPicker
    {
        return $this->inner;
    }

    public function isPopupOpen(): bool
    {
        return $this->panel?->isOpen() ?? false;
    }

    /** @return array{Rect, Rect, Rect, Rect} the square, the hue strip, the preview and the swatch area, from the last arrange */
    public function areas(): array
    {
        return [$this->square, $this->hueStrip, $this->preview, $this->swatchArea];
    }

    public function swatchRect(int $i): Rect
    {
        $b = $this->body();
        $size = $b->extra['swatch'];
        $gap = $b->extra['swatchGap'];
        $perRow = max(1, (int) (($this->swatchArea->w + $gap) / ($size + $gap)));

        return new Rect($this->swatchArea->x + ($i % $perRow) * ($size + $gap), $this->swatchArea->y + intdiv($i, $perRow) * ($size + $gap), $size, $size);
    }

    public function springs(): array
    {
        return [$this->hover(), $this->press()];
    }

    /** @internal the code field was typed in (color_picker.c:270-285) */
    public function hexTyped(HexField $field, string $text): void
    {
        if ($this->syncing) {
            return;
        }
        $text = ltrim($text);
        $code = str_starts_with($text, '#') ? $text : '#'.$text;
        try {
            $c = Color::hex($code);
        } catch (\InvalidArgumentException) {
            return;
        }
        $this->takeRgb($c->red, $c->green, $c->blue);
        // a code without opacity keeps the one there is
        $this->alpha && strlen($code) === 9 && $this->a = $c->alpha;
        $this->changed($field, true);
    }

    /** @internal a number changed (color_picker.c:296-318) */
    public function fieldChanged(NumberField $field, float $value): void
    {
        if ($this->syncing) {
            return;
        }
        if ($field->index === 3) {
            $this->a = $value / 100;
        } elseif ($this->hsvFields) {
            match ($field->index) {
                0 => $this->h = $value,
                1 => $this->s = $value / 100,
                default => $this->v = $value / 100,
            };
        } else {
            $rgb = $this->rgb();
            $rgb[$field->index] = $value / 255;
            $this->takeRgb(...$rgb);
        }
        $this->changed($field, true);
    }

    /** @internal the mode button */
    public function toggleNumbers(): void
    {
        $this->hsvFields = ! $this->hsvFields;
        $this->fieldRanges();
        $this->syncFields();
    }

    /** Writes the colour into the code and the numbers, except the one being typed in (color_picker.c:217-237). */
    public function syncFields(?object $except = null): void
    {
        if ($this->hex === null) {
            return;
        }
        $this->syncing = true;
        $rgb = $this->rgb();
        $this->hex !== $except && $this->hex->setValue($this->code());
        $values = $this->hsvFields ? [round($this->h), round($this->s * 100), round($this->v * 100)]
            : array_map(fn ($c) => round($c * 255), $rgb);
        foreach ([0, 1, 2] as $i) {
            $this->fields[$i] !== $except && $this->fields[$i]->setValue($values[$i]);
        }
        $this->fields[3] !== $except && $this->fields[3]->setValue(round($this->a * 100));
        $this->syncing = false;
    }

    /** #RRGGBB, or #RRGGBBAA when not opaque (color_picker.c:204-215). */
    public function code(): string
    {
        [$r, $g, $b] = array_map(fn ($c) => (int) round($c * 255), $this->rgb());
        $a = (int) round($this->a * 255);

        return $this->alpha && $a < 255 ? sprintf('#%02X%02X%02X%02X', $r, $g, $b, $a) : sprintf('#%02X%02X%02X', $r, $g, $b);
    }

    protected function measureContent(Size $available): Size
    {
        $body = $this->body();
        foreach ($this->visibleChildren() as $c) {
            $c->measure($available);
        }
        $control = $this->look()->metric(Metric::ControlHeight);
        if ($this->popupMode) {
            return new Size(floor($control * 1.7), $control);
        }

        $w = $this->fixedWidth ?? $body->extra['width'];

        return new Size($w, $body->extra['square'] + $this->below($w));
    }

    /** color_picker.c:525-560 */
    protected function arrangeContent(Rect $r): void
    {
        if ($this->popupMode) {
            return;
        }
        $body = $this->body();
        $x = $r->x;
        $w = $r->w;
        $control = $this->look()->metric(Metric::ControlHeight);
        $strip = $body->extra['strip'];
        $gap = $body->extra['gap'];
        $this->square = new Rect($x, $r->y, $w, max(40.0, $r->h - $this->below($w)));
        $y = $this->square->y + $this->square->h + $gap;
        // the alpha strip is blocked (Surface image tint): the strips lay out as without one
        $strips = $strip + 12;
        $pw = $body->extra['preview'];
        $this->preview = new Rect($x, $y, $pw, $strips);
        $this->hueStrip = new Rect($x + $pw + 12, $y + 6, max(20.0, $w - $pw - 12), $strip);
        $y += $strips;
        if ($this->hex !== null) {
            $fg = $body->extra['fieldGap'];
            $mw = $body->extra['modeWidth'];
            $count = $this->alpha ? 4 : 3;
            $field = floor(($w - $fg * ($count - 1)) / $count);
            $y += $gap;
            $this->hex->arrange(new Rect($x, $y, max(40.0, $w - $mw - $fg), $control));
            $this->mode->arrange(new Rect($x + $w - $mw, $y, $mw, $control));
            $y += $control + $fg;
            foreach ($this->fields as $i => $f) {
                if ($i === 3 && ! $this->alpha) {
                    $f->setVisible(false);
                    continue;
                }
                $f->arrange(new Rect($x + ($field + $fg) * $i, $y, $i === $count - 1 ? $w - ($field + $fg) * $i : $field, $control));
            }
            $y += $control;
        }
        $this->swatchArea = new Rect($x, $y + $gap, $w, max(0.0, $r->y + $r->h - $y - $gap));
    }

    public function handle(SilkEvent $e): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }
        if ($this->popupMode) {
            $this->hoverEvent($e);

            return $this->pressEvent($e);
        }
        switch ($e->type) {
            case EventType::MouseDown:
                if ($e->button !== MouseButton::LEFT) {
                    return false;
                }
                // (a little around the strip counts: its knob sticks out)
                $hue = $this->hueStrip;
                $this->drag = match (true) {
                    $this->square->contains($e->x, $e->y) => self::SQUARE,
                    (new Rect($hue->x - 6, $hue->y - 4, $hue->w + 12, $hue->h + 8))->contains($e->x, $e->y) => self::HUE,
                    default => self::NONE,
                };
                if ($this->drag !== self::NONE) {
                    $this->dragTo($e->x, $e->y);

                    return true;
                }
                $p = $this->preview;
                if ((new Rect($p->x, $p->y, $p->w / 2, $p->h))->contains($e->x, $e->y)) {
                    // back to the colour before
                    $this->takeRgb(...array_slice($this->previous, 0, 3));
                    $this->alpha && $this->a = $this->previous[3];
                    $this->changed(null, true);

                    return true;
                }
                if (($i = $this->swatchAt($e->x, $e->y)) >= 0) {
                    $c = $this->swatches[$i];
                    $this->takeRgb($c->red, $c->green, $c->blue);
                    $this->alpha && $this->a = $c->alpha;
                    $this->changed(null, true);

                    return true;
                }

                return false;
            case EventType::MouseMove:
                if ($this->drag !== self::NONE) {
                    $this->dragTo($e->x, $e->y);

                    return true;
                }
                $hot = $this->swatchAt($e->x, $e->y);
                if ($hot !== $this->hotSwatch) {
                    $this->hotSwatch = $hot;
                    $this->invalidatePaint();
                }

                return false;
            case EventType::MouseUp:
                if ($this->drag === self::NONE) {
                    return false;
                }
                $this->drag = self::NONE;
                $this->invalidatePaint();

                return true;
            case EventType::Leave:
                if ($this->hotSwatch >= 0) {
                    $this->hotSwatch = -1;
                    $this->invalidatePaint();
                }

                return false;
            default:
                return false;
        }
    }

    /** The popup-mode swatch was clicked, or Space/Enter pressed on it: open the second picker. */
    protected function activate(): void
    {
        $this->open();
    }

    public function paint(Painter $p): void
    {
        $look = $this->look();
        $edge = $look->part('ColorPicker', 'Edge', State::Normal);
        $color = $this->color();
        [$r, $g, $b] = $this->rgb();
        $opaque = new Color($r, $g, $b, 1.0);
        if ($this->popupMode) {
            $this->paintSwatchButton($p, $color);

            return;
        }
        $body = $this->body();
        $none = new Color(0, 0, 0, 0);

        // the square: the pure hue, whitened to the left, darkened to the bottom (color_picker.c:609-616)
        [$hr, $hg, $hb] = self::hsvToRgb($this->h, 1.0, 1.0);
        $pure = new Color($hr, $hg, $hb, 1.0);
        $sr = $body->extra['squareRadius'];
        [$white, $black, $hues] = self::textures();
        $p->rect($this->square, $pure, $sr);
        $p->image($white, $this->square, $sr);
        $p->image($black, $this->square, $sr);
        $p->rect($this->square, $none, $sr, $edge->border, $edge->borderWidth);
        // where the colour is in it: a ring that shows it
        $ring = $look->part('ColorPicker', 'Ring', State::Normal);
        $size = $this->drag === self::SQUARE ? $ring->extra['held'] : $ring->extra['size'];
        $x = $this->square->x + $this->s * $this->square->w;
        $y = $this->square->y + (1 - $this->v) * $this->square->h;
        $rr = new Rect($x - $size / 2, $y - $size / 2, $size, $size);
        $p->pushClip($this->square->grow(12));
        $ring->shadowColor && $p->shadow(new Rect($rr->x, $rr->y + 1, $rr->w, $rr->h), $size / 2, $ring->shadow, $ring->shadowColor);
        $p->rect($rr, $opaque, $size / 2, $ring->border, $ring->borderWidth);
        $p->popClip();

        // before and now, side by side (color_picker.c:625-636)
        $pv = $this->preview;
        $pr = $body->extra['previewRadius'];
        $before = new Color($this->previous[0], $this->previous[1], $this->previous[2], $this->alpha ? $this->previous[3] : 1.0);
        ($before->alpha < 1 || $color->alpha < 1) && $this->checker($p, $pv);
        $p->rect(new Rect($pv->x, $pv->y, $pv->w / 2, $pv->h), $before, [$pr, 0.0, 0.0, $pr]);
        $p->rect(new Rect($pv->x + $pv->w / 2, $pv->y, $pv->w / 2, $pv->h), $color, [0.0, $pr, $pr, 0.0]);
        $p->rect($pv, $none, $pr, $look->color(\Silk\Contracts\Look\Role::ControlBorder), 1.0);

        // the hues
        $half = $body->extra['strip'] / 2;
        $p->image($hues, $this->hueStrip, $half);
        $p->rect($this->hueStrip, $none, $half, $edge->border, $edge->borderWidth);
        $this->knob($p, $this->hueStrip, $this->h / 360, $pure, $this->drag === self::HUE);

        // the swatches
        $se = $look->part('ColorPicker', 'SwatchEdge', State::Normal);
        foreach ($this->swatches as $i => $c) {
            $rect = $this->swatchRect($i);
            $i === $this->hotSwatch && $rect = $rect->grow(2);
            $c = $this->alpha ? $c : new Color($c->red, $c->green, $c->blue, 1.0);
            $c->alpha < 1 && $this->checker($p, $rect);
            $p->rect($rect, $c, $body->extra['swatchRadius'], $se->border, $se->borderWidth);
        }

        // the code and the numbers
        foreach ($this->visibleChildren() as $child) {
            $child->render($p);
        }
    }

    /** A strip's knob: a pill with a thin shadow that shows the colour it is on (color_picker.c:565-573). */
    private function knob(Painter $p, Rect $strip, float $fraction, Color $color, bool $held): void
    {
        $k = $this->look()->part('ColorPicker', 'Knob', State::Normal);
        $w = $held ? $k->extra['held'] : $k->extra['size'];
        $x = $strip->x + max(0.0, min(1.0, $fraction)) * $strip->w;
        $knob = new Rect($x - $w / 2, $strip->y - 3, $w, $strip->h + 6);
        $k->shadowColor && $p->shadow(new Rect($knob->x, $knob->y + 1, $knob->w, $knob->h), $w / 2, $k->shadow, $k->shadowColor);
        $p->rect($knob, $k->background, $w / 2);
        $p->rect(new Rect($knob->x + 2.5, $knob->y + 2.5, $knob->w - 5, $knob->h - 5), $color, ($w - 5) / 2);
    }

    /** The checkerboard under $r: plain cells, clipped to it, the same size everywhere (color_picker.c:176-194). */
    private function checker(Painter $p, Rect $r): void
    {
        $look = $this->look();
        $cell = $this->body()->extra['checkCell'];
        $a = $look->part('ColorPicker', 'CheckerA', State::Normal)->background;
        $b = $look->part('ColorPicker', 'CheckerB', State::Normal)->background;
        $p->pushClip($r);
        $p->rect($r, $a);
        for ($y = 0; $y * $cell < $r->h; $y++) {
            for ($x = 0; $x * $cell < $r->w; $x++) {
                ($x + $y) % 2 === 1 && $p->rect(new Rect($r->x + $x * $cell, $r->y + $y * $cell, $cell, $cell), $b);
            }
        }
        $p->popClip();
    }

    /** A frame of the theme around the colour, like a button around its text (color_picker.c:582-600). */
    private function paintSwatchButton(Painter $p, Color $color): void
    {
        $look = $this->look();
        $b = $this->bounds();
        $glass = $look->material()->isGlass();
        $radius = $glass ? $b->h / 2 : min($look->metric(Metric::CornerRadius), $b->h / 2);
        $frame = $look->part('ColorPicker', 'Button', State::Normal);
        $hovered = $look->part('ColorPicker', 'Button', State::Hover);
        $press = max(0.0, $this->press()->value());
        $squash = 0.04 * $press;
        $p->pushTransform(1.0, 0.0, 0.0);
        $r = new Rect($b->x + $b->w * $squash * 0.225, $b->y + $b->h * $squash / 2, $b->w * (1 - $squash * 0.45), $b->h * (1 - $squash));
        $hover = $this->isPopupOpen() ? 1.0 : max(0.0, min(1.0, $this->hover()->value()));
        if ($glass) {
            // color_picker.c:604: the frame is glass
            Surfaces::paint($p, $look, $r, $radius, SurfaceKind::Control, $hover, $press);
        } else {
            $p->rect($r, self::mix($frame->background, $hovered->background, $hover), $radius, $frame->border, $frame->borderWidth);
        }
        $inner = new Rect($r->x + 3, $r->y + 3, $r->w - 6, $r->h - 6);
        $c = $this->isEnabled() ? $color : new Color($color->red, $color->green, $color->blue, $color->alpha * 0.4);
        $c->alpha < 1 && $this->checker($p, $inner);
        $p->rect($inner, $c, max($radius - 3, 2.0));
        $p->popTransform();
    }

    private function open(): void
    {
        if ($this->tree() === null) {
            return;
        }
        if ($this->panel === null) {
            $body = $this->body();
            $this->panel = new PickerPopup($this->name().'-popup', 0, $body->extra['popupPadding']);
            $this->inner = new ColorPicker($this->name().'-inner', null, $this->alpha);
            $this->inner->owner = $this;
            $this->inner->fixedWidth = $body->extra['popupWidth'];
            $this->panel->add($this->inner);
        }
        if ($this->panel->isOpen()) {
            return;
        }
        $in = $this->inner;
        [$in->h, $in->s, $in->v, $in->a, $in->swatches, $in->alpha] = [$this->h, $this->s, $this->v, $this->a, $this->swatches, $this->alpha];
        $in->setCompact($this->compact);
        // what the colour was when the popup opened is there to come back to
        $in->previous = [...$this->rgb(), $this->a];
        $in->syncFields();
        $in->invalidateLayout();
        $this->panel->open($this, Placement::Below);
    }

    /** The colour changed: the fields, the other half of a popup pair, and the app are told (color_picker.c:375-413). */
    private function changed(?object $except, bool $notify): void
    {
        $this->h = max(0.0, min(360.0, $this->h));
        $this->s = max(0.0, min(1.0, $this->s));
        $this->v = max(0.0, min(1.0, $this->v));
        $this->a = $this->alpha ? max(0.0, min(1.0, $this->a)) : 1.0;
        $this->syncFields($except);
        $this->invalidatePaint();
        $teller = $this;
        if ($this->inner !== null) {
            [$this->inner->h, $this->inner->s, $this->inner->v, $this->inner->a] = [$this->h, $this->s, $this->v, $this->a];
            $this->inner->syncFields();
            $this->inner->invalidatePaint();
        }
        if ($this->owner !== null) {
            // the picker of a popup: its swatch takes the colour, and is the one that tells the app
            [$this->owner->h, $this->owner->s, $this->owner->v, $this->owner->a] = [$this->h, $this->s, $this->v, $this->a];
            $this->owner->invalidatePaint();
            $teller = $this->owner;
        }
        $notify && $teller->tell();
    }

    private function tell(): void
    {
        $this->post(new ColorChanged($this->window(), $this->path(), $this->uuid(), $this->color()));
    }

    private function dragTo(float $x, float $y): void
    {
        if ($this->drag === self::SQUARE) {
            $this->s = max(0.0, min(1.0, ($x - $this->square->x) / max($this->square->w, 1.0)));
            $this->v = 1 - max(0.0, min(1.0, ($y - $this->square->y) / max($this->square->h, 1.0)));
        } elseif ($this->drag === self::HUE) {
            $this->h = max(0.0, min(1.0, ($x - $this->hueStrip->x) / max($this->hueStrip->w, 1.0))) * 360;
        }
        $this->changed(null, true);
    }

    private function swatchAt(float $x, float $y): int
    {
        foreach ($this->swatches as $i => $_) {
            if ($this->swatchRect($i)->contains($x, $y)) {
                return $i;
            }
        }

        return -1;
    }

    /** Height of everything under the square, for a width (color_picker.c:505-514). */
    private function below(float $width): float
    {
        $body = $this->body();
        $control = $this->look()->metric(Metric::ControlHeight);
        $size = $body->extra['swatch'];
        $gap = $body->extra['swatchGap'];
        $height = $body->extra['gap'] + $body->extra['strip'] + 12;
        $rows = $this->swatches === [] ? 0 : (int) ceil(count($this->swatches) / max(1, (int) (($width + $gap) / ($size + $gap))));
        $this->compact || $this->hex === null || $height += $body->extra['gap'] + $control * 2 + $body->extra['fieldGap'];
        $rows > 0 && $height += $body->extra['gap'] + $rows * $size + ($rows - 1) * $gap;

        return $height;
    }

    private function body(): PartLook
    {
        return $this->look()->part('ColorPicker', 'Body', State::Normal);
    }

    private function makeFields(): bool
    {
        if ($this->hex !== null) {
            return true;
        }
        $this->add($this->hex = new HexField('code', $this));
        $this->add($this->mode = new ModeButton('mode', $this));
        foreach (range(0, 3) as $i) {
            $this->add($this->fields[] = new NumberField(['first', 'second', 'third', 'opacity'][$i], $i, $this));
        }
        $this->fieldRanges();
        $this->syncFields();

        return true;
    }

    private function removeFields(): void
    {
        if ($this->hex === null) {
            return;
        }
        foreach ([$this->hex, $this->mode, ...$this->fields] as $w) {
            $this->remove($w);
        }
        [$this->hex, $this->mode, $this->fields] = [null, null, []];
    }

    /** color_picker.c:239-255 */
    private function fieldRanges(): void
    {
        if ($this->hex === null) {
            return;
        }
        $rgb = ['Red, 0 to 255', 'Green, 0 to 255', 'Blue, 0 to 255', 'Opacity, in percent'];
        $hsv = ['Hue, 0 to 360 degrees', 'Saturation, in percent', 'Value, in percent', 'Opacity, in percent'];
        $this->syncing = true;
        foreach ($this->fields as $i => $f) {
            $f->setRange(0.0, $i === 3 ? 100.0 : ($this->hsvFields ? ($i === 0 ? 360.0 : 100.0) : 255.0));
            $f->setTooltip(($this->hsvFields ? $hsv : $rgb)[$i]);
        }
        $this->mode->setLabel($this->hsvFields ? 'HSV' : 'RGB');
        $this->syncing = false;
    }

    /** @return array{float, float, float} */
    private function rgb(): array
    {
        return self::hsvToRgb($this->h, $this->s, $this->v);
    }

    /** Takes red, green and blue, keeping the hue and saturation they cannot tell (color_picker.c:120-133). */
    private function takeRgb(float $r, float $g, float $b): void
    {
        [$h, $s, $v] = self::rgbToHsv(max(0.0, min(1.0, $r)), max(0.0, min(1.0, $g)), max(0.0, min(1.0, $b)));
        if ($v > 1e-5) {
            $s > 1e-5 && $this->h = $h;
            $this->s = $s;
        }
        $this->v = $v;
    }

    /** @return array{float, float, float} (color_picker.c:72-95) */
    public static function hsvToRgb(float $hue, float $s, float $v): array
    {
        $h = fmod($hue, 360.0);
        $h < 0 && $h += 360.0;
        $s = max(0.0, min(1.0, $s));
        $v = max(0.0, min(1.0, $v));
        $c = $v * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2.0) - 1));
        $m = $v - $c;
        [$r, $g, $b] = match ((int) ($h / 60) % 6) {
            0 => [$c, $x, 0.0], 1 => [$x, $c, 0.0], 2 => [0.0, $c, $x], 3 => [0.0, $x, $c], 4 => [$x, 0.0, $c], default => [$c, 0.0, $x],
        };

        return [$r + $m, $g + $m, $b + $m];
    }

    /** @return array{float, float, float} (color_picker.c:97-110) */
    public static function rgbToHsv(float $r, float $g, float $b): array
    {
        $high = max($r, $g, $b);
        $low = min($r, $g, $b);
        $d = $high - $low;
        $h = 0.0;
        if ($d > 1e-6) {
            $h = match ($high) {
                $r => 60 * fmod(($g - $b) / $d, 6.0),
                $g => 60 * (($b - $r) / $d + 2),
                default => 60 * (($r - $g) / $d + 4),
            };
            $h < 0 && $h += 360;
        }

        return [$h, $high > 1e-6 ? $d / $high : 0.0, $high];
    }

    /** The three textures every picker shares; they never change (color_picker.c:144-175). @return array{Framebuffer, Framebuffer, Framebuffer} */
    private static function textures(): array
    {
        if (self::$textures[0] !== null) {
            return self::$textures;
        }
        $white = $black = $hues = '';
        for ($i = 0; $i < 256; $i++) {
            $white .= "\xff\xff\xff".chr(255 - $i);
            $black .= "\x00\x00\x00".chr($i);
        }
        for ($i = 0; $i < 360; $i++) {
            [$r, $g, $b] = self::hsvToRgb($i, 1.0, 1.0);
            $hues .= chr((int) ($r * 255 + 0.5)).chr((int) ($g * 255 + 0.5)).chr((int) ($b * 255 + 0.5))."\xff";
        }
        self::$built += 3;
        $fb = fn (string $bytes, int $w, int $h) => (new NativeFullFramebuffer(FormatSpec::rgba8(), $w, $h))->writeRgba8($bytes, $w, $h);

        return self::$textures = [$fb($white, 256, 1), $fb($black, 1, 256), $fb($hues, 360, 1)];
    }
}
