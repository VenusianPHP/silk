<?php

use Silk\Contracts\Look\State;
use Silk\Looks\LookManager;
use Silk\Looks\RavenDark;

it('multiplies a colour alpha, as Raven rvn_alpha_scale (platform_looks.c:26), clamped to 1', function () {
    $part = propsLook([['W', null, 'Body', null, ['background' => 'Control', 'backgroundAlpha' => 0.5, 'border' => 'Shadow', 'borderAlpha' => 4.0, 'text' => 'Text', 'textAlpha' => 0.5]]])
        ->part('W', 'Body', State::Normal);

    expect($part->background->alpha)->toEqualWithDelta(0.5, 1e-9)
        ->and($part->border->alpha)->toEqualWithDelta(1.0, 1e-9)
        ->and($part->text->alpha)->toEqualWithDelta(0.5, 1e-9);
});

it('gives ink: the text colour, opaque, at an alpha, as Raven rvn_platform_ink (platform_looks.c:20)', function () {
    $look = propsLook([['W', null, 'Body', State::Hover, ['ink' => 0.07]]]);
    $part = $look->part('W', 'Body', State::Hover);
    $text = $look->color(Silk\Contracts\Look\Role::Text);

    expect($part->background->toHex())->toBe(substr($text->toHex(), 0, 7).'12')
        ->and($part->background->alpha)->toEqualWithDelta(0.07, 1e-9);
});

it('keeps every other prop in extra, metric names resolved', function () {
    $part = propsLook([['W', null, 'Track', null, ['width' => 38.0, 'height' => 22, 'inset' => true, 'thin' => 'CheckSize']]])
        ->part('W', 'Track', State::Normal);

    expect($part->extra)->toBe(['width' => 38.0, 'height' => 22.0, 'inset' => 1.0, 'thin' => 16.0]);
});
