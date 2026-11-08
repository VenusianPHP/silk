<?php

use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Core\Input\FocusManager;
use Silk\Core\Input\Router;
use Silk\Core\Tree\Tree;
use Surface\Contracts\HumanInput\MouseButton;
use Venusian\Silk\Tests\Support\ProbeColumn;
use Venusian\Silk\Tests\Support\ProbeLeaf;

/** root (0,0 200x200) > outer (0,0 200x100) > inner (0,0 200x50) > leaf (0,0 200x20); other leaf below outer. */
function routedTree(): array
{
    $root = new ProbeColumn('window');
    $root->add($outer = new ProbeColumn('outer'));
    $outer->add($inner = new ProbeColumn('inner'));
    $inner->add($leaf = new ProbeLeaf('leaf', 200, 20));
    $inner->add(new ProbeLeaf('pad', 200, 30));
    $outer->add(new ProbeLeaf('pad2', 200, 50));
    $root->add($other = new ProbeLeaf('other', 200, 100));
    $tree = new Tree($root);
    $tree->layout(new Size(200, 200));
    $router = new Router($tree, new FocusManager($tree));

    return compact('root', 'outer', 'inner', 'leaf', 'other', 'tree', 'router');
}

function mouse(EventType $type, float $x, float $y): SilkEvent
{
    return new SilkEvent($type, x: $x, y: $y, button: MouseButton::LEFT, clicks: 1);
}

it('bubbles from the target to ancestors and stops at the first true', function () {
    ['root' => $root, 'outer' => $outer, 'inner' => $inner, 'leaf' => $leaf, 'router' => $router] = routedTree();
    $outer->handles = [EventType::MouseDown];

    expect($router->dispatch(mouse(EventType::MouseDown, 5, 5)))->toBeTrue()
        ->and($leaf->types())->toContain('MouseDown')
        ->and($inner->types())->toContain('MouseDown')
        ->and($outer->types())->toContain('MouseDown')
        ->and($root->types())->not->toContain('MouseDown');
});

it('returns false when nothing handles the event', function () {
    ['router' => $router] = routedTree();

    expect($router->dispatch(mouse(EventType::MouseDown, 5, 5)))->toBeFalse();
});

it('sends moves to the pressed widget outside its bounds until release', function () {
    ['leaf' => $leaf, 'other' => $other, 'router' => $router] = routedTree();
    $leaf->handles = [EventType::MouseDown, EventType::MouseMove, EventType::MouseUp];

    $router->dispatch(mouse(EventType::MouseDown, 5, 5));
    $router->dispatch(mouse(EventType::MouseMove, 5, 150));
    $router->dispatch(mouse(EventType::MouseUp, 5, 150));
    $router->dispatch(mouse(EventType::MouseMove, 5, 160));

    expect(array_values(array_filter($leaf->types(), fn ($t) => str_starts_with($t, 'Mouse'))))
        ->toBe(['MouseDown', 'MouseMove', 'MouseUp'])
        ->and($other->types())->toContain('MouseMove');
});

it('diffs the hover path into Leave and Enter', function () {
    ['root' => $root, 'outer' => $outer, 'inner' => $inner, 'leaf' => $leaf, 'other' => $other, 'router' => $router] = routedTree();

    $router->dispatch(mouse(EventType::MouseMove, 5, 5));
    expect($leaf->types())->toBe(['Enter', 'MouseMove'])
        ->and($outer->types())->toBe(['Enter', 'MouseMove'])
        ->and($root->types())->toBe(['Enter', 'MouseMove']);

    $router->dispatch(mouse(EventType::MouseMove, 5, 150));
    expect($leaf->types())->toBe(['Enter', 'MouseMove', 'Leave'])
        ->and($inner->types())->toBe(['Enter', 'MouseMove', 'Leave'])
        ->and($outer->types())->toBe(['Enter', 'MouseMove', 'Leave'])
        ->and($other->types())->toBe(['Enter', 'MouseMove'])
        // root stays on the path: no Leave, no second Enter
        ->and($root->types())->toBe(['Enter', 'MouseMove', 'MouseMove']);
});

it('leaves the whole hover path when the pointer leaves the host', function () {
    ['root' => $root, 'leaf' => $leaf, 'router' => $router] = routedTree();
    $router->dispatch(mouse(EventType::MouseMove, 5, 5));

    $router->dispatch(new SilkEvent(EventType::Leave));

    expect($leaf->types())->toBe(['Enter', 'MouseMove', 'Leave'])
        ->and($root->types())->toBe(['Enter', 'MouseMove', 'Leave']);
});

it('lets an unhandled wheel on an inner scroll area reach the outer one', function () {
    ['outer' => $outer, 'inner' => $inner, 'router' => $router] = routedTree();
    // inner is at the end of its range and declines; outer scrolls
    $outer->handles = [EventType::Wheel];

    $handled = $router->dispatch(new SilkEvent(EventType::Wheel, x: 5, y: 5, wheelY: -1));

    expect($handled)->toBeTrue()
        ->and($inner->types())->toContain('Wheel')
        ->and($outer->types())->toContain('Wheel');
});

it('gives disabled widgets no input', function () {
    ['inner' => $inner, 'leaf' => $leaf, 'router' => $router] = routedTree();
    $leaf->setEnabled(false);

    $router->dispatch(mouse(EventType::MouseDown, 5, 5));

    expect($leaf->events)->toBe([])
        ->and($inner->types())->toContain('MouseDown');
});

it('drops capture when the captured widget is disabled', function () {
    ['leaf' => $leaf, 'other' => $other, 'router' => $router] = routedTree();
    $leaf->handles = [EventType::MouseDown];
    $router->dispatch(mouse(EventType::MouseDown, 5, 5));

    $leaf->setEnabled(false);
    $router->dispatch(mouse(EventType::MouseMove, 5, 150));

    expect($leaf->types())->toBe(['Enter', 'MouseDown'])
        ->and($other->types())->toContain('MouseMove');
});
