<?php

use Silk\Widgets\Blocks\BlockKind;
use Silk\Widgets\Blocks\BlockLayout;
use Silk\Widgets\Blocks\BlockModel;
use Silk\Widgets\Blocks\BlockPlace;
use Silk\Widgets\Blocks\BlockShape;

/** A model with one kind of each shape, and a layout where a character is 7 units wide. */
function blocksModel(): BlockModel
{
    $m = new BlockModel;
    $m->addKind('start', 'when the game starts', BlockShape::Hat, 'Events');
    $m->addKind('move', 'move %n{10} steps', BlockShape::Statement, 'Motion');
    $m->addKind('say', 'say %s{Hello}', BlockShape::Statement, 'Looks');
    $m->addKind('point', 'point %m', BlockShape::Statement, 'Motion');
    $m->addKind('repeat', 'repeat %n{10}', BlockShape::C, 'Control');
    $m->addKind('ifelse', 'if %b then|else', BlockShape::C, 'Control');
    $m->addKind('stop', 'stop all', BlockShape::Cap, 'Control');
    $m->addKind('add', '%n + %n', BlockShape::Reporter, 'Operators');
    $m->addKind('touching', 'touching %s ?', BlockShape::Boolean, 'Sensing');
    $m->addKind('and', '%b and %b', BlockShape::Boolean, 'Operators');
    $m->addKind('show', 'show %r', BlockShape::Statement, 'Looks');
    $m->setSlotOptions('point', 0, ['north', 'east', 'south', 'west']);

    return $m;
}

function blocksLayout(BlockModel $m): BlockLayout
{
    $l = new BlockLayout($m, fn (string $t) => strlen($t) * 7.0);
    $l->layout();

    return $l;
}

it('reads a label into words, slots with their kinds and defaults, and sections', function () {
    $k = new BlockKind('if', 'if %b then|else %s{x y} now %m', BlockShape::C);
    $words = array_values(array_map(fn ($t) => [$t['section'], $t['slot'], $t['text']], $k->tokens));

    expect($k->sections)->toBe(2)->and($k->slotCount)->toBe(3)
        ->and($k->slotTypes)->toBe(['b', 's', 'm'])
        ->and($k->defaults)->toBe(['', 'x y', ''])
        ->and($words)->toBe([[0, -1, 'if '], [0, 0, null], [0, -1, ' then'], [1, -1, 'else '], [1, 1, null], [1, -1, ' now '], [1, 2, null]])
        ->and((new BlockKind('n', 'move %n steps', BlockShape::Statement))->defaults)->toBe(['0'])
        ->and((new BlockKind('s', 'a|b', BlockShape::Statement))->sections)->toBe(1)
        ->and((new BlockKind('p', '100% sure %z', BlockShape::Statement))->slotCount)->toBe(0);
});

it('refuses a kind name twice, and a block of a kind it does not have', function () {
    $m = blocksModel();

    expect($m->addKind('move', 'again', BlockShape::Statement))->toBe(-1)
        ->and($m->addKind('', 'nameless', BlockShape::Statement))->toBe(-1)
        ->and($m->addBlock('nothing'))->toBe(0)
        ->and($m->value($m->addBlock('move'), 0))->toBe('10');
});

it('hangs a step under a step, a step in a mouth, and a value in a slot, and says where each hangs', function () {
    $m = blocksModel();
    [$a, $b, $c, $v] = [$m->addBlock('move'), $m->addBlock('say'), $m->addBlock('repeat'), $m->addBlock('add')];

    expect($m->attach($b, $a, BlockPlace::Next))->toBeTrue()
        ->and($m->attach($c, $b, BlockPlace::Next))->toBeTrue()
        ->and($m->attach($v, $a, BlockPlace::Input, 0))->toBeTrue()
        ->and($m->block($a)->next)->toBe($b)->and($m->block($b)->next)->toBe($c)
        ->and($m->block($a)->input[0])->toBe($v)
        ->and([$m->block($v)->parent, $m->block($v)->place, $m->block($v)->index])->toBe([$a, BlockPlace::Input, 0])
        ->and($m->scripts())->toBe([$a])
        ->and($m->topOf($c))->toBe($a)->and($m->lastOfChain($a))->toBe($c);
    $d = $m->addBlock('move');
    $m->attach($d, $c, BlockPlace::Body, 0);

    expect($m->block($c)->body[0])->toBe($d)->and($m->scripts())->toBe([$a]);
});

it('keeps a hat on top, a cap at the end, and a value out of steps', function () {
    $m = blocksModel();
    [$hat, $step, $cap, $value, $reporter] = [$m->addBlock('start'), $m->addBlock('move'), $m->addBlock('stop'), $m->addBlock('add'), $m->addBlock('add')];

    expect($m->attach($hat, $step, BlockPlace::Next))->toBeFalse()
        ->and($m->attach($step, $hat, BlockPlace::Next))->toBeTrue()
        ->and($m->attach($cap, $step, BlockPlace::Next))->toBeTrue()
        ->and($m->attach($step, $cap, BlockPlace::Next))->toBeFalse()
        ->and($m->attach($value, $step, BlockPlace::Next))->toBeFalse()
        ->and($m->attach($reporter, $value, BlockPlace::Next))->toBeFalse()
        ->and($m->attach($step, $step, BlockPlace::Next))->toBeFalse()
        ->and($m->attach($step, $hat, BlockPlace::Body, 0))->toBeFalse()
        ->and($m->attach($step, 9999, BlockPlace::Next))->toBeFalse();
});

it('puts a number or text in a reporter slot, a condition in a boolean slot, either in a free slot, and nothing in a menu slot', function () {
    $m = blocksModel();
    [$move, $ifelse, $show, $point] = [$m->addBlock('move'), $m->addBlock('ifelse'), $m->addBlock('show'), $m->addBlock('point')];
    [$sum, $touch] = [$m->addBlock('add'), $m->addBlock('touching')];

    expect($m->attach($touch, $move, BlockPlace::Input, 0))->toBeFalse()
        ->and($m->attach($sum, $ifelse, BlockPlace::Input, 0))->toBeFalse()
        ->and($m->attach($touch, $ifelse, BlockPlace::Input, 0))->toBeTrue()
        ->and($m->attach($sum, $show, BlockPlace::Input, 0))->toBeTrue()
        ->and($m->attach($touch, $point, BlockPlace::Input, 0))->toBeFalse()
        ->and($m->attach($sum, $move, BlockPlace::Input, 3))->toBeFalse()
        ->and($m->attach($sum, $move, BlockPlace::Input, 0))->toBeTrue();
    $show2 = $m->addBlock('show');
    $touch2 = $m->addBlock('touching');
    $m->attach($touch2, $show2, BlockPlace::Input, 0);

    expect($m->block($show2)->input[0])->toBe($touch2);
});

it('lets the value that was in a slot jump out beside when another takes its place', function () {
    $m = blocksModel();
    [$move, $first, $second] = [$m->addBlock('move', 5, 5), $m->addBlock('add'), $m->addBlock('add')];
    $m->attach($first, $move, BlockPlace::Input, 0);
    $m->attach($second, $move, BlockPlace::Input, 0);

    expect($m->block($move)->input[0])->toBe($second)
        ->and($m->block($first)->parent)->toBe(0)->and($m->scripts())->toContain($first);
});

it('slips a stack into the middle of a chain, and the rest hangs under its last step', function () {
    $m = blocksModel();
    [$a, $b, $x, $y] = [$m->addBlock('move'), $m->addBlock('move'), $m->addBlock('say'), $m->addBlock('say')];
    $m->attach($b, $a, BlockPlace::Next);
    $m->attach($y, $x, BlockPlace::Next);
    $m->attach($x, $a, BlockPlace::Next);

    expect($m->block($a)->next)->toBe($x)->and($m->block($x)->next)->toBe($y)->and($m->block($y)->next)->toBe($b)
        ->and($m->block($b)->parent)->toBe($y)->and($m->scripts())->toBe([$a]);
});

it('lets a lone C block wrap what was under the place, and a stack that ends in a cap leave it beside', function () {
    $m = blocksModel();
    [$a, $b, $c] = [$m->addBlock('move'), $m->addBlock('say'), $m->addBlock('repeat')];
    $m->attach($b, $a, BlockPlace::Next);
    $m->attach($c, $a, BlockPlace::Next);

    expect($m->block($a)->next)->toBe($c)->and($m->block($c)->body[0])->toBe($b)
        ->and([$m->block($b)->parent, $m->block($b)->place])->toBe([$c, BlockPlace::Body])->and($m->block($c)->next)->toBe(0);

    $x = $m->addBlock('move');
    $y = $m->addBlock('say');
    $cap = $m->addBlock('stop');
    $m->attach($y, $x, BlockPlace::Next);
    $m->attach($cap, $m->addBlock('move'), BlockPlace::Next);
    $stack = $m->topOf($cap);
    $m->attach($stack, $x, BlockPlace::Next);

    expect($m->block($x)->next)->toBe($stack)->and($m->block($y)->parent)->toBe(0)->and($m->scripts())->toContain($y);
});

it('takes a block out with what is under it as a script of its own, and removes it with everything that hangs from it', function () {
    $m = blocksModel();
    [$a, $b, $c, $v] = [$m->addBlock('move'), $m->addBlock('say'), $m->addBlock('move'), $m->addBlock('add')];
    $m->attach($b, $a, BlockPlace::Next);
    $m->attach($c, $b, BlockPlace::Next);
    $m->attach($v, $c, BlockPlace::Input, 0);
    $m->detach($b, 40.0, 50.0);

    expect($m->block($a)->next)->toBe(0)->and($m->scripts())->toBe([$a, $b])
        ->and([$m->block($b)->x, $m->block($b)->y])->toBe([40.0, 50.0])->and($m->block($b)->next)->toBe($c);

    $m->removeBlock($b);

    expect($m->exists($b))->toBeFalse()->and($m->exists($c))->toBeFalse()->and($m->exists($v))->toBeFalse()
        ->and($m->exists($a))->toBeTrue()->and($m->scripts())->toBe([$a]);
    $m->clear();

    expect($m->count())->toBe(0)->and($m->scripts())->toBe([]);
});

it('keeps a number slot a number, and a text slot as typed', function () {
    $m = blocksModel();
    $a = $m->addBlock('move');
    $b = $m->addBlock('say');
    $m->setValue($a, 0, ' 12abc');
    $m->setValue($b, 0, ' hi ');

    expect($m->value($a, 0))->toBe('12')
        ->and(BlockModel::number('x'))->toBe('0')->and(BlockModel::number('1.50'))->toBe('1.5')->and(BlockModel::number('-3e2'))->toBe('-300')
        ->and($m->value($b, 0))->toBe(' hi ')->and($m->setValue($a, 5, 'x'))->toBeFalse();
});

it('saves the scripts as text and loads them back, and a bad text changes nothing', function () {
    $m = blocksModel();
    [$hat, $move, $loop, $inner, $v] = [$m->addBlock('start', 10, 20), $m->addBlock('move'), $m->addBlock('repeat'), $m->addBlock('say'), $m->addBlock('add')];
    $m->attach($move, $hat, BlockPlace::Next);
    $m->attach($loop, $move, BlockPlace::Next);
    $m->attach($inner, $loop, BlockPlace::Body, 0);
    $m->attach($v, $loop, BlockPlace::Input, 0);
    $m->setValue($inner, 0, 'Bye');
    $m->addBlock('stop', 300, 40);
    $text = $m->save();

    $other = blocksModel();
    $other->addBlock('stop');

    expect($other->load($text))->toBeTrue()->and($other->save())->toBe($text)
        ->and($other->count())->toBe(6)->and(count($other->scripts()))->toBe(2);
    $before = $other->save();

    expect($other->load('not json'))->toBeFalse()->and($other->load('{"scripts":[{"blocks":[{"kind":"nope"}]}]}'))->toBeFalse()
        ->and($other->load('{"scripts":[{"blocks":[{"kind":"move","inputs":{"0":{"kind":"touching"}}}]}]}'))->toBeFalse()
        ->and($other->save())->toBe($before);
});

it('gives a kind a new label, and its blocks keep the values of the slots still there', function () {
    $m = blocksModel();
    $a = $m->addBlock('move', 5, 5);
    $v = $m->addBlock('add');
    $m->attach($v, $a, BlockPlace::Input, 0);
    $m->setValue($a, 0, '42');

    expect($m->setKindLabel('move', 'go %n{1} then %s{x}'))->toBeTrue()
        ->and($m->block($a)->values)->toBe(['42', 'x'])
        ->and($m->setKindLabel('move', 'go'))->toBeTrue()
        ->and($m->block($a)->values)->toBe([])->and($m->block($a)->input)->toBe([0 => 0])
        ->and($m->block($v)->parent)->toBe(0)->and($m->setKindLabel('nothing', 'x'))->toBeFalse();
});

it('lays a chain out top to bottom, a C block around its mouth, and a block wider with its text', function () {
    $m = blocksModel();
    [$a, $b, $c, $d] = [$m->addBlock('move', 30, 40), $m->addBlock('say'), $m->addBlock('repeat'), $m->addBlock('move')];
    $m->attach($b, $a, BlockPlace::Next);
    $m->attach($c, $b, BlockPlace::Next);
    $empty = blocksLayout($m);
    $emptyHeight = $m->block($c)->h;
    $m->attach($d, $c, BlockPlace::Body, 0);
    $l = blocksLayout($m);

    expect([$m->block($a)->lx, $m->block($a)->ly])->toBe([30.0, 40.0])
        ->and($m->block($b)->ly)->toBe($m->block($a)->ly + $m->block($a)->h)
        ->and($m->block($c)->ly)->toBe($m->block($b)->ly + $m->block($b)->h)
        ->and($m->block($d)->lx)->toBe($m->block($c)->lx + BlockLayout::ARM)
        ->and($m->block($d)->ly)->toBe($m->block($c)->ly + $m->block($c)->mouthY[0])
        ->and($m->block($c)->h)->toBeGreaterThan($emptyHeight)
        ->and($l->drawn)->toContain($a, $b, $c, $d);
    $m->setValue($b, 0, str_repeat('long ', 10));
    $wide = blocksLayout($m);

    expect($m->block($b)->w)->toBeGreaterThan(300.0);
});

it('finds the block under a point, not one in a mouth of the block, and the empty slot under it', function () {
    $m = blocksModel();
    [$loop, $inner, $move] = [$m->addBlock('repeat', 100, 100), $m->addBlock('say'), $m->addBlock('move', 400, 100)];
    $m->attach($inner, $loop, BlockPlace::Body, 0);
    $l = blocksLayout($m);
    $c = $m->block($loop);
    $i = $m->block($inner);
    $mv = $m->block($move);

    expect($l->blockAt($c->lx + 3, $c->ly + 3)[0])->toBe($loop)
        ->and($l->blockAt($i->lx + 3, $i->ly + 3)[0])->toBe($inner)
        ->and($l->onBlock($c, $c->lx, $c->ly, $c->lx + 40, $c->ly + $c->mouthY[0] + 5))->toBeFalse()
        ->and($l->blockAt($c->lx + 3, $c->ly + $c->h - 3)[0])->toBe($loop)
        ->and($l->blockAt(5, 5))->toBe([0, -1]);
    [$id, $slot] = $l->blockAt($mv->lx + $mv->slotX[0] + 3, $mv->ly + $mv->slotY[0] + 3);

    expect([$id, $slot])->toBe([$move, 0]);
    $v = $m->addBlock('add');
    $m->attach($v, $move, BlockPlace::Input, 0);
    $l = blocksLayout($m);

    expect($l->blockAt($mv->lx + $mv->slotX[0] + 3, $mv->ly + $mv->slotY[0] + 3)[0])->toBe($v)
        ->and($l->blockAt($mv->lx + $mv->slotX[0] + 3, $mv->ly + $mv->slotY[0] + 3)[1])->toBe(-1);
});

it('lists the palette by category, one block of each visible kind, and finds the kind under a point', function () {
    $m = blocksModel();
    $m->setKindVisible('stop', false);
    $l = blocksLayout($m);
    $kinds = array_map(fn ($e) => $e['kind'], $l->palette);
    $first = $l->palette[0]['block'];

    expect($kinds)->toContain('move', 'repeat')->not->toContain('stop')
        ->and(array_column($l->headers, 'text'))->toBe(['Events', 'Motion', 'Looks', 'Control', 'Operators', 'Sensing'])
        ->and($l->paletteAt($first->lx + 3, $first->ly + 3))->toBe('start')
        ->and($l->paletteAt(2.0, 2.0))->toBeNull()
        ->and($l->paletteHeight)->toBeGreaterThan($first->ly);
});

it('says where a carried stack would go: under a block, in a mouth, in a slot, over a script, and nowhere when far', function () {
    $m = blocksModel();
    [$a, $loop, $move, $hat] = [$m->addBlock('move', 100, 100), $m->addBlock('repeat', 400, 100), $m->addBlock('move', 700, 100), $m->addBlock('start', 1000, 300)];
    $l = blocksLayout($m);
    $A = $m->block($a);
    $C = $m->block($loop);
    $held = $m->addBlock('say', 0, 0);
    $l = blocksLayout($m);

    expect($l->findTarget($held, $A->lx + 5, $A->ly + $A->h + 4))->toBe(['target' => $a, 'place' => BlockPlace::Next, 'index' => 0, 'top' => 0])
        ->and($l->findTarget($held, $C->lx + BlockLayout::ARM + 3, $C->ly + $C->mouthY[0] + 3))->toBe(['target' => $loop, 'place' => BlockPlace::Body, 'index' => 0, 'top' => 0])
        ->and($l->findTarget($held, 2000.0, 2000.0))->toBe(['target' => 0, 'place' => BlockPlace::Next, 'index' => 0, 'top' => 0]);
    $M = $m->block($move);
    $value = $m->addBlock('add');
    $l = blocksLayout($m);
    $found = $l->findTarget($value, $M->lx + $M->slotX[0] + 2, $M->ly + $M->slotY[0] + $M->slotH[0] * 0.5 - $m->block($value)->h * 0.5);

    expect([$found['target'], $found['place'], $found['index']])->toBe([$move, BlockPlace::Input, 0]);
    $step = $m->addBlock('say');
    $l = blocksLayout($m);
    $above = $l->findTarget($step, $M->x + 3, $M->y - $m->block($step)->h);

    expect($above['top'])->toBe($move)->and($above['target'])->toBe(0)
        ->and($l->findTarget($step, $m->block($hat)->x + 3, $m->block($hat)->y - $m->block($step)->h)['top'])->toBe(0);
    $hatOnly = $m->addBlock('start');
    $l = blocksLayout($m);

    expect($l->findTarget($hatOnly, $A->lx, $A->ly + $A->h)['target'])->toBe(0);
});

it('opens a gap where the carried stack would go and puts its shadow there', function () {
    $m = blocksModel();
    [$a, $b] = [$m->addBlock('move', 50, 50), $m->addBlock('say')];
    $m->attach($b, $a, BlockPlace::Next);
    $held = $m->addBlock('move', 500, 500);
    $l = blocksLayout($m);
    $plain = $m->block($b)->ly;
    $height = $m->block($held)->h;
    $l->carry($held, $a, BlockPlace::Next);
    $l->layout();

    expect($m->block($b)->ly)->toBe($plain + $height)
        ->and([$l->ghostX, $l->ghostY])->toBe([$m->block($a)->lx, $m->block($a)->ly + $m->block($a)->h]);
    $l->carry($held, 0, BlockPlace::Next, 0, $a);
    $l->layout();

    expect($m->block($a)->ly)->toBe(50.0 + $height)->and([$l->ghostX, $l->ghostY])->toBe([50.0, 50.0]);
    $l->carry(null);
    $l->layout();

    expect($m->block($b)->ly)->toBe($plain);
});

it('selects blocks, alone or added, and clears the selection', function () {
    $m = blocksModel();
    [$a, $b] = [$m->addBlock('move'), $m->addBlock('say')];
    $m->select($a);
    $m->select($b, true);

    expect($m->selected())->toBe([$a, $b]);
    $m->select($a);

    expect($m->selected())->toBe([$a]);
    $m->select(0);

    expect($m->selected())->toBe([]);
});
