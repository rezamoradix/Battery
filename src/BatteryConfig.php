<?php

namespace Rey\Battery;

class BatteryConfig implements BatteryConfigInterface
{
    public string $theme = "";
    public array $aliases = [];

    public function hasAlias(string $name): bool
    {
        return isset($this->aliases[$name]);
    }

    public function getAlias(string $name): string
    {
        return $this->aliases[$name];
    }

    public function getTheme(): string
    {
        /**
         * Since theme in Battery means a directory,
         * an "/" will be added to $theme
         */
        return $this->theme === "" ? $this->theme : $this->theme . '/';
    }
}

