<?php

namespace Silk\Widgets\Blocks;

/** Where a block hangs: under another one, in a mouth of a C block (index = the mouth), or in a value slot (index = the slot). */
enum BlockPlace: string
{
    case Next = 'next';
    case Body = 'body';
    case Input = 'input';
}
