<?php

use Silk\Contracts\Geometry\Rect;
use Silk\Core\Layout\Spacer;
use Silk\Widgets\Docking\DockSide;

it('builds the tree as Raven: a split per side, a share for the new panel (dock_area.c:296-334)', function () {
    $ui = dockUi();
    $root = $ui->dock->root();

    expect($ui->dock->saveLayout())->toBe('H(250,T(0;files),V(700,T(0;editor),T(0;output)))')
        ->and($root->vertical)->toBeFalse()
        ->and($root->b->vertical)->toBeTrue()
        ->and($ui->dock->panels())->toBe(['files', 'editor', 'output']);
});

it('puts a Tab panel behind the first group, or the relative one, and shows it (dock_area.c:309-313)', function () {
    $ui = dockUi();
    $ui->dock->addPanel(new Spacer('search', 10, 10), 'Search', DockSide::Tab, 'files');
    $ui->dock->addPanel(new Spacer('notes', 10, 10), 'Notes');

    expect($ui->dock->saveLayout())->toBe('H(250,T(2;files,search,notes),V(700,T(0;editor),T(0;output)))')
        ->and($ui->dock->isPanelShown('notes'))->toBeTrue()
        ->and($ui->dock->isPanelShown('files'))->toBeFalse();
});

it('gives the place of a group whose last panel closes to its neighbour (dock_area.c:268-288)', function () {
    $ui = dockUi();
    $ui->dock->closePanel('output');

    expect($ui->dock->saveLayout())->toBe('H(250,T(0;files),T(0;editor))')
        ->and(array_map(fn ($c) => $c->name(), $ui->dock->children()))->toBe(['editor', 'files']);
});

it('lays the nodes out with the splitter gap, keeping each side 72 at least (dock_area.c:325-345)', function () {
    $ui = dockUi();
    $gap = max($ui->tree->look->metric(Silk\Contracts\Look\Metric::SplitterSize), 4.0);
    $files = $ui->dock->root()->a->rect;
    $editor = $ui->dock->root()->b->a->rect;

    expect($files->w)->toBe(floor((600 - $gap) * 0.25))
        ->and($editor->x)->toBe($files->w + $gap)
        ->and($editor->h)->toBe(floor((400 - $gap) * 0.7));
});

it('hides a panel and opens it back where it was (dock_area.c:899-937, 1060-1083)', function () {
    $ui = dockUi();
    $ui->dock->hidePanel('output');

    expect($ui->dock->isPanelOpen('output'))->toBeFalse()
        ->and($ui->dock->saveLayout())->toBe('H(250,T(0;files),T(0;editor))')
        ->and($ui->dock->children()[2]->isVisible())->toBeFalse();

    $ui->dock->openPanel('output');
    expect($ui->dock->saveLayout())->toBe('H(250,T(0;files),V(700,T(0;editor),T(0;output)))')
        ->and($ui->dock->isPanelShown('output'))->toBeTrue();
});

it('glides a moved group\'s neighbours on four springs, and a new group grows from the drop preview (dock_area.c:12-14, 353-385)', function () {
    $ui = dockUi();
    $before = $ui->dock->root()->find('editor')[0]->shown();
    $ui->dock->movePanel('output', DockSide::Tab, 'files');
    $ui->dock->arrange(new Rect(0, 0, 600, 400));
    $editor = $ui->dock->root()->find('editor')[0];

    expect($editor->rect->h)->toBeGreaterThan($before->h)
        ->and($editor->shown()->h)->toBe($before->h)
        ->and($editor->h->atRest())->toBeFalse();
    dockSettle($ui);
    expect($editor->shown()->h)->toBe($editor->rect->h);
});

it('arranges only the shown panel of a group, in its cell under the bar', function () {
    $ui = dockUi();
    $ui->dock->addPanel(new Spacer('search', 10, 10), 'Search', DockSide::Tab, 'files');
    dockSettle($ui);
    $files = $ui->dock->root()->find('files')[0]->shown();
    $search = $ui->dock->children()[3];

    expect($search->isVisible())->toBeTrue()
        ->and($ui->dock->children()[1]->isVisible())->toBeFalse()
        ->and($search->bounds()->y)->toBe($files->y + $ui->tree->look->metric(Silk\Contracts\Look\Metric::ControlHeight) + 2);
});

it('throws for the blocked tear-off: new windows from a widget (roadmap #10)', function () {
    $ui = dockUi();

    expect($ui->dock->isTearable())->toBeTrue()->and($ui->dock->panelWindow('files'))->toBeNull();
    $ui->dock->floatPanel('files', 10, 10);
})->throws(Surface\Contracts\Windows\WindowException::class, 'blocked: new windows from a widget');
