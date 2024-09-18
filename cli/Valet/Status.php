<?php

namespace Valet;

use Valet\Facades\PackageManager;
use Valet\Facades\ServiceManager;

class Status
{
    public $brewServicesUserOutput;

    public $brewServicesRootOutput;

    public $debugInstructions = [];

    public function __construct(public Configuration $config, public PackageManager $pm, public ServiceManager $sm, public PhpEnv $phpEnv, public CommandLine $cli, public Filesystem $files) {}

    /**
     * Check the status of the entire Valet ecosystem and return a status boolean
     * and a set of individual checks and their respective booleans as well.
     */
    public function check(): array
    {
        $isValid = true;

        $output = collect($this->checks())->map(function (array $check) use (&$isValid) {
            if (! $thisIsValid = $check['check']()) {
                $this->debugInstructions[] = $check['debug'];
                $isValid = false;
            }

            return ['description' => $check['description'], 'success' => $thisIsValid ? 'Yes' : 'No'];
        });

        return [
            'success' => $isValid,
            'output' => $output->all(),
            'debug' => $this->debugInstructions(),
        ];
    }

    /**
     * Define a list of checks to test the health of the Valet ecosystem of tools and configs.
     */
    public function checks(): array
    {
        $phpVersion = $this->phpEnv->phpVersion();

        return [
            [
                'description' => 'Is Valet fully installed?',
                'check' => function () {
                    return $this->valetInstalled();
                },
                'debug' => 'Run `composer require laravel/valet` and `valet install`.',
            ],
            [
                'description' => 'Is Valet config valid?',
                'check' => function () {
                    try {
                        $config = $this->config->read();

                        foreach (['tld', 'loopback', 'paths'] as $key) {
                            if (! array_key_exists($key, $config)) {
                                $this->debugInstructions[] = 'Your Valet config is missing the "'.$key.'" key. Re-add this manually, or delete your config file and re-install.';

                                return false;
                            }
                        }

                        return true;
                    } catch (\JsonException $e) {
                        return false;
                    }
                },
                'debug' => 'Run `valet install` to update your configuration.',
            ],
            [
                'description' => 'Is DnsMasq installed?',
                'check' => function () {
                    return $this->pm->installed('dnsmasq');
                },
                'debug' => 'Run `valet install`.',
            ],
            [
                'description' => 'Is Dnsmasq running?',
                'check' => function () {
                    return $this->sm->isServiceRunning('dnsmasq');
                },
                'debug' => 'Run `valet restart`.',
            ],
            [
                'description' => 'Is Nginx installed?',
                'check' => function () {
                    return $this->pm->installed('nginx');
                },
                'debug' => 'Run `valet install`.',
            ],
            [
                'description' => 'Is Nginx running?',
                'check' => function () {
                    return $this->sm->isServiceRunning('nginx');
                },
                'debug' => 'Run `valet restart`.',
            ],
            [
                'description' => 'Is PHP installed?',
                'check' => function () {
                    return $this->phpEnv->hasInstalledPhp();
                },
                'debug' => 'Run `valet install`.',
            ],
            [
                'description' => 'Is linked PHP ('.$phpVersion.') running?',
                'check' => function () use ($phpVersion) {
                    return $this->sm->isServiceRunning($phpVersion);
                },
                'debug' => 'Run `valet restart`.',
            ],
            // TODO: add check for phpenv installation
            [
                'description' => 'Is valet.sock present?',
                'check' => function () {
                    return $this->files->exists(VALET_HOME_PATH.'/valet.sock');
                },
                'debug' => 'Run `valet install`.',
            ],
        ];
    }

    public function valetInstalled(): bool
    {
        return is_dir(VALET_HOME_PATH)
            && file_exists($this->config->path())
            && is_dir(VALET_HOME_PATH.'/Drivers')
            && is_dir(VALET_HOME_PATH.'/Sites')
            && is_dir(VALET_HOME_PATH.'/Log')
            && is_dir(VALET_HOME_PATH.'/Certificates');
    }

    public function debugInstructions(): string
    {
        return collect($this->debugInstructions)->unique()->join(PHP_EOL);
    }
}
