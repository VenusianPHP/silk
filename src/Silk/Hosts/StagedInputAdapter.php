<?php

namespace Silk\Hosts;

/** Staged-window input mail (StagedPressed, StagedKeyPressed, ...) to SilkEvent. */
class StagedInputAdapter extends InputAdapter
{
    protected function takes(object $mail): ?string
    {
        return self::verb($mail, 'Surface\Contracts\Windows\Mail\Staged', 'Staged');
    }
}
