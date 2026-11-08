<?php

use Silk\Contracts\Look\Role;
use Silk\Core\Motion\LookBlend;
use Silk\Core\Motion\Spring;
use Silk\Core\Motion\SpringPreset;
use Silk\Looks\BuiltInThemes;
use Silk\Looks\LookManager;

/*
 * Spec 7.5: a theme switch carries the colours on a spring, so every widget must paint in the middle of one, from any look
 * to any other, drawing from the look it is given and not from one it kept.
 */
it('paints every widget half way through a switch from raven-dark, when it paints in both looks', function (string $to) {
    $manager = BuiltInThemes::register(new LookManager, 'sans-serif', 'monospace');
    $blend = new LookBlend($manager->use('raven-dark')->current(), $manager->use($to)->current(), new Spring(0.5, SpringPreset::Smooth));
    $calls = function (string $name, callable $make, ?Silk\Contracts\Look\Look $look, ?string $theme) {
        [$ui, $w] = $make();
        $ui->tree->reducedMotion = true;
        $theme !== null ? inLook($ui, $theme) : $ui->tree->setLook($look);
        $ui->tree->layout(new Silk\Contracts\Geometry\Size(300, 600));
        $p = new Venusian\Silk\Tests\Support\SpyPainter;
        $w->render($p);

        return count($p->calls);
    };

    foreach ([...tier1Widgets(), ...m2Widgets(), ...m5Widgets()] as $name => $make) {
        // (an overlay scroll bar is hidden at rest in the platform looks: nothing to paint)
        $ends = [$calls($name, $make, null, 'raven-dark'), $calls($name, $make, null, $to)];
        $mid = $calls($name, $make, $blend, null);

        expect($mid > 0 || min($ends) === 0)->toBeTrue("{$name} paints in the middle of a switch to {$to}");
    }
})->with(array_values(array_diff(array_keys(BuiltInThemes::NAMES), ['raven-dark'])));

it('goes from one look to the other on a SMOOTH spring and lands on the new look exactly (spec 7.5)', function () {
    [$m, $tree] = switching();

    $m->use('macos-light');
    $first = $tree->look instanceof LookBlend ? $tree->look->t->preset : null;
    for ($i = 0; $i < 600 && $tree->advance(1 / 60); $i++) {
    }

    expect($first)->toBe(SpringPreset::Smooth)
        ->and($tree->look)->not->toBeInstanceOf(LookBlend::class)
        ->and($tree->look->color(Role::Window)->toHex())->toBe(BuiltInThemes::register(new LookManager, 'sans-serif', 'monospace')->use('macos-light')->current()->color(Role::Window)->toHex());
});
