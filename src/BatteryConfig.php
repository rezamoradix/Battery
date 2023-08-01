<?php

namespace Rey\Battery;

class BatteryConfig implements BatteryConfigInterface
{
    public array $aliases = [];

    public function hasAlias(string $name): bool
    {
        return isset($this->aliases[$name]);
    }

    public function getAlias(string $name): string
    {
        return $this->aliases[$name];
    }
}

