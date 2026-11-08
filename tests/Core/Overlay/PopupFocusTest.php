<?php

use Silk\Contracts\Geometry\Size;
use Silk\Core\Overlay\Popup;
use Silk\Widgets\Button;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\Modifiers;

it('scopes Tab to an open popup and gives focus back to its opener on close (Review Focus 5)', function () {
    $ui = silkUi(function ($root) {
        $root->button('open', 'Open');
        $root->button('other', 'Other');
    });
    [$opener] = $ui->root->children();
    $ui->focus->focus($opener);
    $popup = new Popup('menu', 4, 4);
    $popup->add($a = new Button('a', 'A'));
    $popup->add($b = new Button('b', 'B'));

    $popup->open($opener);
    $ui->tree->layout(new Size(300, 300));
    $seen = [$ui->focus->focused()->name()];
    for ($i = 0; $i < 3; $i++) {
        pressKey($ui->router, Key::TAB);
        $seen[] = $ui->focus->focused()->name();
    }

    expect($seen)->toBe(['a', 'b', 'a', 'b']);

    pressKey($ui->router, Key::ESCAPE);
    expect($ui->focus->focused())->toBe($opener);
});

it('sends keys to the focused widget inside the popup', function () {
    $ui = silkUi(fn ($root) => $root->button('open', 'Open'));
    $popup = new Popup('menu', 4, 4);
    $popup->add(new Button('a', 'A'));
    $popup->open($ui->root->children()[0]);
    $ui->tree->layout(new Size(300, 300));

    pressKey($ui->router, Key::ENTER);

    expect($ui->loop->names())->toBe(['view.clicked.main.menu.a']);
});
