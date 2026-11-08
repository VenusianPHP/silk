<?php

use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Host\WindowAction;
use Silk\Contracts\Host\WindowActions;
use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Look\Metric;
use Silk\Contracts\Look\Role;
use Silk\Contracts\Mail\ColorChanged;
use Silk\Contracts\Mail\ColumnSorted;
use Silk\Contracts\Mail\RowActivated;
use Silk\Contracts\Mail\TilesSelected;
use Silk\Contracts\Mail\TimeChanged;
use Silk\Core\Drag\DragPayload;
use Silk\Core\Drag\DragSession;
use Silk\Core\Drag\DropPhase;
use Silk\Core\Input\FocusManager;
use Silk\Core\Input\Router;
use Silk\Core\Layout\Spacer;
use Silk\Core\Tree\Tree;
use Silk\Hosts\StagedHost;
use Silk\Looks\BuiltInThemes;
use Silk\Looks\LookManager;
use Silk\Looks\RavenDark;
use Silk\Widgets\ColorPicker;
use Silk\Widgets\Column;
use Silk\Widgets\DatePicker;
use Silk\Widgets\HeaderBar;
use Silk\Widgets\ListView;
use Silk\Widgets\MenuBar;
use Silk\Widgets\Table;
use Silk\Widgets\TileMode;
use Silk\Widgets\TileView;
use Silk\Widgets\TimePicker;
use Silk\Widgets\Toast;
use Silk\Widgets\ToastKind;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\Modifiers;
use Surface\Contracts\HumanInput\MouseButton;
use Surface\Contracts\Windows\HitArea;
use Surface\Contracts\Windows\Mail\MenuActivated;
use Surface\Contracts\Windows\Mail\View\ButtonClicked;
use Surface\Contracts\Windows\Mail\View\DateChanged;
use Surface\Contracts\Windows\Mail\View\RowSelected;
use Surface\Contracts\Windows\Mail\View\SelectionChanged;
use Surface\Contracts\Windows\Primitives\TableColumn;
use Surface\Contracts\Windows\WindowCapability;
use Surface\Contracts\Windows\WindowException;
use Surface\Contracts\Windows\WindowMode;
use Surface\NutsAndBolts\Color;
use Venusian\Silk\Tests\Support\SpyEngine;
use Venusian\Silk\Tests\Support\SpyLoop;
use Venusian\Silk\Tests\Support\SpyPainter;
use Venusian\Surface\Tests\Fixtures\FakeSession;

// Lane B's M2 test helpers, shared so no test file declares a global function.

$fixtures = (getenv('SURFACE_TESTS') ?: dirname(__DIR__, 3).'/surface/tests').'/Fixtures';
require_once $fixtures.'/FakeSession.php';
require_once $fixtures.'/FakeStager.php';

function listUi(int $n = 100): object
{
    $items = [];
    for ($i = 1; $i <= $n; $i++) {
        $items[] = "Item {$i}";
    }
    $ui = silkUi(fn ($root) => $root->add(new ListView('list', $items)));
    $ui->list = $ui->root->children()[0];
    $ui->rowH = $ui->look->metric(Metric::RowHeight);

    return $ui;
}

/** The centre of row $i, at the drawn offset. @return array{float, float} */
function rowPoint(object $ui, int $i): array
{
    $b = $ui->list->bounds();

    return [$b->x + $b->w / 3, $b->y + 1 + ($i + 0.5) * $ui->rowH - $ui->list->scroll()->offset];
}

function tableUi(?array $rows = null): object
{
    $rows ??= [
        ['name' => 'file10', 'size' => '3'],
        ['name' => 'file2', 'size' => '1'],
        ['name' => 'file1'],
    ];
    $ui = silkUi(fn ($root) => $root->add(new Table('files', [new TableColumn('name', 'Name'), new TableColumn('size', 'Size')], $rows)));
    $ui->table = $ui->root->children()[0];
    $ui->rowH = $ui->look->metric(Metric::RowHeight);

    return $ui;
}

/** The point in the middle of display row $i, column 0. @return array{float, float} */
function tableRow(object $ui, int $i): array
{
    $body = $ui->table->geometry()['body'];

    return [$body->x + 10, $body->y + ($i + 0.5) * $ui->rowH - $ui->table->scrolls()[0]->offset];
}

/** The centre of header column $id. @return array{float, float} */
function headerOf(object $ui, string $id): array
{
    $b = $ui->table->bounds();
    $h = $ui->table->headerHeight();
    $x = $b->x + 1 - $ui->table->scrolls()[1]->offset;
    foreach ($ui->table->columns() as $c) {
        if ($c->id === $id) {
            return [$x + $ui->table->columnWidth($id) / 2, $b->y + 1 + $h / 2];
        }
        $x += $ui->table->columnWidth($c->id);
    }
    throw new LogicException($id);
}

/** The right edge of header column $id. @return array{float, float} */
function headerEdge(object $ui, string $id): array
{
    [$x, $y] = headerOf($ui, $id);

    return [$x + $ui->table->columnWidth($id) / 2, $y];
}

/** The cell texts painted, in order. @return list<string> */
function tableTexts(object $ui): array
{
    $p = new Venusian\Silk\Tests\Support\SpyPainter;
    $ui->table->render($p);

    return array_values(array_map(fn ($c) => $c[1][0], array_filter($p->calls, fn ($c) => $c[0] === 'text')));
}




function expandedTreeUi(): object
{
    $ui = treeUi();
    $ui->tv->expand('a');
    $ui->tree->layout(new Size(300, 300));

    return $ui;
}

class RecordingWindowActions implements WindowActions
{
    /** @var list<WindowAction> */
    public array $done = [];

    public bool $maximized = false;

    public bool $active = true;

    public function __construct(public array $offered = [WindowAction::Minimize, WindowAction::Maximize, WindowAction::Close], public string $title = 'Window') {}

    public function windowActions(): array
    {
        return $this->offered;
    }

    public function performWindowAction(WindowAction $action): void
    {
        $this->done[] = $action;
    }

    public function windowTitle(): string
    {
        return $this->title;
    }

    public function isMaximized(): bool
    {
        return $this->maximized;
    }

    public function isActive(): bool
    {
        return $this->active;
    }
}

function headerUi(?RecordingWindowActions $actions = null, ?Silk\Contracts\Widget\Align $side = Silk\Contracts\Widget\Align::End): object
{
    $ui = silkUi(function ($root) use ($side) {
        $bar = (new HeaderBar('bar', 'Gallery'))->setButtonSide($side);
        $bar->button('back', 'Back');
        $root->add($bar);
    });
    $ui->bar = $ui->root->children()[0];
    $ui->actions = $actions ?? new RecordingWindowActions;
    $ui->tree->setWindowActions($ui->actions);
    $ui->tree->layout(new Size(300, 300));

    return $ui;
}

function menuBarUi(): object
{
    $ui = silkUi(function ($root) {
        $bar = new MenuBar('bar');
        $file = $bar->menu('file', 'File');
        $file->item('quit', 'Quit');
        $edit = $bar->menu('edit', 'Edit');
        $edit->item('copy', 'Copy');
        $edit->submenu('more', 'More')->item('upper', 'Uppercase');
        $bar->menu('off', 'Off')->item('x', 'X');
        $view = $bar->menu('view', 'Viewing everything here');
        $view->item('wide', 'A wide menu item with a long label');
        $root->add($bar);
        $root->button('below', 'Below');
    });
    $ui->bar = $ui->root->children()[0];
    $ui->titles = $ui->bar->titles();
    $ui->titles[2]->setEnabled(false);
    $ui->tree->layout(new Size(300, 300));

    return $ui;
}

function settleMenus(object $ui): void
{
    settleAll($ui);
}

/** A 280-wide view of 60 px tiles: 4 columns. */
function tileUi(int $n = 30): object
{
    $ui = silkUi(fn ($root) => $root->add(new TileView('tiles')));
    $ui->view = $ui->root->children()[0];
    $ui->asked = [];
    $ui->view->setItems($n, function (int $i) use ($ui) {
        $ui->asked[] = $i;

        return new Silk\Widgets\TileItem("T{$i}");
    });
    $ui->tree->layout(new Size(300, 300));

    return $ui;
}

/** The centre of tile $i, on screen. @return array{float, float} */
function tilePoint(object $ui, int $i): array
{
    return centre_of($ui->view->tileRect($i));
}

function tileMail(object $ui): array
{
    return array_values(array_map(fn ($m) => $m->tiles, array_filter($ui->loop->posted, fn ($m) => $m instanceof TilesSelected)));
}

function toastUi(): object
{
    $ui = silkUi(function ($root) {
        $root->button('under', 'Under');
        $root->add(new Silk\Widgets\Toast('note'));
    });
    $ui->toast = $ui->root->children()[1];

    return $ui;
}

function toastRun(object $ui, float $seconds): void
{
    for ($t = 0.0; $t < $seconds - 1e-9; $t += 1 / 60) {
        $ui->tree->advance(1 / 60);
        $ui->tree->layout(new Size(300, 300));
    }
}

function dateUi(?DateTimeImmutable $d = null, bool $corner = false): object
{
    $ui = silkUi(function ($root) use ($d, $corner) {
        $corner && $root->add(new Silk\Core\Layout\Spacer('push', 0, 240), new Silk\Contracts\Widget\Slot(align: Silk\Contracts\Widget\Align::Fill));
        $root->add(new DatePicker('when', $d), new Silk\Contracts\Widget\Slot(align: $corner ? Silk\Contracts\Widget\Align::End : Silk\Contracts\Widget\Align::Start));
    });
    $ui->picker = $ui->root->children()[$corner ? 1 : 0];

    return $ui;
}

function openCalendar(object $ui): Silk\Widgets\Calendar
{
    click($ui->router, ...centre($ui->picker));
    settleAll($ui);

    return $ui->picker->calendar();
}

function dateMail(object $ui): array
{
    return array_values(array_map(fn ($m) => $m->date->format('Y-m-d'), array_filter($ui->loop->posted, fn ($m) => $m instanceof DateChanged)));
}

/** A focused TimePicker in $theme. */
function timeUi(string $theme = 'raven-dark', int $h = 23, int $m = 59, ?int $s = null): object
{
    $root = new Column('window', 6, 10);
    $root->add($t = new TimePicker('alarm', $h, $m, $s));
    $loop = new SpyLoop;
    $look = BuiltInThemes::register(new LookManager, 'sans-serif', 'monospace')->use($theme)->current();
    $tree = new Tree($root, $look, $loop, 'main');
    $tree->layout(new Size(300, 300));
    $focus = new FocusManager($tree);
    $focus->focus($t);

    return (object) ['t' => $t, 'loop' => $loop, 'tree' => $tree, 'look' => $look, 'router' => new Router($tree, $focus)];
}

function timeMail(object $ui): array
{
    return array_values(array_map(fn ($m) => [$m->hour, $m->minute, $m->second], array_filter($ui->loop->posted, fn ($m) => $m instanceof TimeChanged)));
}

function typeText(object $ui, string $text): void
{
    foreach (str_split($text) as $ch) {
        $ui->router->dispatch(new SilkEvent(EventType::Text, text: $ch));
    }
}

function pickerUi(?Color $c = null, bool $alpha = true): object
{
    $ui = silkUi(fn ($root) => $root->add(new ColorPicker('paint', $c ?? Color::hex('#3366cc'), $alpha)));
    $ui->cp = $ui->root->children()[0];
    $ui->tree->layout(new Size(300, 600));

    return $ui;
}

function colourMail(object $ui): array
{
    return array_values(array_map(fn ($m) => $m->color->toHex(), array_filter($ui->loop->posted, fn ($m) => $m instanceof ColorChanged)));
}

/** outer (takes drops of type 'thing') > inner (a callback that refuses) > leaf spacer; and a plain spacer, and a spacer under outer alone. */
function dragUi(): object
{
    $ui = silkUi(function ($root) {
        $root->add($outer = new Silk\Widgets\Column('outer', 0, 0));
        $outer->add($inner = new Silk\Widgets\Column('inner', 0, 0));
        $inner->add(new Silk\Core\Layout\Spacer('leaf', 100, 40));
        $outer->add(new Silk\Core\Layout\Spacer('open', 100, 40));
        $root->add(new Silk\Core\Layout\Spacer('plain', 100, 60));
    });
    [$ui->outer] = $ui->root->children();
    [$ui->inner, $ui->open] = $ui->outer->children();
    $ui->leaf = $ui->inner->children()[0];
    $ui->plain = $ui->root->children()[1];
    $ui->log = [];
    $ui->outer->setDropCallback(function (Silk\Core\Drag\DropEvent $e) use ($ui) {
        $ui->log[] = "outer:{$e->phase->name}:{$e->data}";

        return $e->type === 'thing';
    });
    $ui->inner->setDropCallback(function (Silk\Core\Drag\DropEvent $e) use ($ui) {
        $ui->log[] = "inner:{$e->phase->name}";

        return false;
    });

    return $ui;
}

function dragMoveTo(object $ui, array $at): void
{
    $ui->router->dispatch(new SilkEvent(EventType::MouseMove, x: $at[0], y: $at[1], button: MouseButton::LEFT));
}

/** A 400x300 staged window (UI scale 2) whose tree has a HeaderBar at the top. */
function stagedUi(array $capabilities = [WindowCapability::HitTest]): object
{
    FakeStagedWindow::$capabilities = $capabilities;
    $window = (new FakeStager(new FakeSession))->openStaged('stage', 400, 300, []);
    $root = new Column('window', 0, 0);
    $root->add($bar = new HeaderBar('bar', 'Title'));
    $look = (new LookManager)->extend('raven-dark', fn () => RavenDark::theme())->use('raven-dark')->current();
    $tree = new Tree($root, $look, new SpyLoop, 'stage');
    $host = new StagedHost($window, $tree, new SpyEngine(400, 300), uiScale: 2.0);
    $tree->layout($host->viewport());

    return (object) ['window' => $window, 'tree' => $tree, 'host' => $host, 'bar' => $bar,
        'test' => (fn () => $this->hit_test)->call($window)];
}

function crumbUi(array $items, float $width = 300): object
{
    $ui = silkUi(fn ($root) => $root->add(new Silk\Widgets\Breadcrumb('path', $items)));
    $ui->crumbs = $ui->root->children()[0];
    $ui->tree->layout(new Size($width, 300));

    return $ui;
}

function crumbMail(object $ui): array
{
    return array_values(array_map(fn ($m) => [$m->index, $m->option], array_filter($ui->loop->posted,
        fn ($m) => $m instanceof Surface\Contracts\Windows\Mail\View\SelectionChanged)));
}

function pagesUi(int $count, int $page): object
{
    $ui = silkUi(fn ($root) => $root->add(new Silk\Widgets\Pagination('pages', $count, $page)));
    $ui->pages = $ui->root->children()[0];
    $ui->tree->layout(new Size(600, 300));

    return $ui;
}

function pageMail(object $ui): array
{
    return array_values(array_map(fn ($m) => (int) $m->value, array_filter($ui->loop->posted, fn ($m) => $m instanceof Surface\Contracts\Windows\Mail\View\ValueChanged)));
}

function ratingUi(float $value = 0.0, int $max = 5): object
{
    $ui = silkUi(fn ($root) => $root->add(new Silk\Widgets\Rating('stars', $value, $max), new Silk\Contracts\Widget\Slot(align: Silk\Contracts\Widget\Align::Start)));
    $ui->rating = $ui->root->children()[0];

    return $ui;
}

function ratingMail(object $ui): array
{
    return array_values(array_map(fn ($m) => $m->value, array_filter($ui->loop->posted, fn ($m) => $m instanceof Surface\Contracts\Windows\Mail\View\ValueChanged)));
}

/**
 * The spans of each line as "text:Token", the state carried from line to line.
 *
 * @param  list<string>  $lines
 * @return list<list<string>>
 */
function syntaxSpans(string $language, array $lines): array
{
    $state = 0;
    $out = [];
    foreach ($lines as $line) {
        $state = \Silk\Text\Syntax\Syntax::line(\Silk\Text\Syntax\Syntax::find($language), $line, $state, $spans);
        $out[] = array_map(fn ($s) => substr($line, $s[0], $s[1] - $s[0]).':'.$s[2]->name, $spans);
    }

    return $out;
}

/**
 * Parsed Markdown, one string per block: "Type q.l.m: span|span", a span as its text with B I C S for
 * bold, italic, code, strike, ⏎ for a break and @n for link n.
 *
 * @return list<string>
 */
function mdShape(string $source): array
{
    $md = \Silk\Text\Markdown\Markdown::parse($source);
    $types = ['P', 'H', 'Code', 'Rule', 'Table', 'Image'];
    $span = function (array $s) use ($md) {
        [$start, $length, $flags, $link] = $s;
        $marks = ($flags & 1 ? 'B' : '').($flags & 2 ? 'I' : '').($flags & 4 ? 'C' : '').($flags & 8 ? 'S' : '');

        return substr($md->text, $start, $length).($marks ? ":{$marks}" : '').($flags & 16 ? '⏎' : '').($link >= 0 ? "@{$link}" : '');
    };
    $out = [];
    foreach ($md->blocks as $b) {
        $head = $types[$b['type']].($b['level'] ? $b['level'] : '')." {$b['quote']}.{$b['list']}.{$b['marker']}";
        if ($b['type'] === 2) {
            $out[] = "{$head} [{$b['language']}] ".substr($md->text, $b['codeStart'], $b['codeLength']);
        } elseif ($b['type'] === 4) {
            $cells = [];
            for ($c = 0; $c < $b['columns'] * $b['rows']; $c++) {
                [$first, $count, $align] = $md->cells[$b['firstCell'] + $c];
                $cells[] = implode('', array_map($span, array_slice($md->spans, $first, $count))).($align ? "~{$align}" : '');
            }
            $out[] = "{$head} {$b['columns']}x{$b['rows']}: ".implode(',', $cells);
        } else {
            $out[] = "{$head}: ".implode('|', array_map($span, array_slice($md->spans, $b['first'], $b['count'])));
        }
    }

    return $out;
}

function markdownUi(string $text, float $width = 0.0): object
{
    $label = (new Silk\Widgets\Label('md', $text))->setMarkdown();
    $width > 0 && $label->setWrap();
    $ui = silkUi(fn ($root) => $root->add($label, new Silk\Contracts\Widget\Slot(align: Silk\Contracts\Widget\Align::Start)));
    $ui->label = $label;

    return $ui;
}

function linkMail(object $ui): array
{
    return array_values(array_map(fn ($m) => $m->url, array_filter($ui->loop->posted, fn ($m) => $m instanceof Silk\Contracts\Mail\LinkActivated)));
}

/** @return list<array{string, mixed}> text calls as [text, Color] */
function mdTexts(object $ui): array
{
    $p = new \Venusian\Silk\Tests\Support\SpyPainter;
    $ui->label->render($p);

    return array_values(array_map(fn ($c) => [$c[1][0], $c[1][3]], array_filter($p->calls, fn ($c) => $c[0] === 'text')));
}

/** A selectable label (plain, or Markdown when $markdown) with a recording clipboard as ->clip. */
function selectUi(string $text, bool $markdown = false): object
{
    $label = (new Silk\Widgets\Label('sel', $text))->setSelectable();
    $markdown && $label->setMarkdown();
    $ui = silkUi(fn ($root) => $root->add($label, new Silk\Contracts\Widget\Slot(align: Silk\Contracts\Widget\Align::Start)));
    $ui->clip = new class implements Surface\Contracts\Windows\Services\Clipboard
    {
        public ?string $text = null;

        public function setText(string $text): void
        {
            $this->text = $text;
        }

        public function requestText(): string
        {
            return 'req';
        }

        public function hasText(): bool
        {
            return $this->text !== null;
        }
    };
    $label->setClipboard($ui->clip);
    $ui->label = $label;

    return $ui;
}

/** A press with $clicks at a point, a drag to another, a release there. */
function pressDrag(object $ui, float $x, float $y, ?float $toX = null, ?float $toY = null, int $clicks = 1, bool $shift = false): void
{
    $mods = new Surface\Contracts\HumanInput\Modifiers(shift: $shift);
    $ui->router->dispatch(new Silk\Contracts\Input\SilkEvent(Silk\Contracts\Input\EventType::MouseDown, x: $x, y: $y, button: Surface\Contracts\HumanInput\MouseButton::LEFT, clicks: $clicks, modifiers: $mods));
    $ui->router->dispatch(new Silk\Contracts\Input\SilkEvent(Silk\Contracts\Input\EventType::MouseMove, x: $toX ?? $x, y: $toY ?? $y));
    $ui->router->dispatch(new Silk\Contracts\Input\SilkEvent(Silk\Contracts\Input\EventType::MouseUp, x: $toX ?? $x, y: $toY ?? $y, button: Surface\Contracts\HumanInput\MouseButton::LEFT));
}

/**
 * Every M2 widget at rest, by name: [the UI, the widget to paint, the colours it may paint besides the theme's
 * roles and syntax colours]. Those extras are Raven derivations of roles, or the widget's own value; each says where.
 *
 * @return array<string, Closure(): array{object, Silk\Core\Tree\Widget, Closure(Silk\Contracts\Look\Look): list<Color>}>
 */
function m2Widgets(): array
{
    $none = fn () => [];
    // ScrollingRows/Table: the selection without the keyboard focus, Selection mixed 0.45 to Input (list_view.c)
    $unfocused = function (Silk\Contracts\Look\Look $look) {
        [$s, $i] = [$look->color(Silk\Contracts\Look\Role::Selection), $look->color(Silk\Contracts\Look\Role::Input)];

        return [new Color($s->red + ($i->red - $s->red) * 0.45, $s->green + ($i->green - $s->green) * 0.45, $s->blue + ($i->blue - $s->blue) * 0.45)];
    };

    return [
        'ListView' => function () use ($unfocused) { $ui = listUi(20); $ui->list->select(2); return [$ui, $ui->list, $unfocused]; },
        'Table' => function () use ($unfocused) { $ui = tableUi(); $ui->table->selectRow(1); return [$ui, $ui->table, $unfocused]; },
        'TreeView' => function () use ($unfocused) { $ui = expandedTreeUi(); return [$ui, $ui->tv, $unfocused]; },
        'TileView' => function () use ($none) { $ui = tileUi(8); return [$ui, $ui->view, $none]; },
        'Toast' => function () use ($none) { $ui = toastUi(); $ui->toast->show('Saved', 5); toastRun($ui, 1); return [$ui, $ui->toast, $none]; },
        'DatePicker' => function () use ($none) { $ui = dateUi(new DateTimeImmutable('2026-11-06')); return [$ui, $ui->picker, $none]; },
        'TimePicker' => function () use ($none) { $ui = timeUi(); return [$ui, $ui->t, $none]; },
        // its value, and the square's pure hue (hue at full saturation and value)
        'ColorPicker' => function () {
            $ui = pickerUi();

            return [$ui, $ui->cp, fn () => [$ui->cp->color(), pureHue($ui->cp->hsv()[0])]];
        },
        'HeaderBar' => function () use ($none) { $ui = headerUi(); return [$ui, $ui->bar, $none]; },
        'MenuBar' => function () use ($none) { $ui = menuBarUi(); return [$ui, $ui->bar, $none]; },
        'Breadcrumb' => function () use ($none) { $ui = crumbUi(['src', 'Silk', 'Widgets']); return [$ui, $ui->crumbs, $none]; },
        'Pagination' => function () use ($none) { $ui = pagesUi(20, 4); return [$ui, $ui->pages, $none]; },
        'Rating' => function () use ($none) { $ui = ratingUi(3.5); return [$ui, $ui->rating, $none]; },
        'Label markdown' => function () use ($none) {
            $ui = markdownUi("# T\n\n- [x] **b** [l](u) `c` ~~s~~\n\n> q\n\n1. n\n\n---\n\n| a |\n|---|\n| 1 |\n\n```c\n#include <x.h>\nint a = 1; // c \"s\"\n```");

            return [$ui, $ui->label, $none];
        },
    ];
}

function pureHue(float $h): Color
{
    $f = fn (float $n) => 1 - max(0, min(1, min($k = fmod($n + $h / 60, 6), 4 - $k)));

    return new Color($f(5), $f(3), $f(1));
}

/**
 * The colours a widget paints in a theme, as hex RGB (alpha left out), that did not come from the look: none of
 * the theme's role or syntax colours, none of the colours its part looks gave out (style rows), none of $extra.
 *
 * @param  Closure(Silk\Contracts\Look\Look): list<Color>  $extra
 * @return list<string>
 */
function offRoleColours(object $ui, Silk\Core\Tree\Widget $w, string $theme, Closure $extra): array
{
    $themed = Silk\Looks\BuiltInThemes::register(new Silk\Looks\LookManager, 'sans-serif', 'monospace')->use($theme)->current();
    $look = new class($themed) implements Silk\Contracts\Look\Look
    {
        /** @var array<string, true> */
        public array $given = [];

        /** $root: the proxy whose $given its dark twin's proxy records into */
        public function __construct(private readonly Silk\Contracts\Look\Look $look, private readonly ?object $root = null) {}

        private function keep(Color $c): Color
        {
            $into = $this->root ?? $this;
            $into->given[rgbKey($c)] = true;

            return $c;
        }

        public function color(Silk\Contracts\Look\Role $r): Color
        {
            return $this->keep($this->look->color($r));
        }

        public function metric(Silk\Contracts\Look\Metric $m): float
        {
            return $this->look->metric($m);
        }

        public function font(Silk\Contracts\Look\FontRole $role = Silk\Contracts\Look\FontRole::Ui): Silk\Contracts\Look\FontSpec
        {
            return $this->look->font($role);
        }

        public function motion(): Silk\Contracts\Look\Motion
        {
            return $this->look->motion();
        }

        public function part(string $widget, string $part, Silk\Contracts\Look\State $state, array $classes = []): Silk\Contracts\Look\PartLook
        {
            $partLook = $this->look->part($widget, $part, $state, $classes);
            $vars = get_object_vars($partLook);
            array_walk_recursive($vars, function ($v) {
                $v instanceof Color && $this->keep($v);
            });

            return $partLook;
        }

        public function syntax(Silk\Contracts\Look\SyntaxToken $t): ?Color
        {
            $c = $this->look->syntax($t);

            return $c === null ? null : $this->keep($c);
        }

        /** a twin (an Island's subtree) records what it gives too */
        public function darkTwin(): Silk\Contracts\Look\Look
        {
            return new self($this->look->darkTwin(), $this->root ?? $this);
        }

        public function family(): string
        {
            return $this->look->family();
        }

        public function material(): Silk\Contracts\Look\Material
        {
            return $this->look->material();
        }

        public function liquid(): bool
        {
            return $this->look->liquid();
        }

        public function dark(): bool
        {
            return $this->look->dark();
        }
    };
    $ui->tree->reducedMotion = true;
    $ui->tree->setLook($look);
    $ui->tree->layout(new Size(300, 600));
    $p = new Venusian\Silk\Tests\Support\SpyPainter;
    $w->render($p);
    $allowed = $look->given;
    foreach ([
        ...array_map(fn ($r) => $themed->color($r), Silk\Contracts\Look\Role::cases()),
        ...array_filter(array_map(fn ($t) => $themed->syntax($t), Silk\Contracts\Look\SyntaxToken::cases())),
        ...$extra($themed),
    ] as $c) {
        $allowed[rgbKey($c)] = true;
    }
    $off = [];
    array_walk_recursive($p->calls, function ($v) use (&$off, $allowed) {
        if ($v instanceof Color && $v->alpha > 0 && ! isset($allowed[rgbKey($v)])) {
            $off[rgbKey($v)] = true;
        }
    });

    return array_keys($off);
}

function rgbKey(Color $c): string
{
    return sprintf('#%02x%02x%02x', (int) round($c->red * 255), (int) round($c->green * 255), (int) round($c->blue * 255));
}

/** A UI holding what $build adds, settled (springs run 1 s); $build returns the widget to paint. */
function sweepUi(Closure $build): array
{
    $made = null;
    $ui = silkUi(function ($root) use ($build, &$made) {
        $made = $build($root);
    });
    $w = $made instanceof Closure ? $made($ui) : $made;
    toastRun($ui, 1);

    return [$ui, $w];
}

/**
 * Every tier 1 widget at rest, by name, in the form of m2Widgets(); checked, open and filled where that is
 * how it is used. Layout containers that paint nothing are left out.
 *
 * @return array<string, Closure(): array{object, Silk\Core\Tree\Widget, Closure(Silk\Contracts\Look\Look): list<Color>}>
 */
function tier1Widgets(): array
{
    $none = fn () => [];
    $one = fn (Closure $build) => function () use ($build, $none) { [$ui, $w] = sweepUi($build); return [$ui, $w, $none]; };
    $popup = fn (Closure $make) => $one(function ($root) use ($make) {
        $root->button('anchor', 'Anchor');

        return fn ($ui) => $make($ui, $root->children()[0]);
    });

    $with = fn (Closure $make, Closure $extra) => function () use ($make, $extra) { [$ui, $w] = $make(); return [$ui, $w, $extra]; };
    // checkbox.c:126, macOS on: the box's bottom edge is its fill mixed toward black by the look's shadeBottom
    $shaded = fn (string $part) => function (Silk\Contracts\Look\Look $look) use ($part) {
        $b = $look->part('Checkbox', $part, Silk\Contracts\Look\State::Normal, ['on']);
        $t = (float) ($b->extra['shadeBottom'] ?? 0.0);

        return $b->background === null ? [] : [new Color($b->background->red * (1 - $t), $b->background->green * (1 - $t), $b->background->blue * (1 - $t))];
    };
    // menu.c: a shortcut is the item's ink mixed toward TextDisabled by the look's shortcutMix
    $shortcut = function (Silk\Contracts\Look\Look $look) {
        $ink = $look->part('Menu', 'Item', Silk\Contracts\Look\State::Normal)->text ?? $look->color(Silk\Contracts\Look\Role::Text);

        return [Silk\Core\Motion\LookBlend::mix($ink, $look->color(Silk\Contracts\Look\Role::TextDisabled),
            (float) ($look->part('Menu', 'Body', Silk\Contracts\Look\State::Normal)->extra['shortcutMix'] ?? 0.6))];
    };
    // tab_view.c:609: a title not active is the text mixed toward Header by the look's dimMix
    $dimmed = fn (Silk\Contracts\Look\Look $look) => [Silk\Core\Motion\LookBlend::mix($look->color(Silk\Contracts\Look\Role::Text),
        $look->color(Silk\Contracts\Look\Role::Header), (float) ($look->part('TabView', 'Body', Silk\Contracts\Look\State::Normal)->extra['dimMix'] ?? 0.3))];
    $menu = function ($ui, $anchor) {
        $m = new Silk\Widgets\Menu('m');
        $m->item('a', 'Open', 'Ctrl+O');
        $m->separator();
        $m->item('c', 'Checked')->setChecked(true);
        $m->submenu('more', 'More')->item('x', 'X');

        return $m->open($anchor);
    };

    return [
        'Label' => $one(fn ($r) => $r->label('l', "Plain\ntext")),
        'Label selected' => $one(fn ($r) => $r->label('l', 'Plain text')->setSelectable()->selectAll()),
        'Button' => $one(fn ($r) => $r->button('b', 'Press')),
        'Checkbox' => $with($one(fn ($r) => $r->checkbox('c', 'Check', true)), $shaded('Box')),
        'Checkbox off' => $one(fn ($r) => $r->checkbox('c', 'Check')),
        'Radio' => $with($one(fn ($r) => $r->radio('r', 'Pick', true)), $shaded('Radio')),
        'Toggle' => $one(fn ($r) => $r->toggle('t', true)),
        'Toggle off' => $one(fn ($r) => $r->toggle('t')),
        'ProgressBar' => $one(fn ($r) => $r->progressBar('p', 0.4)),
        'ProgressBar busy' => $one(fn ($r) => $r->progressBar('p', null)),
        'Separator' => $one(fn ($r) => $r->separator('s')),
        'Separator vertical' => $one(fn ($r) => $r->separator('s', false)),
        'Border' => $one(fn ($r) => $r->border('b', 8)->setBackground(Silk\Contracts\Look\Role::Panel)
            ->setOutline(Silk\Contracts\Look\Role::Separator)->setShadow(8, Silk\Contracts\Look\Role::Shadow)->setCornerRadius(6)),
        'MessageBox' => $one(fn ($r) => fn ($ui) => Silk\Widgets\MessageBox::show($ui->tree, 'Title', 'A message', ['OK', 'Cancel'])),
        'TextInput' => $one(fn ($r) => $r->textInput('i', 'Text')),
        'TextInput placeholder' => $one(fn ($r) => $r->textInput('i', '', 'Your name')),
        'SpinBox' => $one(fn ($r) => $r->spinBox('n', 4)),
        'Dropdown' => $one(fn ($r) => $r->dropdown('d', ['One', 'Two'])),
        'ComboBox' => $one(fn ($r) => $r->comboBox('c', 'pear', ['apple', 'pear'])),
        'Slider' => $one(fn ($r) => $r->slider('s', 0, 10, 4)),
        'ScrollBar' => $one(fn ($r) => $r->scrollBar('b')),
        'ScrollBox' => $one(function ($r) {
            $box = $r->scrollView('s');
            $box->add($c = new Silk\Widgets\Column('inner', 4, 0));
            for ($i = 0; $i < 30; $i++) {
                $c->label("l{$i}", "Line {$i}");
            }

            return $box;
        }),
        'Splitter' => $one(function ($r) {
            $s = $r->split('s');
            $s->add(new Silk\Widgets\Label('a', 'A'));
            $s->add(new Silk\Widgets\Label('b', 'B'));

            return $s;
        }),
        'TabView' => $with($one(function ($r) {
            $t = $r->tabView('t');
            $t->addPage('one', 'One')->label('x', 'First');
            $t->addPage('two', 'Two');

            return $t;
        }), $dimmed),
        'Expander' => $one(function ($r) {
            $e = $r->expander('e', 'More', true);
            $e->body()->label('x', 'Inside');

            return $e;
        }),
        'Menu' => $with($popup($menu), $shortcut),
        'Tooltip' => $popup(function ($ui, $anchor) {
            $anchor->setTooltip('A tooltip');
            $ui->tree->pointerAt($anchor, ...centre($anchor));
            toastRun($ui, 2);

            return $ui->tree->tooltip();
        }),
        'Dialog' => $popup(function ($ui, $anchor) {
            $d = new Silk\Widgets\Dialog('d');
            $d->label('t', 'A dialog');
            $d->button('ok', 'OK');

            return $d->in($ui->tree)->openAt(10, 10);
        }),
        'DropdownList' => $popup(function ($ui, $anchor) {
            $ui->root->add($d = new Silk\Widgets\Dropdown('dd', ['One', 'Two', 'Three'], 1));
            $ui->tree->layout(new Size(300, 300));
            click($ui->router, ...centre($d));

            return $d->list();
        }),
    ];
}
