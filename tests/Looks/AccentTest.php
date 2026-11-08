<?php

use Silk\Contracts\Look\Role;
use Silk\Looks\Accent;
use Silk\Looks\Palette;
use Surface\NutsAndBolts\Color;

/** Raven's own numbers: the branches of rvn_theme_set_accent (theme.c:230-299) compiled to C and run on these inputs. */
function raven(Color $c): string
{
    return sprintf('#%02x%02x%02x/%d', round($c->red * 255), round($c->green * 255), round($c->blue * 255), round($c->alpha * 255));
}

function derived(string $hex, string $family, bool $dark, bool $switchFollows = true): array
{
    $palette = new Palette([
        'Window' => '#1e1e20', 'Accent' => '#3b82f6', 'Focus' => '#3b82f6/80', 'Selection' => '#3b82f6/100',
        'Switch' => $switchFollows ? '#3b82f6' : '#808080',
    ]);

    return array_map(raven(...), Accent::derive(Color::hex($hex), $family, $dark, $palette));
}

// accent hovered pressed text focus selection link
dataset('accents', [
    'macos blue dark' => ['3584E4', 'macos', true, ['#3584e4/255', '#3584e4/255', '#5d9de9/255', '#ffffff/255', '#3584e4/128', '#284c77/255', '#5d9de9/255']],
    'macos blue light' => ['3584E4', 'macos', false, ['#3584e4/255', '#3584e4/255', '#2a6ab6/255', '#ffffff/255', '#3584e4/128', '#c2daf7/255', '#2f74c9/255']],
    'macos yellow dark' => ['F5C211', 'macos', true, ['#f5c211/255', '#f5c211/255', '#f7ce41/255', '#000000/217', '#f5c211/128', '#7f6818/255', '#f7ce41/255']],
    'macos yellow light' => ['F5C211', 'macos', false, ['#f5c211/255', '#f5c211/255', '#c49b0e/255', '#000000/217', '#f5c211/128', '#fcedb8/255', '#d8ab0f/255']],
    'gnome blue dark' => ['3584E4', 'gnome', true, ['#3584e4/255', '#4990e7/255', '#2a6ab6/255', '#ffffff/255', '#7cafed/128', '#3584e4/64', '#7cafed/255']],
    'gnome blue light' => ['3584E4', 'gnome', false, ['#3584e4/255', '#4990e7/255', '#2a6ab6/255', '#ffffff/255', '#2d70c2/128', '#3584e4/64', '#2d70c2/255']],
    'gnome yellow dark' => ['F5C211', 'gnome', true, ['#f5c211/255', '#f6c829/255', '#c49b0e/255', '#000006/204', '#f9d764/128', '#f5c211/64', '#f9d764/255']],
    'gnome yellow light' => ['F5C211', 'gnome', false, ['#f5c211/255', '#f6c829/255', '#c49b0e/255', '#000006/204', '#d0a50e/128', '#f5c211/64', '#d0a50e/255']],
    'raven blue dark' => ['3584E4', 'raven', true, ['#3584e4/255', '#599ae9/255', '#2b6cbb/255', '#ffffff/255', '#3584e4/80', '#3584e4/100', '#72a9ec/255']],
    'raven blue light' => ['3584E4', 'raven', false, ['#3584e4/255', '#599ae9/255', '#2b6cbb/255', '#ffffff/255', '#3584e4/80', '#3584e4/100', '#2d70c2/255']],
    'raven yellow dark' => ['F5C211', 'raven', true, ['#f5c211/255', '#f7cd3c/255', '#c99f0e/255', '#101014/255', '#f5c211/80', '#f5c211/100', '#f8d458/255']],
    'raven yellow light' => ['F5C211', 'raven', false, ['#f5c211/255', '#f7cd3c/255', '#c99f0e/255', '#101014/255', '#f5c211/80', '#f5c211/100', '#d0a50e/255']],
]);

it('derives the roles the way Raven does', function (string $hex, string $family, bool $dark, array $want) {
    $got = derived($hex, $family, $dark);

    expect([$got['Accent'], $got['AccentHovered'], $got['AccentPressed'], $got['AccentText'], $got['Focus'], $got['Selection'], $got['Link']])
        ->toBe($want);
})->with('accents');

it('gives the Raven look an opaque selection a mix with the window', function () {
    $palette = new Palette(['Window' => '#1e1e20', 'Accent' => '#3b82f6', 'Focus' => '#3b82f6/0', 'Selection' => '#3b82f6', 'Switch' => '#808080']);
    $got = array_map(raven(...), Accent::derive(Color::hex('3584E4'), 'raven', true, $palette));

    // no focus alpha: opaque; no selection alpha: the accent seen through the window (theme.c:293-297)
    expect($got['Focus'])->toBe('#3584e4/255')
        ->and($got['Selection'])->toBe('#284c78/255');
});

it('moves the switch only when it followed the accent', function () {
    expect(derived('3584E4', 'gnome', true, true))->toHaveKey('Switch')
        ->and(derived('3584E4', 'gnome', true, true)['Switch'])->toBe('#3584e4/255')
        ->and(derived('3584E4', 'gnome', true, false))->not->toHaveKey('Switch');
});

it('names only colour roles', function () {
    $roles = array_keys(derived('3584E4', 'macos', false));

    foreach ($roles as $role) {
        expect(defined(Role::class.'::'.$role))->toBeTrue();
    }
});
