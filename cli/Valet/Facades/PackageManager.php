<?php

namespace Valet\Facades;

interface PackageManager
{
    public function installed(string $package): bool;

    public function hasInstalledNginx(): bool;

    public function nginxServiceName(): string;

    public function ensureInstalled(string $package): void;

    public function installOrFail(string $package): void;

    public function uninstallFormula(string $package): void;

    public function isAvailable(): bool;
}
