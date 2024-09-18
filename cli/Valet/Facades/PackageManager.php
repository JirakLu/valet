<?php

namespace Valet\Facades;

use Illuminate\Support\Collection;

interface PackageManager
{
    public function installed(string $package): bool;

    public function hasInstalledPhp(): bool;

    public function supportedPhpVersions(): Collection;

    public function limitedPhpVersions(): Collection;

    public function installedPhpFormulae(): Collection;

    public function determineAliasedVersion($formula): string;

    public function hasInstalledNginx(): bool;

    public function nginxServiceName(): string;

    public function ensureInstalled(string $package): void;

    public function installOrFail(string $package): void;

    public function hasLinkedPhp(): bool;

    public function getParsedLinkedPhp(): array;

    public function getLinkedPhpFormula(): string;

    public function linkedPhp(): string;

    public function getPhpExecutablePath(?string $phpVersion = null): string;

    public function restartLinkedPhp(): void;

    public function createSudoersEntry(): void;

    public function removeSudoersEntry(): void;

    public function link(string $formula, bool $force = false): string;

    public function unlink(string $formula): string;

    public function uninstallAllPhpVersions(): string;

    public function uninstallFormula(string $package): void;

    public function cleanupBrew(): string;

    public function parsePhpPath(string $resolvedPath): array;

    public function arePhpVersionsEqual(string $versionA, string $versionB): bool;

    public function isAvailable(): bool;
}
