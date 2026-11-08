<?php

namespace Silk\Core\Motion;

/**
 * A damped oscillator pulled toward a target, stepped with the exact solution
 * (Raven anim.c), so it does not depend on the frame rate.
 */
class Spring
{
    private const float REST_DISTANCE = 0.0015;

    private const float REST_VELOCITY = 0.02;

    private float $velocity = 0.0;

    private float $target;

    /** The first value puts the spring on its target, at rest. */
    public function __construct(
        private float $value,
        public SpringPreset $preset,
        public bool $reduced = false,
    ) {
        $this->target = $value;
    }

    public function target(float $target): static
    {
        $this->target = $target;

        return $this->reduced ? $this->snap($target) : $this;
    }

    public function snap(float $value): static
    {
        $this->value = $this->target = $value;
        $this->velocity = 0.0;

        return $this;
    }

    public function kick(float $velocity): static
    {
        $this->velocity += $velocity;

        return $this;
    }

    public function step(float $dt): static
    {
        if ($this->atRest()) {
            return $this;
        }

        $omega = 2 * M_PI / $this->preset->period();
        $zeta = $this->preset->damping();
        $x = $this->value - $this->target;
        $v = $this->velocity;
        $decay = exp(-$zeta * $omega * $dt);

        if ($zeta < 0.999) {
            $omegaD = $omega * sqrt(1 - $zeta * $zeta);
            $c = cos($omegaD * $dt);
            $s = sin($omegaD * $dt);
            $b = ($v + $zeta * $omega * $x) / $omegaD;
            $this->value = $this->target + $decay * ($x * $c + $b * $s);
            $this->velocity = $decay * (($b * $omegaD - $zeta * $omega * $x) * $c - ($x * $omegaD + $zeta * $omega * $b) * $s);
        } else {
            // critically damped
            $b = $v + $omega * $x;
            $this->value = $this->target + $decay * ($x + $b * $dt);
            $this->velocity = $decay * ($b - $omega * ($x + $b * $dt));
        }

        // Raven settles exactly onto the target once it stops moving
        return $this->atRest() ? $this->snap($this->target) : $this;
    }

    /** Puts the spring on its target, at rest (reduced motion). */
    public function finish(): static
    {
        return $this->snap($this->target);
    }

    public function value(): float
    {
        return $this->value;
    }

    public function velocity(): float
    {
        return $this->velocity;
    }

    public function atRest(): bool
    {
        return abs($this->value - $this->target) <= self::REST_DISTANCE
            && abs($this->velocity) <= self::REST_VELOCITY;
    }
}
