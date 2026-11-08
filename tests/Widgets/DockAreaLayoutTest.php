<?php

use Silk\Core\Layout\Spacer;
use Silk\Widgets\Docking\DockSide;

it('saves and loads Raven\'s layout text, round trip (dock_area.c:1643-1843)', function () {
    $ui = dockUi();
    $saved = $ui->dock->saveLayout();
    $ui->dock->movePanel('output', DockSide::Right, 'files');

    expect($ui->dock->loadLayout($saved))->toBeTrue()
        ->and($ui->dock->saveLayout())->toBe($saved);
});

it('writes names with anything but letters, digits, _ - . as %XX, and reads them back', function () {
    $ui = dockUi();
    $ui->dock->addPanel(new Spacer('my panel/1', 10, 10), 'Mine', DockSide::Tab, 'files');

    expect($ui->dock->saveLayout())->toContain('my%20panel%2F1')
        ->and($ui->dock->loadLayout($ui->dock->saveLayout()))->toBeTrue()
        ->and($ui->dock->panels())->toContain('my panel/1');
});

it('refuses text that is not a layout and changes nothing (Review Focus 5; raven_shell.h:250-255)', function (string $bad) {
    $ui = dockUi();
    $before = $ui->dock->saveLayout();

    expect($ui->dock->loadLayout($bad))->toBeFalse()->and($ui->dock->saveLayout())->toBe($before);
})->with(['', 'nonsense', 'H(500,T(0;files)', 'T(0;files', 'H(x,T(0;a),T(0;b))', 'T(0;fi%ZZles)', 'T(0;files) trailing', str_repeat('H(500,', 70).'T(0;a)']);

it('leaves out unknown names, keeps unnamed panels behind the first tabs, and drops a side left empty', function () {
    $ui = dockUi();

    expect($ui->dock->loadLayout('H(400,T(0;ghost),V(500,T(1;output,editor),T(0;nobody)))'))->toBeTrue()
        ->and($ui->dock->saveLayout())->toBe('T(2;output,editor,files)'); // an inserted tab is shown (dock_area.c:258)
});

it('keeps a hidden panel hidden across a load that does not name it', function () {
    $ui = dockUi();
    $ui->dock->hidePanel('output');

    expect($ui->dock->loadLayout('H(300,T(0;files),T(0;editor))'))->toBeTrue()
        ->and($ui->dock->isPanelOpen('output'))->toBeFalse();
});
