<?php

namespace Silk\Widgets;

use Silk\Core\Tree\Tree;
use Surface\Contracts\Windows\Mail\Services\AlertAnswered;

/**
 * The in-window twin of Surface's Alert: a title, a message and 1..n buttons. A press posts
 * AlertAnswered(name, index) once and closes it; Escape answers the last button (the Alert's cancel).
 */
class MessageBox extends Dialog
{
    private static int $count = 0;

    private bool $answered = false;

    /** @var list<Button> */
    private array $buttons = [];

    /** @var (\Closure(int): void)|null */
    private ?\Closure $answeredBy = null;

    /**
     * @param  list<string>  $buttons
     * @param  (\Closure(int): void)|null  $answered  hears the answer too (a widget that asked: Raven's Rmessage_box returns it)
     */
    public static function show(Tree $tree, string $title, string $text, array $buttons = ['OK'], ?\Closure $answered = null): self
    {
        $box = new self('alert-'.++self::$count);
        $box->answeredBy = $answered;
        $box->add(new Label('title', $title));
        $box->add(new Label('message', $text));
        $box->add($row = new Row('buttons', 8));
        foreach (array_values($buttons) as $i => $label) {
            $row->add($box->buttons[] = new class("button-{$i}", $label, $box, $i) extends Button
            {
                public function __construct(string $name, string $label, private readonly MessageBox $box, private readonly int $index)
                {
                    parent::__construct($name, $label);
                }

                protected function activate(): void
                {
                    $this->box->answer($this->index);
                }
            });
        }

        return $box->in($tree)->openAt(0, 0);
    }

    /** The button that answers $index (button() is the creation method every dialog has). */
    public function answerButton(int $index): Button
    {
        return $this->buttons[$index];
    }

    /** Posts the answer once and closes. */
    public function answer(int $index): void
    {
        if ($this->answered) {
            return;
        }
        $this->answered = true;
        $this->post(new AlertAnswered($this->name(), $index));
        parent::close();
        $this->answeredBy !== null && ($this->answeredBy)($index);
    }

    /** Closing without a press (Escape) answers the last button. */
    public function close(): static
    {
        if (! $this->answered && $this->isOpen()) {
            $this->answer(count($this->buttons) - 1);
        }

        return parent::close();
    }
}
