<?php

namespace Silk\Hosts;

use Silk\Core\Silk;
use Voyager\Contracts\IOPools\Loop;
use Voyager\Contracts\IOPools\LoopResources\Background;
use Voyager\IOPools\Resources\Pollable;

/** The Silk resource on the Venusian loop: one frame per attached host per tick. */
final class FramePump extends Pollable implements Background
{
    public const string NAME = 'silk';

    public function __construct(private readonly Silk $silk) {}

    public function tick(): void
    {
        foreach ($this->silk->hosts() as $host) {
            $host->frame();
        }
    }

    public static function join(Loop $loop, Silk $silk): void
    {
        $loop->resource(self::NAME, new self($silk));
    }
}
