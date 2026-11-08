<?php

use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Core\Input\FocusManager;
use Silk\Core\Input\Router;
use Silk\Core\Tree\Tree;
use Silk\Looks\LookManager;
use Silk\Looks\RavenDark;
use Silk\Widgets\Column;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\MouseButton;
use Venusian\Silk\Tests\Support\SpyLoop;

/** A laid-out tree in window 'main' whose root column 'window' is built by $build. */
function silkUi(callable $build, ?Silk\Text\TextMeasurer $measurer = null): object
{
    $root = new Column('window', 6, 10);
    $build($root);
    $loop = new SpyLoop;
    $look = (new LookManager)->extend('raven-dark', fn () => RavenDark::theme())->use('raven-dark')->current();
    $tree = new Tree($root, $look, $loop, 'main');
    $measurer && $tree->setMeasurer($measurer);
    $tree->layout(new Size(300, 300));
    $focus = new FocusManager($tree);

    return (object) ['root' => $root, 'loop' => $loop, 'tree' => $tree, 'look' => $look, 'focus' => $focus, 'router' => new Router($tree, $focus)];
}

/** Down then Up at the given points (Up defaults to the Down point). */
function click(Router $r, float $x, float $y, ?float $upX = null, ?float $upY = null): void
{
    $r->dispatch(new SilkEvent(EventType::MouseDown, x: $x, y: $y, button: MouseButton::LEFT, clicks: 1));
    $r->dispatch(new SilkEvent(EventType::MouseUp, x: $upX ?? $x, y: $upY ?? $y, button: MouseButton::LEFT, clicks: 1));
}

function pressKey(Router $r, Key $key): void
{
    $r->dispatch(new SilkEvent(EventType::KeyDown, key: $key));
    $r->dispatch(new SilkEvent(EventType::KeyUp, key: $key));
}

/** The centre of a widget's bounds. @return array{float, float} */
function centre(Silk\Contracts\Widget\Widget $w): array
{
    $b = $w->bounds();

    return [$b->x + $b->w / 2, $b->y + $b->h / 2];
}

/** A widget's paint calls as plain data (colours as hex, rects as [x, y, w, h], other objects by class), for identity fixtures. */
function paintSnapshot(Silk\Contracts\Widget\Widget $w): array
{
    $p = new Venusian\Silk\Tests\Support\SpyPainter;
    $w->paint($p);
    $plain = function ($a) use (&$plain) {
        return match (true) {
            $a instanceof Surface\NutsAndBolts\Color => $a->toHex(),
            $a instanceof Silk\Contracts\Geometry\Rect => [$a->x, $a->y, $a->w, $a->h],
            is_array($a) => array_map($plain, $a),
            is_object($a) => $a::class,
            is_float($a) => round($a, 6),
            default => $a,
        };
    };

    return array_map(fn ($c) => [$c[0], array_map($plain, $c[1])], $p->calls);
}

/** Compares $snapshot to tests/Fixtures/paint/$name.json; writes it when SILK_WRITE_PAINT_FIXTURES=1 (old code, once). */
function expectPaintFixture(string $name, array $snapshot): void
{
    $file = __DIR__.'/../Fixtures/paint/'.$name.'.json';
    if (getenv('SILK_WRITE_PAINT_FIXTURES') === '1') {
        @mkdir(dirname($file), 0777, true);
        file_put_contents($file, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION)."\n");
    }
    expect(json_decode(json_encode($snapshot, JSON_PRESERVE_ZERO_FRACTION), true))->toBe(json_decode(file_get_contents($file), true));
}

/** The centre of a rect. @return array{float, float} */
function centre_of(Silk\Contracts\Geometry\Rect $r): array
{
    return [$r->x + $r->w / 2, $r->y + $r->h / 2];
}
