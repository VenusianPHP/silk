<?php

namespace Silk\Widgets;

use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Look\Metric;
use Silk\Contracts\Look\Role;
use Silk\Contracts\Painter\Painter;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\MouseButton;
use Surface\Contracts\Windows\Mail\View\SelectionChanged;

/**
 * `comboBox(name, value, options)` as TKComboBox: a TextInput with a list of options (the chevron) and
 * a completion list under the field. Choosing sets the value, hides the list and posts SelectionChanged
 * (the index in options(), -1 for a completion). The list never takes focus: Up and Down move its
 * highlight from the field; Return chooses, or with nothing highlighted posts TextSubmitted. Escape,
 * focus leaving, a choice, setValue() and setCompletions([]) hide it.
 */
class ComboBox extends TextInput implements Chooses
{
    /** @var list<string> */
    private array $options;

    /** @var list<string> */
    private array $completions = [];

    /** @var list<string> what the open list shows */
    private array $shown = [];

    private bool $showingOptions = false;

    private ?DropdownList $list = null;

    /** @param list<string> $options */
    public function __construct(string $name, string $value = '', array $options = [])
    {
        parent::__construct($name, $value);
        $this->options = array_values($options);
    }

    /** @return list<string> */
    public function options(): array
    {
        return $this->options;
    }

    public function setOptions(array $options): static
    {
        $this->options = array_values($options);

        return $this;
    }

    /** @return list<string> */
    public function completions(): array
    {
        return $this->completions;
    }

    /** Shows them under the field; [] hides the list. */
    public function setCompletions(array $completions): static
    {
        $this->completions = array_values($completions);
        $this->completions === [] ? $this->hideList() : $this->showList($this->completions, false);

        return $this;
    }

    public function setValue(string $value): static
    {
        $this->hideList();

        return parent::setValue($value);
    }

    public function list(): ?DropdownList
    {
        return $this->list;
    }

    public function choose(int $index): void
    {
        $text = $this->shown[$index] ?? null;
        $fromOptions = $this->showingOptions;
        if ($text === null) {
            return;
        }
        $this->setValue($text);
        $this->completions = [];
        $this->post(new SelectionChanged($this->window(), $this->path(), $this->uuid(), $fromOptions ? $index : -1, $text));
    }

    protected function measureContent(Size $available): Size
    {
        $s = parent::measureContent($available);

        return new Size($s->w + $this->look()->metric(Metric::Indent), $s->h);
    }

    public function handle(SilkEvent $e): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }
        if ($e->type === EventType::FocusLost) {
            $this->hideList();
        }
        if ($e->type === EventType::MouseDown && $e->button === MouseButton::LEFT && $this->onChevron($e->x)) {
            $this->list?->isOpen() ? $this->hideList() : $this->showList($this->options, true);

            return true;
        }
        if ($e->type === EventType::KeyDown && $this->list?->isOpen()) {
            switch ($e->key) {
                case Key::DOWN:
                case Key::UP:
                    $this->list->setHighlight($this->list->highlight() + ($e->key === Key::DOWN ? 1 : -1));

                    return true;
                case Key::ENTER:
                    if ($this->list->highlight() >= 0) {
                        $this->choose($this->list->highlight());

                        return true;
                    }
                    break;
            }
        }

        return parent::handle($e);
    }

    public function paint(Painter $p): void
    {
        parent::paint($p);
        $look = $this->look();
        $b = $this->bounds();
        $x = $b->x + $b->w - $look->metric(Metric::PaddingX) - 4;
        $cy = $b->y + $b->h / 2;
        $ink = $this->color($this->isEnabled() ? Role::Text : Role::TextDisabled, $look);
        $p->line($x - 4, $cy - 2, $x, $cy + 2, $ink, 1.5);
        $p->line($x, $cy + 2, $x + 4, $cy - 2, $ink, 1.5);
    }

    /** The chevron's strip at the right end of the field, one Indent wide plus padding. */
    private function onChevron(float $x): bool
    {
        $b = $this->bounds();

        return $x >= $b->x + $b->w - $this->look()->metric(Metric::Indent) - $this->look()->metric(Metric::PaddingX);
    }

    /** @param list<string> $items */
    private function showList(array $items, bool $options): void
    {
        $this->hideList();
        if ($items === [] || $this->tree() === null) {
            return;
        }
        $this->shown = $items;
        $this->showingOptions = $options;
        $this->list = (new DropdownList($this->name().'-list', $this, $items, -1, false))->in($this->tree());
        $this->list->openAt($this->bounds()->x, $this->bounds()->y + $this->bounds()->h);
    }

    private function hideList(): void
    {
        $this->list?->close();
    }
}
