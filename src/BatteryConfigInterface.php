<?php

namespace Rey\Battery;

/**
 * Battery Config Interface
 * @property array $aliases
 */
interface BatteryConfigInterface {
    public function hasAlias(string $name): bool;
    public function getAlias(string $name): string;
}
