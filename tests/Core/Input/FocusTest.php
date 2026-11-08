<?php

use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Core\Input\FocusManager;
use Silk\Core\Input\Router;
use Silk\Core\Tree\Tree;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\Modifiers;
use Surface\Contracts\HumanInput\MouseButton;
use Venusian\Silk\Tests\Support\ProbeColumn;
use Venusian\Silk\Tests\Support\ProbeLeaf;

/** root > [a, group > [b, c], d]; a..d focusable, each 100x20. */
function focusTree(): array
{
    $root = new ProbeColumn('window');
    $root->add($a = (new ProbeLeaf('a', 100, 20))->setFocusable(true));
    $root->add($group = new ProbeColumn('group'));
    $group->add($b = (new ProbeLeaf('b', 100, 20))->setFocusable(true));
    $group->add($c = (new ProbeLeaf('c', 100, 20))->setFocusable(true));
    $root->add($d = (new ProbeLeaf('d', 100, 20))->setFocusable(true));
    $tree = new Tree($root);
    $tree->layout(new Size(100, 100));
    $focus = new FocusManager($tree);

    return compact('a', 'b', 'c', 'd', 'group', 'tree', 'focus') + ['router' => new Router($tree, $focus)];
}

function tab(bool $shift = false): SilkEvent
{
    return new SilkEvent(EventType::KeyDown, key: Key::TAB, modifiers: new Modifiers(shift: $shift));
}

it('walks Tab in tree order and Shift-Tab backwards, wrapping', function () {
    ['a' => $a, 'b' => $b, 'c' => $c, 'd' => $d, 'focus' => $focus, 'router' => $router] = focusTree();

    $seen = [];
    for ($i = 0; $i < 5; $i++) {
        $router->dispatch(tab());
        $seen[] = $focus->focused()->name();
    }
    $router->dispatch(tab(shift: true));

    expect($seen)->toBe(['a', 'b', 'c', 'd', 'a'])
        ->and($focus->focused())->toBe($d);
});

it('skips disabled and hidden widgets and their subtrees', function () {
    ['b' => $b, 'group' => $group, 'focus' => $focus, 'router' => $router] = focusTree();
    $b->setEnabled(false);

    $router->dispatch(tab());
    $router->dispatch(tab());
    expect($focus->focused()->name())->toBe('c');

    $router->dispatch(tab(shift: true)); // c -> a, b is disabled
    expect($focus->focused()->name())->toBe('a');

    $group->setVisible(false);
    $router->dispatch(tab());
    expect($focus->focused()->name())->toBe('d');
});

it('sends FocusLost and FocusGained', function () {
    ['a' => $a, 'b' => $b, 'router' => $router] = focusTree();

    $router->dispatch(tab());
    $router->dispatch(tab());

    expect($a->types())->toBe(['FocusGained', 'FocusLost'])
        ->and($b->types())->toBe(['FocusGained']);
});

it('shows the ring only after Tab and hides it on any press', function () {
    ['focus' => $focus, 'router' => $router] = focusTree();
    expect($focus->showRing())->toBeFalse();

    $router->dispatch(tab());
    expect($focus->showRing())->toBeTrue();

    $router->dispatch(new SilkEvent(EventType::MouseDown, x: 50, y: 70, button: MouseButton::LEFT, clicks: 1));
    expect($focus->showRing())->toBeFalse();
});

it('focuses a focusable widget on press, without the ring', function () {
    ['c' => $c, 'focus' => $focus, 'router' => $router] = focusTree();

    $router->dispatch(new SilkEvent(EventType::MouseDown, x: 50, y: 50, button: MouseButton::LEFT, clicks: 1));

    expect($focus->focused())->toBe($c)->and($focus->showRing())->toBeFalse();
});

it('routes keys to the focused widget', function () {
    ['b' => $b, 'focus' => $focus, 'router' => $router] = focusTree();
    $focus->focus($b);

    $router->dispatch(new SilkEvent(EventType::KeyDown, key: Key::SPACE));

    expect($b->types())->toBe(['FocusGained', 'KeyDown']);
});

it('moves focus on when the focused widget is disabled', function () {
    ['b' => $b, 'focus' => $focus, 'router' => $router] = focusTree();
    $focus->focus($b);

    $b->setEnabled(false);
    $router->dispatch(new SilkEvent(EventType::KeyDown, key: Key::SPACE));

    expect($focus->focused()->name())->toBe('c')
        ->and($b->types())->toBe(['FocusGained'])
        ->and($focus->focused()->types())->toBe(['FocusGained', 'KeyDown']);
});
