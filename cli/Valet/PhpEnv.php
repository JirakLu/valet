<?php

namespace Valet;

use DomainException;
use Illuminate\Support\Collection;

class PhpEnv
{

    // This is the array of PHP versions that Valet will attempt to install/configure when requested
    // TODO: replace this with `/home/lukas/.phpenv/bin/phpenv install --list | grep -Ev "(^Available|snapshot$)"`
    const array SUPPORTED_PHP_VERSIONS = [
        'php@8.3.0',
        'php@8.2.0',
        'php@8.1.0',
        'php@8.0.0',
        'php@7.4.0',
        'php@7.3.0',
        'php@7.2.0',
        'php@7.1.0',
    ];

    // Update this LATEST and the following LIMITED array when PHP versions are released or retired
    // We specify a numbered version here even though Homebrew links its generic 'php' alias to it
    const string LATEST_PHP_VERSION = 'php@8.3.11';

    public function __construct(
        public CommandLine $cli,
    ) {}

    /**
     * Install and configure PhpEnv.
     */
    public function install(): void
    {
        info('Installing and configuring phpenv...');

        // TODO: handle installing phpenv

        $this->cli->runAsUser("/home/lukas/.phpenv/bin/phpenv install".static::getRawPhpVersion(static::LATEST_PHP_VERSION));
        $this->cli->runAsUser("/home/lukas/.phpenv/bin/phpenv rehash");
        $this->cli->runAsUser("/home/lukas/.phpenv/bin/phpenv global".static::getRawPhpVersion(static::LATEST_PHP_VERSION));
    }

    /**
     * Normalize the PHP version.
     * Converts 'php@7.4.0' to '7.4.0'
     */
    public static function getRawPhpVersion(string $version): string
    {
        $version = trim($version);
        return str_replace('php@', '', $version);
    }

    /**
     * Ensure the version of PHP is installed.
     */
    public function installed(string $version): bool
    {
        $normalizedVersion = static::getRawPhpVersion($version);
        return str_contains($this->cli->runAsUser("/home/lukas/.phpenv/bin/phpenv versions --bare"), $normalizedVersion);
    }

    /**
     * Install the given PHP version and throw an exception on failure.
     */
    public function installOrFail(string $version): void
    {
        info("Installing {$version}...");

        $normalizedVersion = static::getRawPhpVersion($version);
        $this->cli->runAsUser("/home/lukas/.phpenv/bin/phpenv install ".$normalizedVersion, function ($exitCode, $errorOutput) use ($version) {
            output($errorOutput);

            throw new DomainException('PHP version ['.$version.'] could not be installed.');
        });
    }

    /**
     * Determine if any PHP version is installed.
     */
    public function hasInstalledPhp(): bool
    {
        $output = $this->cli->runAsUser("/home/lukas/.phpenv/bin/phpenv versions --bare");
        // TODO: investigate
        $output = str_replace('sudo: phpenv: command not found', '', $output);
        return !empty(trim($output));
    }

    /**
     * Ensure that the given version is installed.
     * If the version is 'php', it will install the latest PHP version.
     */
    public function ensureInstalled(string $version): void
    {
        $version = $version === 'php' ? static::LATEST_PHP_VERSION : $version;
        if (! $this->installed($version)) {
            $this->installOrFail($version);
        }
    }


    /**
     * Get the current PHP version.
     * Returns the version e.g. 'php@7.4.0'
     */
    public function phpVersion(): string
    {
        return trim('php@'.$this->cli->runAsUser("/home/lukas/.phpenv/bin/phpenv version-name"));
    }

    /**
     * Get the available PHP versions.
     */
    public function phpVersions(): Collection
    {
        return collect(explode("\n", $this->cli->runAsUser("/home/lukas/.phpenv/bin/phpenv versions --bare")))->map(fn ($version) => 'php@'.$version);
    }

    /**
     * Uninstall the given PHP version.
     */
    public function uninstallPhp(string $version): void
    {
        info("Uninstalling {$version}...");

        $this->cli->runAsUser("/home/lukas/.phpenv/bin/phpenv uninstall ".$version, function ($exitCode, $errorOutput) use ($version) {
            output($errorOutput);

            throw new DomainException('PHP version ['.$version.'] could not be uninstalled.');
        });
    }

    /**
     * Forcefully remove all PHP versions that Valet supports.
     */
    public function uninstallAllPhpVersions(): string
    {
        $this->phpVersions()->each(function ($version) {
            $this->uninstallPhp($version);
        });

        return 'PHP versions removed.';
    }

    /**
     * Get a list of supported PHP versions.
     */
    public function supportedPhpVersions(): Collection
    {
        return collect(static::SUPPORTED_PHP_VERSIONS);
    }

    /**
     * Uses passed php version.
     */
    public function use(string $version): string
    {
        $normalizedVersion = static::getRawPhpVersion($version);

        return $this->cli->runAsUser(
            "phpenv global $normalizedVersion",
            function ($exitCode, $errorOutput) use ($version) {
                output($errorOutput);

                throw new DomainException('PhpEnv was unable to link ['.$version.'].');
            }
        );
    }

    /**
     * Check if phpenv is using latest PHP version.
     */
    public function isUsingLatestPhp(): bool
    {
        return $this->phpVersion() === PhpEnv::LATEST_PHP_VERSION;
    }
}
