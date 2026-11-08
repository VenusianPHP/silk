<?php

use Silk\Contracts\Geometry\Size;
use Silk\Core\Motion\SpringPreset;
use Silk\Core\Motion\Transition;
use Venusian\Silk\Tests\Support\SpyPainter;

it('names a spring preset for every transition', function () {
    expect(Transition::Pop->preset())->toBe(SpringPreset::Bouncy)
        ->and(Transition::Fade->preset())->toBe(SpringPreset::Smooth)
        ->and(Transition::Zoom->preset())->toBe(SpringPreset::Smooth)
        ->and(Transition::FlyUp->preset())->toBe(SpringPreset::Bouncy)
        ->and(Transition::Drop->preset())->toBe(SpringPreset::Wobbly)
        ->and(Transition::SlideLeft->preset())->toBe(SpringPreset::Snappy)
        ->and(Transition::cases())->toHaveCount(10);
});

/** The scale of the first pushTransform a paint recorded, or 1.0 when none. */
function paintedScale(SpyPainter $p): float
{
    foreach ($p->calls as [$method, $args]) {
        if ($method === 'pushTransform') {
            return $args[0];
        }
    }

    return 1.0;
}

it('pops in from below scale 1 to scale 1, with bounds that never move', function () {
    $ui = silkUi(fn ($root) => $root->button('ok', 'OK'));
    $ok = $ui->root->children()[0];
    $ok->appear(Transition::Pop)->setVisible(false);
    $ui->tree->layout(new Size(300, 300));
    $ok->setVisible(true);
    $ui->tree->layout(new Size(300, 300));
    $bounds = $ok->bounds();

    $ui->tree->advance(1 / 60);
    $first = new SpyPainter;
    $ui->tree->paintAll($first);
    expect(paintedScale($first))->toBeLessThan(1.0)->and($first->methods())->toContain('pushOpacity');

    for ($i = 0; $i < 120 && $ui->tree->advance(1 / 60); $i++) {
        $ui->tree->layout(new Size(300, 300));
        expect($ok->bounds())->toEqual($bounds);
    }
    $rest = new SpyPainter;
    $ui->tree->paintAll($rest);
    expect(paintedScale($rest))->toBe(1.0)->and($rest->methods())->not->toContain('pushOpacity');
});

it('keeps a disappearing widget in layout, without input, until it has faded', function () {
    $ui = silkUi(fn ($root) => $root->button('ok', 'OK'));
    $ok = $ui->root->children()[0];
    $bounds = $ok->bounds();

    $ok->disappear(Transition::Fade)->setVisible(false);
    // Raven's opacity is t * 2.2: it stays opaque until the progress is below ~0.45
    for ($i = 0; $i < 60 && $ok->motionSprings()[2]->value() > 0.4; $i++) {
        $ui->tree->advance(1 / 60);
    }
    $p = new SpyPainter;
    $ui->tree->paintAll($p);

    expect($ok->isVisible())->toBeTrue()
        ->and($ok->isEnabled())->toBeFalse()
        ->and($p->methods())->toContain('pushOpacity')
        ->and($ui->tree->hitTest($bounds->x + 2, $bounds->y + 2))->not->toBe($ok);

    for ($i = 0; $i < 120 && $ui->tree->advance(1 / 60); $i++) {
    }
    expect($ok->isVisible())->toBeFalse()->and($ok->isEnabled())->toBeTrue();
});

it('plays nothing when shown with no appear set', function () {
    $ui = silkUi(fn ($root) => $root->button('ok', 'OK'));
    $ok = $ui->root->children()[0]->setVisible(false);
    $ok->setVisible(true);

    expect($ui->tree->advance(1 / 60))->toBeFalse();
});

it('squashes Drop as Raven: taller as it falls, wider as it lands, both held to .6 away (anim.c:249-254)', function () {
    $falling = Transition::Drop->state(0.0);
    $landing = Transition::Drop->state(1.3);

    expect([$falling['scale'], $falling['scaleY'], $falling['dy']])->toEqual([1 - 0.12 * 0.6, 1 + 0.25 * 0.6, -140.0])
        ->and([$landing['scale'], $landing['scaleY']])->toEqualWithDelta([1 + 0.12 * 0.3, 1 - 0.25 * 0.3], 1e-9)
        ->and(Transition::Pop->state(0.5)['scaleY'])->toBe(Transition::Pop->state(0.5)['scale']);
});
