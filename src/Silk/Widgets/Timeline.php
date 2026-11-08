<?php

namespace Silk\Widgets;

use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Look\FontRole;
use Silk\Contracts\Look\FontSpec;
use Silk\Contracts\Look\Metric;
use Silk\Contracts\Look\Role;
use Silk\Contracts\Look\State;
use Silk\Contracts\Mail\TimelineEdited;
use Silk\Contracts\Mail\TimelineItemActivated;
use Silk\Contracts\Mail\TimelineItemChanged;
use Silk\Contracts\Mail\TimelineItemRemoved;
use Silk\Contracts\Mail\TimelineSelectionChanged;
use Silk\Contracts\Mail\TimelineTimeChanged;
use Silk\Contracts\Painter\Painter;
use Silk\Core\Motion\Spring;
use Silk\Core\Motion\SpringPreset;
use Silk\Core\Tree\Widget;
use Silk\Looks\Canvas\CanvasLook;
use Silk\Looks\Canvas\CanvasPreset;
use Silk\Looks\Canvas\CanvasShape;
use Silk\Looks\Canvas\PartOverrides;
use Silk\Looks\Canvas\PartStyle;
use Silk\Painter\MarkShape;
use Silk\Painter\RavenPaint;
use Silk\Widgets\Concerns\MeasuresText;
use Silk\Widgets\Rows\RowScroll;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\MouseButton;
use Surface\NutsAndBolts\Color;

/**
 * A sequencer, as Raven's widgets/timeline.c: a ruler of time, a playhead, tracks of clips and keys, markers.
 * Times are seconds. What is shown is the view (start, span); what is drawn glides to it (SNAPPY), and the mouse
 * works with what is drawn. One gesture at a time: scrubbing, moving the selection, stretching a clip, a
 * selection rectangle, panning; a move remembers where everything started, to snap as a whole and to be undone
 * with Escape. Its look is a canvas look (preset, theme rows, overrides, track and item styles).
 */
class Timeline extends Widget
{
    use MeasuresText;

    /** Sizes for a theme whose text is 13 high, scaled by unit() (timeline.c:33-50). */
    public const float BASE_FONT = 13.0;

    public const float RULER = 30.0;

    public const float TRACK = 38.0;

    public const float EDGE = 7.0;

    public const float SNAP_PIXELS = 8.0;

    public const float KEY = 11.0;

    public const float TOGGLE = 20.0;

    public const float FLAG = 15.0;

    public const float HEADER_MIN = 96.0;

    public const float HEADER_MAX = 240.0;

    public const float MIN_TRACK = 16.0;

    private const int DRAG_NONE = 0;

    private const int DRAG_SCRUB = 1;

    private const int DRAG_MOVE = 2;

    private const int DRAG_LEFT = 3;

    private const int DRAG_RIGHT = 4;

    private const int DRAG_BOX = 5;

    private const int DRAG_PAN = 6;

    private const int PART_NONE = 0;

    private const int PART_BODY = 1;

    private const int PART_LEFT = 2;

    private const int PART_RIGHT = 3;

    private float $duration = 10.0;

    private float $time = 0.0;

    private float $fps = 0.0;

    private bool $snapping = true;

    private bool $playing = false;

    private bool $loop = false;

    private float $viewStart = 0.0;

    private float $viewSpan = 10.0;

    private Spring $shownStart;

    private Spring $shownSpan;

    /** What the last paint drew: the mouse works with this. */
    private float $drawStart = 0.0;

    private float $drawSpan = 10.0;

    private float $headerWidth = 0.0;

    private float $trackHeight = 0.0;

    private float $rulerHeight = 0.0;

    private CanvasPreset $preset = CanvasPreset::Auto;

    private PartOverrides $over;

    /** @var list<array{id: int, name: string, color: ?Color, height: float, locked: bool, muted: bool, style: PartOverrides}> */
    private array $tracks = [];

    /**
     * @var list<array{id: int, type: TimelineItem, track: int, start: float, length: float, label: ?string, color: ?Color,
     *   selected: bool, fromStart: float, fromLength: float, fromTrack: int, style: ?PartOverrides}>
     */
    private array $items = [];

    /** @var array<int, Spring> how much each item stands out (under the pointer, held), by id; made when first needed */
    private array $lift = [];

    private int $nextId = 1;

    private RowScroll $timeBar;

    private RowScroll $trackBar;

    private int $drag = self::DRAG_NONE;

    private int $dragItem = 0;

    private bool $dragMoved = false;

    private float $pressX = 0.0;

    private float $pressY = 0.0;

    private float $pressTime = 0.0;

    private int $pressRow = 0;

    private float $panStart = 0.0;

    private float $panScroll = 0.0;

    private Rect $box;

    /** Where a move snapped, shown as a guide; negative: nowhere. */
    private float $guide = -1.0;

    private int $hotItem = 0;

    private int $hotPart = self::PART_NONE;

    /** A track header's toggle under the pointer: index * 2 + (0 mute, 1 lock), or -1. */
    private int $hotToggle = -1;

    private bool $gestureChanged = false;

    private bool $removable = false;

    private bool $hasFocus = false;

    public function __construct(string $name)
    {
        parent::__construct($name);
        $this->focusable = true;
        $this->shownStart = new Spring(0.0, SpringPreset::Snappy);
        $this->shownSpan = new Spring(10.0, SpringPreset::Snappy);
        $this->timeBar = new RowScroll;
        $this->trackBar = new RowScroll;
        $this->over = new PartOverrides;
        $this->box = new Rect(0, 0, 0, 0);
    }

    // ---------------------------------------------------------------- model

    private function trackIndex(int $id): int
    {
        foreach ($this->tracks as $i => $t) {
            if ($t['id'] === $id) {
                return $i;
            }
        }

        return -1;
    }

    private function itemIndex(int $id): int
    {
        foreach ($this->items as $i => $item) {
            if ($item['id'] === $id) {
                return $i;
            }
        }

        return -1;
    }

    private function addItem(TimelineItem $type, int $track, float $start, float $length, ?string $label): int
    {
        $id = $this->nextId++;
        $this->items[] = [
            'id' => $id, 'type' => $type, 'track' => $track, 'start' => max(0.0, $start), 'length' => max(0.0, $length),
            'label' => $label === null || $label === '' ? null : $label, 'color' => null, 'selected' => false,
            'fromStart' => 0.0, 'fromLength' => 0.0, 'fromTrack' => 0, 'style' => null,
        ];
        $this->invalidatePaint();

        return $id;
    }

    private function removeItemAt(int $index): void
    {
        $id = $this->items[$index]['id'];
        $this->hotItem === $id && $this->hotItem = 0;
        $this->dragItem === $id && $this->dragItem = 0;
        unset($this->lift[$id]);
        array_splice($this->items, $index, 1);
    }

    /** How much bigger than the flat theme the sizes are (rvn_tl_unit). */
    private function unit(): float
    {
        return max($this->look()->metric(Metric::FontSize) / self::BASE_FONT, 0.5);
    }

    private function rulerHeightNow(): float
    {
        return $this->rulerHeight > 0 ? $this->rulerHeight : round(self::RULER * $this->unit());
    }

    public function defaultTrackHeight(): float
    {
        return $this->trackHeight > 0 ? $this->trackHeight : round(self::TRACK * $this->unit());
    }

    private function heightOf(array $track): float
    {
        return $track['height'] > 0 ? $track['height'] : $this->defaultTrackHeight();
    }

    private function tracksHeight(): float
    {
        return array_sum(array_map(fn ($t) => $this->heightOf($t), $this->tracks));
    }

    /** @var array<int, Color> the palette's colours, read once a paint */
    private array $palette = [];

    private function trackColor(int $index): Color
    {
        if ($index >= 0 && $this->tracks[$index]['color'] !== null) {
            return $this->tracks[$index]['color'];
        }
        $slot = max(0, $index) % 8;

        return $this->palette[$slot] ??= $this->look()->part('Timeline', 'Palette'.$slot, State::Normal)->background;
    }

    private function itemColor(array $item): Color
    {
        if ($item['color'] !== null) {
            return $item['color'];
        }
        if ($item['type'] === TimelineItem::Marker) {
            return $this->look()->color(Role::Warning);
        }

        return $this->trackColor($this->trackIndex($item['track']));
    }

    // ------------------------------------------------------------- geometry

    /** @return array<string, mixed> rvn_tl_layout (timeline.c:276-313), in the tree's coordinates */
    private function layout(): array
    {
        $b = $this->bounds();
        $bar = $this->look()->metric(Metric::ScrollbarSize);
        $unit = $this->unit();
        $ruler = $this->rulerHeightNow();
        $toggle = round(self::TOGGLE * $unit);
        $header = $this->headerWidth;
        if ($header <= 0) {
            // as wide as the longest name asks, within reason: the dot before it, the two toggles after
            $around = 26.0 * $unit + $toggle * 2.0 + 22.0 * $unit;
            $header = self::HEADER_MIN * $unit;
            foreach ($this->tracks as $t) {
                $t['name'] !== '' && $header = max($header, $this->textSize($t['name'])->w + $around);
            }
            $header = ceil(min($header, self::HEADER_MAX * $unit));
        }
        $header = min($header, $b->w * 0.5);
        $lanesHeight = max(0.0, $b->h - $ruler - $bar);
        $scroll = $this->tracksHeight() > $lanesHeight + 0.5;
        $lanesWidth = max(1.0, $b->w - $header - ($scroll ? $bar : 0.0));

        return [
            'unit' => $unit, 'rulerH' => $ruler, 'bar' => $bar, 'key' => self::KEY * $unit, 'edge' => self::EDGE * $unit,
            'toggle' => $toggle, 'flag' => round(self::FLAG * $unit),
            'ruler' => new Rect($b->x + $header, $b->y, $lanesWidth, $ruler),
            'lanes' => new Rect($b->x + $header, $b->y + $ruler, $lanesWidth, $lanesHeight),
            'headers' => new Rect($b->x, $b->y + $ruler, $header, $lanesHeight),
            'timeTrack' => new Rect($b->x + $header, $b->y + $b->h - $bar, $lanesWidth, $bar),
            'trackTrack' => new Rect($b->x + $b->w - $bar, $b->y + $ruler, $bar, $lanesHeight),
        ];
    }

    private function span(): float
    {
        return $this->drawSpan > 1e-9 ? $this->drawSpan : ($this->viewSpan > 1e-9 ? $this->viewSpan : 1.0);
    }

    /** The x of a time in what is drawn. */
    public function xOf(float $time): float
    {
        $l = $this->layout()['lanes'];

        return $l->x + (float) (($time - $this->drawStart) / $this->span()) * $l->w;
    }

    private function timeAt(array $layout, float $x): float
    {
        return $this->drawStart + (($x - $layout['lanes']->x) / $layout['lanes']->w) * $this->span();
    }

    private function perPixel(array $layout): float
    {
        return $this->span() / $layout['lanes']->w;
    }

    /** @return array{float, float} the top of a track and its height */
    private function trackY(array $layout, int $index): array
    {
        $y = $layout['lanes']->y - $this->trackBar->offset;
        for ($i = 0; $i < $index && $i < count($this->tracks); $i++) {
            $y += $this->heightOf($this->tracks[$i]);
        }

        return [$y, isset($this->tracks[$index]) ? $this->heightOf($this->tracks[$index]) : $this->defaultTrackHeight()];
    }

    /** The track at a height: -1 above the first, the count below the last. */
    private function rowAt(array $layout, float $y): int
    {
        $top = $layout['lanes']->y - $this->trackBar->offset;
        if ($y < $top) {
            return -1;
        }
        foreach ($this->tracks as $i => $t) {
            $top += $this->heightOf($t);
            if ($y < $top) {
                return $i;
            }
        }

        return count($this->tracks);
    }

    private function clampView(): void
    {
        $minimum = $this->fps > 0 ? 4.0 / $this->fps : 0.05;
        $this->duration < $minimum && $minimum = $this->duration > 0 ? $this->duration : 1.0;
        $this->viewSpan = max($minimum, min($this->viewSpan, $this->duration));
        $this->viewStart + $this->viewSpan > $this->duration && $this->viewStart = $this->duration - $this->viewSpan;
        $this->viewStart = max(0.0, $this->viewStart);
    }

    /** The bars follow the view and the tracks (rvn_tl_sync_bars). */
    private function syncBars(): void
    {
        $layout = $this->layout();
        $perPixel = $this->viewSpan / $layout['lanes']->w;
        $this->timeBar->viewport = $layout['lanes']->w;
        $this->timeBar->content = (float) ($this->duration / $perPixel);
        $this->timeBar->offset = (float) ($this->viewStart / $perPixel);
        $this->trackBar->viewport = $layout['lanes']->h;
        $this->trackBar->content = $this->tracksHeight();
        $this->trackBar->clamp();
    }

    private function setViewNow(float $start, float $span, bool $animate): void
    {
        $this->viewStart = $start;
        $this->viewSpan = $span;
        $this->clampView();
        $this->shownStart->target($this->viewStart);
        $this->shownSpan->target($this->viewSpan);
        if (! $animate) {
            $this->shownStart->snap($this->viewStart);
            $this->shownSpan->snap($this->viewSpan);
            $this->drawStart = $this->viewStart;
            $this->drawSpan = $this->viewSpan;
        }
        $this->bounds()->w > 0 && $this->syncBars();
        $this->invalidatePaint();
    }

    // ---------------------------------------------------------------- ruler

    /**
     * The step between two labelled marks of the ruler, and how many small marks it is cut into
     * (rvn_tl_major_step, timeline.c:396-425).
     *
     * @return array{float, int}
     */
    public function majorStep(float $perPixel): array
    {
        static $seconds = [0.001, 0.002, 0.005, 0.01, 0.02, 0.05, 0.1, 0.2, 0.5, 1.0, 2.0, 5.0, 10.0, 15.0, 30.0, 60.0, 120.0, 300.0, 600.0, 1800.0, 3600.0, 7200.0, 21600.0];
        static $secondsMinor = [5, 2, 5, 5, 2, 5, 5, 2, 5, 5, 2, 5, 5, 3, 3, 6, 2, 5, 5, 6, 6, 4, 6];
        static $frames = [1, 2, 5, 10];
        static $framesMinor = [1, 2, 5, 5];
        $wanted = $perPixel * 84.0;
        if ($this->fps > 0) {
            foreach ($frames as $i => $f) {
                $step = $f / $this->fps;
                if ($step >= $wanted && $f < $this->fps) {
                    return [$step, $framesMinor[$i]];
                }
            }
        }
        foreach ($seconds as $i => $s) {
            // (with frames, nothing finer than a second is left here)
            if ($this->fps > 0 && $s < 1.0) {
                continue;
            }
            if ($s >= $wanted) {
                $minor = $secondsMinor[$i];
                if ($this->fps > 0 && $s === 1.0) {
                    $minor = $this->fps <= 30.0 ? (int) ($this->fps + 0.5) : ($this->fps <= 60.0 ? (int) ($this->fps / 2.0 + 0.5) : 10);
                }
                // small marks too close to tell apart are not drawn
                while ($minor > 1 && $s / $minor < $perPixel * 5.0) {
                    $minor = $minor % 2 === 0 ? intdiv($minor, 2) : 1;
                }

                return [$s, $minor];
            }
        }

        return [end($seconds), 6];
    }

    /** A time as the ruler writes it (rvn_tl_format_time, timeline.c:427-446). */
    public function formatTime(float $time, float $step): string
    {
        $time = max(0.0, $time);
        if ($this->fps > 0) {
            $frame = (int) floor($time * $this->fps + 0.5);
            $perSecond = (int) ($this->fps + 0.5);
            $whole = $perSecond > 0 ? intdiv($frame, $perSecond) : 0;
            $rest = $perSecond > 0 ? $frame % $perSecond : 0;

            return match (true) {
                $whole >= 3600 => sprintf('%d:%02d:%02d:%02d', intdiv($whole, 3600), intdiv($whole, 60) % 60, $whole % 60, $rest),
                $whole >= 60 => sprintf('%d:%02d:%02d', intdiv($whole, 60), $whole % 60, $rest),
                default => sprintf('%d:%02d', $whole, $rest),
            };
        }
        if ($step < 1.0) {
            $decimals = $step < 0.01 ? 3 : ($step < 0.1 ? 2 : 1);

            return $time >= 60.0 ? sprintf('%d:%0'.($decimals + 3).'.'.$decimals.'f', (int) ($time / 60.0), fmod($time, 60.0))
                : sprintf('%.'.$decimals.'fs', $time);
        }
        $whole = (int) floor($time + 0.5);

        return match (true) {
            $whole >= 3600 => sprintf('%d:%02d:%02d', intdiv($whole, 3600), intdiv($whole, 60) % 60, $whole % 60),
            $whole >= 60 => sprintf('%d:%02d', intdiv($whole, 60), $whole % 60),
            default => "{$whole}s",
        };
    }

    // ------------------------------------------------------------- snapping

    private function quantize(array $layout, float $time): float
    {
        if ($this->fps > 0) {
            return floor($time * $this->fps + 0.5) / $this->fps;
        }
        [$major, $minor] = $this->majorStep($this->perPixel($layout));
        $step = $major / max($minor, 1);

        return floor($time / $step + 0.5) * $step;
    }

    /**
     * What a time is drawn to within 8 pixels: the playhead, a marker, an end of a clip that is not moving, the ends
     * of the sequence (rvn_tl_magnet, timeline.c:466-483). Null when nothing is in reach.
     */
    private function magnet(array $layout, float $time, bool $withPlayhead): ?float
    {
        $best = $this->perPixel($layout) * self::SNAP_PIXELS;
        $found = null;
        $try = function (float $candidate) use ($time, &$best, &$found) {
            $distance = abs($candidate - $time);
            if ($distance < $best) {
                $best = $distance;
                $found = $candidate;
            }
        };
        $try(0.0);
        $try($this->duration);
        $withPlayhead && $try($this->time);
        foreach ($this->items as $item) {
            // (what moves with the gesture is not something to snap to)
            if ($item['selected'] && $this->drag !== self::DRAG_SCRUB) {
                continue;
            }
            $try($item['start']);
            $item['type'] === TimelineItem::Clip && $try($item['start'] + $item['length']);
        }

        return $found;
    }

    // -------------------------------------------------------------- mail

    private function setTimeNow(float $time, bool $notify): void
    {
        $time = max(0.0, min($this->duration, $time));
        if ($time === $this->time) {
            return;
        }
        $this->time = $time;
        $this->invalidatePaint();
        $notify && $this->post(new TimelineTimeChanged($this->window(), $this->path(), $this->uuid(), $time));
    }

    private function notifyItem(string $class, int $item): void
    {
        $this->post(new $class($this->window(), $this->path(), $this->uuid(), $item));
    }

    private function selectOnly(int $id): void
    {
        foreach ($this->items as $i => $item) {
            $this->items[$i]['selected'] = $item['id'] === $id;
        }
    }

    // ---------------------------------------------------------------- playing

    /** Plays on the frame clock; at the end it stops, or starts again with loop (rvn_tl_play_tick, timeline.c:522-544). */
    private function tick(float $dt): bool
    {
        if (! $this->playing) {
            return false;
        }
        $time = $this->time + $dt;
        if ($time >= $this->duration) {
            if ($this->loop && $this->duration > 0) {
                $time = fmod($time, $this->duration);
            } else {
                $time = $this->duration;
                $this->playing = false;
            }
        }
        // the view follows the playhead: a page at a time
        if ($time < $this->viewStart || $time > $this->viewStart + $this->viewSpan) {
            $this->setViewNow($time < $this->viewStart ? $time : $time - $this->viewSpan * 0.05, $this->viewSpan, true);
        }
        $this->setTimeNow($time, true);

        return $this->playing;
    }

    private function setPlayingNow(bool $playing): void
    {
        if ($this->playing === $playing) {
            return;
        }
        $this->playing = $playing;
        if ($playing) {
            $this->time >= $this->duration && $this->time = 0.0;
            $this->tree()?->tick(fn (float $dt) => $this->tick($dt));
        }
        $this->invalidatePaint();
    }

    // ------------------------------------------------------------------ API

    public function setDuration(float $seconds): static
    {
        if (! ($seconds > 0.0)) {
            throw new \InvalidArgumentException("A timeline's duration is positive, not {$seconds}.");
        }
        // (a view that showed everything goes on showing everything)
        $whole = $this->viewStart <= 0.0 && $this->viewSpan >= $this->duration;
        $this->duration = $seconds;
        $this->time > $seconds && $this->time = $seconds;
        $this->setViewNow($whole ? 0.0 : $this->viewStart, $whole ? $seconds : $this->viewSpan, false);

        return $this;
    }

    public function duration(): float
    {
        return $this->duration;
    }

    /** The playhead, from the program: no mail. */
    public function setTime(float $seconds): static
    {
        $this->setTimeNow($seconds, false);

        return $this;
    }

    public function time(): float
    {
        return $this->time;
    }

    /** Frames a second; 0: none (times are free). */
    public function setFrameRate(float $fps): static
    {
        $this->fps = max(0.0, $fps);
        $this->setViewNow($this->viewStart, $this->viewSpan, false);

        return $this;
    }

    public function frameRate(): float
    {
        return $this->fps;
    }

    public function setSnapping(bool $on = true): static
    {
        $this->snapping = $on;

        return $this;
    }

    public function setView(float $start, float $end): static
    {
        if (! ($end > $start)) {
            throw new \InvalidArgumentException('A view ends after it starts.');
        }
        $this->setViewNow($start, $end - $start, false);

        return $this;
    }

    /** @return array{float, float} start and end */
    public function view(): array
    {
        return [$this->viewStart, $this->viewStart + $this->viewSpan];
    }

    public function setPlaying(bool $playing = true): static
    {
        $this->setPlayingNow($playing);

        return $this;
    }

    public function isPlaying(): bool
    {
        return $this->playing;
    }

    public function setLoop(bool $loop = true): static
    {
        $this->loop = $loop;

        return $this;
    }

    /** 0: as wide as the names ask. */
    public function setHeaderWidth(float $width): static
    {
        $this->headerWidth = max(0.0, $width);

        return $this->invalidateLayout();
    }

    public function headerWidth(): float
    {
        return $this->layout()['headers']->w;
    }

    public function setRulerHeight(float $height): static
    {
        $this->rulerHeight = max(0.0, $height);

        return $this->invalidateLayout();
    }

    public function rulerHeight(): float
    {
        return $this->rulerHeightNow();
    }

    public function setDefaultTrackHeight(float $height): static
    {
        $this->trackHeight = $height > 0 ? max($height, self::MIN_TRACK) : 0.0;

        return $this->invalidateLayout();
    }

    /** A track at the bottom; its id. */
    public function addTrack(string $name): int
    {
        $id = $this->nextId++;
        $this->tracks[] = ['id' => $id, 'name' => $name, 'color' => null, 'height' => 0.0, 'locked' => false, 'muted' => false, 'style' => new PartOverrides];
        $this->invalidateLayout();

        return $id;
    }

    /** Removes a track and its items. */
    public function removeTrack(int $track): static
    {
        $index = $this->trackIndex($track);
        if ($index >= 0) {
            for ($i = count($this->items) - 1; $i >= 0; $i--) {
                $this->items[$i]['track'] === $track && $this->removeItemAt($i);
            }
            array_splice($this->tracks, $index, 1);
            $this->hotToggle = -1;
            $this->invalidateLayout();
        }

        return $this;
    }

    public function trackCount(): int
    {
        return count($this->tracks);
    }

    /** The id of the track at an index; 0 for none. */
    public function track(int $index): int
    {
        return $this->tracks[$index]['id'] ?? 0;
    }

    public function setTrackName(int $track, string $name): static
    {
        $i = $this->trackIndex($track);
        $i >= 0 && $this->tracks[$i]['name'] = $name;

        return $this->invalidateLayout();
    }

    public function trackName(int $track): string
    {
        $i = $this->trackIndex($track);

        return $i >= 0 ? $this->tracks[$i]['name'] : '';
    }

    public function setTrackColor(int $track, ?Color $color): static
    {
        $i = $this->trackIndex($track);
        $i >= 0 && $this->tracks[$i]['color'] = $color;

        return $this->invalidatePaint();
    }

    public function setTrackHeight(int $track, float $height): static
    {
        $i = $this->trackIndex($track);
        $i >= 0 && $this->tracks[$i]['height'] = $height > 0 ? max($height, self::MIN_TRACK) : 0.0;

        return $this->invalidateLayout();
    }

    public function setTrackLocked(int $track, bool $locked = true): static
    {
        $i = $this->trackIndex($track);
        $i >= 0 && $this->tracks[$i]['locked'] = $locked;

        return $this->invalidatePaint();
    }

    public function setTrackMuted(int $track, bool $muted = true): static
    {
        $i = $this->trackIndex($track);
        $i >= 0 && $this->tracks[$i]['muted'] = $muted;

        return $this->invalidatePaint();
    }

    public function isTrackMuted(int $track): bool
    {
        $i = $this->trackIndex($track);

        return $i >= 0 && $this->tracks[$i]['muted'];
    }

    public function isTrackLocked(int $track): bool
    {
        $i = $this->trackIndex($track);

        return $i >= 0 && $this->tracks[$i]['locked'];
    }

    /**
     * A property of a track's look: of its clips, its keys, its lane ("Clip.Shape", "Key.Size", "Track.Background",
     * or ClipStyle, ClipRadius, ClipGlow, ClipBorder, TextColor, KeyShape, KeySize, KeyGlow, Background,
     * LineColor; timeline.c:1696-1712). False for an unknown property or a value it cannot take.
     */
    public function setTrackStyle(int $track, string $property, string $value): bool
    {
        $i = $this->trackIndex($track);
        if ($i < 0) {
            return false;
        }
        static $names = [
            'clipstyle' => ['Clip', 'Shape'], 'clipshape' => ['Clip', 'Shape'], 'clipradius' => ['Clip', 'CornerRadius'], 'clipglow' => ['Clip', 'Glow'],
            'clipborder' => ['Clip', 'BorderColor'], 'textcolor' => ['Clip', 'Foreground'], 'keyshape' => ['Key', 'Shape'], 'keysize' => ['Key', 'Size'],
            'keyglow' => ['Key', 'Glow'], 'background' => ['Track', 'Background'], 'linecolor' => ['Track', 'BorderColor'],
        ];
        $style = $this->tracks[$i]['style'];
        foreach (['clip.' => 'Clip', 'key.' => 'Key', 'track.' => 'Track'] as $prefix => $slot) {
            if (stripos($property, $prefix) === 0) {
                return $this->styled(fn () => $style->set($slot, substr($property, strlen($prefix)), $value));
            }
        }
        [$slot, $name] = $names[strtolower($property)] ?? [null, null];

        return $slot !== null && $this->styled(fn () => $style->set($slot, $name, $value));
    }

    /** A property of one clip, key or marker's look ("Shape", "Background", "Glow"...; timeline.c:1714-1723). */
    public function setItemStyle(int $item, string $property, string $value): bool
    {
        $i = $this->itemIndex($item);
        if ($i < 0) {
            return false;
        }
        $property = match (strtolower($property)) {
            'style', 'clipstyle', 'keyshape' => 'Shape',
            'textcolor', 'text' => 'Foreground',
            default => $property,
        };
        $this->items[$i]['style'] ??= new PartOverrides;
        $style = $this->items[$i]['style'];

        return $this->styled(fn () => $style->set('Item', $property, $value));
    }

    /**
     * A property of the timeline's parts (Raven's attributes): "Preset"/"Look", the short names (ClipStyle,
     * KeyShape, GridStyle, GridColor, PlayheadColor, PlayheadWidth, RulerColor, HeaderColor, StripeColor...) or
     * "Part.Property" ("Clip.Shape", "Canvas.Background"); timeline.c:1654-1694.
     */
    public function setPartStyle(string $name, string $value): bool
    {
        if (strcasecmp($name, 'Preset') === 0 || strcasecmp($name, 'Look') === 0) {
            $preset = CanvasPreset::byName($value);
            $preset !== null && $this->setPreset($preset);

            return $preset !== null;
        }
        static $short = [
            'clipstyle' => ['Clip', 'Shape'], 'clipshape' => ['Clip', 'Shape'], 'clipradius' => ['Clip', 'CornerRadius'], 'clipglow' => ['Clip', 'Glow'],
            'keyshape' => ['Key', 'Shape'], 'keysize' => ['Key', 'Size'], 'gridstyle' => ['Grid', 'Shape'], 'gridcolor' => ['Grid', 'Foreground'],
            'playheadcolor' => ['Playhead', 'Background'], 'playheadwidth' => ['Playhead', 'Size'], 'rulercolor' => ['Ruler', 'Background'],
            'headercolor' => ['Header', 'Background'], 'stripecolor' => ['Track', 'Background'],
        ];
        if (isset($short[strtolower($name)])) {
            [$part, $property] = $short[strtolower($name)];

            return $this->styled(fn () => $this->over->set($part, $property, $value));
        }

        return $this->styled(fn () => $this->over->setDotted($name, $value));
    }

    private function styled(\Closure $set): bool
    {
        try {
            $ok = $set();
        } catch (\InvalidArgumentException) {
            return false;
        }
        $ok && $this->invalidatePaint();

        return $ok;
    }

    public function setPreset(CanvasPreset $preset): static
    {
        $this->preset = $preset;

        return $this->invalidatePaint();
    }

    public function preset(): CanvasPreset
    {
        return $this->preset;
    }

    /** A clip on a track; its id (0 for no such track). */
    public function addClip(int $track, float $start, float $length, ?string $label = null): int
    {
        if ($this->trackIndex($track) < 0) {
            throw new \InvalidArgumentException("No track {$track}.");
        }

        return $this->addItem(TimelineItem::Clip, $track, $start, $length, $label);
    }

    public function addKey(int $track, float $time): int
    {
        if ($this->trackIndex($track) < 0) {
            throw new \InvalidArgumentException("No track {$track}.");
        }

        return $this->addItem(TimelineItem::Key, $track, $time, 0.0, null);
    }

    public function addMarker(float $time, ?string $label = null): int
    {
        return $this->addItem(TimelineItem::Marker, 0, $time, 0.0, $label);
    }

    public function removeItem(int $item): static
    {
        $i = $this->itemIndex($item);
        $i >= 0 && $this->removeItemAt($i);

        return $this->invalidatePaint();
    }

    public function clear(): static
    {
        $this->drag !== self::DRAG_NONE && $this->endDrag(true);
        $this->items = $this->tracks = $this->lift = [];
        $this->hotItem = $this->dragItem = 0;

        return $this->invalidateLayout();
    }

    public function itemType(int $item): ?TimelineItem
    {
        return $this->items[$this->itemIndex($item)]['type'] ?? null;
    }

    public function itemTrack(int $item): int
    {
        return $this->items[$this->itemIndex($item)]['track'] ?? 0;
    }

    public function itemStart(int $item): float
    {
        return $this->items[$this->itemIndex($item)]['start'] ?? 0.0;
    }

    public function itemLength(int $item): float
    {
        return $this->items[$this->itemIndex($item)]['length'] ?? 0.0;
    }

    /** Moves an item: its track (0 keeps it), its start, its length (clips; negative keeps it). */
    public function setItem(int $item, int $track, float $start, float $length = -1.0): static
    {
        $i = $this->itemIndex($item);
        if ($i < 0) {
            return $this;
        }
        if ($track !== 0 && $this->items[$i]['type'] !== TimelineItem::Marker) {
            $this->trackIndex($track) < 0 && throw new \InvalidArgumentException("No track {$track}.");
            $this->items[$i]['track'] = $track;
        }
        $this->items[$i]['start'] = max(0.0, $start);
        $length >= 0.0 && $this->items[$i]['type'] === TimelineItem::Clip && $this->items[$i]['length'] = $length;

        return $this->invalidatePaint();
    }

    public function setItemLabel(int $item, ?string $label): static
    {
        $i = $this->itemIndex($item);
        $i >= 0 && $this->items[$i]['label'] = $label === null || $label === '' ? null : $label;

        return $this->invalidatePaint();
    }

    public function itemLabel(int $item): string
    {
        return $this->items[$this->itemIndex($item)]['label'] ?? '';
    }

    public function setItemColor(int $item, ?Color $color): static
    {
        $i = $this->itemIndex($item);
        $i >= 0 && $this->items[$i]['color'] = $color;

        return $this->invalidatePaint();
    }

    public function itemCount(): int
    {
        return count($this->items);
    }

    /** The id of the item at an index; 0 for none. */
    public function item(int $index): int
    {
        return $this->items[$index]['id'] ?? 0;
    }

    /** Selects an item, alone or added to the selection (no mail: code). */
    public function select(int $item, bool $add = false): static
    {
        $i = $this->itemIndex($item);
        if ($i >= 0) {
            $add ? $this->items[$i]['selected'] = true : $this->selectOnly($item);
        }

        return $this->invalidatePaint();
    }

    public function clearSelection(): static
    {
        $this->selectOnly(0);

        return $this->invalidatePaint();
    }

    public function isSelected(int $item): bool
    {
        return $this->items[$this->itemIndex($item)]['selected'] ?? false;
    }

    /** @return list<int> the selected items' ids, in order */
    public function selection(): array
    {
        return array_values(array_map(fn ($i) => $i['id'], array_filter($this->items, fn ($i) => $i['selected'])));
    }

    /**
     * Delete and Backspace remove the selected items of unlocked tracks, each posting TimelineItemRemoved (Raven
     * does it only with a removed callback set; this is that choice). Off by default.
     */
    public function setRemovable(bool $removable = true): static
    {
        $this->removable = $removable;

        return $this;
    }

    // --------------------------------------------------------------- layout

    /** rvn_timeline_measure (timeline.c:595-600). */
    protected function measureContent(Size $available): Size
    {
        $track = $this->defaultTrackHeight();
        $tracks = max($track * 2.0, min($this->tracksHeight(), $track * 8.0));

        return new Size(480.0 * $this->unit(), $this->rulerHeightNow() + $tracks + $this->look()->metric(Metric::ScrollbarSize));
    }

    protected function arrangeContent(Rect $r): void
    {
        $this->syncBars();
    }

    public function springs(): array
    {
        return [$this->shownStart, $this->shownSpan, ...$this->timeBar->springs(), ...$this->trackBar->springs(), ...array_values($this->lift)];
    }

    private function liftOf(int $id): Spring
    {
        return $this->lift[$id] ??= new Spring(0.0, SpringPreset::Snappy);
    }

    // ---------------------------------------------------------------- looks

    /**
     * What the look gives a part from the theme's roles (rvn_tl_base, timeline.c:622-732); the hard values of the
     * presets are DataStyles rows by class, applied after.
     */
    private function base(PartStyle $s, string $part, State $state, CanvasLook $c): void
    {
        $modern = $c->is('modern');
        switch ($part) {
            case 'Canvas':
                $s->radius = $c->look->metric(Metric::CornerRadius);
                $s->borderWidth = 1.0;
                $s->background = $c->input;
                $s->border = $modern ? PartStyle::fade($c->text, 0.08) : $c->border;
                break;
            case 'Ruler':
            case 'Header':
                $s->border = $modern ? PartStyle::fade($c->text, 0.08) : $c->separator;
                $s->foreground = $c->text;
                $s->background = $c->look->color(Role::Header);
                break;
            case 'Track':
                // the background of every other track, and the line between them
                $s->background = PartStyle::fade($c->text, $modern ? 0.0 : 0.028);
                $s->border = $modern ? PartStyle::fade($c->text, 0.05) : PartStyle::fade($c->separator, 0.6);
                break;
            case 'Grid':
                // (from the text, faint: the separators of some themes are strong lines)
                $s->foreground = $c->text;
                break;
            case 'Clip':
                $state === State::Selected && ! $c->dark && $s->border = PartStyle::fade($c->text, 0.9);
                break;
            case 'Key':
                $state === State::Selected && ! $c->dark && $s->border = PartStyle::fade($c->text, 0.9);
                break;
            case 'Playhead':
                $s->background = self::opaque($modern ? $c->accent : $c->danger);
                break;
        }
    }

    private static function opaque(Color $c): Color
    {
        return new Color($c->red, $c->green, $c->blue, 1.0);
    }

    /** @return array<string, mixed> the parts as they are drawn now (rvn_tl_resolve) */
    private function resolve(): array
    {
        $c = new CanvasLook($this->look(), $this->preset);
        $base = fn (PartStyle $s, string $part, State $state, CanvasLook $c) => $this->base($s, $part, $state, $c);
        $part = fn (string $name, State $state = State::Normal) => $c->part('Timeline', $name, $state, $base, $this->over);

        return [
            'c' => $c, 'body' => $part('Canvas'), 'ruler' => $part('Ruler'), 'header' => $part('Header'), 'track' => $part('Track'),
            'grid' => $part('Grid'), 'clip' => $part('Clip'), 'clipHot' => $part('Clip', State::Hover), 'clipSel' => $part('Clip', State::Selected),
            'key' => $part('Key'), 'keySel' => $part('Key', State::Selected), 'marker' => $part('Marker'), 'playhead' => $part('Playhead'),
        ];
    }

    /** Text that reads on a colour (rvn_tl_ink_on, timeline.c:756-759): the dark ink row, or white. */
    private function inkOn(Color $color): Color
    {
        return CanvasLook::lightness($color) > 0.62 ? $this->look()->part('Timeline', 'InkDark', State::Normal)->background : new Color(1, 1, 1, 1);
    }

    private static function mix(Color $a, Color $b, float $t): Color
    {
        return RavenPaint::mix($a, $b, $t);
    }

    private function textIn(Painter $p, string $text, Rect $r, float $align, Color $color, FontSpec $font): void
    {
        $w = $this->measurer()->measure($text, $font)->w;
        $p->text($text, $r->x + max(0.0, $r->w - $w) * $align, $this->baselineIn($r, $font), $color, $font);
    }

    private function smallFont(): FontSpec
    {
        $f = $this->textStyle();

        return new FontSpec($f->family, floor($f->size * 0.86), $f->weight, $f->italic);
    }

    /** A marker flag's width: the paint and the pointer use the same (rvn_tl_flag_width). */
    private function flagWidth(array $layout, array $item): float
    {
        return $item['label'] !== null ? $this->measurer()->measure($item['label'], $this->smallFont())->w + 12.0 * $layout['unit'] : 10.0 * $layout['unit'];
    }

    // ---------------------------------------------------------------- paint

    /** A clip in its form: solid, gradient, tinted (a bar of its colour on the left), outlined (timeline.c:761-821). */
    private function paintClip(Painter $p, array $look, array $layout, array $item, PartStyle $cl, Rect $rect, Color $color, float $lift, float $opacity, bool $hot, bool $locked): void
    {
        $c = $look['c'];
        $radius = min($cl->radius * $layout['unit'], $rect->h * 0.5, $rect->w * 0.5);
        $shape = $cl->shape === CanvasShape::Default ? CanvasShape::Tint : $cl->shape;
        $back = self::opaque($look['body']->background ?? $c->input);
        $black = new Color(0, 0, 0, 1);
        $white = new Color(1, 1, 1, 1);
        $opacity *= $cl->opacity > 0 ? $cl->opacity : 1.0;
        $c->is('blender') && $color = self::mix(self::opaque($color), $this->look()->part('Timeline', 'ClipTone', State::Normal, $c->classes())->background, 0.4);
        PartStyle::shows($cl->background) && $color = $cl->background;
        $solid = self::opaque($color);
        $fill = match ($shape) {
            CanvasShape::Solid => self::mix($solid, $black, 0.08),
            CanvasShape::Outline => self::mix($back, $solid, 0.12),
            CanvasShape::Gradient => $solid,
            default => self::mix($back, $solid, $c->dark ? 0.3 : 0.2),
        };
        $border = PartStyle::shows($cl->border) ? $cl->border : ($shape === CanvasShape::Tint ? PartStyle::fade($solid, 0.55) : self::mix($solid, $white, 0.3));
        $shape === CanvasShape::Outline && ! PartStyle::shows($cl->border) && $border = $solid;

        // raised: under the pointer, carried, or the look's own
        if (($cl->shadow > 0 || $lift > 0.02) && $cl->shadowColor !== null) {
            $p->shadow(new Rect($rect->x, $rect->y + 2.0 + $lift * 4.0, $rect->w, $rect->h), $radius, $cl->shadow + 6.0 * $lift,
                PartStyle::fade($cl->shadowColor, $opacity));
        }
        $cl->glow > 0.01 && $p->shadow($rect, $radius, 4.0 + 8.0 * $cl->glow, PartStyle::fade(self::opaque($border), max(0.0, min(0.8, 0.5 * $cl->glow)) * $opacity));
        if ($shape === CanvasShape::Gradient) {
            $end = PartStyle::shows($cl->gradient) ? self::opaque($cl->gradient) : self::mix($solid, $black, 0.45);
            RavenPaint::gradient($p, $rect, [$radius, $radius, $radius, $radius], PartStyle::fade(self::mix($solid, $white, 0.08), $opacity), PartStyle::fade($end, $opacity), false);
        } else {
            $p->rect($rect, PartStyle::fade($fill, $opacity), $radius);
        }
        if ($shape === CanvasShape::Tint && $rect->w > 6.0) {
            // a bar of its colour on its left
            $p->pushClip(new Rect($rect->x, $rect->y, min(3.0 * $layout['unit'], $rect->w), $rect->h));
            $p->rect($rect, PartStyle::fade($solid, $opacity), $radius);
            $p->popClip();
        }
        $cl->borderWidth > 0 && $p->rect($rect, new Color(0, 0, 0, 0), $radius, PartStyle::fade($border, $opacity), $cl->borderWidth);

        if ($item['label'] !== null && $rect->w > 26.0) {
            // the name stays in sight while the clip starts before what is shown
            $left = min(max($rect->x, $layout['lanes']->x), $rect->x + $rect->w - 40.0);
            $ink = match (true) {
                PartStyle::shows($cl->foreground) => $cl->foreground,
                $shape === CanvasShape::Solid || $shape === CanvasShape::Gradient => $this->inkOn($fill),
                $c->is('neon') => self::mix($solid, $white, 0.35),
                default => $c->dark ? self::mix($solid, $white, 0.72) : self::mix($solid, $black, 0.55),
            };
            $p->pushClip(new Rect($rect->x + 7.0, $rect->y, $rect->w - 14.0, $rect->h));
            $this->textIn($p, $item['label'], new Rect($left + 9.0, $rect->y, $rect->w, $rect->h), 0.0, PartStyle::fade($ink, $opacity), $this->smallFont());
            $p->popClip();
        }
        // the ends that stretch it show under the pointer
        if ($hot && $rect->w > 22.0 && ! $locked) {
            $grip = PartStyle::fade($shape === CanvasShape::Solid || $shape === CanvasShape::Gradient ? $this->inkOn($fill) : ($c->dark ? $white : $black),
                $this->hotPart === self::PART_BODY ? 0.35 : 0.9);
            $this->hotPart !== self::PART_RIGHT && $p->rect(new Rect($rect->x + 5.0, $rect->y + $rect->h * 0.28, 2.5, $rect->h * 0.44), $grip, 1.25);
            $this->hotPart !== self::PART_LEFT && $p->rect(new Rect($rect->x + $rect->w - 7.5, $rect->y + $rect->h * 0.28, 2.5, $rect->h * 0.44), $grip, 1.25);
        }
    }

    private static function markShape(CanvasShape $shape): MarkShape
    {
        return match ($shape) {
            CanvasShape::Circle => MarkShape::Circle, CanvasShape::Ring => MarkShape::Ring, CanvasShape::Square => MarkShape::Square,
            CanvasShape::Triangle => MarkShape::Triangle, CanvasShape::Arrow => MarkShape::Arrow, CanvasShape::None => MarkShape::None,
            default => MarkShape::Diamond,
        };
    }

    /** rvn_timeline_paint (timeline.c:823-1064) and its overlay (1066-1076). */
    public function paint(Painter $p): void
    {
        $this->palette = [];
        $look = $this->resolve();
        $c = $look['c'];
        $layout = $this->layout();
        $b = $this->bounds();
        $u = $layout['unit'];
        $lanes = $layout['lanes'];
        $font = $this->textStyle();
        $small = $this->smallFont();
        $enabled = $this->isEnabled();
        $text = PartStyle::shows($look['header']->foreground) ? $look['header']->foreground : $this->color($enabled ? Role::Text : Role::TextDisabled, $c->look);
        $dim = PartStyle::fade(PartStyle::shows($look['ruler']->foreground) ? $look['ruler']->foreground : $text, 0.55);
        $line = $look['ruler']->border ?? $c->separator;
        $head = $look['playhead']->background ?? self::opaque($c->danger);
        $accent = $c->accent;
        // the view glides to where it was asked to go
        $this->shownStart->target($this->viewStart);
        $this->shownSpan->target($this->viewSpan);
        $this->drawStart = $this->shownStart->value();
        $this->drawSpan = max($this->shownSpan->value(), 1e-4);
        $perPixel = $this->perPixel($layout);
        [$major, $minor] = $this->majorStep($perPixel);
        $x = fn (float $t) => $lanes->x + (float) (($t - $this->drawStart) / $this->span()) * $lanes->w;

        // the whole widget is one rounded surface
        $body = $look['body'];
        $p->rect($b, $body->background ?? $c->input, $body->radius, $body->border, $body->borderWidth);

        // ---- the lanes ----
        $p->pushClip($lanes);
        foreach ($this->tracks as $i => $track) {
            $lane = clone $look['track'];
            [$y, $height] = $this->trackY($layout, $i);
            if ($y > $lanes->y + $lanes->h || $y + $height < $lanes->y) {
                continue;
            }
            $track['style']->applyTo('Track', $lane);
            // every other track a little lighter, unless the track has a background of its own
            if (($i % 2 === 1 || $track['style']->has('Track', PartStyle::BACKGROUND)) && PartStyle::shows($lane->background)) {
                $p->rect(new Rect($lanes->x, $y, $lanes->w, $height), $lane->background);
            }
            PartStyle::shows($lane->border) && $p->rect(new Rect($lanes->x, $y + $height - 1.0, $lanes->w, 1.0), $lane->border);
        }
        // the marks of the ruler run down the lanes
        $first = floor($this->drawStart / $major) * $major;
        $grid = $look['grid'];
        if ($grid->shape !== CanvasShape::None && $grid->foreground !== null) {
            $g = PartStyle::fade(self::opaque($grid->foreground), $grid->opacity > 0 ? $grid->opacity : 0.75);
            for ($k = 0; $first + $k * $major / $minor <= $this->drawStart + $this->drawSpan; $k++) {
                $gx = floor($x($first + $k * $major / $minor));
                if ($gx < $lanes->x) {
                    continue;
                }
                if ($grid->shape === CanvasShape::Dots) {
                    for ($gy = $lanes->y + 6.0; $gy < $lanes->y + $lanes->h; $gy += 12.0) {
                        $p->rect(new Rect($gx - 1.0, $gy - 1.0, 2.0, 2.0), $k % $minor === 0 ? $g : PartStyle::fade($g, 0.45), 1.0);
                    }
                } else {
                    $p->rect(new Rect($gx, $lanes->y, 1.0, $lanes->h), $k % $minor === 0 ? $g : PartStyle::fade($g, 0.4));
                }
            }
        }
        // what is past the end of the sequence
        $end = $x($this->duration);
        $end < $lanes->x + $lanes->w && $p->rect(new Rect($end, $lanes->y, $lanes->x + $lanes->w - $end, $lanes->h), new Color(0, 0, 0, $c->dark ? 0.25 : 0.06));
        foreach ($this->items as $item) {
            $item['type'] === TimelineItem::Marker && $p->rect(new Rect(floor($x($item['start'])), $lanes->y, 1.0, $lanes->h),
                PartStyle::fade($this->itemColor($item), $item['selected'] ? 0.95 : 0.55));
        }

        // clips, then keys over them; what is held is drawn last, over the rest
        for ($pass = 0; $pass < 4; $pass++) {
            foreach ($this->items as $item) {
                $index = $this->trackIndex($item['track']);
                $held = $item['selected'] && in_array($this->drag, [self::DRAG_MOVE, self::DRAG_LEFT, self::DRAG_RIGHT], true) && $this->dragMoved;
                $hot = $this->hotItem === $item['id'] && $enabled && $this->drag === self::DRAG_NONE;
                if ($item['type'] === TimelineItem::Marker || $index < 0 || ($item['type'] === TimelineItem::Key) !== ($pass % 2 === 1) || $held !== ($pass >= 2)) {
                    continue;
                }
                [$y, $height] = $this->trackY($layout, $index);
                if ($y > $lanes->y + $lanes->h || $y + $height < $lanes->y) {
                    continue;
                }
                // (out of sight across: nothing to draw, no spring to keep)
                $x0 = $x($item['start']);
                $x1 = $item['type'] === TimelineItem::Clip ? $x($item['start'] + $item['length']) : $x0;
                if ($x1 < $lanes->x - 12.0 || $x0 > $lanes->x + $lanes->w + 12.0) {
                    continue;
                }
                $lift = max(0.0, min(1.2, $this->liftOf($item['id'])->target($held ? 1.0 : ($hot ? 0.45 : 0.0))->value()));
                $color = $this->itemColor($item);
                $track = $this->tracks[$index];
                $opacity = ($track['muted'] ? 0.38 : 1.0) * ($enabled ? 1.0 : 0.5);
                if ($item['type'] === TimelineItem::Clip) {
                    if ($x1 < $lanes->x || $x0 > $lanes->x + $lanes->w) {
                        continue;
                    }
                    $inset = min(4.0 * $u, $height * 0.2);
                    $rect = new Rect($x0, $y + $inset - $lift * 1.5, max($x1 - $x0, 3.0), $height - $inset * 2.0 - 1.0);
                    $cl = clone $look['clip'];
                    $cl->mix($look['clipHot'], max(0.0, min(1.0, $lift / 0.45)));
                    $item['selected'] && $cl = clone $look['clipSel'];
                    $track['style']->applyTo('Clip', $cl);
                    $item['style']?->applyTo('Item', $cl);
                    $this->paintClip($p, $look, $layout, $item, $cl, $rect, $color, $lift, $opacity, $hot, $track['locked']);
                } else {
                    $kl = clone ($item['selected'] ? $look['keySel'] : $look['key']);
                    $track['style']->applyTo('Key', $kl);
                    $item['style']?->applyTo('Item', $kl);
                    $kx = $x($item['start']);
                    $cy = $y + $height * 0.5 - $lift * 1.5;
                    if ($kx < $lanes->x - 12.0 || $kx > $lanes->x + $lanes->w + 12.0) {
                        continue;
                    }
                    $shape = $kl->shape === CanvasShape::Default ? CanvasShape::Diamond : $kl->shape;
                    $half = ($kl->size > 0 ? $kl->size * $u : $layout['key'] * 0.5) + $lift * 2.0 + ($item['selected'] ? 1.0 : 0.0);
                    $fill = PartStyle::shows($kl->background) ? $kl->background : self::opaque($color);
                    $opacity *= $kl->opacity > 0 ? $kl->opacity : 1.0;
                    RavenPaint::markGlow($p, self::markShape($shape), $kx, $cy, $half, PartStyle::fade($fill, $opacity), $kl->glow);
                    PartStyle::shows($kl->border) && RavenPaint::mark($p, self::markShape($shape), $kx, $cy, $half + 1.5, PartStyle::fade($kl->border, $opacity));
                    RavenPaint::mark($p, self::markShape($shape), $kx, $cy, $half, PartStyle::fade($fill, $opacity));
                    if ($shape === CanvasShape::Ring || ($kl->borderWidth > 0 && $shape !== CanvasShape::Circle)) {
                        // hollow: the colour of the lane inside
                        RavenPaint::mark($p, self::markShape($shape === CanvasShape::Ring ? CanvasShape::Circle : $shape), $kx, $cy, $half - max($kl->borderWidth, 1.6),
                            PartStyle::fade(self::opaque($body->background ?? $c->input), $opacity));
                    }
                    if ($item['label'] !== null) {
                        $this->textIn($p, $item['label'], new Rect($kx + $half + 6.0, $y, max($lanes->x + $lanes->w - ($kx + $half + 6.0), 0.0), $height), 0.0,
                            PartStyle::fade($item['selected'] ? $text : $dim, $opacity), $small);
                    }
                }
            }
        }

        $this->drag === self::DRAG_BOX && $this->dragMoved && $p->rect($this->box, PartStyle::fade($accent, 0.16), 3.0, PartStyle::fade($accent, 0.85), 1.0);
        $this->guide >= 0 && $p->rect(new Rect(floor($x($this->guide)), $lanes->y, 1.0, $lanes->h), PartStyle::fade($accent, 0.9));
        // the playhead
        $px = floor($x($this->time));
        $width = max($look['playhead']->size, 1.0);
        $glow = $look['playhead']->glow;
        $glow > 0.01 && $p->shadow(new Rect($px, $lanes->y, $width, $lanes->h), 0.0, 4.0 + 6.0 * $glow, PartStyle::fade($head, 0.5 * $glow));
        $p->rect(new Rect($px - 1.0, $lanes->y, $width + 2.0, $lanes->h), PartStyle::fade($head, 0.18));
        $p->rect(new Rect($px - floor(($width - 1.0) * 0.5), $lanes->y, $width, $lanes->h), $head);
        $p->popClip();

        // ---- the headers of the tracks: the column goes down to the bottom ----
        $headers = $layout['headers'];
        $p->rect(new Rect($b->x + 1.0, $b->y + $layout['rulerH'], $headers->w - 1.0, $b->h - $layout['rulerH'] - 1.0), $look['header']->background ?? $c->panel,
            [0.0, 0.0, 0.0, max($body->radius - 1.0, 0.0)]);
        $p->pushClip($headers);
        foreach ($this->tracks as $i => $track) {
            [$y, $height] = $this->trackY($layout, $i);
            if ($y > $headers->y + $headers->h || $y + $height < $headers->y) {
                continue;
            }
            $color = $this->trackColor($i);
            $toggle = $layout['toggle'];
            $right = $headers->x + $headers->w - 8.0 * $u;
            $dot = 10.0 * $u;
            if ($c->is('blender') || $c->is('blueprint')) {
                // a band of the colour of the track along its edge
                $p->rect(new Rect($b->x + 1.0, $y + 1.0, 3.0 * $u, $height - 2.0), PartStyle::fade($color, $track['muted'] ? 0.4 : 1.0));
            } else {
                $p->rect(new Rect($b->x + 8.0 * $u, $y + ($height - $dot) * 0.5, $dot, $dot), PartStyle::fade($color, $track['muted'] ? 0.4 : 1.0), $dot * 0.5);
                $c->is('neon') && RavenPaint::markGlow($p, MarkShape::Circle, $b->x + 8.0 * $u + $dot * 0.5, $y + $height * 0.5, $dot * 0.5, $color, 0.6);
            }
            $p->pushClip(new Rect($b->x + 24.0 * $u, $y, max($right - $b->x - $toggle * 2.0 - 32.0 * $u, 0.0), $height));
            $this->textIn($p, $track['name'], new Rect($b->x + 26.0 * $u, $y, $headers->w, $height), 0.0, $track['muted'] ? $dim : $text, $font);
            $p->popClip();
            // mute and lock: two small toggles
            for ($t = 0; $t < 2; $t++) {
                $on = $t === 0 ? $track['muted'] : $track['locked'];
                $hot = $this->hotToggle === $i * 2 + $t && $enabled;
                $rect = new Rect($right - $toggle * (2 - $t) - ($t === 0 ? 3.0 * $u : 0.0), $y + ($height - $toggle) * 0.5, $toggle, $toggle);
                $fill = $on ? ($t === 0 ? $c->warning : $accent) : PartStyle::fade($text, $hot ? 0.16 : 0.07);
                $p->rect($rect, $fill, min(6.0, $look['clip']->radius) * $u);
                $this->textIn($p, $t === 0 ? 'M' : 'L', $rect, 0.5, $on ? $this->inkOn($fill) : ($hot ? $text : $dim), $small);
            }
            PartStyle::shows($look['track']->border) && $p->rect(new Rect($b->x, $y + $height - 1.0, $headers->w, 1.0), $look['track']->border);
        }
        $p->popClip();
        $look['header']->border !== null && $p->rect(new Rect($b->x + $headers->w - 1.0, $b->y, 1.0, $b->h), $look['header']->border);

        // ---- the ruler ----
        $rh = $layout['rulerH'];
        $p->pushClip(new Rect($b->x, $b->y, $b->w, $rh));
        $corner = max($body->radius - 1.0, 0.0);
        $p->rect(new Rect($b->x + 1.0, $b->y + 1.0, $b->w - 2.0, $rh - 1.0), $look['ruler']->background ?? $c->panel, [$corner, $corner, 0.0, 0.0]);
        $p->rect(new Rect($b->x, $b->y + $rh - 1.0, $b->w, 1.0), $line);
        // the time of the playhead, where the headers leave room for it
        $mono = $this->textStyle(FontRole::Mono);
        $this->textIn($p, $this->formatTime($this->time, $this->fps > 0 ? 1.0 : 0.01), new Rect($b->x + 10.0 * $u, $b->y, $headers->w - 16.0 * $u, $rh), 0.0,
            $c->is('blender') || $c->is('neon') ? $head : $text, $mono);
        $p->popClip();

        $ruler = $layout['ruler'];
        $ascent = $this->measurer()->metrics($small)['ascent'];
        $p->pushClip($ruler);
        for ($k = 0; $first + $k * $major / $minor <= $this->drawStart + $this->drawSpan; $k++) {
            $time = $first + $k * $major / $minor;
            $tx = floor($x($time));
            $labelled = $k % $minor === 0;
            $tick = ($labelled ? 11.0 : 5.0) * $u;
            if ($tx < $ruler->x - 60.0 * $u) {
                continue;
            }
            $p->rect(new Rect($tx, $b->y + $rh - $tick, 1.0, $tick), $labelled ? $dim : PartStyle::fade($dim, 0.6));
            $labelled && $p->text($this->formatTime($time, $major), $tx + 4.0 * $u, $b->y + 4.0 * $u + $ascent, $dim, $small);
        }
        foreach ($this->items as $item) {
            if ($item['type'] !== TimelineItem::Marker) {
                continue;
            }
            $ml = clone $look['marker'];
            $item['style']?->applyTo('Item', $ml);
            $color = PartStyle::shows($ml->background) ? $ml->background : $this->itemColor($item);
            $mx = floor($x($item['start']));
            $width = $this->flagWidth($layout, $item);
            // a flag on its pole
            $ml->glow > 0.01 && $p->shadow(new Rect($mx, $b->y + 3.0, $width, $layout['flag']), 4.0, 4.0 + 6.0 * $ml->glow, PartStyle::fade($color, 0.5 * $ml->glow));
            $p->rect(new Rect($mx, $b->y + 3.0, 1.0, $rh - 3.0), $color);
            $p->rect(new Rect($mx, $b->y + 3.0, $width, $layout['flag']), $color, min($ml->radius * $u, $layout['flag'] * 0.5),
                $item['selected'] ? new Color(1, 1, 1, 1) : null, $item['selected'] ? 1.5 : 0.0);
            $item['label'] !== null && $this->textIn($p, $item['label'], new Rect($mx + 6.0 * $u, $b->y + 3.0, $width, $layout['flag']), 0.0,
                PartStyle::shows($ml->foreground) ? $ml->foreground : $this->inkOn($color), $small);
        }
        // the head of the playhead: a tab that points down
        $hx = floor($x($this->time)) + 0.5;
        $grow = $this->drag === self::DRAG_SCRUB ? 1.5 * $u : 0.0;
        $glow > 0.01 && $p->shadow(new Rect($hx - 6.0 * $u, $b->y + $rh - 17.0 * $u, 12.0 * $u, 16.0 * $u), 3.0 * $u, 6.0 * $glow + 3.0, PartStyle::fade($head, 0.5 * $glow));
        $p->rect(new Rect($hx - 6.0 * $u - $grow, $b->y + $rh - 17.0 * $u - $grow, 12.0 * $u + $grow * 2.0, 11.0 * $u + $grow), $head, 3.0 * $u);
        $p->triangle($hx - 6.0 * $u - $grow, $b->y + $rh - 7.0 * $u, $hx + 6.0 * $u + $grow, $b->y + $rh - 7.0 * $u, $hx, $b->y + $rh - 0.5, $head);
        $p->popClip();

        // the overlay: the bars
        $this->syncBars();
        $this->timeBar->paint($p, $c->look, $layout['timeTrack'], false, true, false);
        $this->trackBar->paint($p, $c->look, $layout['trackTrack'], true, true, false);
    }

    // ------------------------------------------------------------- pointing

    /** @return array{int, int} the item at a point and which part of it (rvn_tl_hit, timeline.c:1083-1132) */
    private function hit(array $layout, float $x, float $y): array
    {
        $b = $this->bounds();
        if ($y < $b->y + $layout['rulerH']) {
            if ($x < $layout['ruler']->x) {
                return [0, self::PART_NONE];
            }
            for ($i = count($this->items) - 1; $i >= 0; $i--) {
                $item = $this->items[$i];
                if ($item['type'] !== TimelineItem::Marker) {
                    continue;
                }
                $mx = $this->xAt($layout, $item['start']);
                // the flag as it is painted, and a little around it
                if ($x >= $mx - 3.0 && $x <= $mx + $this->flagWidth($layout, $item) && $y >= $b->y + 2.0 && $y <= $b->y + 4.0 + $layout['flag']) {
                    return [$item['id'], self::PART_BODY];
                }
            }

            return [0, self::PART_NONE];
        }
        if (! $layout['lanes']->contains($x, $y)) {
            return [0, self::PART_NONE];
        }
        // keys are over the clips, and what was added last is over the rest
        for ($pass = 0; $pass < 2; $pass++) {
            for ($i = count($this->items) - 1; $i >= 0; $i--) {
                $item = $this->items[$i];
                $index = $this->trackIndex($item['track']);
                if ($index < 0 || $item['type'] === TimelineItem::Marker || ($item['type'] === TimelineItem::Key) !== ($pass === 0)) {
                    continue;
                }
                [$top, $height] = $this->trackY($layout, $index);
                if ($y < $top || $y >= $top + $height) {
                    continue;
                }
                if ($item['type'] === TimelineItem::Key) {
                    if (abs($x - $this->xAt($layout, $item['start'])) <= $layout['key'] * 0.5 + 3.0) {
                        return [$item['id'], self::PART_BODY];
                    }
                } else {
                    $x0 = $this->xAt($layout, $item['start']);
                    $x1 = max($this->xAt($layout, $item['start'] + $item['length']), $x0 + 3.0);
                    if ($x >= $x0 && $x <= $x1) {
                        // a narrow clip is all body: it can always be moved
                        $edge = $x1 - $x0 > $layout['edge'] * 3.0 ? $layout['edge'] : 0.0;

                        return [$item['id'], $x < $x0 + $edge ? self::PART_LEFT : ($x > $x1 - $edge ? self::PART_RIGHT : self::PART_BODY)];
                    }
                }
            }
        }

        return [0, self::PART_NONE];
    }

    private function xAt(array $layout, float $time): float
    {
        return $layout['lanes']->x + (float) (($time - $this->drawStart) / $this->span()) * $layout['lanes']->w;
    }

    private function toggleAt(array $layout, float $x, float $y): int
    {
        $toggle = $layout['toggle'];
        $h = $layout['headers'];
        $right = $h->x + $h->w - 8.0 * $layout['unit'];
        if (! $h->contains($x, $y)) {
            return -1;
        }
        $row = $this->rowAt($layout, $y);
        if ($row < 0 || $row >= count($this->tracks)) {
            return -1;
        }
        [$top, $height] = $this->trackY($layout, $row);
        if ($y < $top + ($height - $toggle) * 0.5 || $y > $top + ($height + $toggle) * 0.5) {
            return -1;
        }
        if ($x >= $right - $toggle * 2.0 - 3.0 * $layout['unit'] && $x < $right - $toggle - 3.0 * $layout['unit']) {
            return $row * 2;
        }

        return $x >= $right - $toggle && $x < $right ? $row * 2 + 1 : -1;
    }

    private function itemLocked(array $item): bool
    {
        $i = $this->trackIndex($item['track']);

        return $i >= 0 && $this->tracks[$i]['locked'];
    }

    private function updateHot(float $x, float $y): void
    {
        $layout = $this->layout();
        [$item, $part] = $this->hit($layout, $x, $y);
        $toggle = $this->toggleAt($layout, $x, $y);
        // shortcut: Raven shows the resize cursor on a clip's ends and the hand on a toggle; no pointer-cursor API (Blocked #7)
        if ($item !== $this->hotItem || $part !== $this->hotPart || $toggle !== $this->hotToggle) {
            [$this->hotItem, $this->hotPart, $this->hotToggle] = [$item, $part, $toggle];
            $this->invalidatePaint();
        }
    }

    /** @return array{int, int} the item under a point and its part ('none', 'body', 'left', 'right' as 0..3); for those who drive it */
    public function itemAt(float $x, float $y): array
    {
        return $this->hit($this->layout(), $x, $y);
    }

    /** @return array{float, float} the middle of a track's lane at a time */
    public function pointAt(float $time, int $track): array
    {
        $layout = $this->layout();
        [$y, $height] = $this->trackY($layout, max(0, $this->trackIndex($track)));

        return [$this->xAt($layout, $time), $y + $height / 2];
    }

    // ------------------------------------------------------------- gestures

    private function remember(): void
    {
        foreach ($this->items as $i => $item) {
            $this->items[$i]['fromStart'] = $item['start'];
            $this->items[$i]['fromLength'] = $item['length'];
            $this->items[$i]['fromTrack'] = $item['track'];
        }
    }

    private function restore(): void
    {
        foreach ($this->items as $i => $item) {
            $this->items[$i]['start'] = $item['fromStart'];
            $this->items[$i]['length'] = $item['fromLength'];
            $this->items[$i]['track'] = $item['fromTrack'];
        }
    }

    private function minimumLength(array $layout): float
    {
        return $this->fps > 0 ? 1.0 / $this->fps : $this->perPixel($layout) * 4.0;
    }

    /** Every selected item by the same time, clips and keys by the same number of tracks (rvn_tl_drag_move, timeline.c:1201-1269). */
    private function dragMove(array $layout, float $x, float $y, bool $free): void
    {
        $leadIndex = $this->itemIndex($this->dragItem);
        if ($leadIndex < 0) {
            return;
        }
        $lead = $this->items[$leadIndex];
        $delta = $this->timeAt($layout, $x) - $this->pressTime;
        $this->guide = -1.0;
        if (! $free && $this->snapping) {
            // the item under the hand decides: by its start, or by its end when that is closer to something
            $start = $lead['fromStart'] + $delta;
            $end = $start + $lead['fromLength'];
            $atStart = $this->magnet($layout, $start, true);
            $atEnd = $lead['type'] === TimelineItem::Clip ? $this->magnet($layout, $end, true) : null;
            if ($atStart !== null && ($atEnd === null || abs($atStart - $start) <= abs($atEnd - $end))) {
                $delta += $atStart - $start;
                $this->guide = $atStart;
            } elseif ($atEnd !== null) {
                $delta += $atEnd - $end;
                $this->guide = $atEnd;
            } else {
                $delta = $this->quantize($layout, $start) - $lead['fromStart'];
            }
        }
        // nothing leaves the sequence, and nothing changes place with regard to the rest
        $rows = $this->rowAt($layout, $y) - $this->pressRow;
        $first = true;
        $low = $high = 0.0;
        $rowLow = $rowHigh = 0;
        foreach ($this->items as $item) {
            if (! $item['selected']) {
                continue;
            }
            $before = -$item['fromStart'];
            $after = $this->duration - ($item['fromStart'] + $item['fromLength']);
            ($first || $before > $low) && $low = $before;
            ($first || $after < $high) && $high = $after;
            if ($item['type'] !== TimelineItem::Marker) {
                $row = $this->trackIndex($item['fromTrack']);
                ($first || -$row > $rowLow) && $rowLow = -$row;
                ($first || count($this->tracks) - 1 - $row < $rowHigh) && $rowHigh = count($this->tracks) - 1 - $row;
            }
            $first = false;
        }
        $delta = max($low, min($high, $delta));
        $lead['type'] === TimelineItem::Marker && $rows = 0;
        $rows = max($rowLow, min(max($rowLow, $rowHigh), $rows));
        foreach ($this->items as $i => $item) {
            if (! $item['selected']) {
                continue;
            }
            $start = $item['fromStart'] + $delta;
            $track = $item['fromTrack'];
            if ($item['type'] !== TimelineItem::Marker && $rows !== 0) {
                $target = $this->trackIndex($item['fromTrack']) + $rows;
                // (a locked track takes nothing)
                if ($target >= 0 && $target < count($this->tracks) && ! $this->tracks[$target]['locked']) {
                    $track = $this->tracks[$target]['id'];
                }
            }
            if ($start !== $item['start'] || $track !== $item['track']) {
                $this->items[$i]['start'] = $start;
                $this->items[$i]['track'] = $track;
                $this->gestureChanged = true;
                $this->notifyItem(TimelineItemChanged::class, $item['id']);
            }
        }
        $this->invalidatePaint();
    }

    /** Stretches a clip by one end (rvn_tl_drag_stretch, timeline.c:1271-1311). */
    private function dragStretch(array $layout, float $x, bool $free): void
    {
        $i = $this->itemIndex($this->dragItem);
        if ($i < 0) {
            return;
        }
        $minimum = $this->minimumLength($layout);
        $time = $this->timeAt($layout, $x);
        $this->guide = -1.0;
        if (! $free && $this->snapping) {
            // (its own ends are among the candidates while it is not marked as moving)
            $was = $this->items[$i]['selected'];
            $this->items[$i]['selected'] = true;
            $found = $this->magnet($layout, $time, true);
            $this->items[$i]['selected'] = $was;
            if ($found !== null) {
                $time = $this->guide = $found;
            } else {
                $time = $this->quantize($layout, $time);
            }
        }
        $item = $this->items[$i];
        if ($this->drag === self::DRAG_LEFT) {
            $end = $item['fromStart'] + $item['fromLength'];
            $time = min(max(0.0, $time), $end - $minimum);
            [$start, $length] = [$time, $end - $time];
        } else {
            $time = max(min($this->duration, $time), $item['fromStart'] + $minimum);
            [$start, $length] = [$item['fromStart'], $time - $item['fromStart']];
        }
        if ($start !== $item['start'] || $length !== $item['length']) {
            $this->items[$i]['start'] = $start;
            $this->items[$i]['length'] = $length;
            $this->gestureChanged = true;
            $this->notifyItem(TimelineItemChanged::class, $item['id']);
        }
        $this->invalidatePaint();
    }

    /** The selection rectangle (rvn_tl_drag_box, timeline.c:1313-1338). */
    private function dragBox(array $layout, float $x, float $y): void
    {
        $l = $layout['lanes'];
        $x = max($l->x, min($l->x + $l->w, $x));
        $y = max($l->y, min($l->y + $l->h, $y));
        $this->box = new Rect(min($this->pressX, $x), min($this->pressY, $y), abs($x - $this->pressX), abs($y - $this->pressY));
        $changed = false;
        foreach ($this->items as $i => $item) {
            $index = $this->trackIndex($item['track']);
            if ($index < 0 || $item['type'] === TimelineItem::Marker) {
                continue;
            }
            [$top, $height] = $this->trackY($layout, $index);
            $x0 = $this->xAt($layout, $item['start']);
            $x1 = $item['type'] === TimelineItem::Clip ? $this->xAt($layout, $item['start'] + $item['length']) : $x0;
            $inside = $x1 >= $this->box->x && $x0 <= $this->box->x + $this->box->w && $top + $height > $this->box->y && $top < $this->box->y + $this->box->h;
            // (fromTrack -1: selected before the rectangle, Shift adds to it)
            $inside = $inside || $item['fromTrack'] < 0;
            if ($inside !== $item['selected']) {
                $this->items[$i]['selected'] = $inside;
                $changed = true;
            }
        }
        $changed && $this->notifyItem(TimelineSelectionChanged::class, 0);
        $this->invalidatePaint();
    }

    /** Ends a gesture; cancelled, a move goes back where it started (rvn_tl_end_drag, timeline.c:1340-1358). */
    private function endDrag(bool $cancel): void
    {
        $changed = $this->gestureChanged;
        if ($this->drag === self::DRAG_NONE) {
            return;
        }
        if ($this->drag === self::DRAG_BOX) {
            foreach ($this->items as $i => $item) {
                $this->items[$i]['fromTrack'] = $item['track'];
            }
        } elseif ($cancel && in_array($this->drag, [self::DRAG_MOVE, self::DRAG_LEFT, self::DRAG_RIGHT], true)) {
            $this->restore();
        }
        $this->drag = self::DRAG_NONE;
        $this->dragItem = 0;
        $this->guide = -1.0;
        $this->gestureChanged = false;
        $this->invalidatePaint();
        // one mail for the whole gesture
        $changed && $this->post(new TimelineEdited($this->window(), $this->path(), $this->uuid()));
    }

    /** Zooms around $x by $factor (rvn_tl_zoom). */
    private function zoomAt(array $layout, float $x, float $factor): void
    {
        $anchor = $this->timeAt($layout, $x);
        $fraction = max(0.0, min(1.0, ($x - $layout['lanes']->x) / $layout['lanes']->w));
        $this->viewSpan *= $factor;
        $this->clampView();
        $this->setViewNow($anchor - $fraction * $this->viewSpan, $this->viewSpan, true);
    }

    private function removeSelected(): void
    {
        if (! $this->removable) {
            return;
        }
        $any = false;
        for ($i = count($this->items) - 1; $i >= 0; $i--) {
            $item = $this->items[$i];
            if ($item['selected'] && ! $this->itemLocked($item)) {
                $this->removeItemAt($i);
                $this->notifyItem(TimelineItemRemoved::class, $item['id']);
                $any = true;
            }
        }
        if ($any) {
            $this->invalidatePaint();
            $this->notifyItem(TimelineSelectionChanged::class, 0);
            $this->post(new TimelineEdited($this->window(), $this->path(), $this->uuid()));
        }
    }

    // --------------------------------------------------------------- events

    /** rvn_timeline_event (timeline.c:1396-1631). */
    public function handle(SilkEvent $e): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }
        $layout = $this->layout();
        $x = $e->x;
        $y = $e->y;
        $b = $this->bounds();
        switch ($e->type) {
            case EventType::FocusGained:
            case EventType::FocusLost:
                $this->hasFocus = $e->type === EventType::FocusGained;

                return false;
            case EventType::Wheel:
                $amount = $e->wheelY != 0.0 ? $e->wheelY : $e->wheelX;
                if ($e->modifiers->ctrl) {
                    $this->zoomAt($layout, $x, 0.82 ** $amount);

                    return true;
                }
                // the wheel scrolls the tracks when there are more than fit, else it moves along the time
                $this->syncBars();
                if (! $e->modifiers->shift && $e->wheelY != 0.0 && $this->trackBar->needed()) {
                    $changed = $this->trackBar->wheel($e->wheelY * $this->defaultTrackHeight());
                    $this->invalidatePaint();

                    return $changed;
                }
                $before = $this->viewStart;
                $this->setViewNow($this->viewStart - $amount * $this->viewSpan * 0.12, $this->viewSpan, true);

                return $this->viewStart !== $before;
            case EventType::MouseDown:
                return $this->mouseDown($e, $layout, $x, $y, $b);
            case EventType::MouseMove:
                return $this->mouseMove($e, $layout, $x, $y);
            case EventType::MouseUp:
                if ($this->drag === self::DRAG_NONE) {
                    return $this->bars($e, $layout);
                }
                if (($this->drag === self::DRAG_PAN) !== ($e->button === MouseButton::MIDDLE)) {
                    return true;
                }
                // a click on one of several selected items, without moving: it becomes the only one
                if ($this->drag === self::DRAG_MOVE && ! $this->dragMoved && ! $e->modifiers->ctrl && ! $e->modifiers->shift && count($this->selection()) > 1) {
                    $this->selectOnly($this->dragItem);
                    $this->notifyItem(TimelineSelectionChanged::class, $this->dragItem);
                }
                $this->endDrag(false);
                $this->updateHot($x, $y);

                return true;
            case EventType::Leave:
                $this->hotItem = 0;
                $this->hotToggle = -1;
                $this->invalidatePaint();

                return false;
            case EventType::KeyDown:
                return $this->key($e, $layout);
            default:
                return false;
        }
    }

    private function bars(SilkEvent $e, array $layout): bool
    {
        $this->syncBars();
        $before = $this->timeBar->offset;
        $used = $this->timeBar->event($e, $layout['timeTrack'], false) || $this->trackBar->event($e, $layout['trackTrack'], true);
        if ($this->timeBar->offset !== $before) {
            $this->setViewNow($this->timeBar->offset * ($this->viewSpan / $layout['lanes']->w), $this->viewSpan, false);
        }
        $used && $this->invalidatePaint();

        return $used;
    }

    private function mouseDown(SilkEvent $e, array $layout, float $x, float $y, Rect $b): bool
    {
        if ($this->drag !== self::DRAG_NONE) {
            return true;
        }
        if ($e->button === MouseButton::LEFT && $this->bars($e, $layout)) {
            return true;
        }
        $this->pressX = $x;
        $this->pressY = $y;
        $this->pressTime = $this->timeAt($layout, $x);
        $this->pressRow = $this->rowAt($layout, $y);
        $this->dragMoved = false;
        $this->gestureChanged = false;
        if ($e->button === MouseButton::MIDDLE) {
            $this->drag = self::DRAG_PAN;
            $this->panStart = $this->viewStart;
            $this->panScroll = $this->trackBar->offset;

            return true;
        }
        if ($e->button !== MouseButton::LEFT) {
            return false;
        }
        $toggle = $this->toggleAt($layout, $x, $y);
        if ($toggle >= 0) {
            $key = $toggle % 2 === 0 ? 'muted' : 'locked';
            $this->tracks[intdiv($toggle, 2)][$key] = ! $this->tracks[intdiv($toggle, 2)][$key];
            $this->invalidatePaint();
            $this->post(new TimelineEdited($this->window(), $this->path(), $this->uuid()));

            return true;
        }
        [$id] = $hit = $this->hit($layout, $x, $y);
        $index = $this->itemIndex($id);
        if ($index >= 0) {
            $add = $e->modifiers->ctrl || $e->modifiers->meta || $e->modifiers->shift;
            if ($e->clicks >= 2) {
                $this->notifyItem(TimelineItemActivated::class, $id);

                return true;
            }
            if ($add) {
                $this->items[$index]['selected'] = ! $this->items[$index]['selected'];
                $this->notifyItem(TimelineSelectionChanged::class, $id);
            } elseif (! $this->items[$index]['selected']) {
                $this->selectOnly($id);
                $this->notifyItem(TimelineSelectionChanged::class, $id);
            }
            $this->invalidatePaint();
            $item = $this->items[$index];
            if ($item['selected'] && ! $this->itemLocked($item)) {
                $this->drag = match ($hit[1]) {
                    self::PART_LEFT => self::DRAG_LEFT,
                    self::PART_RIGHT => self::DRAG_RIGHT,
                    default => self::DRAG_MOVE,
                };
                $this->dragItem = $id;
                $this->remember();
            }

            return true;
        }
        if ($y < $b->y + $layout['rulerH'] && $x >= $layout['ruler']->x) {
            // the ruler: the playhead comes under the pointer and follows it
            $this->drag = self::DRAG_SCRUB;
            $this->setTimeNow($this->scrubTime($layout, $this->pressTime, $e->modifiers->alt), true);

            return true;
        }
        if ($layout['lanes']->contains($x, $y)) {
            // empty space: a selection rectangle; a simple click only forgets the selection
            $add = $e->modifiers->ctrl || $e->modifiers->meta || $e->modifiers->shift;
            $had = false;
            foreach ($this->items as $i => $item) {
                $had = $had || $item['selected'];
                $this->items[$i]['fromTrack'] = $add && $item['selected'] ? -1 : $item['track'];
                $add || $this->items[$i]['selected'] = false;
            }
            $this->drag = self::DRAG_BOX;
            $this->box = new Rect($x, $y, 0, 0);
            $this->invalidatePaint();
            $had && ! $add && $this->notifyItem(TimelineSelectionChanged::class, 0);
        }

        return true;
    }

    private function scrubTime(array $layout, float $time, bool $free): float
    {
        if (! $this->snapping || $free) {
            return $time;
        }

        return $this->magnet($layout, $time, false) ?? $this->quantize($layout, $time);
    }

    private function mouseMove(SilkEvent $e, array $layout, float $x, float $y): bool
    {
        if ($this->drag === self::DRAG_NONE) {
            if ($this->bars($e, $layout)) {
                return true;
            }
            $this->updateHot($x, $y);
            $this->invalidatePaint();

            return false;
        }
        if (! $this->dragMoved && abs($x - $this->pressX) < 3.0 && abs($y - $this->pressY) < 3.0 && $this->drag !== self::DRAG_SCRUB) {
            return true;
        }
        $this->dragMoved = true;
        match ($this->drag) {
            self::DRAG_SCRUB => $this->setTimeNow($this->scrubTime($layout, $this->timeAt($layout, $x), $e->modifiers->alt), true),
            self::DRAG_MOVE => $this->dragMove($layout, $x, $y, $e->modifiers->alt),
            self::DRAG_LEFT, self::DRAG_RIGHT => $this->dragStretch($layout, $x, $e->modifiers->alt),
            self::DRAG_BOX => $this->dragBox($layout, $x, $y),
            self::DRAG_PAN => $this->pan($layout, $x, $y),
            default => null,
        };

        return true;
    }

    private function pan(array $layout, float $x, float $y): void
    {
        $this->setViewNow($this->panStart - ($x - $this->pressX) * ($this->viewSpan / $layout['lanes']->w), $this->viewSpan, false);
        $this->trackBar->set($this->panScroll - ($y - $this->pressY));
    }

    private function key(SilkEvent $e, array $layout): bool
    {
        $step = $this->fps > 0 ? 1.0 / $this->fps : $this->perPixel($layout) * 10.0;
        $e->modifiers->shift && $step *= 10.0;
        switch ($e->key) {
            case Key::ESCAPE:
                if ($this->drag === self::DRAG_NONE) {
                    return false;
                }
                $this->endDrag(true);

                return true;
            case Key::LEFT:
                $this->setTimeNow($this->time - $step, true);

                return true;
            case Key::RIGHT:
                $this->setTimeNow($this->time + $step, true);

                return true;
            case Key::HOME:
                $this->setTimeNow(0.0, true);
                $this->setViewNow(0.0, $this->viewSpan, true);

                return true;
            case Key::END:
                $this->setTimeNow($this->duration, true);
                $this->setViewNow($this->duration, $this->viewSpan, true);

                return true;
            case Key::SPACE:
                $this->setPlayingNow(! $this->playing);

                return true;
            case Key::DELETE:
            case Key::BACKSPACE:
                $this->removeSelected();

                return true;
            case Key::A:
                if (! $e->modifiers->ctrl && ! $e->modifiers->meta) {
                    return false;
                }
                foreach ($this->items as $i => $item) {
                    $this->items[$i]['selected'] = true;
                }
                $this->invalidatePaint();
                $this->notifyItem(TimelineSelectionChanged::class, 0);

                return true;
            default:
                return false;
        }
    }
}
