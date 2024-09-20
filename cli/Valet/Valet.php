<?php

namespace Valet;

use DomainException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Container\Container;
use Valet\Facades\PackageManager;
use Valet\Facades\ServiceManager;
use Valet\PackageManagers\Pacman;
use Valet\ServiceManagers\Systemd;

class Valet
{
    public string $valetBin = '/usr/bin/valet';

    public function __construct(public CommandLine $cli, public Filesystem $files) {}

    /**
     * Symlink the Valet Bash script into the user's local bin.
     */
    public function symlinkToUsersBin(): void
    {
        $this->unlinkFromUsersBin();

        $this->cli->runAsUser('sudo ln -s "'.realpath(__DIR__.'/../../valet').'" '.$this->valetBin);
    }

    /**
     * Remove the symlink from the user's local bin.
     */
    public function unlinkFromUsersBin(): void
    {
        $this->cli->quietlyAsUser('sudo rm '.$this->valetBin);
    }

    /**
     * Determine if this is the latest version of Valet.
     *
     * @throws GuzzleException
     */
    public function onLatestVersion(string $currentVersion): bool
    {
        // TODO: change this
        $url = 'https://api.github.com/repos/laravel/valet/releases/latest';
        $response = json_decode((new Client)->get($url)->getBody());

        return version_compare($currentVersion, trim($response->tag_name, 'v'), '>=');
    }

    /**
     * Create the "sudoers.d" entry for running Valet.
     */
    public function createSudoersEntry(): void
    {
        $this->files->ensureDirExists('/etc/sudoers.d');

        // TODO: fix this
        $this->files->put('/etc/sudoers.d/valet', 'Cmnd_Alias VALET = /home/lukas/valet/valet *
        %admin ALL=(root) NOPASSWD:SETENV: VALET'.PHP_EOL);
    }

    /**
     * Remove the "sudoers.d" entry for running Valet.
     */
    public function removeSudoersEntry(): void
    {
        $this->cli->quietly('rm /etc/sudoers.d/valet');
    }

    /**
     * Run composer global diagnose.
     */
    public function composerGlobalDiagnose(): void
    {
        $this->cli->runAsUser('composer global diagnose');
    }

    /**
     * Run composer global update.
     */
    public function composerGlobalUpdate(): void
    {
        $this->cli->runAsUser('composer global update');
    }

    public function forceUninstallText(): string
    {
        return '<fg=red>NOTE:</>
<comment>Valet has attempted to uninstall itself, but there are some steps you need to complete manually:</comment>

1. Run <info>php -v</info> and <info>which php</info> to verify the current PHP version you are using.
2. Run <info>composer global update</info> to update your globally-installed Composer packages to ensure compatibility with your default PHP version.
    NOTE: Be aware that Composer may have other dependencies for globally installed apps, and those may not be compatible with your current PHP version.
3. Finish removing any Composer remnants of Valet:
    Run <info>composer global remove laravel/valet</info>
    Then remove the Valet executable link if it still exists with <info>rm -f ~/.composer/vendor/bin/valet</info> or <info>rm -f ~/.config/composer/vendor/bin/valet</info> depending on your system setup.

Optional:
- Use <info>sudo apt autoremove</info> (or <info>dnf autoremove</info>, <info>pacman -Rns</info>, etc.) to clean up unused dependencies from package uninstalls.
- Run <info>sudo systemctl status nginx</info> to check if these services are still running, and disable or remove them as needed.

If you had customized your DNS settings (e.g., via `/etc/resolv.conf`), you may need to remove `127.0.0.1` from your DNS settings manually.

Additionally, you may want to check for leftover trust certificates:
- Open your certificate manager (e.g., `certutil` or `update-ca-certificates`), and search for any Valet-related certificates to remove them.
';
    }

    public function uninstallText(): string
    {
        return "
<info>You did not pass the <fg=red>--force</> parameter, so this will only return instructions on how to uninstall, not ACTUALLY uninstall anything.
A --force removal WILL delete your custom configuration information, so be sure to make backups first.</info>

IF YOU WANT TO UNINSTALL VALET MANUALLY ON LINUX, DO THE FOLLOWING...

<info>1. Valet Certificates</info>
Before removing Valet configuration files, it's recommended to run <comment>valet unsecure --all</comment> to remove the certificates Valet added to your system.
    Alternatively, you can manually search for certificates related to Valet (e.g., <comment>@laravel.valet</comment>) and remove them.

<info>2. Valet Configuration Files</info>
You can remove your user-specific Valet config files by running: <comment>rm -rf ~/.config/valet</comment>

<info>3. Uninstall phpenv</info>
Remove the `phpenv` directory: <comment>rm -rf ~/.phpenv</comment>

<info>4. Remove the Valet Package</info>
Run <comment>composer global remove laravel/valet</comment> to uninstall the Valet package.

<info>5. System Services</info>
You can uninstall services such as Nginx and Dnsmasq (if installed via a package manager like `apt`, `dnf`, or `pacman`) with the following commands:
    - For `apt` (Debian/Ubuntu-based systems): <comment>sudo apt remove --purge nginx dnsmasq</comment>
    - For `dnf` (Fedora-based systems): <comment>sudo dnf remove php nginx dnsmasq</comment>
    - For `pacman` (Arch-based systems): <comment>sudo pacman -Rns nginx dnsmasq</comment>

Additionally, you can manually remove any leftover configurations for these services in directories such as <comment>/etc/</comment> and <comment>/var/log/</comment>.

<info>6. GENERAL TROUBLESHOOTING</info>
If you're considering uninstalling due to troubleshooting, you can run system diagnostics such as:
- <comment>sudo nginx -t</comment> to test your Nginx configuration files.
- <comment>sudo systemctl status nginx</comment> to check the status of the Nginx service.

You may also want to check your global Composer configuration:
- <comment>composer global update</comment> to update global packages.
- <comment>composer global outdated</comment> to check for outdated packages.
- <comment>composer global diagnose</comment> to run diagnostics on your global Composer setup.

Remember to check logs in <comment>/var/log/nginx</comment>  if you're encountering errors.
            ";
    }

    /**
     * Configure the environment for Valet
     */
    public function environmentSetup(): void
    {
        $this->packageManagerSetup();
        $this->serviceManagerSetup();
    }

    /**
     * Configure package manager
     */
    public function packageManagerSetup(): void
    {
        Container::getInstance()->bind(PackageManager::class, $this->getAvailablePackageManager());
    }

    /**
     * Determine the users package manager
     */
    public function getAvailablePackageManager(): string
    {
        return collect([
            Pacman::class,
        ])->first(static function ($pm) {
            return resolve($pm)->isAvailable();
        }, static function () {
            throw new DomainException('No compatible package manager found.');
        });
    }

    /**
     * Configure service manager
     */
    public function serviceManagerSetup(): void
    {
        Container::getInstance()->bind(ServiceManager::class, $this->getAvailableServiceManager());
    }

    /**
     * Determine the users service manager
     */
    public function getAvailableServiceManager(): string
    {
        return collect([
            Systemd::class,
        ])->first(static function ($pm) {
            return resolve($pm)->isAvailable();
        }, static function () {
            throw new DomainException('No compatible service manager found.');
        });
    }
}
