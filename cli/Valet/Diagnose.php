<?php

namespace Valet;

use Illuminate\Support\Collection;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\ConsoleOutput;

class Diagnose
{
    public string $print;

    public ?ProgressBar $progressBar;

    public function __construct(public PhpFpm $phpFpm, public PhpEnv $phpEnv, public CommandLine $cli, public Filesystem $files) {}

    public function commands(): array
    {
        $commands = [
            'valet --version',
            'cat ~/.config/valet/config.json',
            'cat ~/.composer/composer.json',
            'composer global diagnose',
            'composer global outdated',
            'sudo ls -al /etc/sudoers.d/',
            'php -v',
            'which -a php',
            'php --ini',
            'nginx -v',
            'curl --version',
            'php --ri curl',
            '/bin/ngrok version',
            'openssl version -a',
            'openssl ciphers',
            'sudo nginx -t',
            'ls -aln /etc/resolv.conf',
            'cat /etc/resolv.conf',
            'ip addr show lo',
            'sh -c \'echo "------\n/etc/nginx/valet/valet.conf\n---\n"; cat /etc/nginx/valet/valet.conf | grep -n "# valet loopback"; echo "\n------\n"\'',
            'sh -c \'for file in ~/.config/valet/dnsmasq.d/*; do echo "------\n~/.config/valet/dnsmasq.d/$(basename $file)\n---\n"; cat $file; echo "\n------\n"; done\'',
            'sh -c \'for file in ~/.config/valet/Nginx/*; do echo "------\n~/.config/valet/Nginx/$(basename $file)\n---\n"; cat $file | grep -n "# valet loopback"; echo "\n------\n"; done\'',
        ];

        foreach ($this->phpFpm->utilizedPhpVersions() as $phpService) {
            $phpPath = $this->phpEnv->getPhpExecutablePath($phpService);
            $commands[] = "$phpPath -v";
            $commands[] = "$phpPath --ini";

            $phpFpmPath = str_replace('/php', '/php-fpm', $phpPath);
            $commands[] = "$phpFpmPath -v";
            // TODO: add fpmConfPath to PhpFpm

            $fpmConfPath = $_SERVER['HOME'].'/.phpenv/versions/'.PhpEnv::getRawPhpVersion($phpService).'/etc/php-fpm.conf';
            $commands[] = "sudo $phpFpmPath -y $fpmConfPath --test";
        }

        return $commands;
    }

    /**
     * Run diagnostics.
     */
    public function run(bool $print, bool $plainText): void
    {
        $this->print = $print;

        $this->beforeRun();

        $results = collect($this->commands())->map(function ($command) {
            $this->beforeCommand($command);

            $output = $this->runCommand($command);

            if ($this->ignoreOutput($command)) {
                return;
            }

            $this->afterCommand($command, $output);

            return compact('command', 'output');
        })->filter()->values();

        $output = $this->format($results, $plainText);

        $this->files->put('valet_diagnostics.txt', $output);

        $this->cli->run('xclip -sel clip < valet_diagnostics.txt');

        $this->files->unlink('valet_diagnostics.txt');

        $this->afterRun();
    }

    public function beforeRun(): void
    {
        if ($this->print) {
            return;
        }

        $this->progressBar = new ProgressBar(new ConsoleOutput, count($this->commands()));

        $this->progressBar->start();
    }

    public function afterRun(): void
    {
        if ($this->progressBar) {
            $this->progressBar->finish();
        }

        output('');
    }

    public function runCommand(string $command): string
    {
        return str_starts_with($command, 'sudo ')
            ? $this->cli->run($command)
            : $this->cli->runAsUser($command);
    }

    public function beforeCommand(string $command): void
    {
        if ($this->print) {
            info(PHP_EOL."$ $command");
        }
    }

    public function afterCommand(string $command, string $output): void
    {
        if ($this->print) {
            output(trim($output));
        } else {
            $this->progressBar->advance();
        }
    }

    public function ignoreOutput(string $command): bool
    {
        return str_contains($command, '> /dev/null 2>&1');
    }

    public function format(Collection $results, bool $plainText): string
    {
        return $results->map(function ($result) use ($plainText) {
            $command = $result['command'];
            $output = trim($result['output']);

            if ($plainText) {
                return implode(PHP_EOL, ["$ {$command}", $output]);
            }

            return sprintf(
                '<details>%s<summary>%s</summary>%s<pre>%s</pre>%s</details>',
                PHP_EOL, $command, PHP_EOL, $output, PHP_EOL
            );
        })->implode($plainText ? PHP_EOL.str_repeat('-', 20).PHP_EOL : PHP_EOL);
    }
}
