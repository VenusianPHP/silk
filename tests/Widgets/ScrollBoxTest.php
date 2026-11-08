<?php

use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Core\Layout\Spacer;
use Silk\Core\Tree\Tree;
use Silk\Widgets\ScrollBar;
use Silk\Widgets\ScrollBox;
use Surface\Contracts\HumanInput\Modifiers;
use Surface\Contracts\Windows\Mail\View\ViewScrolled;
use Venusian\Silk\Tests\Support\SpyPainter;

function scrollUi(float $contentH = 1000): object
{
    $ui = silkUi(fn ($root) => $root->add(new ScrollBox('list')));
    $ui->box = $ui->root->children()[0];
    $ui->box->add(new Spacer('content', 100, $contentH));
    $ui->tree->layout(new Size(300, 300));

    return $ui;
}

function wheel(object $ui, float $lines, bool $shift = false): bool
{
    [$x, $y] = centre($ui->box);

    return $ui->router->dispatch(new SilkEvent(EventType::Wheel, x: $x, y: $y, wheelY: $lines, modifiers: new Modifiers(shift: $shift)));
}

function rest(object $ui): void
{
    for ($i = 0; $i < 300 && $ui->tree->advance(1 / 60); $i++) {
    }
    $ui->tree->layout(new Size(300, 300));
}

it('moves the target 48 units per wheel line, and the drawn offset follows on SMOOTH', function () {
    $ui = scrollUi();

    expect(wheel($ui, 1))->toBeTrue()->and($ui->box->target())->toBe([0.0, 48.0]);
    $ui->tree->advance(1 / 60);
    expect($ui->box->offset()[1])->toBeGreaterThan(0.0)->toBeLessThan(48.0);

    rest($ui);
    expect($ui->box->offset())->toBe([0.0, 48.0])
        // moved by a translate at paint time, not a relayout
        ->and($ui->box->content()->bounds()->y)->toBe($ui->box->bounds()->y)
        ->and($ui->box->childShift())->toBe([0.0, -48.0]);
});

it('scrolls without a relayout, and hit tests and clicks through the offset', function () {
    $ui = silkUi(fn ($root) => $root->add(new ScrollBox('list')));
    $ui->box = $ui->root->children()[0];
    $ui->box->add($items = new Silk\Widgets\Column('items', 0, 0));
    for ($i = 0; $i < 40; $i++) {
        $items->button("b{$i}", "Button {$i}");
    }
    $ui->tree->layout(new Size(300, 300));
    $ui->box->scrollTo(0, 260, animate: false);
    $ui->tree->advance(1 / 60);

    expect($ui->tree->layout(new Size(300, 300)))->toBe(0);

    $b10 = $items->children()[10];
    $screenY = $b10->bounds()->y - 260 + $b10->bounds()->h / 2;
    expect($ui->tree->hitTest(50, $screenY))->toBe($b10);

    click($ui->router, 50, $screenY);
    expect($ui->loop->names())->toBe(['view.clicked.main.window.list.items.b10']);
});

it('posts ViewScrolled for a wheel, not for scrollTo', function () {
    $ui = scrollUi();

    $ui->box->scrollTo(0, 100, animate: false);
    expect($ui->loop->posted)->toBe([])->and($ui->box->offset())->toBe([0.0, 100.0]);

    wheel($ui, 1);
    expect($ui->loop->posted)->toHaveCount(1)
        ->and($ui->loop->posted[0])->toBeInstanceOf(ViewScrolled::class)
        ->and($ui->loop->names())->toBe(['view.scrolled.main.window.list']);
});

it('kicks the drawn offset past the top on overscroll, bubbles the wheel, and springs back to 0', function () {
    $ui = scrollUi();

    expect(wheel($ui, -1))->toBeFalse();
    $min = 0.0;
    for ($i = 0; $i < 300 && $ui->tree->advance(1 / 60); $i++) {
        $min = min($min, $ui->box->offset()[1]);
    }

    expect($ui->box->target())->toBe([0.0, 0.0])->and($min)->toBeLessThan(-1.0)->and($ui->box->offset()[1])->toBe(0.0);
});

it('shows no bar and bubbles the wheel when the content fits (Review Focus 3)', function () {
    $ui = scrollUi(100);
    $p = new SpyPainter;
    $ui->box->paint($p);

    expect(wheel($ui, 1))->toBeFalse()->and($ui->box->target())->toBe([0.0, 0.0])
        ->and($ui->box->scrollable())->toBe([false, false]);
});

it('swaps axes with Shift', function () {
    $ui = silkUi(fn ($root) => $root->add(new ScrollBox('list')));
    $ui->box = $ui->root->children()[0];
    $ui->box->add(new Spacer('content', 1000, 100));
    $ui->tree->layout(new Size(300, 300));

    expect(wheel($ui, 1, shift: true))->toBeTrue()->and($ui->box->target())->toBe([48.0, 0.0]);
});

it('sizes the thumb as max(track x viewport / content, min(24, track))', function () {
    expect(ScrollBar::thumbLength(200, 100, 400))->toBe(50.0)
        ->and(ScrollBar::thumbLength(200, 100, 10000))->toBe(24.0)
        ->and(ScrollBar::thumbLength(10, 100, 10000))->toBe(10.0);
});

it('is a TKScrollBar twin standalone: range, page, step, value; posts ScrollChanged for a wheel only', function () {
    // a vertical bar wants no height: give it the column's spare room
    $ui = silkUi(fn ($root) => $root->add(new ScrollBar('bar', vertical: true), new Silk\Contracts\Widget\Slot(grow: 1)));
    $bar = $ui->root->children()[0];
    $bar->setRange(100, 20)->setStep(5)->setValue(90);

    expect($bar->value())->toBe(80)->and($bar->maximum())->toBe(100)->and($bar->page())->toBe(20)
        ->and($ui->loop->posted)->toBe([]);

    [$x, $y] = centre($bar);
    $ui->router->dispatch(new SilkEvent(EventType::Wheel, x: $x, y: $y, wheelY: -2));

    expect($bar->value())->toBe(70)->and($ui->loop->names())->toBe(['view.scroll-changed.main.window.bar']);
});

function pad(object $ui, float $lines, Surface\Contracts\Windows\Mail\View\ScrollPhase $phase): bool
{
    [$x, $y] = centre($ui->box);

    return $ui->router->dispatch(new SilkEvent(EventType::Wheel, x: $x, y: $y, wheelY: $lines, precise: true, phase: $phase));
}

it('follows macOS momentum deltas as scrolling', function () {
    $ui = scrollUi();
    $P = Surface\Contracts\Windows\Mail\View\ScrollPhase::class;

    pad($ui, 0.25, $P::BEGAN);
    pad($ui, 0.25, $P::CHANGED);
    pad($ui, 0.0, $P::ENDED);
    $handled = pad($ui, 0.5, $P::MOMENTUM);
    pad($ui, 0.5, $P::MOMENTUM);

    expect($handled)->toBeTrue()->and($ui->box->target())->toBe([0.0, 72.0]);
});

it('bounces once at an end during momentum, and keeps the rest of the momentum from the parent', function () {
    $ui = scrollUi();
    $P = Surface\Contracts\Windows\Mail\View\ScrollPhase::class;
    $spring = $ui->box->springs()[1];

    $handled = [];
    for ($i = 0; $i < 40; $i++) {
        $handled[] = pad($ui, -0.5, $P::MOMENTUM);
    }

    expect($spring->velocity())->toBe(-24.0 * ScrollBox::WHEEL_KICK)
        ->and(array_unique($handled))->toBe([true])
        ->and($ui->box->target())->toBe([0.0, 0.0]);
    pad($ui, 0.0, $P::MOMENTUM_ENDED);
    pad($ui, -0.5, $P::MOMENTUM);
    expect($spring->velocity())->toBeLessThan(-24.0 * ScrollBox::WHEEL_KICK);
});

it('does not rubber-band while the fingers drag past an end, and lets that bubble', function () {
    $ui = scrollUi();
    $P = Surface\Contracts\Windows\Mail\View\ScrollPhase::class;

    expect(pad($ui, -0.5, $P::CHANGED))->toBeFalse()->and($ui->box->springs()[1]->velocity())->toBe(0.0);
});

it('scrolls toward the bottom for a positive wheel delta, as CanvasWheeled delivers it', function () {
    // Surface's contract: positive dx/dy scroll toward the content's right/bottom, the system's direction already applied
    $ui = scrollUi();

    expect(wheel($ui, 1))->toBeTrue()->and($ui->box->target())->toBe([0.0, 48.0]);

    $bars = silkUi(fn ($root) => $root->add(new ScrollBar('bar', vertical: true), new Silk\Contracts\Widget\Slot(grow: 1)));
    $bar = $bars->root->children()[0];
    $bar->setRange(100, 20)->setStep(5)->setValue(10);
    [$x, $y] = centre($bar);
    $bars->router->dispatch(new SilkEvent(EventType::Wheel, x: $x, y: $y, wheelY: 1));

    expect($bar->value())->toBe(15);
});

it('gives a visible bar its thickness from the content (scroll_box.c:66-72)', function () {
    $ui = barBox('raven-dark');
    $bar = $ui->tree->look->metric(Silk\Contracts\Look\Metric::ScrollbarSize);

    expect($ui->content->bounds()->w)->toBe(200 - $bar)
        ->and($ui->box->scrollable())->toBe([false, true]);
});

it('draws the Raven bar at the right edge, the whole height, with its track (scroll_box.c:105-116, common.c:638-641)', function () {
    $ui = barBox('raven-dark');
    $bar = $ui->tree->look->metric(Silk\Contracts\Look\Metric::ScrollbarSize);
    $rects = array_values(array_filter($ui->calls, fn ($c) => $c[0] === 'rect'));

    expect($rects[0][1][0])->toEqual(new Silk\Contracts\Geometry\Rect(200 - $bar, 0, $bar, 100))
        ->and($rects[1][1][0]->h)->toBe((float) max(100 * 100 / 400, 24.0) - 4);
});

it('hides the overlay bar while nothing moves and the pointer is away (common.c:585-603)', function () {
    $ui = barBox('gnome-light');

    expect(array_filter($ui->calls, fn ($c) => $c[0] === 'rect'))->toBe([]);
});

it('measures its content once per arrange, as Raven (scroll_box.c:63), and again after the layout is invalidated', function () {
    $box = new ScrollBox('box');
    $box->add($content = new class('content') extends Spacer {
        public int $measures = 0;

        protected function measureContent(Size $available): Size
        {
            $this->measures++;

            return new Size(150, 400);
        }
    });
    $tree = new Tree($box, Silk\Looks\BuiltInThemes::register(new Silk\Looks\LookManager, 'sans-serif', 'monospace')->use('raven-dark')->current());
    $tree->layout(new Size(200, 100));
    $after = $content->measures;
    $box->paint(new SpyPainter);
    $box->scrollable();
    $box->handle(new SilkEvent(EventType::Wheel, x: 10, y: 10, wheelY: 1.0));
    expect($content->measures)->toBe($after);

    $content->invalidateLayout();
    $tree->layout(new Size(200, 100));
    expect($content->measures)->toBeGreaterThan($after);
});
