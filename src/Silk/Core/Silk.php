<?php

namespace Silk\Core;

use Silk\Contracts\Host\Host;

/** The `silk` singleton: the entry point later tasks hang the looks and hosts on. */
class Silk
{
    /** @param array<string, mixed> $config the `silk` config */
    public function __construct(
        private readonly array $config = [],
    ) {}

    /** @var list<Host> */
    private array $hosts = [];

    /** Adds a host to the frame pump on the loop. */
    public function attach(Host $host): static
    {
        if (! in_array($host, $this->hosts, true)) {
            $this->hosts[] = $host;
        }

        return $this;
    }

    public function detach(Host $host): static
    {
        $this->hosts = array_values(array_filter($this->hosts, fn (Host $h) => $h !== $host));

        return $this;
    }

    /** @return list<Host> */
    public function hosts(): array
    {
        return $this->hosts;
    }

    /** @return array<string, mixed> */
    public function config(): array
    {
        return $this->config;
    }
}
