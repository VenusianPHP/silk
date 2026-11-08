<?php

use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Mail\ColumnSorted;
use Silk\Widgets\Table;
use Surface\Contracts\HumanInput\Modifiers;
use Surface\Contracts\HumanInput\MouseButton;
use Surface\Contracts\Windows\Mail\View\RowSelected;
use Surface\Contracts\Windows\Primitives\TableColumn;
use Surface\Contracts\Windows\WindowException;
use Venusian\Silk\Tests\Support\SpyPainter;

function sorted(object $ui): array
{
    return array_values(array_filter($ui->loop->posted, fn ($m) => $m instanceof ColumnSorted));
}

it('is created as TKTable: rows as given, one key per column, a missing key as empty text', function () {
    $ui = tableUi();

    expect($ui->table->rows()[2])->toBe(['name' => 'file1', 'size' => ''])
        ->and($ui->table->columns()[1]->label)->toBe('Size')
        ->and(tableTexts($ui))->toBe(['Name', 'Size', 'file10', '3', 'file2', '1', 'file1', '']);
});

it('sorts on a release over the header pressed, up then turned around, posting ColumnSorted; rows() keeps its order', function () {
    $ui = tableUi();
    click($ui->router, ...headerOf($ui, 'name'));
    // texts without regard to case, not natural order (table.c:282-286)
    expect(array_slice(tableTexts($ui), 2, 6))->toBe(['file1', '', 'file10', '3', 'file2', '1']);
    click($ui->router, ...headerOf($ui, 'name'));
    expect(array_slice(tableTexts($ui), 2, 6))->toBe(['file2', '1', 'file10', '3', 'file1', '']);

    expect(array_map(fn ($m) => [$m->column, $m->ascending], sorted($ui)))->toBe([['name', true], ['name', false]])
        ->and($ui->table->rows()[0]['name'])->toBe('file10');
});

it('does not sort when the press and the release are on different headers', function () {
    $ui = tableUi();
    [$x, $y] = headerOf($ui, 'name');
    $ui->router->dispatch(new SilkEvent(EventType::MouseDown, x: $x, y: $y, button: MouseButton::LEFT, clicks: 1));
    $ui->router->dispatch(new SilkEvent(EventType::MouseUp, x: headerOf($ui, 'size')[0], y: $y, button: MouseButton::LEFT));

    expect(sorted($ui))->toBe([])->and($ui->table->sort())->toBe([null, true]);
});

it('orders numbers by value before texts, and turns all of it around descending, ties in the order given', function () {
    $ui = tableUi([['size' => 'b'], ['size' => '2'], ['size' => 'B'], ['size' => 'a'], ['size' => '2'], ['size' => 'b']]);
    $ui->table->sortBy('size', ascending: false);
    $col = array_values(array_filter(array_slice(tableTexts($ui), 2), fn ($t, $i) => $i % 2 === 1, ARRAY_FILTER_USE_BOTH));
    expect($col)->toBe(['b', 'b', 'B', 'a', '2', '2']);
});

it('orders numbers by value before texts', function () {
    $ui = tableUi([['size' => 'x'], ['size' => '10'], ['size' => ' 9 '], ['size' => '2%'], ['size' => 'X']]);
    $ui->table->sortBy('size');
    $col = array_values(array_filter(array_slice(tableTexts($ui), 2), fn ($t, $i) => $i % 2 === 1, ARRAY_FILTER_USE_BOTH));

    // equal without case, then exactly: 'X' (88) before 'x' (120), as strcmp
    expect($col)->toBe(['2%', ' 9 ', '10', 'X', 'x']);
});

it('posts RowSelected with the row as given, after sorting', function () {
    $ui = tableUi();
    $ui->table->sortBy('name');
    click($ui->router, ...tableRow($ui, 0));

    $sel = array_values(array_filter($ui->loop->posted, fn ($m) => $m instanceof RowSelected));
    expect($sel)->toHaveCount(1)->and($sel[0]->row)->toBe(2)->and($sel[0]->cells)->toBe(['name' => 'file1', 'size' => ''])
        ->and($ui->table->selectedRow())->toBe(2);
});

it('resizes a column by dragging within 4 units of its edge, down to 24, posting nothing', function () {
    $ui = tableUi();
    [$ex, $ey] = headerEdge($ui, 'name');
    $was = $ui->table->columnWidth('name');
    drag3($ui, [$ex - 3, $ey], [$ex + 27, $ey]);
    expect($ui->table->columnWidth('name'))->toBe($was + 30);
    [$ex] = headerEdge($ui, 'name');
    drag3($ui, [$ex, $ey], [$ex - 500, $ey]);
    expect($ui->table->columnWidth('name'))->toBe(Table::MIN_COLUMN)->and($ui->loop->posted)->toBe([]);
});

it('fits a column to its header and cells on a double click on its edge', function () {
    $ui = tableUi([['name' => 'a much longer file name than the header'], ['name' => 'b']]);
    [$ex, $ey] = headerEdge($ui, 'name');
    $ui->router->dispatch(new SilkEvent(EventType::MouseDown, x: $ex, y: $ey, button: MouseButton::LEFT, clicks: 2));
    $ui->tree->layout(new Size(300, 300));
    $m = $ui->tree->measurer();
    $pad = $ui->look->metric(Silk\Contracts\Look\Metric::PaddingX) * 0.8;

    expect($ui->table->columnWidth('name'))->toBe(ceil($m->measure('a much longer file name than the header', $ui->look->font())->w + $pad * 2 + 2));
});

it('keeps the header in place while the rows scroll', function () {
    $rows = [];
    for ($i = 1; $i <= 500; $i++) {
        $rows[] = ['name' => "r{$i}", 'size' => (string) $i];
    }
    $ui = tableUi($rows);
    $ui->table->scrolls()[0]->set(1000);
    settleAll($ui);
    $texts = tableTexts($ui);

    expect(array_slice($texts, 0, 2))->toBe(['Name', 'Size'])->and($texts)->not->toContain('r1');
});

it('scrolls 3 rows a notch, and the columns 40 with Shift', function () {
    $rows = array_fill(0, 200, ['name' => 'x', 'size' => '1']);
    $ui = tableUi($rows);
    $ui->table->setColumnWidth('name', 400);
    $ui->tree->layout(new Size(300, 300));
    [$x, $y] = tableRow($ui, 1);
    $ui->router->dispatch(new SilkEvent(EventType::Wheel, x: $x, y: $y, wheelY: 1));
    $ui->router->dispatch(new SilkEvent(EventType::Wheel, x: $x, y: $y, wheelY: 1, modifiers: new Modifiers(shift: true)));
    [$v, $h] = $ui->table->scrolls();

    expect($v->offset)->toBe(3 * $ui->rowH)->and($h->offset)->toBe(40.0);
});

it('paints only the visible rows of 100,000', function () {
    $rows = [];
    for ($i = 1; $i <= 100_000; $i++) {
        $rows[] = ['name' => "r{$i}", 'size' => (string) $i];
    }
    $ui = tableUi($rows);
    $ui->table->render(new SpyPainter);
    $t = hrtime(true);
    $texts = tableTexts($ui);

    expect(count($texts))->toBeLessThan(2 * (300 / $ui->rowH + 4))->and((hrtime(true) - $t) / 1e6)->toBeLessThan(10.0);
});

it('clears the selection on setRows and clearRows, without mail; selectRow refuses a row outside', function () {
    $ui = tableUi();
    $ui->table->selectRow(1);
    $ui->table->setRows([['name' => 'x']]);
    expect($ui->table->selectedRow())->toBeNull();
    $ui->table->appendRow(['name' => 'y'])->selectRow(1)->clearRows();
    expect($ui->table->selectedRow())->toBeNull()->and($ui->table->rows())->toBe([])->and($ui->loop->posted)->toBe([])
        ->and(fn () => $ui->table->selectRow(0))->toThrow(WindowException::class);
});

it('measures clamp(widths + 2, 160, 640) by the header and 3 to 8 rows', function () {
    $ui = tableUi();
    $s = $ui->table->measure(new Size(999, 999));

    expect($s->w)->toBe(242.0)->and($s->h)->toBe($ui->table->headerHeight() + 3 * $ui->rowH + 2);
});

it('keeps one copy of 100,000 rows, and sorts them in place: peak growth one keyed array and the order', function () {
    $rows = [];
    for ($i = 1; $i <= 100_000; $i++) {
        $rows[] = ['name' => "r{$i}", 'size' => (string) $i];
    }
    $columns = [new TableColumn('name', 'Name'), new TableColumn('size', 'Size')];
    gc_collect_cycles();
    memory_reset_peak_usage();
    $before = memory_get_usage();
    $table = new Table('big', $columns, $rows);
    $table->sortBy('name');
    $growth = memory_get_peak_usage() - $before;

    // a sort needs one keyed array of the column (131,072 hash slots x (32 B bucket + 8 B slot) = 5.2 MB) and the
    // order it gives (a list of ints, 1.6 MB): 7.4 MB measured. 9 MB bounds it with one more int list of margin;
    // a second copy of these rows' cells is ~46 MB
    expect($growth)->toBeLessThan(9_000_000)->and($table->rows()[99_999])->toBe(['name' => 'r100000', 'size' => '100000']);
});
