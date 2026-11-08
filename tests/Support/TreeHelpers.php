<?php

use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Look\Metric;
use Silk\Widgets\TreeView;
use Surface\Contracts\HumanInput\Modifiers;
use Surface\Contracts\HumanInput\MouseButton;
use Surface\Contracts\Windows\Primitives\TableColumn;
use Surface\Contracts\Windows\Primitives\TreeNode;

// TreeView test helpers, shared by TreeViewTest and TreeViewDragTest

/** a(a1(a1x), a2), b */
function treeNodes(): array
{
    return [
        new TreeNode('a', ['name' => 'A'], [new TreeNode('a1', ['name' => 'A1'], [new TreeNode('a1x', ['name' => 'A1X'])]), new TreeNode('a2', ['name' => 'A2'])]),
        new TreeNode('b', ['name' => 'B']),
    ];
}

function treeUi(?array $nodes = null): object
{
    $ui = silkUi(fn ($root) => $root->add(new TreeView('tree', [new TableColumn('name', 'Name')], $nodes ?? treeNodes())));
    $ui->tv = $ui->root->children()[0];
    $ui->rowH = $ui->look->metric(Metric::RowHeight);
    $ui->indent = $ui->look->metric(Metric::Indent);

    return $ui;
}

/** A point on visible row $i: on its label, or on its chevron. @return array{float, float} */
function treeRow(object $ui, int $i, bool $chevron = false): array
{
    $b = $ui->tv->bounds();
    $depth = $ui->tv->depthAt($i);
    // the chevron's zone starts at 5 + indent x depth (tree_view.c:525); the text one indent further
    $x = $b->x + 5 + $depth * $ui->indent + ($chevron ? $ui->indent / 2 : $ui->indent * 1.5);

    return [$x, $b->y + 1 + ($i + 0.5) * $ui->rowH - $ui->tv->scroll()->offset];
}

function treeClick(object $ui, array $at, int $clicks = 1, ?Modifiers $mods = null): void
{
    $ui->router->dispatch(new SilkEvent(EventType::MouseDown, x: $at[0], y: $at[1], button: MouseButton::LEFT, clicks: $clicks, modifiers: $mods));
    $ui->router->dispatch(new SilkEvent(EventType::MouseUp, x: $at[0], y: $at[1], button: MouseButton::LEFT, clicks: $clicks, modifiers: $mods));
}

/** @return list<object> */
function mailOf(object $ui, string $class): array
{
    return array_values(array_filter($ui->loop->posted, fn ($m) => $m instanceof $class));
}
