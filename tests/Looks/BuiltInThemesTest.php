<?php

use Silk\Contracts\Look\Metric;
use Silk\Contracts\Look\Role;
use Silk\Looks\BuiltInThemes;
use Silk\Looks\LookManager;
use Silk\Looks\Palette;

dataset('built-in themes', array_keys(BuiltInThemes::NAMES));

it('resolves every role and metric to the graph values', function (string $name) {
    $oracle = json_decode(file_get_contents(__DIR__.'/../Fixtures/theme-tokens.json'), true)[BuiltInThemes::NAMES[$name]];
    $look = BuiltInThemes::register(new LookManager, 'sans-serif', 'monospace')->use($name)->current();

    foreach (Role::cases() as $role) {
        $want = Palette::parse($oracle['colors'][$role->name]);
        $got = $look->color($role);
        expect($got->toHex())->toBe($want->toHex(), "{$name} {$role->name}")
            ->and($got->alpha)->toEqualWithDelta($want->alpha, 1e-9);
    }
    foreach (Metric::cases() as $metric) {
        expect($look->metric($metric))->toBe($oracle['metrics'][$metric->name], "{$name} {$metric->name}");
    }
    expect($look->theme['motion'])->toBe($oracle['motion'])
        ->and($look->theme['family'])->toBe($oracle['family'])
        ->and($look->theme['dark'])->toBe($oracle['dark']);
})->with('built-in themes');

it('lists the eight M1 looks in spec order, then the four glass looks, without the Windows looks', function () {
    expect(array_values(BuiltInThemes::NAMES))->toBe(['RavenDark', 'RavenLight', 'RavenHighContrastDark', 'RavenHighContrastLight', 'MacOSDark', 'MacOSLight', 'GnomeDark', 'GnomeLight', 'RavenGlassDark', 'RavenGlassLight', 'MacOSGlassDark', 'MacOSGlassLight']);
});

it('makes a Raven glass look frosted glass, and a platform\'s glass look solid but liquid (style.c:1000-1002, widget.h:396-413)', function (string $name, Silk\Contracts\Look\Material $material, bool $liquid) {
    $look = BuiltInThemes::register(new LookManager, 'App Sans', 'App Mono')->use($name)->current();

    expect($look->material())->toBe($material)->and($look->liquid())->toBe($liquid)
        ->and($look->darkTwin()->material())->toBe($material)->and($look->darkTwin()->liquid())->toBe($liquid)->and($look->darkTwin()->dark())->toBeTrue();
})->with([
    ['raven-dark', Silk\Contracts\Look\Material::Solid, false],
    ['raven-glass-dark', Silk\Contracts\Look\Material::FrostedGlass, false],
    ['raven-glass-light', Silk\Contracts\Look\Material::FrostedGlass, false],
    ['macos-light', Silk\Contracts\Look\Material::Solid, false],
    ['macos-glass-dark', Silk\Contracts\Look\Material::Solid, true],
    ['macos-glass-light', Silk\Contracts\Look\Material::Solid, true],
]);

it('brings the platform font to platform looks, first family present; Raven looks keep the app font', function (string $name, string $family) {
    $look = BuiltInThemes::register(new LookManager, 'App Sans', 'App Mono')->use($name)->current();

    expect($look->font()->family)->toBe($family);
})->with([
    ['raven-light', 'App Sans'],
    ['raven-high-contrast-dark', 'App Sans'],
    ['macos-dark', 'SF Pro Text,Helvetica Neue,App Sans'],
    ['gnome-light', 'Adwaita Sans,Cantarell,App Sans'],
]);
