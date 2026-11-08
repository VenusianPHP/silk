<?php

namespace Silk\Looks;

use Silk\Contracts\Look\Look;
use Silk\Contracts\Look\Material;
use Silk\Contracts\Look\Metric;
use Silk\Contracts\Look\Motion;
use Silk\Contracts\Look\PartLook;
use Silk\Contracts\Look\Role;
use Silk\Contracts\Look\State;
use Silk\Contracts\Look\SyntaxToken;
use Silk\Contracts\Look\FontRole;
use Silk\Contracts\Look\FontSpec;
use Surface\NutsAndBolts\Color;

/** A look built from a theme laid over its base: palette, metrics and style sheet. */
class ThemedLook implements Look
{
    /** @var array<string, PartLook> */
    private array $parts = [];

    private ?Look $twin = null;

    /** The colours a light look gives its dark twin (theme.c:181-183). */
    private const array KEPT = [Role::Accent, Role::AccentHovered, Role::AccentPressed, Role::AccentText, Role::Focus];

    public function __construct(
        public readonly Palette $palette,
        public readonly Metrics $metrics,
        public readonly StyleSheet $styles,
        public readonly array $theme = [],
    ) {}

    public function color(Role $r): Color
    {
        return $this->palette->get($r);
    }

    /** Raven family (high contrast too) -> RavenDark; macOS -> MacOSDark; GNOME -> GnomeDark (theme.c:185-198). */
    public function darkTwin(): Look
    {
        if ((bool) ($this->theme['dark'] ?? true)) {
            return $this;
        }
        if ($this->twin !== null) {
            return $this->twin;
        }
        // (a glass look's twin is the dark glass look: it stays glass, theme.c:186-190)
        $t = BuiltInThemes::theme(match ($this->theme['family'] ?? 'raven') {
            'macos' => $this->liquid() ? 'MacOSGlassDark' : 'MacOSDark',
            'gnome' => 'GnomeDark',
            default => $this->material()->isGlass() ? 'RavenGlassDark' : 'RavenDark',
        });
        $palette = new Palette($t['colors']);
        $palette->merge(array_combine(array_map(fn (Role $r) => $r->name, self::KEPT), array_map(fn (Role $r) => $this->color($r), self::KEPT)));

        return $this->twin = new self($palette, clone $this->metrics, (new StyleSheet)->add($t['styles']),
            ['font' => $this->theme['font'] ?? $t['font'], 'material' => $this->theme['material'] ?? $t['material']] + $t);
    }

    public function dark(): bool
    {
        return (bool) ($this->theme['dark'] ?? true);
    }

    public function material(): Material
    {
        if ($this->family() !== 'raven') {
            return Material::Solid;
        }

        return Material::tryFrom((string) ($this->theme['material'] ?? 'solid')) ?? Material::Solid;
    }

    public function liquid(): bool
    {
        return (bool) ($this->theme['liquid'] ?? false);
    }

    public function family(): string
    {
        return (string) ($this->theme['family'] ?? 'raven');
    }

    public function syntax(SyntaxToken $t): ?Color
    {
        $own = $this->theme['syntax'] ?? [];
        $v = array_key_exists($t->name, $own) ? $own[$t->name] : BuiltInThemes::syntax((bool) ($this->theme['dark'] ?? true))[$t->name];
        if ($v === null) {
            return null;
        }
        $c = Palette::parse($v);

        return $c->alpha < 1.0 ? new Color($c->red, $c->green, $c->blue, 1.0) : $c;
    }

    public function metric(Metric $m): float
    {
        return $this->metrics->get($m);
    }

    public function font(FontRole $role = FontRole::Ui): FontSpec
    {
        $font = $this->theme['font'] ?? [];
        $family = $role === FontRole::Mono ? ($font['mono'] ?? 'monospace') : ($font['family'] ?? 'sans-serif');

        return new FontSpec($family, $this->metric(Metric::FontSize));
    }

    public function motion(): Motion
    {
        return Motion::tryFrom($this->theme['motion'] ?? 'springy') ?? Motion::Springy;
    }

    public function part(string $widget, string $part, State $state, array $classes = []): PartLook
    {
        $key = $widget.'|'.$part.'|'.$state->name.'|'.implode(',', $classes);

        return $this->parts[$key] ??= $this->build($this->styles->resolve($widget, $part, $state, $classes));
    }

    /** Props name roles and metrics by string, or give a Color or number directly. */
    private function build(array $props): PartLook
    {
        $color = fn ($v) => match (true) {
            $v === null, $v instanceof Color => $v,
            default => $this->color(constant(Role::class.'::'.$v)),
        };
        $number = fn ($v) => is_string($v) ? $this->metric(constant(Metric::class.'::'.$v)) : (float) ($v ?? 0.0);

        $background = isset($props['ink']) ? $this->ink((float) $props['ink']) : $color($props['background'] ?? null);
        // other props: metric names resolve, colours and other strings stay (a named variant, such as steppers => 'stacked')
        $extra = array_map(fn ($v) => match (true) {
            $v instanceof Color => $v,
            is_bool($v) => (float) $v,
            is_string($v) && ! defined(Metric::class.'::'.$v) => $v,
            default => $number($v),
        }, array_diff_key($props, array_flip(self::PROPS)));
        $text = isset($props['textInk']) ? $this->ink((float) $props['textInk']) : $color($props['text'] ?? null);

        return new PartLook(
            self::scaled($background, (float) ($props['backgroundAlpha'] ?? 1.0)),
            self::scaled(isset($props['borderInk']) ? $this->ink((float) $props['borderInk']) : $color($props['border'] ?? null), (float) ($props['borderAlpha'] ?? 1.0)),
            self::scaled($text, (float) ($props['textAlpha'] ?? 1.0)),
            $number($props['radius'] ?? null),
            $number($props['borderWidth'] ?? null),
            $number($props['shadow'] ?? null),
            $this->faded($color($props['shadowColor'] ?? null), (float) ($props['shadowAlpha'] ?? 1.0)),
            $extra,
            self::scaled($color($props['borderTop'] ?? null), (float) ($props['borderAlpha'] ?? 1.0) * (float) ($props['borderTopAlpha'] ?? 1.0)),
            self::scaled(isset($props['borderBottomInk']) ? $this->ink((float) $props['borderBottomInk']) : $color($props['borderBottom'] ?? null),
                (float) ($props['borderAlpha'] ?? 1.0) * (float) ($props['borderBottomAlpha'] ?? 1.0)),
        );
    }

    /** The props PartLook has fields for; the rest go to its extra. */
    private const array PROPS = ['background', 'border', 'text', 'radius', 'borderWidth', 'shadow', 'shadowColor', 'shadowAlpha',
        'backgroundAlpha', 'borderAlpha', 'textAlpha', 'ink', 'borderTop', 'borderBottom', 'borderTopAlpha', 'borderBottomAlpha', 'textInk',
        'borderInk', 'borderBottomInk'];

    /** The text colour, opaque, at $alpha: Raven rvn_platform_ink (platform_looks.c:20). */
    private function ink(float $alpha): Color
    {
        $t = $this->color(Role::Text);

        return new Color($t->red, $t->green, $t->blue, $alpha);
    }

    /** Alpha times $factor, clamped to 1: Raven rvn_alpha_scale (platform_looks.c:26), which may grow it. */
    private static function scaled(?Color $c, float $factor): ?Color
    {
        return $c === null || $factor === 1.0 ? $c : new Color($c->red, $c->green, $c->blue, min(1.0, max(0.0, $c->alpha * $factor)));
    }

    private function faded(?Color $c, float $alpha): ?Color
    {
        return $c === null || $alpha >= 1.0 ? $c : new Color($c->red, $c->green, $c->blue, $c->alpha * $alpha);
    }
}
