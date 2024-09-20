<?php

namespace Valet\PackageManagers;

use DomainException;
use Valet\CommandLine;
use Valet\Facades\PackageManager;

use function Valet\info;
use function Valet\output;

class Pacman implements PackageManager
{
    public function __construct(public CommandLine $cli) {}

    /**
     * Ensure the package is installed.
     */
    public function installed(string $package): bool
    {
        return $this->cli->run("pacman -Q $package") !== '';
    }

    /**
     * Install the given package and throw an exception on failure.
     */
    public function installOrFail(string $package): void
    {
        info("Installing {$package}...");

        $this->cli->runAsUser("sudo pacman -S --needed --noconfirm $package", function () use ($package) {
            throw new DomainException("Pacman was unable to install [{$package}].");
        });
    }

    /**
     * Ensure that the given package is installed.
     */
    public function ensureInstalled(string $package): void
    {
        if (! $this->installed($package)) {
            $this->installOrFail($package);
        }
    }

    /**
     * Determine if a compatible nginx version is installed.
     */
    public function hasInstalledNginx(): bool
    {
        return $this->cli->run('nginx -v') !== '';
    }

    /**
     * Return name of the nginx service installed via Homebrew.
     */
    public function nginxServiceName(): string
    {
        return 'nginx';
    }

    /**
     * Uninstall a package by name.
     */
    public function uninstallFormula(string $package): void
    {
        output("Removing {$package}...");
        $this->cli->runAsUser("sudo pacman -R --noconfirm $package");
    }

    public function isAvailable(): bool
    {
        return $this->cli->run('command -v pacman') !== '';
    }
}
