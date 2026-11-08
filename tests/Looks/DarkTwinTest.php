<?php

use Silk\Contracts\Look\Metric;
use Silk\Contracts\Look\Role;
use Silk\Core\Motion\LookBlend;
use Silk\Core\Motion\Spring;
use Silk\Core\Motion\SpringPreset;
use Silk\Looks\BuiltInThemes;
use Silk\Looks\LookManager;

$graph = json_decode(file_get_contents(__DIR__.'/../Fixtures/theme-tokens.json'), true);

it('is the look itself for a dark look (theme.c:187-190)', function (string $config) {
    $look = BuiltInThemes::register(new LookManager, 'sans-serif', 'monospace')->use($config)->current();

    expect($look->darkTwin())->toBe($look);
})->with(['raven-dark', 'raven-high-contrast-dark', 'macos-dark', 'gnome-dark']);

it('is the family\'s dark look for a light one, keeping its accents, focus, metrics and font (theme.c:181-198)', function (string $config, string $twin) use ($graph) {
    $look = BuiltInThemes::register(new LookManager, 'sans-serif', 'monospace')->use($config)->current();
    $dark = $look->darkTwin();

    expect($dark->color(Role::Window)->toHex())->toBe(Silk\Looks\Palette::parse($graph[$twin]['colors']['Window'])->toHex())
        ->and($dark->color(Role::Text)->toHex())->toBe(Silk\Looks\Palette::parse($graph[$twin]['colors']['Text'])->toHex())
        ->and($dark->metric(Metric::CornerRadius))->toBe($look->metric(Metric::CornerRadius))
        ->and($dark->font()->family)->toBe($look->font()->family)
        ->and($dark->darkTwin())->toBe($dark);
    foreach ([Role::Accent, Role::AccentHovered, Role::AccentPressed, Role::AccentText, Role::Focus] as $r) {
        expect($dark->color($r)->toHex())->toBe($look->color($r)->toHex());
    }
})->with([
    ['raven-light', 'RavenDark'],
    // a Raven look, high contrast too, takes RavenDark (theme.c:191)
    ['raven-high-contrast-light', 'RavenDark'],
    ['macos-light', 'MacOSDark'],
    ['gnome-light', 'GnomeDark'],
]);

it('blends the twins in a theme switch', function () {
    $m = BuiltInThemes::register(new LookManager, 'sans-serif', 'monospace');
    [$a, $b] = [$m->use('raven-light')->current(), $m->use('gnome-light')->current()];
    $half = new LookBlend($a, $b, new Spring(0.5, SpringPreset::Smooth));

    expect($half->darkTwin()->color(Role::Window)->toHex())
        ->toBe(LookBlend::mix($a->darkTwin()->color(Role::Window), $b->darkTwin()->color(Role::Window), 0.5)->toHex());
});

it('names its family, the one a blend heads to', function () {
    $m = BuiltInThemes::register(new LookManager, 'sans-serif', 'monospace');
    $families = array_map(fn ($c) => $m->use($c)->current()->family(), array_keys(BuiltInThemes::NAMES));
    [$a, $b] = [$m->use('raven-dark')->current(), $m->use('gnome-light')->current()];

    expect($families)->toBe(['raven', 'raven', 'raven', 'raven', 'macos', 'macos', 'gnome', 'gnome', 'raven', 'raven', 'macos', 'macos'])
        ->and((new LookBlend($a, $b, new Spring(0.2, SpringPreset::Smooth)))->family())->toBe('gnome')
        ->and($b->darkTwin()->family())->toBe('gnome');
});
