<?php

namespace Rey\Battery;

/**
 * Battery Config Interface
 * @property array $aliases
 * @property string $theme
 * @property bool $useJalali
 */
interface BatteryConfigInterface {
    public function hasAlias(string $name): bool;
    public function getAlias(string $name): string;
    public function getTheme(): string;
}
