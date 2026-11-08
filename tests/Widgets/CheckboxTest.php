<?php


use Silk\Contracts\Look\Metric;
use Silk\Contracts\Look\Role;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\Windows\Mail\View\Toggled;

it('toggles and posts Toggled on click', function () {
    $ui = silkUi(fn ($root) => $root->checkbox('agree', 'I agree'));
    $box = $ui->root->children()[0];

    click($ui->router, ...centre($box));

    expect($box->isChecked())->toBeTrue()
        ->and($ui->loop->posted[0])->toBeInstanceOf(Toggled::class)
        ->and($ui->loop->posted[0]->on)->toBeTrue()
        ->and($ui->loop->names())->toBe(['view.toggled.main.window.agree']);

    click($ui->router, ...centre($box));
    expect($box->isChecked())->toBeFalse()->and($ui->loop->posted[1]->on)->toBeFalse();
});

it('toggles on Space when focused', function () {
    $ui = silkUi(fn ($root) => $root->checkbox('agree', 'I agree'));
    $box = $ui->root->children()[0];
    $ui->focus->focus($box);

    pressKey($ui->router, Key::SPACE);

    expect($box->isChecked())->toBeTrue()->and($ui->loop->posted)->toHaveCount(1);
});

it('posts nothing for setChecked, and starts checked when asked', function () {
    $ui = silkUi(fn ($root) => $root->checkbox('agree', 'I agree', true));
    $box = $ui->root->children()[0];

    $box->setChecked(false)->setChecked(true);

    expect($box->isChecked())->toBeTrue()->and($ui->loop->posted)->toBe([]);
});

it('posts nothing when released outside', function () {
    $ui = silkUi(fn ($root) => $root->checkbox('agree', 'I agree'));
    [$x, $y] = centre($ui->root->children()[0]);

    click($ui->router, $x, $y, 290, 290);

    expect($ui->loop->posted)->toBe([])->and($ui->root->children()[0]->isChecked())->toBeFalse();
});

it('paints RavenDark as Raven: off, a Control box of radius 3 with a ControlBorder outline (checkbox.c:341-347)', function () {
    [$c, $calls, $look] = checkIn('raven-dark');
    $mark = $look->metric(Metric::CheckSize);
    $rect = $calls[0];

    expect($rect[0])->toBe('rect')
        ->and([$rect[1][0]->w, $rect[1][0]->h])->toBe([$mark, $mark])
        ->and($rect[1][1])->toBe($look->color(Role::Control)->toHex())
        ->and($rect[1][2])->toBe(3.0)
        ->and($rect[1][3])->toBe($look->color(Role::ControlBorder)->toHex())
        ->and($rect[1][4])->toBe($look->metric(Metric::BorderWidth))
        ->and(array_column($calls, 0))->not->toContain('line');
});

it('paints RavenDark on: an Accent box without outline, an AccentText check 0.55 of the mark (checkbox.c:347, 358-359)', function () {
    [$c, $calls, $look] = checkIn('raven-dark', fn ($c) => $c->setChecked(true));
    $lines = array_values(array_filter($calls, fn ($x) => $x[0] === 'line'));

    expect($calls[0][1][1])->toBe($look->color(Role::Accent)->toHex())
        ->and($calls[0][1][4])->toBe(0.0)
        ->and($lines)->toHaveCount(2)
        ->and($lines[0][1][4])->toBe($look->color(Role::AccentText)->toHex())
        // the check spans size x 0.5 left of centre: size = mark x 0.55
        ->and(round($calls[0][1][0]->x + $calls[0][1][0]->w / 2 - $lines[0][1][0], 4))->toBe(round($look->metric(Metric::CheckSize) * 0.55 / 2, 4));
});


it('measures at least a row high and the mark plus Spacing plus the text (checkbox.c:47-58)', function () {
    [$c, , $look] = checkIn('raven-dark');
    $size = $c->measure(new Silk\Contracts\Geometry\Size(300, 300));

    expect($size->h)->toBeGreaterThanOrEqual($look->metric(Metric::RowHeight))
        ->and($size->w)->toBeGreaterThan($look->metric(Metric::CheckSize) + $look->metric(Metric::Spacing));
});

it('paints macOS light off as a white square, round 3.5, its bottom edge darker (checkbox.c:119-130)', function () {
    [$c, $calls, $look] = checkIn('macos-light');
    $rects = array_values(array_filter($calls, fn ($x) => $x[0] === 'rect'));

    expect($rects[0][1][1])->toBe('#ffffff')
        ->and($rects[0][1][2])->toBe(3.5)
        // three bands: the fill, then the outline in the middle and the bottom bands
        ->and(count($rects))->toBeGreaterThan(1);
});

it('paints macOS on in the accent, its bottom edge the accent 15% toward black (checkbox.c:123-126)', function () {
    [$c, $calls, $look] = checkIn('macos-dark', fn ($c) => $c->setChecked(true));
    $accent = $look->color(Role::Accent);
    $shade = Silk\Core\Motion\LookBlend::mix($accent, new Surface\NutsAndBolts\Color(0, 0, 0, 1), 0.15)->toHex();
    $borders = array_map(fn ($x) => $x[1][3] ?? null, array_filter($calls, fn ($x) => $x[0] === 'rect'));

    expect(array_values(array_filter($calls, fn ($x) => $x[0] === 'rect'))[0][1][1])->toBe($accent->toHex())
        ->and($borders)->toContain($shade)
        ->and(array_values(array_filter($calls, fn ($x) => $x[0] === 'line')))->toHaveCount(2);
});

it('paints GNOME as a 20 round square with a ring 2 wide, the accent when on (checkbox.c:42-44, 132-141)', function () {
    [$c, $calls, $look] = checkIn('gnome-dark');
    [$c2, $on] = checkIn('gnome-dark', fn ($c) => $c->setChecked(true));
    $rects = fn ($cs) => array_values(array_filter($cs, fn ($x) => $x[0] === 'rect'));
    [$calls, $on] = [$rects($calls), $rects($on)];

    expect($calls[0][1][0]->w)->toBe($look->metric(Metric::CheckSize) + 6.0)
        ->and($calls[0][1][2])->toBe(6.0)
        ->and($calls[0][1][4])->toBe(2.0)
        // the ring is the text ink at .2 (borderInk; platform_looks.c:20)
        ->and($calls[0][1][3])->toBe((new Surface\NutsAndBolts\Color($look->color(Role::Text)->red, $look->color(Role::Text)->green, $look->color(Role::Text)->blue, 0.2))->toHex())
        ->and(substr($calls[0][1][1], 7))->toBe('00')
        ->and($on[0][1][1])->toBe($look->color(Role::Accent)->toHex())
        ->and($on[0][1][4])->toBe(0.0);
});

it('paints a disabled platform checkbox at half opacity (checkbox.c:143)', function () {
    [$c, $calls] = checkIn('gnome-light', fn ($c) => $c->setEnabled(false));

    expect($calls[0])->toBe(['pushOpacity', [0.5]]);
});

it('dips the RavenDark box 1.5 under the finger (checkbox.c:344-345)', function () {
    [, $calls] = checkIn('raven-dark');
    [, $held] = checkIn('raven-dark', fn ($c) => $c->press()->target(1.0));

    expect($held[0][1][0]->x - $calls[0][1][0]->x)->toBe(1.5)
        ->and($calls[0][1][0]->w - $held[0][1][0]->w)->toBe(3.0);
});

/** A Toggle tree in a look. */
function glassToggleIn(string $theme, bool $on = true): object
{
    $ui = silkUi(fn ($root) => $root->toggle('sw', $on));
    $ui->tree->reducedMotion = true;
    $ui->tree->setLook(Silk\Looks\BuiltInThemes::register(new Silk\Looks\LookManager, 'sans-serif', 'monospace')->use($theme)->current());
    $ui->tree->layout(new Silk\Contracts\Geometry\Size(300, 300));
    settleAll($ui);
    $ui->toggle = $ui->root->children()[0];

    return $ui;
}

it('is a long capsule in a glass look, its elongated knob a glass knob, on the Switch track (checkbox.c:33-34, 320-336)', function () {
    $ui = glassToggleIn('raven-glass-dark');
    $size = $ui->tree->look->metric(Silk\Contracts\Look\Metric::CheckSize);
    $p = new Venusian\Silk\Tests\Support\SpyPainter;
    $ui->toggle->paint($p);
    $rects = array_values(array_filter($p->calls, fn ($c) => $c[0] === 'rect'));
    $track = $rects[0][1];
    $knob = $rects[array_key_last($rects)][1];
    $knobH = $size + 6 - 4;

    expect([$track[0]->w, $track[0]->h])->toBe([floor(($size + 6) * 2.1), $size + 6.0])
        ->and($track[1]->toHex())->toBe($ui->tree->look->color(Silk\Contracts\Look\Role::Switch)->toHex())
        ->and($knob[0]->w)->toBe(floor($knobH * 1.45))
        ->and($p->methods())->toContain('shadow');
});

it('gives macOS glass the 42 by 22 switch with a white capsule knob 1.4 times as long (checkbox.c:38, 209-220)', function () {
    $ui = glassToggleIn('macos-glass-dark', false);
    $p = new Venusian\Silk\Tests\Support\SpyPainter;
    $ui->toggle->paint($p);
    $rects = array_values(array_filter($p->calls, fn ($c) => $c[0] === 'rect'));
    $knob = $rects[array_key_last($rects)][1];

    expect($rects[0][1][0]->w)->toBe(42.0)
        ->and($rects[0][1][0]->h)->toBe(22.0)
        ->and($knob[0]->w)->toBe(floor(18 * 1.4))
        ->and($knob[0]->h)->toBe(18.0)
        ->and($knob[1]->toHex())->toBe('#ffffff');
});

it('rounds the box of a glass look all the way, with a ring 1.5 wide and a smaller check (checkbox.c:343-357)', function () {
    $ui = silkUi(fn ($root) => $root->checkbox('c', 'Check', false));
    $ui->tree->reducedMotion = true;
    $ui->tree->setLook(Silk\Looks\BuiltInThemes::register(new Silk\Looks\LookManager, 'sans-serif', 'monospace')->use('raven-glass-dark')->current());
    $ui->tree->layout(new Silk\Contracts\Geometry\Size(300, 300));
    settleAll($ui);
    $p = new Venusian\Silk\Tests\Support\SpyPainter;
    $ui->root->children()[0]->paint($p);
    $box = array_values(array_filter($p->calls, fn ($c) => $c[0] === 'rect'))[0][1];

    expect($box[2])->toBe($box[0]->h / 2)
        ->and($box[4])->toBe(1.5);
});
