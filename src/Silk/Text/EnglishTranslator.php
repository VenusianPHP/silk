<?php

namespace Silk\Text;

use Silk\Contracts\Text\Translator;

/** The words as Raven ships them: English. Bind another 'silk.translator' to translate. */
final class EnglishTranslator implements Translator
{
    public function text(string $key, string $english): string
    {
        return $english;
    }
}
