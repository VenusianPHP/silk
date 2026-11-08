<?php

use Silk\Contracts\Look\Material;
use Silk\Contracts\Look\Role;
use Silk\Contracts\Look\SurfaceKind;
use Silk\Looks\BuiltInThemes;
use Silk\Looks\GlassStyle;
use Silk\Looks\LookManager;

/*
 * GlassStyle: Raven's glass_style (common.c:48-175). Numbers are worked from the source by hand.
 */

function glassLook(string $name)
{
    return BuiltInThemes::register(new LookManager, 'sans-serif', 'monospace')->use($name)->current();
}

it('makes a Control of the control colour, 0.9 frost, a shadow of 6, the rim at 0.55 in a dark look', function () {
    $look = glassLook('raven-glass-dark');
    $g = GlassStyle::of($look, SurfaceKind::Control);

    // Control #FFFFFF/28: not past 0.74, kept
    expect(round($g->tint->alpha * 255))->toBe(28.0)
        ->and([$g->frost, $g->refraction, $g->saturation, $g->shadow, $g->bevel])->toBe([0.9, 10.0, 1.5, 6.0, 0.0])
        ->and($g->rim->alpha)->toBe(0.55)
        ->and($g->glow)->toBe(0.0)
        ->and($g->shadowColor->toHex())->toBe($look->color(Role::Shadow)->toHex());
});

it('mixes the tint toward hovered and pressed, and lights the rim and the glass when pressed', function () {
    $look = glassLook('raven-glass-dark');
    $pressed = GlassStyle::of($look, SurfaceKind::Control, 0.0, 1.0);

    // ControlPressed #FFFFFF/66; the rim 0.55 + 0.25; a glow of 0.05
    expect(round($pressed->tint->alpha * 255))->toBe(66.0)
        ->and($pressed->rim->alpha)->toBe(0.8)
        ->and($pressed->glow)->toBe(0.05);
    expect(round(GlassStyle::of($look, SurfaceKind::Control, 0.5)->tint->alpha * 255))->toBe(37.0);
});

it('makes an Accent 0.84 of the accent, more saturated glass than a control, with a shadow of 7', function () {
    $g = GlassStyle::of(glassLook('raven-glass-dark'), SurfaceKind::Accent);

    expect(round($g->tint->alpha * 255))->toBe(214.0)
        ->and([$g->saturation, $g->shadow])->toBe([1.3, 7.0]);
});

it('sets the bevel, refraction and shadow of a Bar, a Panel, a Popup and a Lens', function () {
    $look = glassLook('raven-glass-dark');
    $at = fn (SurfaceKind $k) => GlassStyle::of($look, $k);

    expect([$at(SurfaceKind::Bar)->bevel, $at(SurfaceKind::Bar)->refraction])->toBe([6.0, 8.0])
        ->and([$at(SurfaceKind::Panel)->bevel, $at(SurfaceKind::Panel)->refraction])->toBe([12.0, 14.0])
        ->and([$at(SurfaceKind::Popup)->bevel, $at(SurfaceKind::Popup)->refraction, $at(SurfaceKind::Popup)->saturation, $at(SurfaceKind::Popup)->shadow])->toBe([10.0, 14.0, 1.7, 22.0])
        ->and([$at(SurfaceKind::Lens)->frost, $at(SurfaceKind::Lens)->refraction, $at(SurfaceKind::Lens)->rim->alpha])->toBe([0.0, 16.0, 1.0])
        ->and(round($at(SurfaceKind::Lens)->tint->alpha * 255))->toBe(15.0);
});

it('keeps a pale rim stronger in a light look, and caps a tint past 0.74 of alpha', function () {
    $light = glassLook('raven-glass-light');

    // Panel #FFFFFF/105 -> kept; Popup #FAFAFA/160 -> kept; the light rim is 0.85
    expect(GlassStyle::of($light, SurfaceKind::Control)->rim->alpha)->toBe(0.85);
    $loud = GlassStyle::of(glassLook('raven-glass-dark'), SurfaceKind::Panel, 0, 0, Material::FrostedGlass);
    expect($loud->tint->alpha)->toBeLessThanOrEqual(0.74 + 1 / 255);
});

it('clears the glass in the Glass material: less tint, frost 0.22 of it, a stronger bend', function () {
    $look = glassLook('raven-glass-dark');
    $g = GlassStyle::of($look, SurfaceKind::Control, 0, 0, Material::Glass);

    // 28 × 0.55 = 15.4 → 15; frost 0.9 × 0.22; refraction 10 × 1.5; saturation 1 + 0.5 × 0.5; the rim min(0.55 × 1.2, 1)
    expect(round($g->tint->alpha * 255))->toBe(15.0)
        ->and($g->frost)->toEqualWithDelta(0.198, 1e-9)
        ->and([$g->refraction, $g->saturation])->toBe([15.0, 1.25])
        ->and($g->rim->alpha)->toEqualWithDelta(0.66, 1e-9);
});

it('shows the accent through a TintedGlass surface', function () {
    $look = glassLook('raven-glass-dark');
    $plain = GlassStyle::of($look, SurfaceKind::Panel, 0, 0, Material::FrostedGlass)->tint;
    $tinted = GlassStyle::of($look, SurfaceKind::Panel, 0, 0, Material::TintedGlass);

    expect($tinted->saturation)->toBe(1.25)
        ->and($tinted->tint->blue)->toBeGreaterThan($plain->blue);
});
