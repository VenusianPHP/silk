<?php

use Silk\Contracts\Look\Metric;
use Silk\Contracts\Look\Role;
use Silk\Core\Motion\SpringPreset;
use Silk\Widgets\Slider;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\Windows\Mail\View\ValueChanged;

it('clamps and snaps to its step, and posts nothing for setValue', function () {
    $ui = sliderUi();
    $s = $ui->slider->setStep(5);

    expect($s->setValue(12)->value())->toBe(10.0)
        ->and($s->setValue(13)->value())->toBe(15.0)
        ->and($s->setValue(500)->value())->toBe(100.0)
        ->and($s->setValue(-3)->value())->toBe(0.0)
        ->and([$s->min(), $s->max()])->toBe([0.0, 100.0])
        ->and($ui->loop->posted)->toBe([]);
});

it('refuses a range whose min is not below max', function () {
    (new Slider('s', 0, 1, 0))->setRange(5, 5);
})->throws(InvalidArgumentException::class);

it('sets the value where a press lands, and the knob flies there on BOUNCY (slider.c:204-205, 266-272)', function () {
    $ui = sliderUi();
    settleAll($ui);
    $b = $ui->slider->bounds();

    click($ui->router, $b->x + $b->w * 0.9, $b->y + $b->h / 2);
    $ui->tree->advance(1 / 60);

    expect($ui->slider->value())->toBeGreaterThan(80.0)
        ->and($ui->slider->drawn())->toBeLessThan($ui->slider->value())
        ->and($ui->slider->springs()[1]->preset)->toBe(SpringPreset::Bouncy)
        ->and($ui->loop->posted)->toHaveCount(1)
        ->and($ui->loop->posted[0])->toBeInstanceOf(ValueChanged::class);
    settleAll($ui);
    expect($ui->slider->drawn())->toBe($ui->slider->value());
});

it('follows a drag exactly, from anywhere on the slider (slider.c:273-277)', function () {
    $ui = sliderUi();
    settleAll($ui);
    $b = $ui->slider->bounds();

    drag3($ui, [$b->x + $b->w * 0.8, $b->y + $b->h / 2], [$b->x + $b->w * 0.25, $b->y + $b->h / 2]);

    expect($ui->slider->drawn())->toBe($ui->slider->value())->and($ui->slider->value())->toBeLessThan(40.0);
});

it('steps with the arrow keys, Home and End go to the ends (slider.c:285-302)', function () {
    $ui = sliderUi();
    $ui->slider->setStep(10);
    $ui->focus->focus($ui->slider);

    pressKey($ui->router, Key::RIGHT);
    pressKey($ui->router, Key::RIGHT);
    pressKey($ui->router, Key::LEFT);
    pressKey($ui->router, Key::END);
    pressKey($ui->router, Key::HOME);

    expect($ui->slider->value())->toBe(0.0)->and(array_map(fn ($m) => $m->value, $ui->loop->posted))->toBe([50.0, 60.0, 50.0, 100.0, 0.0]);
});

it('measures 120 by the handle plus 4 or a row (slider.c:58-63)', function () {
    [$s, , $look] = sliderIn('raven-dark');

    expect($s->measure(new Silk\Contracts\Geometry\Size(500, 500))->w)->toBe(120.0)
        ->and($s->measure(new Silk\Contracts\Geometry\Size(500, 500))->h)->toBe(max($look->metric(Metric::SliderHandleSize) + 4, $look->metric(Metric::RowHeight)));
});

it('paints RavenDark as Raven: a Track 4 thick between the knob\'s ends, the Accent fill, an Accent disc ringed 2 in AccentText (slider.c:211-222)', function () {
    [$s, $calls, $look] = sliderIn('raven-dark');
    $h = $look->metric(Metric::SliderHandleSize);
    [$track, $fill, $knob] = array_column($calls, 1);
    $b = $s->bounds();

    expect(array_column($calls, 0))->toBe(['rect', 'rect', 'rect'])
        ->and([$track[0]->x, $track[0]->w, $track[0]->h, $track[2]])->toBe([$b->x + $h / 2, $b->w - $h, 4.0, 2.0])
        ->and($track[1])->toBe($look->color(Role::Track)->toHex())
        ->and(round($fill[0]->w, 4))->toBe(round(($b->w - $h) * 0.4, 4))
        ->and($fill[1])->toBe($look->color(Role::Accent)->toHex())
        ->and($knob[0]->w)->toBe($h)
        ->and([$knob[1], $knob[3], $knob[4]])->toBe([$look->color(Role::Accent)->toHex(), $look->color(Role::AccentText)->toHex(), 2.0]);
});

it('swells the RavenDark knob 2.5 held and 1 under the pointer (slider.c:196, 219)', function () {
    [, $calls, $look] = sliderIn('raven-dark', function ($s) {
        $s->press()->target(1.0);
        $s->hover()->target(1.0);
    });

    expect($calls[2][1][0]->w)->toBe($look->metric(Metric::SliderHandleSize) + 3.5);
});

it('paints the platforms\' track across, the fill to the knob, a white knob with a shadow (slider.c:103-134, 173-177)', function () {
    foreach (['macos-light' => [4.0, 2.0], 'gnome-dark' => [6.0, 0.0]] as $name => [$t, $inset]) {
        [$s, $calls, $look] = sliderIn($name);
        $rects = array_values(array_filter($calls, fn ($x) => $x[0] === 'rect'));
        $b = $s->bounds();

        expect([$rects[0][1][0]->x, $rects[0][1][0]->w, $rects[0][1][0]->h])->toBe([$b->x, $b->w, $t])
            ->and($rects[1][1][1])->toBe($look->color(Role::Accent)->toHex())
            ->and($rects[2][1][0]->w)->toBe($look->metric(Metric::SliderHandleSize) - $inset)
            ->and($rects[2][1][1])->toBe('#ffffff')
            ->and(array_column($calls, 0))->toContain('shadow');
    }
});

it('stands up when vertical: measured across, growing upwards, filled from the knob down (slider.c:62, 223-235, 251-253)', function () {
    [$s, $calls, $look] = sliderIn('raven-dark', fn ($s) => $s->setVertical(true));
    $h = $look->metric(Metric::SliderHandleSize);
    $b = $s->bounds();
    [$track, $fill, $knob] = array_column($calls, 1);

    expect($s->measure(new Silk\Contracts\Geometry\Size(500, 500))->h)->toBe(120.0)
        ->and([$track[0]->y, $track[0]->w, $track[0]->h])->toBe([$b->y + $h / 2, 4.0, $b->h - $h])
        // 40%: the knob 60% of the way down, the fill from it to the bottom end
        ->and(round($knob[0]->y + $knob[0]->h / 2 - $b->y, 4))->toBe(round($h / 2 + ($b->h - $h) * 0.6, 4))
        ->and(round($fill[0]->y + $fill[0]->h, 4))->toBe(round($b->y + $b->h - $h / 2, 4));
});

it('takes a vertical press as a value from the bottom up', function () {
    $ui = sliderUi();
    $ui->slider->setVertical(true);
    $ui->tree->layout(new Silk\Contracts\Geometry\Size(300, 300));
    $b = $ui->slider->bounds();

    click($ui->router, $b->x + $b->w / 2, $b->y + $b->h - $ui->tree->look->metric(Metric::SliderHandleSize) / 2);

    expect($ui->slider->value())->toBe(0.0);
});

it('paints the platforms vertical too: the track along the height, the fill below the knob', function () {
    [$s, $calls] = sliderIn('gnome-light', fn ($s) => $s->setVertical(true));
    $rects = array_values(array_filter($calls, fn ($x) => $x[0] === 'rect'));
    $b = $s->bounds();

    expect([$rects[0][1][0]->h, $rects[0][1][0]->w])->toBe([$b->h, 6.0])
        ->and($rects[1][1][0]->y + $rects[1][1][0]->h)->toBe($b->y + $b->h);
});

/** A slider tree in another look. */
function glassSliderUi(string $theme): object
{
    $ui = sliderUi();
    $ui->tree->reducedMotion = true;
    $ui->tree->setLook(Silk\Looks\BuiltInThemes::register(new Silk\Looks\LookManager, 'sans-serif', 'monospace')->use($theme)->current());
    $ui->tree->layout(new Silk\Contracts\Geometry\Size(300, 300));

    return $ui;
}

it('paints a glass look\'s slider with a 6-thick track and a capsule knob 1.5 times as long, not a disc (slider.c:53-56, 190, 218-222)', function () {
    $ui = glassSliderUi('raven-glass-dark');
    $p = new Venusian\Silk\Tests\Support\SpyPainter;
    $ui->slider->paint($p);
    $rects = array_values(array_filter($p->calls, fn ($c) => $c[0] === 'rect'));
    $size = $ui->tree->look->metric(Metric::SliderHandleSize);
    $knob = $rects[array_key_last($rects)][1];

    expect($rects[0][1][0]->h)->toBe(6.0)
        ->and($knob[0]->w)->toBe(floor($size * 1.5))
        ->and($knob[0]->h)->toBe($size)
        ->and($knob[1]->toHex())->toBe('#ffffff')
        ->and($p->methods())->toContain('shadow')
        ->and($p->methods())->not->toContain('glass');
});

it('turns the held knob into a lens of clear glass, the solid knob fading into it (common.c:209-219)', function () {
    $ui = glassSliderUi('raven-glass-dark');
    $ui->slider->press()->snap(1.0);
    $p = new Venusian\Silk\Tests\Support\SpyPainter;
    $ui->slider->paint($p);
    $glass = array_values(array_filter($p->calls, fn ($c) => $c[0] === 'glass'));

    expect($glass)->toHaveCount(1)
        ->and($glass[0][1][2]->frost)->toBe(0.0)
        ->and($glass[0][1][2]->refraction)->toBe(16.0)
        ->and($p->methods())->toContain('pushOpacity');
})->skip(fn () => ! method_exists(Silk\Widgets\Slider::class, 'press'), 'the press spring is private');

it('keeps the disc in the other looks, and gives macOS glass the capsule knob (slider.c:151-168)', function () {
    $flat = glassSliderUi('raven-dark');
    $p = new Venusian\Silk\Tests\Support\SpyPainter;
    $flat->slider->paint($p);
    $discs = array_values(array_filter($p->calls, fn ($c) => $c[0] === 'rect' && $c[1][0]->w == $c[1][0]->h));

    expect($discs)->not->toBe([]);

    $mac = glassSliderUi('macos-glass-dark');
    $q = new Venusian\Silk\Tests\Support\SpyPainter;
    $mac->slider->paint($q);
    $rects = array_values(array_filter($q->calls, fn ($c) => $c[0] === 'rect'));
    $knob = $rects[array_key_last($rects)][1];
    $size = $mac->tree->look->metric(Metric::SliderHandleSize);

    expect([$knob[0]->w, $knob[0]->h])->toBe([floor($size * 1.5), $size])
        ->and($q->methods())->toContain('shadow');
});
