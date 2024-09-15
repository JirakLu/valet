<?php

namespace Valet\ServiceManagers;

use Illuminate\Support\Collection;
use Valet\CommandLine;
use Valet\Facades\ServiceManager;

class Systemd implements ServiceManager
{
    protected CommandLine $cli;

    public function __construct(CommandLine $cli)
    {
        $this->cli = $cli;
    }

    public function restartService($services): void
    {
        $services = is_array($services) ? $services : func_get_args();

        foreach ($services as $service) {
            $this->cli->run("sudo systemctl restart {$service}");
        }
    }

    public function stopService($services): void
    {
        $services = is_array($services) ? $services : func_get_args();

        foreach ($services as $service) {
            $this->cli->run("sudo systemctl stop {$service}");
        }
    }

    public function getAllRunningServices(): Collection
    {
        $command = 'systemctl list-units --type=service --state=running --no-pager --no-legend | awk \'{print $1}\'';
        $result = $this->cli->run($command);

        return collect(array_filter(explode(PHP_EOL, $result)));
    }

    public function isAvailable(): bool
    {
        return $this->cli->run('command -v systemctl') !== '';
    }
}
