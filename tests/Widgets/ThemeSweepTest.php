<?php

use Silk\Looks\BuiltInThemes;

/*
 * Every widget at rest (tier 1 and M2), in every theme: each colour it paints (the paint commands, never pixels) is one of
 * that theme's role or syntax colours, a colour its part looks gave out (style rows), or a Raven derivation or
 * value its tier1Widgets()/m2Widgets() entry names. A theme added
 * to BuiltInThemes is swept with no change here.
 */
it('paints only colours from the look', function (string $widget, string $theme) {
    [$ui, $w, $extra] = [...tier1Widgets(), ...m2Widgets(), ...m5Widgets()][$widget]();

    expect(offRoleColours($ui, $w, $theme, $extra))->toBe([]);
})->with(array_keys([...tier1Widgets(), ...m2Widgets(), ...m5Widgets()]))->with(array_keys(BuiltInThemes::NAMES));

it('paints something in every theme', function (string $widget) {
    [$ui, $w] = [...tier1Widgets(), ...m2Widgets(), ...m5Widgets()][$widget]();
    $p = new Venusian\Silk\Tests\Support\SpyPainter;
    $w->render($p);

    expect(count($p->calls))->toBeGreaterThan(0);
})->with(array_keys([...tier1Widgets(), ...m2Widgets(), ...m5Widgets()]));

it('catches a colour that is not the theme\'s', function () {
    $ui = markdownUi('text');
    $ui->label->setColor(Silk\Contracts\Look\Role::Text, Surface\NutsAndBolts\Color::hex('#123457'));

    expect(offRoleColours($ui, $ui->label, 'raven-light', fn () => []))->toBe(['#123457']);
});
