<?php


use Silk\Contracts\Look\Metric;
use Silk\Contracts\Look\Role;
use Silk\Core\Motion\SpringPreset;
use Silk\Widgets\Toggle;
use Surface\Contracts\Windows\Mail\View\Toggled;

it('flips, posts Toggled and moves its knob on a bouncy spring', function () {
    $ui = silkUi(fn ($root) => $root->toggle('wifi'));
    $sw = $ui->root->children()[0];

    click($ui->router, ...centre($sw));

    expect($sw)->toBeInstanceOf(Toggle::class)
        ->and($sw->isOn())->toBeTrue()
        ->and($sw->knob()->preset)->toBe(SpringPreset::Bouncy)
        ->and($sw->knob()->atRest())->toBeFalse()
        ->and($ui->loop->posted[0])->toBeInstanceOf(Toggled::class)
        ->and($ui->loop->names())->toBe(['view.toggled.main.window.wifi']);
});

it('slides the knob for a programmatic setOn, as Raven, and posts nothing', function () {
    $ui = silkUi(fn ($root) => $root->toggle('wifi'));
    $sw = $ui->root->children()[0];

    $sw->setOn(true);

    expect($sw->knob()->atRest())->toBeFalse()->and($sw->knob()->finish()->value())->toBe(1.0)->and($ui->loop->posted)->toBe([]);
});

it('paints RavenDark as Raven: a Control capsule floor(1.9 x CheckSize) by CheckSize, a HandleHovered knob 4 smaller, no shadow (checkbox.c:40, 319-340)', function () {
    [$sw, $calls, $look] = toggleIn('raven-dark');
    $size = $look->metric(Metric::CheckSize);

    expect(array_column($calls, 0))->toBe(['rect', 'rect'])
        ->and([$calls[0][1][0]->w, $calls[0][1][0]->h])->toBe([floor($size * 1.9), $size])
        ->and($calls[0][1][1])->toBe($look->color(Role::Control)->toHex())
        ->and($calls[0][1][3])->toBe($look->color(Role::ControlBorder)->toHex())
        ->and($calls[1][1][0]->h)->toBe($size - 4)
        ->and($calls[1][1][0]->x - $calls[0][1][0]->x)->toBe(2.0)
        ->and($calls[1][1][1])->toBe($look->color(Role::HandleHovered)->toHex());
});

it('paints RavenDark on: an Accent capsule without outline, an AccentText knob at the far end (checkbox.c:324-339)', function () {
    [$sw, $calls, $look] = toggleIn('raven-dark', fn ($s) => $s->setOn(true));
    [$track, $knob] = [$calls[0][1], $calls[1][1]];

    expect($track[1])->toBe($look->color(Role::Accent)->toHex())
        ->and($track[4])->toBe(0.0)
        ->and($knob[1])->toBe($look->color(Role::AccentText)->toHex())
        ->and($knob[0]->x + $knob[0]->w)->toBe($track[0]->x + $track[0]->w - 2);
});

it('stretches the RavenDark knob 0.28 of its height under the finger (checkbox.c:323)', function () {
    [$sw, $calls, $look] = toggleIn('raven-dark', fn ($s) => $s->press()->target(1.0));
    $h = $look->metric(Metric::CheckSize) - 4;

    expect(round($calls[1][1][0]->w, 4))->toBe(round($h * 1.28, 4));
});

it('paints macOS as NSSwitch: 38 x 22, the ink off, Switch on, a white knob 20 with a shadow (checkbox.c:38, 156-180)', function () {
    [$sw, $calls, $look] = toggleIn('macos-light');
    [, $on] = toggleIn('macos-light', fn ($s) => $s->setOn(true));
    $rects = fn ($cs) => array_values(array_filter($cs, fn ($x) => $x[0] === 'rect'));
    [$off, $onRects] = [$rects($calls), $rects($on)];

    expect([$off[0][1][0]->w, $off[0][1][0]->h])->toBe([38.0, 22.0])
        ->and($off[0][1][2])->toBe(11.0)
        ->and($onRects[0][1][1])->toBe($look->color(Role::Switch)->toHex())
        ->and($off[1][1][0]->h)->toBe(20.0)
        ->and($off[1][1][1])->toBe('#ffffff')
        ->and(array_column($calls, 0))->toContain('shadow');
});

it('paints GNOME 48 x 26 with a knob 20 that does not stretch (checkbox.c:39, 173, 224)', function () {
    [$sw, $calls] = toggleIn('gnome-dark', fn ($s) => $s->press()->target(1.0));
    $rects = array_values(array_filter($calls, fn ($x) => $x[0] === 'rect'));

    expect([$rects[0][1][0]->w, $rects[0][1][0]->h])->toBe([48.0, 26.0])
        ->and([$rects[1][1][0]->w, $rects[1][1][0]->h])->toBe([20.0, 20.0]);
});
