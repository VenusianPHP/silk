<?php

use Silk\Widgets\Pagination;
use Surface\Contracts\HumanInput\Key;

it('shows the first and last pages, one sibling each side, and gaps; a gap of one page shows that page', function () {
    [$P, $N, $G] = [Pagination::PREVIOUS, Pagination::NEXT, Pagination::GAP];

    expect(pagesUi(20, 9)->pages->buttons())->toBe([$P, 0, $G, 8, 9, 10, $G, 19, $N])
        ->and(pagesUi(20, 2)->pages->buttons())->toBe([$P, 0, 1, 2, 3, $G, 19, $N])
        ->and(pagesUi(20, 3)->pages->buttons())->toBe([$P, 0, 1, 2, 3, 4, $G, 19, $N])
        ->and(pagesUi(1, 0)->pages->buttons())->toBe([$P, 0, $N]);
});

it('goes to a clicked page, and steps with previous and next, posting the page from 0', function () {
    $ui = pagesUi(20, 9);
    click($ui->router, ...centre_of($ui->pages->buttonRect(5)));
    click($ui->router, ...centre_of($ui->pages->buttonRect(0)));
    $last = count($ui->pages->buttons()) - 1;
    click($ui->router, ...centre_of($ui->pages->buttonRect($last)));

    expect(pageMail($ui))->toBe([10, 9, 10]);
});

it('does nothing for a gap, or previous on the first page', function () {
    $ui = pagesUi(20, 0);
    click($ui->router, ...centre_of($ui->pages->buttonRect(0)));
    $gap = array_search(Pagination::GAP, $ui->pages->buttons(), true);
    click($ui->router, ...centre_of($ui->pages->buttonRect($gap)));

    expect(pageMail($ui))->toBe([]);
});

it('moves with the keys and posts each change; setPage and setCount post nothing', function () {
    $ui = pagesUi(20, 5);
    $ui->focus->focus($ui->pages);
    pressKey($ui->router, Key::LEFT);
    pressKey($ui->router, Key::PAGE_DOWN);
    pressKey($ui->router, Key::END);
    pressKey($ui->router, Key::HOME);
    $ui->pages->setPage(7)->setCount(3);

    expect(pageMail($ui))->toBe([4, 5, 19, 0])->and($ui->pages->page())->toBe(2);
});
