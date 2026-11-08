<?php

use Silk\Looks\BuiltInThemes;
use Silk\Painter\RavenPaint;
use Surface\NutsAndBolts\Color;

/* M1 step 3: what a widget paints must be readable in every look. */

it('reads the active tab of a TabView in every look', function (string $theme) {
    [$ui, $w] = [...tier1Widgets(), ...m2Widgets()]['TabView']();
    inLook($ui, $theme);

    expect(inkContrast($ui, $w, 'One'))->toBeGreaterThan(3.0);
})->with(array_keys(BuiltInThemes::NAMES));

it('reads the active tab of a DockArea in every look', function (string $theme) {
    [$ui, $w] = m5Widgets()['DockArea']();
    inLook($ui, $theme);

    expect(inkContrast($ui, $w, 'Files'))->toBeGreaterThan(3.0);
})->with(array_keys(BuiltInThemes::NAMES));

it('keeps the ink where it already reads, and takes the other where it does not (inkOn)', function () {
    [$white, $black, $cyan] = [new Color(1, 1, 1), new Color(0, 0, 0), Color::hex('#1AEBFF')];
    $dark = Color::hex('#1E1E20');

    expect(RavenPaint::inkOn($white, $dark, $dark, $black))->toBe($white)
        ->and(RavenPaint::inkOn($white, $cyan, $black, $black))->toBe($black)
        // a see-through fill is judged on what it lies on
        ->and(RavenPaint::inkOn($white, new Color(1, 1, 1, 0.2), $black, $black))->toBe($white);
});
