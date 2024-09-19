<?php

namespace Valet;

use DomainException;
use Illuminate\Support\Collection;
use Valet\Facades\ServiceManager;

class PhpFpm
{
    public function __construct(
        public PhpEnv $phpEnv,
        public ServiceManager $sm,
        public CommandLine $cli,
        public Filesystem $files,
        public Configuration $config,
        public Site $site,
        public Nginx $nginx
    ) {}

    /**
     * Install and configure PhpFpm.
     */
    public function install(): void
    {
        info('Installing and configuring phpfpm...');

        if (! $this->phpEnv->hasInstalledPhp()) {
            $this->phpEnv->ensureInstalled('php');
        }

        $this->files->ensureDirExists(VALET_HOME_PATH.'/Log', user());

        $phpVersion = $this->phpEnv->phpVersion();
        $this->createConfigurationFiles($phpVersion);

        // Remove old valet.sock
        $this->files->unlink(VALET_HOME_PATH.'/valet.sock');
        $this->cli->quietly('sudo rm '.VALET_HOME_PATH.'/valet.sock');

        $this->restart();

        $this->symlinkPrimaryValetSock($phpVersion);
    }

    /**
     * Forcefully uninstall all of Valet's supported PHP versions and configurations.
     */
    public function uninstall(): void
    {
        // TODO: rewrite this
        $this->phpEnv->uninstallAllPhpVersions();
        $this->cli->run('rm -rf /var/log/php-fpm.log');
    }

    /**
     * Create (or re-create) the PHP FPM configuration files.
     *
     * Writes FPM config file, pointing to the correct .sock file, and log and ini files.
     */
    public function createConfigurationFiles(string $phpVersion): void
    {
        info("Updating PHP configuration for {$phpVersion}...");

        $fpmConfigFile = $this->fpmConfigPath($phpVersion);

        $this->files->ensureDirExists(dirname($fpmConfigFile), user());

        // rename (to disable) old FPM Pool configuration, regardless of whether it's a default config or one customized by an older Valet version
        $oldFile = dirname($fpmConfigFile).'/www.conf';
        if (file_exists($oldFile)) {
            rename($oldFile, $oldFile.'-backup');
        }

        // Create FPM Config File from stub
        $contents = str_replace(
            ['VALET_USER', 'VALET_HOME_PATH', 'valet.sock'],
            [user(), VALET_HOME_PATH, self::fpmSockName($phpVersion)],
            $this->files->getStub('etc-phpfpm-valet.conf')
        );
        $this->files->put($fpmConfigFile, $contents);

        // Create other config files from stubs
        $destDir = dirname(dirname($fpmConfigFile)).'/conf.d';
        $this->files->ensureDirExists($destDir, user());

        $this->files->putAsUser(
            $destDir.'/php-memory-limits.ini',
            $this->files->getStub('php-memory-limits.ini')
        );

        $contents = str_replace(
            ['VALET_USER', 'VALET_HOME_PATH'],
            [user(), VALET_HOME_PATH],
            $this->files->getStub('etc-phpfpm-error_log.ini')
        );
        $this->files->putAsUser($destDir.'/error_log.ini', $contents);

        // Create log directory and file
        $this->files->ensureDirExists(VALET_HOME_PATH.'/Log', user());
        $this->files->touch(VALET_HOME_PATH.'/Log/php-fpm.log', user());
    }

    /**
     * Restart the PHP FPM process (if one specified) or processes (if none specified).
     */
    public function restart(?string $phpVersion = null): void
    {
        $phpVersion = $phpVersion === 'php' ? PhpEnv::LATEST_PHP_VERSION : $phpVersion;
        $this->sm->restartService($phpVersion ?: $this->utilizedPhpVersions());
    }

    /**
     * Stop the PHP FPM process.
     */
    public function stop(): void
    {
        info('Stopping phpfpm...');
        call_user_func_array(
            [$this->sm, 'stopService'],
            $this->phpEnv->supportedPhpVersions()->toArray()
        );
    }

    /**
     * Get the path to the FPM configuration file for the current PHP version.
     */
    public function fpmConfigPath(?string $phpVersion = null): string
    {
        if (! $phpVersion) {
            $phpVersion = $this->phpEnv->phpVersion();
        }

        $versionNormalized = $this->normalizePhpVersion($phpVersion === 'php' ? PhpEnv::LATEST_PHP_VERSION : $phpVersion);
        $versionNormalized = PhpEnv::getRawPhpVersion($versionNormalized);

        return $_SERVER['HOME']."/.phpenv/versions/{$versionNormalized}/etc/php-fpm.d/valet-fpm.conf";
    }

    /**
     * Stop only the running php services.
     */
    public function stopRunning(): void
    {
        info('Stopping phpfpm...');
        $this->sm->stopService(
            $this->sm->getAllRunningServices()
                ->filter(function ($service) {
                    return str_starts_with($service, 'php@');
                })
                ->all()
        );
    }

    /**
     * Stop a given PHP version, if that specific version isn't being used globally or by any sites.
     */
    public function stopIfUnused(?string $phpVersion = null): void
    {
        if (! $phpVersion) {
            return;
        }

        $phpVersion = $this->normalizePhpVersion($phpVersion);

        if (! in_array($phpVersion, $this->utilizedPhpVersions())) {
            $this->sm->stopService($phpVersion);
        }
    }

    /**
     * Isolate a given directory to use a specific version of PHP.
     */
    public function isolateDirectory(string $directory, string $version): void
    {
        $site = $this->site->getSiteUrl($directory);

        $version = $this->validateRequestedVersion($version);

        $this->phpEnv->ensureInstalled($version);

        $oldCustomPhpVersion = $this->site->customPhpVersion($site); // Example output: "740"
        $this->createConfigurationFiles($version);

        $this->site->isolate($site, $version);

        $this->stopIfUnused($oldCustomPhpVersion);
        $this->restart($version);
        $this->nginx->restart();

        info(sprintf('The site [%s] is now using %s.', $site, $version));
    }

    /**
     * Remove PHP version isolation for a given directory.
     */
    public function unIsolateDirectory(string $directory): void
    {
        $site = $this->site->getSiteUrl($directory);

        $oldCustomPhpVersion = $this->site->customPhpVersion($site); // Example output: "740"

        $this->site->removeIsolation($site);
        $this->stopIfUnused($oldCustomPhpVersion);
        $this->nginx->restart();

        info(sprintf('The site [%s] is now using the default PHP version.', $site));
    }

    /**
     * List all directories with PHP isolation configured.
     */
    public function isolatedDirectories(): Collection
    {
        return $this->nginx->configuredSites()->filter(function ($item) {
            return str_contains($this->files->get(VALET_HOME_PATH . '/Nginx/' . $item), ISOLATED_PHP_VERSION);
        })->map(function ($item) {
            return ['url' => $item, 'version' => $this->normalizePhpVersion($this->site->customPhpVersion($item))];
        });
    }

    /**
     * Use a specific version of PHP globally.
     */
    public function useVersion(string $version, bool $force = false): ?string
    {
        $version = $this->validateRequestedVersion($version);

        try {
            if ($version === $this->phpEnv->phpVersion() && ! $force) {
                output(sprintf('<info>Valet is already using version: <comment>%s</comment>.</info> To re-link and re-configure use the --force parameter.'.PHP_EOL,
                    $version));
                exit();
            }
        } catch (DomainException $e) { /* ignore thrown exception when no linked php is found */
        }

        $this->phpEnv->ensureInstalled($version);

        info(sprintf('Setting up new version: %s', $version));
        $this->phpEnv->use($version);

        $this->stopRunning();

        $this->install();

        $this->nginx->restart();

        info(sprintf('Valet is now using %s.', $version).PHP_EOL);
        info('Note that you might need to run <comment>composer global update</comment> if your PHP version change affects the dependencies of global packages required by Composer.');

        return $version;
    }

    /**
     * Symlink (Capistrano-style) a given Valet.sock file to be the primary valet.sock.
     */
    public function symlinkPrimaryValetSock(string $phpVersion): void
    {
        $this->files->symlinkAsUser(VALET_HOME_PATH.'/'.$this->fpmSockName($phpVersion), VALET_HOME_PATH.'/valet.sock');
    }

    /**
     * If passed php7.4.0, or php740, 7.4.0, or 740 formats, normalize to php@7.4.0 format.
     */
    public function normalizePhpVersion(?string $version): string
    {
        // TODO: fix this soon tm
        return preg_replace('/(?:php@?)?([0-9]+)\.?([0-9]+)\.?([0-9]+)?/i', 'php@$1.$2.$3', (string) $version);
    }

    /**
     * Validate the requested version to be sure we can support it.
     */
    public function validateRequestedVersion(string $version): string
    {
        $version = $this->normalizePhpVersion($version);

        if (! $this->phpEnv->supportedPhpVersions()->contains($version)) {
            throw new DomainException("Valet doesn't support PHP version: {$version} (try something like 'php@8.1.0' instead)");
        }

        if ($version === 'php') {
            if ($this->phpEnv->isUsingLatestPhp()) {
                throw new DomainException('PhpEnv is already using the latest version. To use another version, please specify. eg: php@8.1.0');
            }
        }

        return $version;
    }

    /**
     * Get FPM sock file name for a given PHP version.
     */
    public static function fpmSockName(?string $phpVersion = null): string
    {
        $phpVersion = $phpVersion === 'php' ? PhpEnv::LATEST_PHP_VERSION : $phpVersion;
        $versionInteger = PhpEnv::getRawPhpVersion($phpVersion);

        return "valet{$versionInteger}.sock";
    }

    /**
     * Get a list including the global PHP version and allPHP versions currently serving "isolated sites" (sites with
     * custom Nginx configs pointing them to a specific PHP version).
     */
    public function utilizedPhpVersions(): array
    {
        $fpmSockFiles = $this->phpEnv->supportedPhpVersions()->map(function ($version) {
            return self::fpmSockName($this->normalizePhpVersion($version));
        })->unique();

        return $this->nginx->configuredSites()->map(function ($file) use ($fpmSockFiles) {
            $content = $this->files->get(VALET_HOME_PATH.'/Nginx/'.$file);

            // Get the normalized PHP version for this config file, if it's defined
            foreach ($fpmSockFiles as $sock) {
                if (str_contains($content, $sock)) {
                    // Extract the PHP version number from a custom .sock path and normalize it to, e.g., "php@7.4"
                    return $this->normalizePhpVersion(str_replace(['valet', '.sock'], '', $sock));
                }
            }
        })->merge($this->phpEnv->phpVersion())->filter()->unique()->values()->toArray();
    }

}
