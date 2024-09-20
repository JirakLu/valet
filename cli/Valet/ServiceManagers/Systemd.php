<?php

namespace Valet\ServiceManagers;

use Illuminate\Support\Collection;
use Valet\CommandLine;
use Valet\Facades\ServiceManager;

class Systemd implements ServiceManager
{
    public function __construct(public CommandLine $cli) {}

    public function restartService($services): void
    {
        $services = is_array($services) ? $services : func_get_args();
        $this->cli->run('sudo systemctl daemon-reload');

        foreach ($services as $service) {
            $this->cli->run("sudo systemctl restart {$service}");
            $this->cli->run("sudo systemctl enable --now {$service}");
        }
    }

    public function stopService($services): void
    {
        $services = is_array($services) ? $services : func_get_args();

        foreach ($services as $service) {
            $this->cli->run("sudo systemctl disable {$service}");
            $this->cli->run("sudo systemctl stop {$service}");
        }
    }

    public function getAllRunningServices(): Collection
    {
        $command = 'systemctl list-units --type=service --state=running --no-pager --no-legend | awk \'{print $1}\'';
        $result = $this->cli->run($command);

        return collect(array_filter(explode(PHP_EOL, $result)));
    }

    public function isServiceRunning(string $service): bool
    {
        return $this->cli->run("systemctl is-active --quiet {$service} && echo -n '*'") === '*';
    }

    public function isAvailable(): bool
    {
        return $this->cli->run('command -v systemctl') !== '';
    }
}
