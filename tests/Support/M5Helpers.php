<?php

use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Surface\Contracts\Windows\Input\EditCommand;

/** A focused TextEditor 'ed' holding $text, with a recording clipboard as ->clip. */
function editorUi(string $text = '', ?string $language = null): object
{
    $ed = new Silk\Widgets\TextEditor('ed', $text);
    $ed->setLanguage($language);
    $ui = silkUi(fn ($root) => $root->add($ed, new Silk\Contracts\Widget\Slot(grow: 1)));
    $ui->ed = $ed;
    $ui->clip = new class implements Surface\Contracts\Windows\Services\Clipboard
    {
        public ?string $text = null;

        public int $asked = 0;

        public function setText(string $text): void
        {
            $this->text = $text;
        }

        public function requestText(): string
        {
            return 'paste-'.++$this->asked;
        }

        public function hasText(): bool
        {
            return $this->text !== null;
        }
    };
    $ed->setClipboard($ui->clip);
    $ui->focus->focus($ed);

    return $ui;
}

function edCommand(object $ui, EditCommand ...$commands): void
{
    foreach ($commands as $c) {
        $ui->router->dispatch(new SilkEvent(EventType::Edit, command: $c));
    }
}

/** Types each character as its own Text event, as a keyboard does. */
function edType(object $ui, string $text): void
{
    foreach (mb_str_split($text) as $ch) {
        $ui->router->dispatch(new SilkEvent(EventType::Text, text: $ch));
    }
}

/** The clipboard's answer to the editor's last paste request. */
function edPaste(object $ui, string $text): void
{
    edCommand($ui, EditCommand::Paste);
    $ui->ed->handles(new Surface\Contracts\Windows\Mail\Services\ClipboardText('paste-'.$ui->clip->asked, $text));
}

/** The text with the caret as | (and the anchor as ^ when there is a selection). */
function edShow(object $ui): string
{
    $lines = explode("\n", $ui->ed->value());
    [$cl, $cc] = $ui->ed->cursor();
    [$from, $to] = $ui->ed->selection();
    $anchor = $from === [$cl, $cc] ? $to : $from;
    $marks = [[$cl, $cc, '|']];
    $anchor !== [$cl, $cc] && $marks[] = [$anchor[0], $anchor[1], '^'];
    usort($marks, fn ($a, $b) => [$b[0], $b[1]] <=> [$a[0], $a[1]]);
    foreach ($marks as [$l, $c, $m]) {
        $lines[$l] = substr($lines[$l], 0, $c).$m.substr($lines[$l], $c);
    }

    return implode("\n", $lines);
}

function textMail(object $ui): array
{
    return array_values(array_map(fn ($m) => $m->value, array_filter($ui->loop->posted, fn ($m) => $m instanceof Surface\Contracts\Windows\Mail\View\TextChanged)));
}

/** @return list<array{string, mixed}> the editor's paint calls */
function edPaint(object $ui): array
{
    $p = new Venusian\Silk\Tests\Support\SpyPainter;
    $ui->ed->render($p);

    return $p->calls;
}

/** The texts the editor paints, in order. @return list<string> */
function edTexts(object $ui): array
{
    return array_values(array_map(fn ($c) => $c[1][0], array_filter(edPaint($ui), fn ($c) => $c[0] === 'text')));
}

/** The tree point of a position of the editor (its row's middle). @return array{float, float} */
function edPoint(object $ui, int $line, int $column): array
{
    $r = new ReflectionMethod($ui->ed, 'update');
    [, , $area] = $r->invoke($ui->ed);
    $p = new ReflectionMethod($ui->ed, 'pointOf');
    [$x, $y] = $p->invoke($ui->ed, [$line, $column]);
    $lh = (new ReflectionMethod($ui->ed, 'view'))->invoke($ui->ed)[0]->lineHeight;

    return [$area->x + $x - $ui->ed->scrollPosition()->x * 0, $area->y + $y + $lh / 2];
}

function edClick(object $ui, float $x, float $y, int $clicks = 1, bool $shift = false): void
{
    $mods = new Surface\Contracts\HumanInput\Modifiers(shift: $shift);
    $ui->router->dispatch(new SilkEvent(EventType::MouseDown, x: $x, y: $y, button: Surface\Contracts\HumanInput\MouseButton::LEFT, clicks: $clicks, modifiers: $mods));
    $ui->router->dispatch(new SilkEvent(EventType::MouseUp, x: $x, y: $y, button: Surface\Contracts\HumanInput\MouseButton::LEFT, clicks: $clicks));
}

/**
 * Every M5 widget at rest, by name, in the form of m2Widgets() (the theme sweep's dataset).
 *
 * @return array<string, Closure(): array{object, Silk\Core\Tree\Widget, Closure(Silk\Contracts\Look\Look): list<Surface\NutsAndBolts\Color>}>
 */
function m5Widgets(): array
{
    $none = fn () => [];

    return [
        'TextEditor' => function () use ($none) {
            $ui = editorUi("int f(x) {\n    return x; // twice\n}", 'C');
            $ui->ed->setLineNumbers()->addMarker(1, 4, 10, Silk\Text\Editor\MarkerKind::Warning, 'w')->select(1, 4, 1, 10);

            return [$ui, $ui->ed, $none];
        },
        // file_browser.c:860-872: other selected rows are Selection mixed toward Input, 0.2 focused (0.45 not)
        'FileBrowser' => function () {
            $ui = browserUi(fileTree());
            $ui->fb->setMultiple()->select('A.md');
            fbKey($ui, Surface\Contracts\HumanInput\Key::DOWN, new Surface\Contracts\HumanInput\Modifiers(shift: true));
            $mix = function (Silk\Contracts\Look\Look $look) {
                [$s, $i] = [$look->color(Silk\Contracts\Look\Role::Selection), $look->color(Silk\Contracts\Look\Role::Input)];

                // its accept button is primary: on macOS its edges are its fill mixed toward white by lightTop and
                // toward black by shadeBottom (Button.php, platform_looks.c:87-92)
                $body = $look->part('Button', 'Body', Silk\Contracts\Look\State::Normal, ['primary']);
                $edges = [];
                foreach (['lightTop' => new Surface\NutsAndBolts\Color(1, 1, 1), 'shadeBottom' => new Surface\NutsAndBolts\Color(0, 0, 0)] as $key => $toward) {
                    $t = (float) ($body->extra[$key] ?? 0.0);
                    $t > 0 && $body->background !== null && $edges[] = Silk\Core\Motion\LookBlend::mix($body->background, $toward, $t);
                }

                return [...array_map(fn ($t) => Silk\Core\Motion\LookBlend::mix($s, $i, $t), [0.2, 0.45]), ...$edges];
            };

            return [$ui, $ui->fb, $mix];
        },
        // dock_area.c:1336: an active tab's close cross is the text at .6 (bright only under the pointer)
        'DockArea' => function () {
            [$ui, $w] = sweepUi(function ($root) {
                $d = new Silk\Widgets\DockArea('dock');
                $root->add($d, new Silk\Contracts\Widget\Slot(grow: 1));
                $d->addPanel(new Silk\Widgets\Label('a', 'Files'), 'Files');
                $d->addPanel(new Silk\Widgets\Label('b', 'Editor'), 'Editor', Silk\Widgets\Docking\DockSide::Right, 'a');
                $d->addPanel(new Silk\Widgets\Label('c', 'Output'), 'Output', Silk\Widgets\Docking\DockSide::Tab, 'b');

                return $d;
            });
            $faded = function (Silk\Contracts\Look\Look $look) {
                $t = $look->color(Silk\Contracts\Look\Role::Text);

                return array_map(fn ($a) => new Surface\NutsAndBolts\Color($t->red, $t->green, $t->blue, $t->alpha * $a), [0.6, 0.62]);
            };

            return [$ui, $w, $faded];
        },
        // timeline.c:761-821: a tinted clip is its track's colour mixed into the lane (0.2 light, 0.3 dark), its label
        // the colour mixed toward white 0.72 (dark) or black 0.55 (light); past the end the lanes darken with black
        'Timeline' => function () {
            $ui = timelineUi();
            $ui->tl->select($ui->b);
            $extra = function (Silk\Contracts\Look\Look $look) {
                $c = new Silk\Looks\Canvas\CanvasLook($look, Silk\Looks\Canvas\CanvasPreset::Auto);
                [$black, $white] = [new Surface\NutsAndBolts\Color(0, 0, 0), new Surface\NutsAndBolts\Color(1, 1, 1)];
                $out = [$black, $white];
                for ($i = 0; $i < 2; $i++) {
                    $p = $look->part('Timeline', 'Palette'.$i, Silk\Contracts\Look\State::Normal)->background;
                    // (macOS clips are solid: the colour mixed 0.08 toward black)
                    array_push($out, $p, Silk\Painter\RavenPaint::mix($c->input, $p, $c->dark ? 0.3 : 0.2), Silk\Painter\RavenPaint::mix($p, $black, 0.08),
                        Silk\Painter\RavenPaint::mix($p, $white, 0.72), Silk\Painter\RavenPaint::mix($p, $black, 0.55));
                }

                return $out;
            };

            return [$ui, $ui->tl, $extra];
        },
        // island.c:383-466: the capsule's rim and shadows are its row's white and black at the row's alphas; the
        // subtree paints with the dark twin, whose colours the proxy records
        'Island' => function () {
            $ui = islandUi();
            $ui->island->setExpanded(true);
            $ui->tree->layout(new Silk\Contracts\Geometry\Size(400, 400));
            settleAll($ui);

            return [$ui, $ui->island, fn () => []];
        },
        // node_graph.c:313-323, 1045-1180: types are coloured from Raven's palette; black and white are the shadows', the
        // header ink's and the sleeves' constants; the rest comes from the roles and NodeStyles rows
        'NodeGraph' => function () {
            $ui = nodeGraphUi();
            $ui->g->connect($ui->out, $ui->a);
            $ui->g->setNodeBadge($ui->mix, '2');
            $ui->g->selectNode($ui->mix);
            nodeGraphPaint($ui);
            // the body is the Popup (GNOME: the window 0.08 toward the text, white when light); the header is it tinted
            // toward the node's colour (0.2 dark, 0.13 light), a hollow pin's inside it toward black (0.3, 0.04)
            $palette = function (Silk\Contracts\Look\Look $look) {
                [$black, $white] = [new Surface\NutsAndBolts\Color(0, 0, 0), new Surface\NutsAndBolts\Color(1, 1, 1)];
                $c = new Silk\Looks\Canvas\CanvasLook($look, Silk\Looks\Canvas\CanvasPreset::Auto);
                $opaque = fn ($x) => new Surface\NutsAndBolts\Color($x->red, $x->green, $x->blue, 1.0);
                $body = $c->is('gnome') ? ($c->dark ? $opaque(Silk\Painter\RavenPaint::mix($opaque($c->window), $opaque($c->text), 0.08)) : $white) : $opaque($c->popup);

                return [$black, $white, $body, ...array_map(fn ($hex) => Surface\NutsAndBolts\Color::hex($hex), Silk\Widgets\NodeGraph::PALETTE),
                    Silk\Painter\RavenPaint::mix($body, $opaque($c->accent), $c->dark ? 0.2 : 0.13), Silk\Painter\RavenPaint::mix($body, $black, $c->dark ? 0.3 : 0.04)];
            };

            return [$ui, $ui->g, $palette];
        },
    ];
}

/**
 * A folder tree for the file browser: b.txt (2 KB), A.md, c.png, .hidden, docs/ (with inner.txt), Zeta/.
 * Removed at the end of the run.
 */
function fileTree(): string
{
    $root = sys_get_temp_dir().'/silk-fb-'.bin2hex(random_bytes(4));
    mkdir($root.'/docs', 0777, true);
    mkdir($root.'/Zeta');
    file_put_contents($root.'/b.txt', str_repeat('x', 2048));
    file_put_contents($root.'/A.md', 'a');
    file_put_contents($root.'/c.png', 'ccc');
    file_put_contents($root.'/.hidden', '');
    file_put_contents($root.'/docs/inner.txt', 'i');
    touch($root.'/A.md', 1_000_000_000);
    register_shutdown_function(fn () => exec('rm -rf '.escapeshellarg($root)));

    return realpath($root);
}

/** A FileBrowser 'files' showing $dir, focused. */
function browserUi(string $dir): object
{
    $fb = new Silk\Widgets\FileBrowser('files');
    $ui = silkUi(fn ($root) => $root->add($fb, new Silk\Contracts\Widget\Slot(grow: 1)));
    $ui->tree->layout(new Silk\Contracts\Geometry\Size(900, 600));
    $fb->setDirectory($dir);
    $ui->tree->layout(new Silk\Contracts\Geometry\Size(900, 600));
    $ui->focus->focus($fb);
    $ui->fb = $fb;

    return $ui;
}

/** The FileBrowser mail of a kind: the file (or null) of each. */
function browserMail(object $ui, string $class): array
{
    return array_values(array_map(fn ($m) => $m->file ?? null, array_filter($ui->loop->posted, fn ($m) => $m instanceof $class)));
}

function fbKey(object $ui, Surface\Contracts\HumanInput\Key $key, ?Surface\Contracts\HumanInput\Modifiers $mods = null): void
{
    $ui->router->dispatch(new SilkEvent(EventType::KeyDown, key: $key, modifiers: $mods));
}

/** A Timeline 'seq' of 10 s, laid out 900 x 400: tracks Video (clips A 1-3, B 5-7) and Audio (a key at 2), a marker at 4. */
function timelineUi(): object
{
    $tl = new Silk\Widgets\Timeline('seq');
    $ui = silkUi(fn ($root) => $root->add($tl, new Silk\Contracts\Widget\Slot(grow: 1)));
    $ui->video = $tl->addTrack('Video');
    $ui->audio = $tl->addTrack('Audio');
    $ui->a = $tl->addClip($ui->video, 1.0, 2.0, 'A');
    $ui->b = $tl->addClip($ui->video, 5.0, 2.0, 'B');
    $ui->key = $tl->addKey($ui->audio, 2.0);
    $ui->marker = $tl->addMarker(4.0, 'Cue');
    $ui->tree->layout(new Silk\Contracts\Geometry\Size(900, 400));
    $ui->focus->focus($tl);
    $ui->tl = $tl;

    return $ui;
}

/** The mail of a Timeline kind: the item (or time) of each, or the count when it has none. */
function timelineMail(object $ui, string $class): array
{
    return array_values(array_map(fn ($m) => $m->item ?? $m->time ?? true, array_filter($ui->loop->posted, fn ($m) => $m instanceof $class)));
}

/** Down at a point, moves to another in steps, up there. */
function tlDrag(object $ui, array $from, array $to, ?Surface\Contracts\HumanInput\Modifiers $mods = null): void
{
    $ui->router->dispatch(new SilkEvent(EventType::MouseDown, x: $from[0], y: $from[1], button: Surface\Contracts\HumanInput\MouseButton::LEFT, clicks: 1, modifiers: $mods));
    foreach ([0.5, 1.0] as $t) {
        $ui->router->dispatch(new SilkEvent(EventType::MouseMove, x: $from[0] + ($to[0] - $from[0]) * $t, y: $from[1] + ($to[1] - $from[1]) * $t, modifiers: $mods));
    }
    $ui->router->dispatch(new SilkEvent(EventType::MouseUp, x: $to[0], y: $to[1], button: Surface\Contracts\HumanInput\MouseButton::LEFT, modifiers: $mods));
}

/** Runs the timeline's tree for $seconds at 60 frames, laid out at its own 900 x 400. */
function tlRun(object $ui, float $seconds): void
{
    for ($t = 0.0; $t < $seconds - 1e-9; $t += 1 / 60) {
        $ui->tree->advance(1 / 60);
        $ui->tree->layout(new Silk\Contracts\Geometry\Size(900, 400));
    }
}
