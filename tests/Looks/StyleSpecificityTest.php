<?php

use Silk\Contracts\Look\Role;
use Silk\Contracts\Look\State;
use Silk\Core\Layout\Column;
use Silk\Core\Layout\Spacer;
use Silk\Looks\LookManager;
use Silk\Looks\RavenDark;
use Surface\NutsAndBolts\Color;

function styled(array $styles): Silk\Contracts\Look\Look
{
    return (new LookManager)
        ->extend('raven-dark', fn () => RavenDark::theme())
        ->extend('t', fn () => ['look' => 'raven-dark', 'styles' => $styles])
        ->use('t')
        ->current();
}

it('gives a button body the control colours by state', function () {
    $look = styled([]);

    expect($look->part('Button', 'Body', State::Normal)->background->toHex())->toBe('#35353a')
        ->and($look->part('Button', 'Body', State::Hover)->background->toHex())->toBe('#424248')
        ->and($look->part('Button', 'Body', State::Pressed)->background->toHex())->toBe('#2b2b30')
        ->and($look->part('Button', 'Body', State::Normal)->radius)->toBe(4.0);
});

it('lets a class style beat a type style, whatever the order', function () {
    $look = styled([
        [null, 'danger', 'Body', null, ['background' => Color::hex('#ff0000')]],
        ['Button', null, 'Body', null, ['background' => Color::hex('#00ff00')]],
    ]);

    expect($look->part('Button', 'Body', State::Normal, ['danger'])->background->toHex())->toBe('#ff0000')
        ->and($look->part('Button', 'Body', State::Normal)->background->toHex())->toBe('#00ff00');
});

it('lets a state style beat a class style', function () {
    $look = styled([
        ['Button', null, 'Body', State::Hover, ['background' => Color::hex('#0000ff')]],
        ['Button', 'danger', 'Body', null, ['background' => Color::hex('#ff0000')]],
    ]);

    // type+state = 3, type+class = 3: equal, so the last written wins
    expect($look->part('Button', 'Body', State::Hover, ['danger'])->background->toHex())->toBe('#ff0000');

    $look = styled([
        [null, 'danger', 'Body', null, ['background' => Color::hex('#ff0000')]],
        ['Button', null, 'Body', State::Hover, ['background' => Color::hex('#0000ff')]],
    ]);

    // state + type (3) beats class (2)
    expect($look->part('Button', 'Body', State::Hover, ['danger'])->background->toHex())->toBe('#0000ff');
});

it('lets a widget colour beat the theme, and only text inherits', function () {
    $look = styled([]);
    $col = (new Column('window'))->setColor(Role::Text, Color::hex('#ff00ff'))->setColor(Role::Panel, Color::hex('#00ffff'));
    $col->add($leaf = new Spacer('leaf'));

    expect($col->color(Role::Panel, $look)->toHex())->toBe('#00ffff')
        ->and($leaf->color(Role::Text, $look)->toHex())->toBe('#ff00ff')
        ->and($leaf->color(Role::Panel, $look)->toHex())->toBe('#27272a');
});
