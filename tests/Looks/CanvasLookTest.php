<?php

use Silk\Contracts\Look\State;
use Silk\Looks\BuiltInThemes;
use Silk\Looks\Canvas\CanvasLook;
use Silk\Looks\Canvas\CanvasPreset;
use Silk\Looks\Canvas\CanvasShape;
use Silk\Looks\Canvas\PartOverrides;
use Silk\Looks\Canvas\PartStyle;
use Silk\Looks\LookManager;
use Surface\NutsAndBolts\Color;

$look = fn (string $theme) => BuiltInThemes::register(new LookManager, 'sans-serif', 'monospace')->use($theme)->current();

it('reads presets and shapes by name with Raven\'s other words', function () {
    expect(CanvasPreset::byName('Unreal'))->toBe(CanvasPreset::Blueprint)->and(CanvasPreset::byName('glow'))->toBe(CanvasPreset::Neon)
        ->and(CanvasPreset::byName('Native'))->toBe(CanvasPreset::Auto)->and(CanvasPreset::byName('x'))->toBeNull()
        ->and(CanvasShape::byName('spline'))->toBe(CanvasShape::Bezier)->and(CanvasShape::byName('Off'))->toBe(CanvasShape::None);
});

it('parses part properties as rvn_part_property: short names, colours, None, bad values', function () {
    $s = new PartStyle;
    expect($s->set('Fill', '#abc'))->toBe(PartStyle::BACKGROUND)->and($s->background->toHex())->toBe(Color::rgb(0xAA, 0xBB, 0xCC)->toHex())
        ->and($s->set('Border', '#11223380'))->toBe(PartStyle::BORDER)->and(round($s->border->alpha * 255))->toBe(128.0)
        ->and($s->set('Radius', '4'))->toBe(PartStyle::RADIUS)->and($s->radius)->toBe(4.0)
        ->and($s->set('Style', 'Outline'))->toBe(PartStyle::SHAPE)->and($s->shape)->toBe(CanvasShape::Outline)
        ->and($s->set('Background', 'None'))->toBe(PartStyle::BACKGROUND)->and($s->background)->toBeNull()
        ->and($s->set('Text', 'magenta'))->toBe(PartStyle::FOREGROUND)
        ->and($s->set('Nothing', '1'))->toBe(0);
    expect(fn () => $s->set('Glow', '-1'))->toThrow(InvalidArgumentException::class);
});

it('applies a border alone as the whole border, with a width of 1 when it had none (look.c:287-291)', function () {
    $s = new PartStyle;
    $s->borderTop = Color::hex('#ffffff');
    $from = new PartStyle;
    $from->border = Color::hex('#000000');
    $s->apply($from, PartStyle::BORDER);

    expect($s->borderTop)->toBeNull()->and($s->borderWidth)->toBe(1.0);
});

it('blends parts, a form changing half way, a transparent end taking the other\'s colour', function () {
    $a = new PartStyle;
    $b = new PartStyle;
    $b->background = Color::hex('#ff0000');
    $b->radius = 8.0;
    $b->shape = CanvasShape::Ring;
    $a->mix($b, 0.25);
    expect(round($a->background->alpha, 2))->toBe(0.25)->and(rgbKey($a->background))->toBe('#ff0000')
        ->and($a->radius)->toBe(2.0)->and($a->shape)->toBe(CanvasShape::Default);
    $a->mix($b, 0.5);
    expect($a->shape)->toBe(CanvasShape::Ring);
});

it('resolves a part: the base, the theme rows of the preset classes, then the overrides', function () use ($look) {
    $c = new CanvasLook($look('raven-light'), CanvasPreset::Blender);
    $over = new PartOverrides;
    $over->setDotted('Clip.CornerRadius', '12');
    $clip = $c->part('Timeline', 'Clip', State::Normal, fn (PartStyle $s) => $s->radius = 1.0, $over);
    $base = $c->part('Timeline', 'Clip', State::Normal, fn () => null);

    expect($c->dark)->toBeTrue()->and($c->classes())->toBe(['blender', 'blender-dark'])
        ->and($base->shape)->toBe(CanvasShape::Solid)->and($base->radius)->toBe(3.0)->and($base->foreground->toHex())->toBe(Color::hex('#EDEDED')->toHex())
        ->and($clip->radius)->toBe(12.0);
});

it('takes Auto from the theme\'s family, and rows set things to nothing with none', function () use ($look) {
    $mac = new CanvasLook($look('macos-dark'), CanvasPreset::Auto);
    $clip = $mac->part('Timeline', 'Clip', State::Normal, fn (PartStyle $s) => $s->borderWidth = 1.0);
    $neon = new CanvasLook($look('raven-dark'), CanvasPreset::Neon);
    $track = $neon->part('Timeline', 'Track', State::Normal, fn (PartStyle $s) => $s->background = Color::hex('#ffffff'));

    expect($mac->preset)->toBe('macos')->and($clip->shape)->toBe(CanvasShape::Solid)->and($clip->borderWidth)->toBe(0.0)
        ->and($track->background)->toBeNull();
});
