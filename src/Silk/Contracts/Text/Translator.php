<?php

namespace Silk\Contracts\Text;

/** The words widgets show (Raven's text table, date_picker.c:68-84): the text for $key, or $english when there is none. */
interface Translator
{
    public function text(string $key, string $english): string;
}
