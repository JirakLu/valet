<?php

namespace Valet;

use DomainException;
use Illuminate\Support\Collection;

class PhpEnv
{
    const LATEST_PHP_VERSION = 'php@8.3.11';

    const EXTENSIONS = 'imagick=3.7.0 mongodb=1.20.0 redis=6.0.2 sodium=2.0.23 pdo_sqlsrv=5.12.0 sqlsrv=5.12.0';

    public function __construct(
        public CommandLine $cli,
        public Filesystem $files,
    ) {}

    /**
     * Install and configure PhpEnv.
     */
    public function install(): void
    {
        info('Installing and configuring phpenv...');

        $this->files->put(
            $this->phpServicePath(),
            str_replace(
                'USER_HOME',
                $_SERVER['HOME'],
                $this->files->getStub('php@.service')
            )
        );

        $this->cli->runAsUser("git clone https://github.com/phpenv/phpenv.git {$_SERVER['HOME']}/.phpenv");
        $this->cli->runAsUser("git clone https://github.com/php-build/php-build {$_SERVER['HOME']}/.phpenv/plugins/php-build");

        $this->use(static::LATEST_PHP_VERSION);
    }

    /**
     * Uninstall PhpEnv.
     */
    public function uninstall(): void
    {
        info('Uninstalling phpenv...');

        if ($this->files->exists($this->phpServicePath())) {
            $this->files->unlink($this->phpServicePath());
        }

        $this->cli->runAsUser("rm -rf {$_SERVER['HOME']}/.phpenv");
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
        return str_contains($this->runPhpEnv('versions --bare'), static::getRawPhpVersion($version));
    }

    /**
     * Install the given PHP version and throw an exception on failure.
     */
    public function installOrFail(string $version): void
    {
        info("Installing {$version}... (this might take a while)");

        $this->cli->runAsUser('PHP_BUILD_INSTALL_EXTENSION="'.self::EXTENSIONS.'"'.' '.$this->phpEnvPath().' '.'install -i development '.static::getRawPhpVersion($version), function ($exitCode, $errorOutput) use ($version) {
            $this->runPhpEnv('uninstall '.static::getRawPhpVersion($version));
            output($errorOutput);

            throw new DomainException('PHP version ['.$version.'] could not be installed.');
        });
        $this->runPhpEnv('rehash');
    }

    /**
     * Determine if any PHP version is installed.
     */
    public function hasInstalledPhp(): bool
    {
        $output = $this->runPhpEnv('versions --bare');

        return ! empty(trim($output));
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
     * Get the path to the PHP service file.
     */
    public function phpServicePath(): string
    {
        return '/etc/systemd/system/php@.service';
    }

    /**
     * Get the current PHP version.
     * Returns the version e.g. 'php@7.4.0'
     */
    public function phpVersion(): string
    {
        return trim('php@'.$this->runPhpEnv('version-name'));
    }

    /**
     * Get the available PHP versions.
     */
    public function phpVersions(): Collection
    {
        return collect(explode("\n", $this->runPhpEnv('versions --bare')))->map(fn ($version) => 'php@'.$version);
    }

    /**
     * Uninstall the given PHP version.
     */
    public function uninstallPhp(string $version): void
    {
        info("Uninstalling {$version}...");

        $this->runPhpEnv('uninstall '.$version, function ($exitCode, $errorOutput) use ($version) {
            output($errorOutput);

            throw new DomainException('PHP version ['.$version.'] could not be uninstalled.');
        });
        $this->runPhpEnv('rehash');
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
        return collect(explode("\n", $this->runPhpEnv('install --list | grep -Ev "(^Available|snapshot$)"')))->map(fn ($version) => 'php@'.trim($version));
    }

    /**
     * Uses passed php version.
     */
    public function use(string $version): string
    {
        $this->ensureInstalled($version);

        return $this->runPhpEnv('global '.static::getRawPhpVersion($version),
            function ($exitCode, $errorOutput) use ($version) {
                output($errorOutput);

                throw new DomainException('PhpEnv was unable to link ['.$version.'].');
            }
        );
    }

    /**
     * Extract PHP executable path from PHP Version.
     */
    public function getPhpExecutablePath(?string $phpVersion = null): string
    {
        $phpVersion = $phpVersion ?? $this->phpVersion();

        $executable = $_SERVER['HOME'].'/.phpenv/versions/'.static::getRawPhpVersion($phpVersion).'/bin/php';
        if ($this->files->exists($executable)) {
            return $executable;
        }

        return '/usr/bin/php';
    }

    /**
     * Runs phpenv command
     */
    public function runPhpEnv(string $command, ?callable $onError = null): string
    {
        return $this->cli->runAsUser($this->phpEnvPath().' '.$command, $onError);
    }

    /**
     * Check if phpenv is using latest PHP version.
     */
    public function isUsingLatestPhp(): bool
    {
        return $this->phpVersion() === PhpEnv::LATEST_PHP_VERSION;
    }

    /**
     * PhpEnv path.
     */
    public function phpEnvPath(): string
    {
        return $_SERVER['HOME'].'/.phpenv/bin/phpenv';
    }
}
