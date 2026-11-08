<?php

use Silk\Core\Motion\Transition;

it('lands every spring on its target after one frame with reduced motion', function () {
    $ui = silkUi(function ($root) {
        $root->button('ok', 'OK');
        $root->toggle('wifi');
    });
    [$ok, $wifi] = $ui->root->children();
    $ui->tree->reducedMotion = true;

    $ok->hover()->target(1.0);
    $ok->press()->kick(-11.0);
    $wifi->setOn(false);
    $wifi->knob()->target(1.0);
    $ok->appear(Transition::Pop)->setVisible(false);
    $ok->setVisible(true);
    $ui->tree->advance(1 / 60);

    foreach ([$ok->hover(), $ok->press(), $wifi->knob(), ...$ok->motionSprings()] as $spring) {
        expect($spring->atRest())->toBeTrue();
    }
    expect($ok->hover()->value())->toBe(1.0)->and($wifi->knob()->value())->toBe(1.0);
});
