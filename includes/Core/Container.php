<?php

namespace NBS3\Core;

class Container
{
    private array $services = [];
    private array $instances = [];

    public function register(string $id, $concrete): self
    {
        $this->services[$id] = $concrete;
        return $this;
    }

    public function get(string $id)
    {
        if (!isset($this->services[$id])) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $id is a class name constant, not user input
            throw new \Exception("Service '$id' not found in container");
        }

        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        if ($this->services[$id] instanceof \Closure) {
            $this->instances[$id] = $this->services[$id]($this);
        } else {
            $this->instances[$id] = $this->services[$id];
        }

        return $this->instances[$id];
    }

    public function has(string $id): bool
    {
        return isset($this->services[$id]);
    }
}
