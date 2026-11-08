<?php

use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Mail\DockChanged;
use Surface\Contracts\HumanInput\MouseButton;

it('docks a dragged tab beside a group: the right half of the editor (dock_area.c:779-797, 1600-1606)', function () {
    $ui = dockUi();
    $editor = $ui->dock->root()->find('editor')[0]->shown();

    // (10 from the area's right edge would be the edge band: against the whole area)
    dockDrag($ui, dockTab($ui, 'files'), [$editor->x + $editor->w * 0.8, $editor->y + $editor->h / 2]);

    expect($ui->dock->saveLayout())->toBe('V(700,H(500,T(0;editor),T(0;files)),T(0;output))')
        ->and($ui->loop->posted[0])->toBeInstanceOf(DockChanged::class);
});

it('docks behind a group\'s tabs when dropped in its middle, and along the whole area at its edges (dock_area.c:742-756, 779-786)', function () {
    $ui = dockUi();
    $editor = $ui->dock->root()->find('editor')[0]->shown();
    dockDrag($ui, dockTab($ui, 'output'), [$editor->x + $editor->w / 2, $editor->y + $editor->h / 2]);

    expect($ui->dock->saveLayout())->toBe('H(250,T(0;files),T(1;editor,output))');

    $ui = dockUi();
    dockDrag($ui, dockTab($ui, 'files'), [590, 200]);
    expect($ui->dock->saveLayout())->toBe('H(750,V(700,T(0;editor),T(0;output)),T(0;files))');
});

it('does nothing when the press does not travel 6, and shows a tab it lands on (dock_area.c:1495-1497, 1579-1582)', function () {
    $ui = dockUi();
    $ui->dock->addPanel(new Silk\Core\Layout\Spacer('search', 10, 10), 'Search', Silk\Widgets\Docking\DockSide::Tab, 'files');
    dockSettle($ui);
    [$x, $y] = dockTab($ui, 'files');

    dockDrag($ui, [$x, $y], [$x + 4, $y]);

    expect($ui->dock->saveLayout())->toBe('H(250,T(0;files,search),V(700,T(0;editor),T(0;output)))')
        ->and($ui->dock->isPanelShown('files'))->toBeTrue();
});

it('resizes two nodes by their border, the border following the hand (dock_area.c:1480-1491, 1555-1561)', function () {
    $ui = dockUi();
    $files = $ui->dock->root()->a->rect;
    $bx = $files->x + $files->w + 1;

    dockDrag($ui, [$bx, 200], [$bx + 60, 200]);
    $ui->dock->arrange(new Silk\Contracts\Geometry\Rect(0, 0, 600, 400));

    expect($ui->dock->root()->a->rect->w)->toBeGreaterThan($files->w + 50)
        ->and($ui->dock->root()->find('files')[0]->shown()->w)->toBe($ui->dock->root()->a->rect->w);
});

it('closes a tab by its close button or a middle click; the callback may keep it; a fixed tab has no button (dock_area.c:872-894, 1530-1586)', function () {
    $ui = dockUi();
    $asked = [];
    $ui->dock->setCloseCallback(function (string $p) use (&$asked) {
        $asked[] = $p;

        return $p !== 'editor';
    });
    $ui->dock->setPanelClosable('files', false);
    $click = function (MouseButton $b, array $at) use ($ui) {
        $ui->router->dispatch(new SilkEvent(EventType::MouseDown, x: $at[0], y: $at[1], button: $b, clicks: 1));
        $ui->router->dispatch(new SilkEvent(EventType::MouseUp, x: $at[0], y: $at[1], button: $b, clicks: 1));
    };

    $click(MouseButton::MIDDLE, dockTab($ui, 'files'));
    $click(MouseButton::MIDDLE, dockTab($ui, 'editor'));
    $g = $ui->dock->root()->find('output')[0];
    $tab = (fn () => $this->tabRect($g, $g->shown(), 0))->call($ui->dock);
    $click(MouseButton::LEFT, [$tab->x + $tab->w - 12, $tab->y + $tab->h / 2]);

    expect($asked)->toBe(['editor', 'output'])
        ->and($ui->dock->panels())->toBe(['files', 'editor']);
});

it('hides on close when close hides, and keeps panels in place when not movable (dock_area.c:880-890, 1495)', function () {
    $ui = dockUi();
    $ui->dock->setCloseHides(true)->setMovable(false);
    $editor = $ui->dock->root()->find('editor')[0]->shown();
    $ui->router->dispatch(new SilkEvent(EventType::MouseDown, x: dockTab($ui, 'output')[0], y: dockTab($ui, 'output')[1], button: MouseButton::MIDDLE, clicks: 1));
    $ui->router->dispatch(new SilkEvent(EventType::MouseUp, x: dockTab($ui, 'output')[0], y: dockTab($ui, 'output')[1], button: MouseButton::MIDDLE, clicks: 1));

    expect($ui->dock->isPanelOpen('output'))->toBeFalse()->and($ui->dock->children())->toHaveCount(3);
    dockDrag($ui, dockTab($ui, 'files'), [$editor->x + $editor->w - 10, $editor->y + $editor->h / 2]);
    expect($ui->dock->saveLayout())->toBe('H(250,T(0;files),T(0;editor))');
});

it('paints a group as Raven: a Panel box, the Header bar, the active tab TabActive with an Accent top (dock_area.c:1294-1309)', function () {
    $ui = dockUi();
    $look = $ui->tree->look;
    $p = new Venusian\Silk\Tests\Support\SpyPainter;
    (fn () => $this->paintSelf($p))->call($ui->dock);
    $rects = array_values(array_filter($p->calls, fn ($c) => $c[0] === 'rect'));
    $hex = fn ($r) => $look->color($r)->toHex();

    expect($rects[0][1][1]->toHex())->toBe($hex(Silk\Contracts\Look\Role::Panel))
        ->and($rects[0][1][3]->toHex())->toBe($hex(Silk\Contracts\Look\Role::Border))
        ->and($rects[1][1][1]->toHex())->toBe($hex(Silk\Contracts\Look\Role::Header))
        ->and($rects[3][1][1]->toHex())->toBe($hex(Silk\Contracts\Look\Role::TabActive))
        ->and([$rects[4][1][0]->h, $rects[4][1][1]->toHex()])->toBe([2.0, $hex(Silk\Contracts\Look\Role::Accent)]);
});

it('shows the drop preview and the chip while dragging, then lets them go (dock_area.c:1358-1446)', function () {
    $ui = dockUi();
    $editor = $ui->dock->root()->find('editor')[0]->shown();
    [$x, $y] = dockTab($ui, 'files');
    $ui->router->dispatch(new SilkEvent(EventType::MouseDown, x: $x, y: $y, button: MouseButton::LEFT, clicks: 1));
    $ui->router->dispatch(new SilkEvent(EventType::MouseMove, x: $editor->x + $editor->w - 10, y: $editor->y + $editor->h / 2));
    foreach ($ui->dock->springs() as $s) {
        $s->finish();
    }
    $p = new Venusian\Silk\Tests\Support\SpyPainter;
    (fn () => $this->paintOverlay($p))->call($ui->dock);

    expect(array_column($p->calls, 0))->toContain('shadow')->toContain('pushTransform')
        ->and(count(array_filter($p->calls, fn ($c) => $c[0] === 'rect')))->toBeGreaterThanOrEqual(2);
});

it('stretches the chip the way it goes, by its speed, about the pointer (dock_area.c:1405-1442)', function () {
    $ui = dockUi();
    [$x, $y] = dockTab($ui, 'files');
    $ui->router->dispatch(new SilkEvent(EventType::MouseDown, x: $x, y: $y, button: MouseButton::LEFT, clicks: 1));
    $ui->router->dispatch(new SilkEvent(EventType::MouseMove, x: $x + 300, y: $y + 10));
    $chip = fn (string $s) => (fn () => $this->{$s})->call($ui->dock);
    $chip('chipShown')->finish();
    $chip('chipX')->kick(600.0);
    $p = new Venusian\Silk\Tests\Support\SpyPainter;
    (fn () => $this->paintOverlay($p))->call($ui->dock);
    [$sx, , , $sy] = array_values(array_filter($p->calls, fn ($c) => $c[0] === 'pushTransform'))[0][1];
    $v = min(0.3, abs($chip('chipX')->velocity()) * 0.00035);

    expect($sx)->toEqualWithDelta(1 + $v, 1e-9)
        ->and($sy)->toEqualWithDelta(1 - $v * 0.5, 1e-9);
});
