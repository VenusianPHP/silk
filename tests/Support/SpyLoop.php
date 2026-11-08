<?php

namespace Venusian\Silk\Tests\Support;

use Closure;
use LogicException;
use Voyager\Contracts\IOPools\Loop;
use Voyager\Contracts\IOPools\LoopResource;
use Voyager\Contracts\IOPools\LoopResources\Timer;
use Voyager\Contracts\IOPools\Promise;
use Voyager\Contracts\IOPools\Task;
use Voyager\Contracts\IOPools\WakeReason;

/** A Loop that keeps posted mail and registered resources; everything else is refused. */
class SpyLoop implements Loop
{
    /** @var list<object> */
    public array $posted = [];

    /** @var array<string, LoopResource> */
    public array $resources = [];

    /** @return list<string> the posted mail names */
    public function names(): array
    {
        return array_map(fn ($m) => $m->name(), $this->posted);
    }

    public function post(object $mail): void
    {
        $this->posted[] = $mail;
    }

    public function resource(string $name, LoopResource $resource): LoopResource
    {
        return $this->resources[$name] = $resource;
    }

    public function forget(string $name): void
    {
        unset($this->resources[$name]);
    }

    private function refuse(): never
    {
        throw new LogicException('SpyLoop does not run.');
    }

    public function at(float $delay_s, callable $fire): Timer { $this->refuse(); }

    public function every(float $interval_s, callable $fire, string $name): Timer { $this->refuse(); }

    public function promise(): Promise { $this->refuse(); }

    public function await(mixed $value): mixed { $this->refuse(); }

    public function async(callable $body): Task { $this->refuse(); }

    public function defer(Closure $work): Promise { $this->refuse(); }

    public function crown(string $name): void {}

    public function supports(WakeReason $kind): bool { return false; }

    public function descriptor(): ?int { return null; }

    public function onStop(callable $hook): void {}

    public function onStopSignal(callable $handler): void {}

    public function ignoreStopSignals(): void {}

    public function run(): int { $this->refuse(); }

    public function stop(int $status = 0): void {}

    public function until(Closure $assertion): void { $this->refuse(); }
}
