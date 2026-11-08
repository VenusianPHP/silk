<?php

namespace Silk\Widgets;

use Silk\Painter\Surfaces;
use Silk\Contracts\Look\SurfaceKind;
use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Look\FontSpec;
use Silk\Contracts\Look\Metric;
use Silk\Contracts\Look\Role;
use Silk\Contracts\Look\State;
use Silk\Contracts\Mail\FileAccepted;
use Silk\Contracts\Mail\FileCancelled;
use Silk\Contracts\Mail\FileSelectionChanged;
use Silk\Contracts\Painter\Painter;
use Silk\Contracts\Widget\Widget as WidgetContract;
use Silk\Core\Motion\LookBlend;
use Silk\Core\Motion\Spring;
use Silk\Core\Motion\SpringPreset;
use Silk\Core\Motion\Transition;
use Silk\Core\Tree\Container;
use Silk\Files\FileSystem;
use Silk\Painter\Icons;
use Silk\Widgets\Concerns\MeasuresText;
use Silk\Widgets\Rows\RowScroll;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\MouseButton;
use Surface\Contracts\Windows\Mail\View\ButtonClicked;
use Surface\Contracts\Windows\Mail\View\SelectionChanged;
use Surface\Contracts\Windows\Mail\View\TextSubmitted;
use Surface\NutsAndBolts\Color;

/**
 * An explorer of the files of the computer, as Raven's widgets/file_browser.c: the list of files and the
 * navigation buttons it draws itself; the path, places, name, filter and buttons are ordinary widgets it owns
 * and arranges by hand. Mail: FileAccepted, FileCancelled, FileSelectionChanged.
 */
class FileBrowser extends Container
{
    use MeasuresText;

    public const string OPEN = 'open';

    public const string SAVE = 'save';

    public const string FOLDER = 'folder';

    /** file_browser.c:20-24 */
    public const float GAP = 8.0;

    public const float PLACES_WIDTH = 150.0;

    public const float SIZE_WIDTH = 84.0;

    public const float DATE_WIDTH = 132.0;

    public const int HISTORY_MAX = 64;

    private const int BACK = 0;

    private const int FORWARD = 1;

    private const int UP = 2;

    private const int NAME = 0;

    private const int SIZE = 1;

    private const int DATE = 2;

    private string $mode = self::OPEN;

    private ?string $directory = null;

    /** @var list<array{name: string, isDir: bool, hidden: bool, size: int, modified: int}> the entries shown: filtered, sorted */
    private array $entries = [];

    /** One byte a entry, '1' selected (as TileView). */
    private string $selected = '';

    /** Entry the keyboard is on, entry a range started from, entry under the pointer; -1 none. */
    private int $cursor = -1;

    private int $anchor = -1;

    private int $hot = -1;

    private int $sortColumn = self::NAME;

    private bool $sortDescending = false;

    private bool $showHidden = false;

    private bool $multiple = false;

    private bool $controlsHidden = false;

    private bool $placesHidden = false;

    /** @var list<array{string, string}> [label, patterns] */
    private array $filters = [];

    private int $filterIndex = 0;

    private ?string $defaultExtension = null;

    /** @var list<array{label: string, path: string}> */
    private array $places;

    /** @var list<string> folders visited, for Back and Forward */
    private array $history = [];

    private int $historyAt = -1;

    private TextInput $pathInput;

    private ListView $placesList;

    private TextInput $nameInput;

    private Dropdown $filterBox;

    private Button $acceptButton;

    private Button $cancelButton;

    /** While the browser itself changes its fields: what they say is not acted on. */
    private int $updating = 0;

    /** @var list<Rect> */
    private array $nav = [];

    private Rect $list;

    private float $headerHeight = 0.0;

    private RowScroll $bar;

    private Spring $selectY;

    private bool $selectPlaced = false;

    private int $navHot = -1;

    private int $navDown = -1;

    /** @var list<Spring> */
    private array $navHover = [];

    /** @var list<Spring> */
    private array $navPress = [];

    private bool $hasFocus = false;

    /** The place of the folder shown, selected in the places list at the next arrange. */
    private ?int $place = null;

    private bool $hovered = false;

    /** Typing the first letters of a name moves to it. */
    private string $typed = '';

    private float $typedAt = -10.0;

    /** Seconds, monotonic; tests set their own. */
    public static ?\Closure $clock = null;

    public function __construct(string $name)
    {
        parent::__construct($name);
        $this->focusable = true;
        $this->bar = new RowScroll;
        $this->selectY = new Spring(0.0, SpringPreset::Snappy);
        $this->list = new Rect(0, 0, 0, 0);
        for ($i = 0; $i < 3; $i++) {
            $this->navHover[] = new Spring(0.0, SpringPreset::Bouncy);
            $this->navPress[] = new Spring(0.0, SpringPreset::Snappy);
            $this->nav[] = new Rect(0, 0, 0, 0);
        }
        $this->places = FileSystem::places();
        $this->add($this->pathInput = new TextInput('path'));
        $this->add($this->placesList = new ListView('places', array_column($this->places, 'label')));
        $this->add($this->nameInput = new TextInput('name', '', 'File name'));
        $this->add($this->filterBox = new Dropdown('filter', [''], 0));
        $this->add($this->cancelButton = new Button('cancel', 'Cancel'));
        // Raven's accept button is "Style Primary" (file_browser.c:571)
        $this->add($this->acceptButton = (new Button('accept', 'Open'))->setStyle(ButtonStyle::Primary));
        $this->navigate(FileSystem::home(), true);
    }

    // ------------------------------------------------------------------ API

    /** OPEN, SAVE or FOLDER (Rfile_browser_set_mode). */
    public function setMode(string $mode): static
    {
        if (! in_array($mode, [self::OPEN, self::SAVE, self::FOLDER], true)) {
            throw new \InvalidArgumentException("A file browser's mode is open, save or folder, not '{$mode}'.");
        }
        if ($mode !== $this->mode) {
            $this->mode = $mode;
            $this->nameInput->setPlaceholder($mode === self::FOLDER ? 'Folder' : 'File name');
            $this->load();
            $this->syncFields();
        }

        return $this;
    }

    public function mode(): string
    {
        return $this->mode;
    }

    /** Shows a folder (a file: its folder, the file selected); false when the path does not exist. */
    public function setDirectory(string $path): bool
    {
        $absolute = FileSystem::absolute($path);
        if ($absolute === null || ! FileSystem::exists($absolute)) {
            return false;
        }
        $this->navigate($path, true);

        return true;
    }

    public function directory(): string
    {
        return $this->directory ?? '';
    }

    /** Reads the folder again. */
    public function refresh(): static
    {
        $this->load();

        return $this;
    }

    /**
     * The filters of the filter box (Rfile_browser_set_filters): [name, "*.png;*.jpg"] each; the first is active.
     *
     * @param  list<array{?string, ?string}>  $filters
     */
    public function setFilters(array $filters): static
    {
        $this->filters = [];
        $labels = [];
        foreach ($filters as [$name, $patterns]) {
            $patterns = $patterns ?? '*';
            // "Images (*.png;*.jpg)"
            $label = $name !== null && $name !== '' ? "{$name} ({$patterns})" : $patterns;
            $this->filters[] = [$label, $patterns];
            $labels[] = $label;
        }
        $this->filterIndex = 0;
        $this->updating++;
        $this->filterBox->setOptions($labels === [] ? [''] : $labels)->select(0);
        $this->updating--;
        $this->load();
        $this->syncFields();

        return $this;
    }

    public function setShowHidden(bool $show = true): static
    {
        $this->showHidden = $show;
        $this->load();

        return $this;
    }

    public function setMultiple(bool $multiple = true): static
    {
        $this->multiple = $multiple;

        return $this;
    }

    public function setControlsVisible(bool $visible = true): static
    {
        $this->controlsHidden = ! $visible;
        $this->syncFields();

        return $this;
    }

    public function setPlacesVisible(bool $visible = true): static
    {
        $this->placesHidden = ! $visible;
        $this->syncFields();

        return $this;
    }

    public function setFileName(string $name): static
    {
        $this->setField($this->nameInput, $name);

        return $this;
    }

    public function setDefaultExtension(?string $extension): static
    {
        $this->defaultExtension = $extension;

        return $this;
    }

    /** @return list<string> the paths selected */
    public function selection(): array
    {
        $out = [];
        foreach ($this->entries as $i => $entry) {
            $this->selected[$i] === '1' && $out[] = FileSystem::child($this->directory, $entry['name']);
        }

        return $out;
    }

    public function selectionCount(): int
    {
        return substr_count($this->selected, '1');
    }

    /** Puts the cursor on an entry of the folder by its name. */
    public function select(string $name): static
    {
        foreach ($this->entries as $i => $entry) {
            if ($entry['name'] === $name) {
                $this->selectRow($i, false, false);
                break;
            }
        }

        return $this;
    }

    /** @return list<string> the names shown, in order */
    public function names(): array
    {
        return array_column($this->entries, 'name');
    }

    public function cursor(): int
    {
        return $this->cursor;
    }

    /** The fields it owns, for those who drive it: path, places, name, filter, accept, cancel. */
    public function field(string $name): WidgetContract
    {
        return match ($name) {
            'path' => $this->pathInput, 'places' => $this->placesList, 'name' => $this->nameInput,
            'filter' => $this->filterBox, 'accept' => $this->acceptButton, 'cancel' => $this->cancelButton,
        };
    }

    // -------------------------------------------------------------- entries

    private function patterns(): ?string
    {
        return $this->filters[$this->filterIndex][1] ?? null;
    }

    /** Reads the folder again (rvn_fb_load, file_browser.c:148-189); an unreadable folder is empty. */
    private function load(): bool
    {
        // the entry the cursor is on stays selected through a refresh
        $keep = $this->entries[$this->cursor]['name'] ?? null;
        $all = $this->directory === null ? null : FileSystem::read($this->directory);
        $kept = [];
        foreach ($all ?? [] as $entry) {
            $show = $this->showHidden || ! $entry['hidden'];
            if ($show && ! $entry['isDir']) {
                $show = $this->mode !== self::FOLDER && FileSystem::matches($this->patterns(), $entry['name']);
            }
            $show && $kept[] = $entry;
        }
        usort($kept, fn ($x, $y) => $this->compare($x, $y));
        $this->entries = $kept;
        $this->selected = str_repeat('0', count($kept));
        $this->cursor = $this->anchor = $this->hot = -1;
        if ($keep !== null) {
            foreach ($kept as $i => $entry) {
                if ($entry['name'] === $keep) {
                    $this->cursor = $this->anchor = $i;
                    $this->selected[$i] = '1';
                    break;
                }
            }
        }
        $this->bar->set(0.0);
        $this->bar->shown->snap(0.0);
        $this->selectPlaced = false;
        $this->invalidateLayout();

        return $all !== null;
    }

    /** Folders first, whatever the order; then the column, the name breaking ties (rvn_fb_compare, file_browser.c:113-126). */
    private function compare(array $x, array $y): int
    {
        if ($x['isDir'] !== $y['isDir']) {
            return $x['isDir'] ? -1 : 1;
        }
        $result = 0;
        if ($this->sortColumn === self::SIZE && ! $x['isDir']) {
            $result = $x['size'] <=> $y['size'];
        } elseif ($this->sortColumn === self::DATE) {
            $result = $x['modified'] <=> $y['modified'];
        }
        if ($result === 0) {
            $result = strcasecmp($x['name'], $y['name']) ?: strcmp($x['name'], $y['name']);
            if ($this->sortColumn !== self::NAME) {
                return $result;
            }
        }

        return $this->sortDescending ? -$result : $result;
    }

    private function entryPath(int $index): string
    {
        return FileSystem::child($this->directory, $this->entries[$index]['name']);
    }

    // --------------------------------------------------------------- fields

    private function setField(TextInput|Button $field, string $text): void
    {
        $this->updating++;
        $field instanceof Button ? $field->setLabel($text) : $field->setValue($text);
        $this->updating--;
    }

    private function acceptLabel(): string
    {
        return match ($this->mode) {
            self::SAVE => 'Save',
            self::FOLDER => 'Select folder',
            default => 'Open',
        };
    }

    /** Path, places, button label and the controls in line with the state (rvn_fb_sync_fields, file_browser.c:213-235). */
    private function syncFields(): void
    {
        $this->setField($this->pathInput, $this->directory ?? '');
        $this->setField($this->acceptButton, $this->acceptLabel());
        foreach ([$this->nameInput, $this->acceptButton, $this->cancelButton] as $field) {
            $field->setVisible(! $this->controlsHidden);
        }
        $this->filterBox->setVisible(! ($this->controlsHidden || $this->filters === [] || $this->mode === self::FOLDER));
        $this->placesList->setVisible(! $this->placesHidden);
        $place = null;
        foreach ($this->places as $i => $p) {
            $p['path'] === $this->directory && $place = $i;
        }
        // (the list selects in a tree with a look: applied at the next arrange)
        $this->place = $place;
        $this->invalidateLayout();
    }

    /** A field that cannot take what was asked shakes its head. */
    private function refuse(WidgetContract $field): void
    {
        $field->playTransition(Transition::Pop);
    }

    private function notifySelection(): void
    {
        // the name of what is selected goes to the name field, when it is the kind of thing being chosen
        if ($this->cursor >= 0 && $this->entries[$this->cursor]['isDir'] === ($this->mode === self::FOLDER)) {
            $this->setField($this->nameInput, $this->entries[$this->cursor]['name']);
        }
        $this->invalidatePaint();
        $this->post(new FileSelectionChanged($this->window(), $this->path(), $this->uuid(), $this->cursor >= 0 ? $this->entryPath($this->cursor) : null));
    }

    // ----------------------------------------------------------- navigation

    /** rvn_fb_navigate (file_browser.c:257-307). */
    private function navigate(string $path, bool $remember): void
    {
        $absolute = FileSystem::absolute($path);
        if ($absolute === null) {
            return;
        }
        $select = null;
        // a path to a file shows its folder and selects the file
        if (FileSystem::exists($absolute) && ! FileSystem::isDir($absolute)) {
            $select = FileSystem::name($absolute);
            $absolute = FileSystem::parent($absolute);
            if ($absolute === null) {
                return;
            }
        }
        if (! FileSystem::isDir($absolute)) {
            $this->setField($this->pathInput, $this->directory ?? '');
            $this->refuse($this->pathInput);

            return;
        }
        if ($absolute !== $this->directory) {
            $this->directory = $absolute;
            if ($remember) {
                // a new visit forgets where Forward was going
                $this->history = array_slice($this->history, 0, $this->historyAt + 1);
                count($this->history) === self::HISTORY_MAX && array_shift($this->history);
                $this->history[] = $absolute;
                $this->historyAt = count($this->history) - 1;
            }
            $this->entries = [];
            $this->cursor = -1;
        }
        $this->load();
        if ($select !== null) {
            foreach ($this->entries as $i => $entry) {
                if ($entry['name'] === $select) {
                    $this->selected = str_repeat('0', count($this->entries));
                    $this->selected[$i] = '1';
                    $this->cursor = $this->anchor = $i;
                }
            }
            $this->setField($this->nameInput, $select);
        }
        $this->syncFields();
        $this->notifySelection();
    }

    private function navEnabled(int $button): bool
    {
        return match ($button) {
            self::BACK => $this->historyAt > 0,
            self::FORWARD => $this->historyAt + 1 < count($this->history),
            default => $this->directory !== null && FileSystem::parent($this->directory) !== null,
        };
    }

    private function nav(int $button): void
    {
        if (! $this->navEnabled($button)) {
            return;
        }
        if ($button === self::UP) {
            $from = $this->directory;
            $this->navigate(FileSystem::parent($from), true);
            // the folder that was left is the one selected in its parent
            $this->select(FileSystem::name($from));

            return;
        }
        $this->historyAt += $button === self::BACK ? -1 : 1;
        $this->navigate($this->history[$this->historyAt], false);
    }

    // -------------------------------------------------------------- choosing

    private function accepted(string $path): void
    {
        $this->post(new FileAccepted($this->window(), $this->path(), $this->uuid(), $path));
    }

    /** What the name field means: a path, relative to the folder shown unless it is absolute. */
    private function resolve(string $name): ?string
    {
        return FileSystem::absolute($name[0] === '~' || $name[0] === '/' ? $name : FileSystem::child($this->directory, $name));
    }

    /** The name with the default extension when typed without one (file_browser.c:385-411). */
    private function withExtension(string $name): string
    {
        if (str_contains(FileSystem::name($name), '.')) {
            return $name;
        }
        $extension = $this->defaultExtension;
        $patterns = $this->patterns();
        if (($extension === null || $extension === '') && $patterns !== null && str_starts_with($patterns, '*.')) {
            // the first pattern of the active filter: "*.xml;*.ui" -> "xml"
            $extension = substr(substr($patterns, 2), 0, min(31, strcspn(substr($patterns, 2), ';,*')));
        }
        if ($extension === null || $extension === '') {
            return $name;
        }

        return $name.'.'.ltrim($extension, '.');
    }

    /** rvn_fb_accept (file_browser.c:419-476). */
    private function accept(): void
    {
        $name = ltrim($this->nameInput->value(), ' ');
        if ($this->directory === null) {
            return;
        }
        if ($this->mode === self::FOLDER) {
            if ($name === '') {
                $this->accepted($this->directory);

                return;
            }
            $path = $this->resolve($name);
            $path !== null && FileSystem::isDir($path) ? $this->accepted($path) : $this->refuse($this->nameInput);

            return;
        }
        if ($name === '') {
            // nothing typed: Open enters the folder the cursor is on
            if ($this->cursor >= 0 && $this->entries[$this->cursor]['isDir']) {
                $this->navigate($this->entryPath($this->cursor), true);
            } else {
                $this->refuse($this->nameInput);
            }

            return;
        }
        $path = $this->resolve($this->mode === self::SAVE ? $this->withExtension($name) : $name);
        if ($path === null) {
            return;
        }
        if (FileSystem::isDir($path)) {
            $this->setField($this->nameInput, '');
            $this->navigate($path, true);
        } elseif ($this->mode === self::OPEN) {
            FileSystem::exists($path) ? $this->accepted($path) : $this->refuse($this->nameInput);
        } elseif (FileSystem::exists($path) && $this->tree() !== null) {
            MessageBox::show($this->tree(), 'Replace the file?', '"'.FileSystem::name($path)."\" already exists.\nDo you want to replace it?",
                ['Yes', 'No'], fn (int $answer) => $answer === 0 && $this->accepted($path));
        } else {
            $this->accepted($path);
        }
    }

    /** A double click or Enter on an entry (rvn_fb_activate, file_browser.c:479-493). */
    private function activate(int $index): void
    {
        if ($index < 0 || $index >= count($this->entries)) {
            return;
        }
        $entry = $this->entries[$index];
        if ($entry['isDir']) {
            $this->navigate($this->entryPath($index), true);
        } elseif ($this->mode === self::SAVE) {
            $this->setField($this->nameInput, $entry['name']);
            $this->accept();
        } else {
            $this->accepted($this->entryPath($index));
        }
    }

    /** The fields' mail is the browser's to act on (file_browser.c:499-545); none of it goes on. */
    public function childMail(WidgetContract $from, object $mail): bool
    {
        if (! in_array($from, [$this->pathInput, $this->placesList, $this->nameInput, $this->filterBox, $this->acceptButton, $this->cancelButton], true)) {
            return false;
        }
        if ($this->updating > 0) {
            return true;
        }
        match (true) {
            $from === $this->pathInput && $mail instanceof TextSubmitted => $this->navigate($mail->value, true),
            $from === $this->nameInput && $mail instanceof TextSubmitted => $this->accept(),
            $from === $this->placesList && $mail instanceof SelectionChanged => $mail->index >= 0 && isset($this->places[$mail->index])
                && $this->navigate($this->places[$mail->index]['path'], true),
            $from === $this->filterBox && $mail instanceof SelectionChanged => $this->filterChosen($mail->index),
            $from === $this->acceptButton && $mail instanceof ButtonClicked => $this->accept(),
            $from === $this->cancelButton && $mail instanceof ButtonClicked => $this->post(new FileCancelled($this->window(), $this->path(), $this->uuid())),
            default => null,
        };

        return true;
    }

    private function filterChosen(int $index): void
    {
        $this->filterIndex = $index;
        $this->load();
    }

    // --------------------------------------------------------------- layout

    private function rowHeight(): float
    {
        return $this->look()->metric(Metric::RowHeight);
    }

    /** rvn_file_browser_measure: 720 x 440, smaller without the controls (file_browser.c:607-614). */
    protected function measureContent(Size $available): Size
    {
        foreach ($this->children() as $child) {
            $child->measure($available);
        }
        if ($this->controlsHidden) {
            return new Size($this->placesHidden ? 300.0 : 460.0, 320.0);
        }

        return new Size(720.0, 440.0);
    }

    /** rvn_file_browser_arrange (file_browser.c:616-656). */
    protected function arrangeContent(Rect $r): void
    {
        $control = $this->look()->metric(Metric::ControlHeight);
        $top = $control + self::GAP;
        $bottom = $r->h;
        $x = 0.0;
        for ($i = 0; $i < 3; $i++) {
            $this->nav[$i] = new Rect($r->x + $x, $r->y, $control, $control);
            $x += $control + 4.0;
        }
        $x += 4.0;
        $this->pathInput->arrange(new Rect($r->x + $x, $r->y, max(0.0, $r->w - $x), $control));
        if (! $this->controlsHidden) {
            $accept = max($this->acceptButton->measure(new Size($r->w, $control))->w, 96.0);
            $cancel = max($this->cancelButton->measure(new Size($r->w, $control))->w, 84.0);
            $filter = $this->filterBox->isVisible() ? min(210.0, $r->w * 0.35) : 0.0;
            $row = $r->h - $control;
            $this->acceptButton->arrange(new Rect($r->x + $r->w - $accept, $r->y + $row, $accept, $control));
            $this->cancelButton->arrange(new Rect($r->x + $r->w - $accept - self::GAP - $cancel, $r->y + $row, $cancel, $control));
            $row -= $control + self::GAP;
            $filter > 0 && $this->filterBox->arrange(new Rect($r->x + $r->w - $filter, $r->y + $row, $filter, $control));
            $this->nameInput->arrange(new Rect($r->x, $r->y + $row, max(0.0, $r->w - ($filter > 0 ? $filter + self::GAP : 0.0)), $control));
            $bottom = $row - self::GAP;
        }
        // the places leave when the browser is too narrow for them
        $listX = 0.0;
        if (! $this->placesHidden && $r->w >= 420.0) {
            $this->placesList->arrange(new Rect($r->x, $r->y + $top, self::PLACES_WIDTH, max(0.0, $bottom - $top)));
            $listX = self::PLACES_WIDTH + self::GAP;
        } else {
            $this->placesList->arrange(new Rect($r->x, $r->y + $top, 0.0, 0.0));
        }
        $this->list = new Rect($r->x + $listX, $r->y + $top, max(0.0, $r->w - $listX), max(0.0, $bottom - $top));
        $this->headerHeight = $this->rowHeight() + 2.0;
        $this->syncBar();
        if ($this->placesList->selected() !== $this->place) {
            $this->updating++;
            $this->placesList->select($this->place);
            $this->updating--;
        }
    }

    private function syncBar(): void
    {
        $this->bar->content = $this->rowHeight() * count($this->entries);
        $this->bar->viewport = max(0.0, $this->list->h - $this->headerHeight - 2.0);
        $this->bar->clamp();
    }

    private function track(): Rect
    {
        $bar = $this->look()->metric(Metric::ScrollbarSize);

        return new Rect($this->list->x + $this->list->w - $bar - 1, $this->list->y + $this->headerHeight, $bar, max(0.0, $this->list->h - $this->headerHeight - 1));
    }

    private function rowsTop(): float
    {
        return $this->list->y + $this->headerHeight;
    }

    /** The entry under a point, or -1 (rvn_fb_row_at). */
    public function rowAt(float $x, float $y): int
    {
        $l = $this->list;
        if ($x < $l->x || $x >= $l->x + $l->w || $y < $this->rowsTop() || $y >= $l->y + $l->h - 1.0) {
            return -1;
        }
        $row = (int) floor(($y - $this->rowsTop() + $this->bar->offset) / $this->rowHeight());

        return $row >= 0 && $row < count($this->entries) ? $row : -1;
    }

    /** The header column under a point, or -1 (rvn_fb_column_at). */
    private function columnAt(float $x, float $y): int
    {
        $l = $this->list;
        $right = $l->x + $l->w;
        if ($x < $l->x || $x >= $right || $y < $l->y || $y >= $l->y + $this->headerHeight) {
            return -1;
        }
        if ($l->w < 380.0) {
            return self::NAME;
        }

        return $x >= $right - self::DATE_WIDTH ? self::DATE : ($x >= $right - self::DATE_WIDTH - self::SIZE_WIDTH ? self::SIZE : self::NAME);
    }

    private function navAt(float $x, float $y): int
    {
        foreach ($this->nav as $i => $rect) {
            if ($rect->contains($x, $y)) {
                return $i;
            }
        }

        return -1;
    }

    /** @return array{float, float} the centre of an entry's row, for those who drive it */
    public function rowPoint(int $row): array
    {
        return [$this->list->x + 40.0, $this->rowsTop() + $this->rowHeight() * ($row + 0.5) - $this->bar->offset];
    }

    /** @return array{float, float} the centre of a navigation button: 'back', 'forward', 'up' */
    public function navPoint(string $button): array
    {
        $r = $this->nav[['back' => 0, 'forward' => 1, 'up' => 2][$button]];

        return [$r->x + $r->w / 2, $r->y + $r->h / 2];
    }

    private function reveal(int $row): void
    {
        $height = $this->rowHeight();
        $top = $height * $row;
        $this->syncBar();
        if ($top < $this->bar->offset) {
            $this->bar->set($top);
        } elseif ($top + $height > $this->bar->offset + $this->bar->viewport) {
            $this->bar->set($top + $height - $this->bar->viewport);
        }
        $this->invalidatePaint();
    }

    /** The cursor to a row: alone, toggled, or the end of a range (rvn_fb_select_row, file_browser.c:731-751). */
    private function selectRow(int $row, bool $toggle, bool $range): void
    {
        if ($row < 0 || $row >= count($this->entries)) {
            return;
        }
        if (! $this->multiple) {
            $toggle = $range = false;
        }
        if ($range && $this->anchor >= 0) {
            [$from, $to] = [min($this->anchor, $row), max($this->anchor, $row)];
            $this->selected = str_repeat('0', $from).str_repeat('1', $to - $from + 1).str_repeat('0', count($this->entries) - $to - 1);
        } elseif ($toggle) {
            $this->selected[$row] = $this->selected[$row] === '1' ? '0' : '1';
            $this->anchor = $row;
        } else {
            $this->selected = str_repeat('0', count($this->entries));
            $this->selected[$row] = '1';
            $this->anchor = $row;
        }
        $this->cursor = $row;
        $this->reveal($row);
        $this->notifySelection();
    }

    // --------------------------------------------------------------- events

    private function now(): float
    {
        return self::$clock !== null ? (self::$clock)() : microtime(true);
    }

    /** Typing the start of a name moves to the first entry that has it, within 0.9 s (rvn_fb_type). */
    private function typeAhead(string $text): bool
    {
        $now = $this->now();
        if ($now - $this->typedAt > 0.9 || strlen($this->typed) + strlen($text) >= 32) {
            $this->typed = '';
        }
        $this->typed .= $text;
        $this->typedAt = $now;
        foreach ($this->entries as $i => $entry) {
            if (strncasecmp($entry['name'], $this->typed, strlen($this->typed)) === 0) {
                $this->selectRow($i, false, false);
                break;
            }
        }

        return true;
    }

    /** rvn_file_browser_event (file_browser.c:930-1060); the fields handle their own events. */
    public function handle(SilkEvent $e): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }
        $own = ! $this->overChild($e);
        if ($own && in_array($e->type, [EventType::MouseDown, EventType::MouseMove, EventType::MouseUp], true)) {
            $this->syncBar();
            if ($this->bar->event($e, $this->track(), true)) {
                $this->invalidatePaint();

                return true;
            }
        }
        $count = count($this->entries);
        switch ($e->type) {
            case EventType::Enter:
                $this->hovered = true;

                return false;
            case EventType::MouseMove:
                $nav = $own ? $this->navAt($e->x, $e->y) : -1;
                $row = $own ? $this->rowAt($e->x, $e->y) : -1;
                if ($row !== $this->hot || $nav !== $this->navHot) {
                    [$this->hot, $this->navHot] = [$row, $nav];
                    $this->invalidatePaint();
                }

                return $this->navDown >= 0;
            case EventType::Leave:
                $this->hot = -1;
                $this->hovered = false;
                $this->navDown < 0 && $this->navHot = -1;
                $this->invalidatePaint();

                return false;
            case EventType::MouseDown:
                // the side buttons of a mouse go back and forward
                if ($e->button === MouseButton::X1 || $e->button === MouseButton::X2) {
                    $which = $e->button === MouseButton::X1 ? self::BACK : self::FORWARD;
                    if (! $this->navEnabled($which)) {
                        return false;
                    }
                    $this->nav($which);

                    return true;
                }
                if (! $own || $e->button !== MouseButton::LEFT) {
                    return false;
                }
                $nav = $this->navAt($e->x, $e->y);
                if ($nav >= 0) {
                    $this->navDown = $this->navHot = $nav;
                    $this->invalidatePaint();

                    return true;
                }
                $column = $this->columnAt($e->x, $e->y);
                if ($column >= 0) {
                    // a second click on the same column reverses the order
                    $this->sortDescending = $this->sortColumn === $column ? ! $this->sortDescending : false;
                    $this->sortColumn = $column;
                    $this->load();

                    return true;
                }
                $row = $this->rowAt($e->x, $e->y);
                if ($row >= 0) {
                    $e->clicks === 2 ? $this->activate($row) : $this->selectRow($row, $e->modifiers->ctrl || $e->modifiers->meta, $e->modifiers->shift);
                }

                return true;
            case EventType::MouseUp:
                $down = $this->navDown;
                if ($e->button !== MouseButton::LEFT || $down < 0) {
                    return false;
                }
                $this->navDown = -1;
                $this->invalidatePaint();
                if ($this->navAt($e->x, $e->y) === $down) {
                    $this->navHover[$down]->kick(6.0);
                    $this->nav($down);
                }

                return true;
            case EventType::Wheel:
                if (! $own) {
                    return false;
                }
                $this->syncBar();
                if (! $this->bar->wheel($e->wheelY * $this->rowHeight() * 3.0)) {
                    $this->invalidatePaint();

                    return false;
                }
                $this->hot = $this->rowAt($e->x, $e->y);
                $this->invalidatePaint();

                return true;
            case EventType::FocusGained:
            case EventType::FocusLost:
                $this->hasFocus = $e->type === EventType::FocusGained;
                $this->invalidatePaint();

                return false;
            case EventType::Text:
                return $this->hasFocus && $e->text !== null && ! $e->modifiers->ctrl && ! $e->modifiers->alt && mb_ord($e->text) >= 32 && $this->typeAhead($e->text);
            case EventType::KeyDown:
                return $this->hasFocus && $this->key($e, $count);
            default:
                return false;
        }
    }

    private function key(SilkEvent $e, int $count): bool
    {
        $page = max(1, (int) ($this->bar->viewport / $this->rowHeight()) - 1);
        $ctrl = $e->modifiers->ctrl || $e->modifiers->meta;
        $row = match ($e->key) {
            Key::UP => $this->cursor < 0 ? $count - 1 : $this->cursor - 1,
            Key::DOWN => $this->cursor + 1,
            Key::PAGE_UP => $this->cursor - $page,
            Key::PAGE_DOWN => $this->cursor + $page,
            Key::HOME => 0,
            Key::END => $count - 1,
            default => null,
        };
        if ($row === null) {
            switch ($e->key) {
                case Key::ENTER:
                case Key::NUMPAD_ENTER:
                    $this->cursor >= 0 && $this->activate($this->cursor);

                    return true;
                case Key::BACKSPACE:
                    $this->nav(self::UP);

                    return true;
                case Key::LEFT:
                case Key::RIGHT:
                    if (! $e->modifiers->alt) {
                        return false;
                    }
                    $this->nav($e->key === Key::LEFT ? self::BACK : self::FORWARD);

                    return true;
                case Key::F5:
                    $this->load();

                    return true;
                case Key::H:
                    if (! $ctrl) {
                        return false;
                    }
                    $this->showHidden = ! $this->showHidden;
                    $this->load();

                    return true;
                case Key::A:
                    if (! $ctrl || ! $this->multiple) {
                        return false;
                    }
                    $this->selected = implode('', array_map(fn ($entry) => $entry['isDir'] ? '0' : '1', $this->entries));
                    $this->notifySelection();

                    return true;
                default:
                    return false;
            }
        }
        $count > 0 && $this->selectRow(max(0, min($count - 1, $row)), false, $e->modifiers->shift);

        return true;
    }

    /** Is the event's point over one of the fields (they take their own)? */
    private function overChild(SilkEvent $e): bool
    {
        if (! $e->type->isPointer()) {
            return false;
        }
        foreach ($this->children() as $child) {
            if ($child->isVisible() && $child->bounds()->contains($e->x, $e->y)) {
                return true;
            }
        }

        return false;
    }

    public function springs(): array
    {
        return [...$this->bar->springs(), $this->selectY, ...$this->navHover, ...$this->navPress];
    }

    // ---------------------------------------------------------------- paint

    /** "%u B", or "%.1f KB" under 10, "%.0f MB" above (rvn_fb_format_size). */
    public static function formatSize(int $size): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float) $size;
        $unit = 0;
        while ($value >= 1024.0 && $unit < 4) {
            $value /= 1024.0;
            $unit++;
        }
        if ($unit === 0) {
            return "{$size} B";
        }

        return sprintf($value < 10.0 ? '%.1f %s' : '%.0f %s', $value, $units[$unit]);
    }

    private function textIn(Painter $p, string $text, Rect $r, float $align, Color $color, ?FontSpec $font = null): void
    {
        $font ??= $this->textStyle();
        $w = $this->measurer()->measure($text, $font)->w;
        $p->text($text, $r->x + max(0.0, $r->w - $w) * $align, $this->baselineIn($r, $font), $color, $font);
    }

    /** A folder or a sheet of paper, in a 16 x 14 box (rvn_fb_paint_icon, file_browser.c:770-783). */
    private function icon(Painter $p, bool $isDir, float $x, float $y, Color $accent, Color $text): void
    {
        if ($isDir) {
            $p->rect(new Rect($x, $y, 7.5, 5.0), $accent, 1.5);
            $p->rect(new Rect($x, $y + 2.5, 16.0, 11.0), $accent, 2.0);
            $shine = $this->look()->part('FileBrowser', 'FolderShine', State::Normal)->background;
            $shine !== null && $p->rect(new Rect($x + 1.0, $y + 4.5, 14.0, 1.0), $shine);

            return;
        }
        $line = self::fade($text, 0.55);
        $p->rect(new Rect($x + 2.5, $y - 0.5, 11.0, 14.5), self::fade($text, 0.08), 2.0, $line, 1.0);
        $p->rect(new Rect($x + 5.0, $y + 4.0, 6.0, 1.0), $line);
        $p->rect(new Rect($x + 5.0, $y + 7.0, 6.0, 1.0), $line);
        $p->rect(new Rect($x + 5.0, $y + 10.0, 4.0, 1.0), $line);
    }

    /** rvn_fb_paint_nav (file_browser.c:785-818). */
    private function paintNav(Painter $p, int $button, Color $text): void
    {
        $look = $this->look();
        $r = $this->nav[$button];
        $enabled = $this->isEnabled() && $this->navEnabled($button);
        $hover = $this->navHover[$button]->target($enabled && $this->navHot === $button ? 1.0 : 0.0)->value();
        $press = $this->navPress[$button]->target($enabled && $this->navDown === $button && $this->navHot === $button ? 1.0 : 0.0)->value();
        $fill = LookBlend::mix(self::fade($look->color(Role::ControlHovered), max(0.0, min(1.0, $hover))), $look->color(Role::ControlPressed), max(0.0, min(1.0, $press)));
        if ($look->material()->isGlass()) {
            // file_browser.c:790-798: a bead of glass, shown only while the pointer is on the button
            $shown = max(0.0, min(1.0, max($hover, $press)));
            if ($shown > 0.01) {
                $p->pushOpacity($shown);
                Surfaces::paint($p, $look, $r, $r->h / 2, SurfaceKind::Control, $hover, $press);
                $p->popOpacity();
            }
        } else {
            $p->rect($r, $fill, $look->metric(Metric::CornerRadius));
        }
        [$cx, $cy] = [$r->x + $r->w / 2, $r->y + $r->h / 2];
        $scale = 1.0 + 0.16 * $hover - 0.2 * $press;
        $scaled = abs($scale - 1.0) > 0.001;
        $scaled && $p->pushTransform($scale, $cx * (1 - $scale), $cy * (1 - $scale));
        Icons::chevron($p, $cx, $cy, 10.0, [M_PI, 0.0, -M_PI / 2][$button], $enabled ? $text : self::fade($text, 0.3));
        $scaled && $p->popTransform();
    }

    /** rvn_file_browser_paint (file_browser.c:820-898); the fields paint themselves after. */
    protected function paintSelf(Painter $p): void
    {
        $look = $this->look();
        $l = $this->list;
        $row = $this->rowHeight();
        $padding = $look->metric(Metric::PaddingX) * 0.8;
        $this->syncBar();
        $barWidth = $this->bar->needed() ? $look->metric(Metric::ScrollbarSize) : 0.0;
        $contentWidth = $l->w - 2.0 - $barWidth;
        $offset = $this->bar->drawn();
        $top = $this->rowsTop();
        $columns = $l->w >= 380.0;
        $nameWidth = $contentWidth - ($columns ? self::SIZE_WIDTH + self::DATE_WIDTH : 0.0);
        $text = $this->color($this->isEnabled() ? Role::Text : Role::TextDisabled, $look);
        $dim = self::fade($text, 0.6);
        $accent = $look->color(Role::Accent);
        $selection = $look->color(Role::Selection);
        $input = $look->color(Role::Input);

        for ($i = 0; $i < 3; $i++) {
            $this->paintNav($p, $i, $text);
        }
        if ($l->w <= 2.0 || $l->h <= 2.0) {
            return;
        }
        $p->rect($l, $input, $look->metric(Metric::CornerRadius), $look->color($this->hasFocus ? Role::Focus : Role::Border), $look->metric(Metric::BorderWidth));

        // header: the names of the columns; a chevron shows which one orders the list
        $titles = ['Name', 'Size', 'Modified'];
        $xs = [$l->x + 1.0, $l->x + 1.0 + $nameWidth, $l->x + 1.0 + $nameWidth + self::SIZE_WIDTH];
        $ws = [$nameWidth, self::SIZE_WIDTH, self::DATE_WIDTH];
        for ($i = 0; $i < ($columns ? 3 : 1); $i++) {
            $cell = new Rect($xs[$i] + $padding, $l->y + 1.0, max(0.0, $ws[$i] - $padding * 2.0), $this->headerHeight - 1.0);
            $this->textIn($p, $titles[$i], $cell, $i === self::SIZE ? 1.0 : 0.0, $dim);
            if ($this->sortColumn === $i) {
                $tw = $this->textSize($titles[$i])->w;
                $cx = $i === self::SIZE ? $cell->x + $cell->w - $tw - 10.0 : $cell->x + $tw + 10.0;
                Icons::chevron($p, $cx, $cell->y + $cell->h / 2, 7.0, $this->sortDescending ? M_PI / 2 : -M_PI / 2, $dim);
            }
        }
        $p->rect(new Rect($l->x + 1.0, $l->y + $this->headerHeight - 1.0, $l->w - 2.0, 1.0), $look->color(Role::Separator));

        $p->pushClip(new Rect($l->x + 1.0, $top, $l->w - 2.0, max(0.0, $l->h - $this->headerHeight - 1.0)));
        $first = max((int) floor($offset / $row), 0);
        $last = min(count($this->entries), $first + (int) ($this->bar->viewport / $row) + 3);
        if ($this->cursor >= 0 && ($this->selected[$this->cursor] ?? '0') === '1') {
            // the row under the cursor glides from where it was
            $target = $row * $this->cursor;
            $this->selectPlaced || $this->selectY->snap($target);
            $this->selectPlaced = true;
            $y = $this->selectY->target($target)->value();
            $p->rect(new Rect($l->x + 1.0, $top + $y - $offset, $contentWidth, $row), $this->hasFocus ? $selection : LookBlend::mix($selection, $input, 0.45));
        } else {
            $this->selectPlaced = false;
        }
        for ($i = $first; $i < $last; $i++) {
            $entry = $this->entries[$i];
            $line = new Rect($l->x + 1.0, $top + $row * $i - $offset, $contentWidth, $row);
            if ($this->selected[$i] === '1' && $i !== $this->cursor) {
                $p->rect($line, LookBlend::mix($selection, $input, $this->hasFocus ? 0.2 : 0.45));
            } elseif ($i === $this->hot && $this->selected[$i] !== '1' && $this->hovered && $this->isEnabled()) {
                $p->rect($line, $look->color(Role::RowHovered));
            }
            $this->icon($p, $entry['isDir'], $line->x + $padding, $line->y + floor(($row - 14.0) * 0.5), $accent, $text);
            $p->pushClip(new Rect($line->x, $line->y, max(0.0, $nameWidth - 6.0), $row));
            $this->textIn($p, $entry['name'], new Rect($line->x + $padding + 24.0, $line->y, $nameWidth, $row), 0.0, $text);
            $p->popClip();
            if (! $columns) {
                continue;
            }
            if (! $entry['isDir']) {
                $this->textIn($p, self::formatSize($entry['size']), new Rect($line->x + $nameWidth + $padding, $line->y, self::SIZE_WIDTH - $padding * 2.0, $row), 1.0, $dim);
            }
            $entry['modified'] > 0 && $this->textIn($p, date('Y-m-d H:i', $entry['modified']),
                new Rect($line->x + $nameWidth + self::SIZE_WIDTH + $padding, $line->y, self::DATE_WIDTH - $padding * 2.0, $row), 0.0, $dim);
        }
        if ($this->entries === []) {
            $this->textIn($p, 'This folder is empty', new Rect($l->x, $top, $l->w, min($row * 3.0, $l->h - $this->headerHeight)), 0.5, $dim);
        }
        $p->popClip();
        $this->bar->paint($p, $look, $this->track(), true, $this->hovered, false);
    }

    private static function fade(Color $c, float $a): Color
    {
        return new Color($c->red, $c->green, $c->blue, $c->alpha * $a);
    }
}
