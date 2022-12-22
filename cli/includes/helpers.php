<?php

namespace Valet;

use Exception;
use Illuminate\Container\Container;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Define constants.
 */
if (! defined('VALET_HOME_PATH')) {
    if (testing()) {
        define('VALET_HOME_PATH', __DIR__.'/../../tests/config/valet');
    } else {
        define('VALET_HOME_PATH', $_SERVER['HOME'].'/.config/valet');
    }
}
if (! defined('VALET_STATIC_PREFIX')) {
    define('VALET_STATIC_PREFIX', '41c270e4-5535-4daa-b23e-c269744c2f45');
}

define('VALET_LOOPBACK', '127.0.0.1');
define('VALET_SERVER_PATH', realpath(__DIR__.'/../../server.php'));
define('VALET_LEGACY_HOME_PATH', $_SERVER['HOME'].'/.valet');

define('BREW_PREFIX', (new CommandLine())->runAsUser('printf $(brew --prefix)'));

define('ISOLATED_PHP_VERSION', 'ISOLATED_PHP_VERSION');

/**
 * Set or get a global console writer.
 *
 * @param  null|OutputInterface  $writer
 * @return OutputInterface|\NullWriter|null
 */
function writer($writer = null)
{
    $container = Container::getInstance();

    if (! $writer) {
        if (! $container->bound('writer')) {
            $container->instance('writer', new ConsoleOutput());
        }

        return $container->make('writer');
    }

    $container->instance('writer', $writer);
    return null;
}


/**
 * Output the given text to the console.
 *
 * @param  string  $output
 * @return void
 */
function info($output)
{
    output('<info>'.$output.'</info>');
}

/**
 * Output the given text to the console.
 *
 * @param  string  $output
 * @return void
 */
function warning($output)
{
    output('<fg=red>'.$output.'</>');
}

/**
 * Output a table to the console.
 *
 * @param  array  $headers
 * @param  array  $rows
 * @return void
 */
function table(array $headers = [], array $rows = [])
{
    $table = new Table(writer());

    $table->setHeaders($headers)->setRows($rows);

    $table->render();
}

/**
 * Return whether the app is in the testing environment.
 *
 * @return bool
 */
function testing()
{
    return strpos($_SERVER['SCRIPT_NAME'], 'phpunit') !== false;
}

/**
 * Output the given text to the console.
 *
 * @param  string  $output
 * @return void
 */
function output($output)
{
    writer()->writeln($output);
}

/**
 * Resolve the given class from the container.
 *
 * @param  string  $class
 * @return mixed
 */
function resolve($class)
{
    return Container::getInstance()->make($class);
}

/**
 * Swap the given class implementation in the container.
 *
 * @param  string  $class
 * @param  mixed  $instance
 * @return void
 */
function swap($class, $instance)
{
    Container::getInstance()->instance($class, $instance);
}

/**
 * Retry the given function N times.
 *
 * @param  int  $retries
 * @param  callable  $retries
 * @param  int  $sleep
 * @return mixed
 */
function retry($retries, $fn, $sleep = 0)
{
    beginning:
    try {
        return $fn();
    } catch (Exception $e) {
        if (! $retries) {
            throw $e;
        }

        $retries--;

        if ($sleep > 0) {
            usleep($sleep * 1000);
        }

        goto beginning;
    }
}

/**
 * Verify that the script is currently running as "sudo".
 *
 * @return void
 */
function should_be_sudo()
{
    if (! isset($_SERVER['SUDO_USER'])) {
        throw new Exception('This command must be run with sudo.');
    }
}

/**
 * Tap the given value.
 *
 * @param  mixed  $value
 * @param  callable  $callback
 * @return mixed
 */
function tap($value, callable $callback)
{
    $callback($value);

    return $value;
}

/**
 * Determine if a given string ends with a given substring.
 *
 * @param  string  $haystack
 * @param  string|array  $needles
 * @return bool
 */
function ends_with($haystack, $needles)
{
    foreach ((array) $needles as $needle) {
        if (substr($haystack, -strlen($needle)) === (string) $needle) {
            return true;
        }
    }

    return false;
}

/**
 * Determine if a given string starts with a given substring.
 *
 * @param  string  $haystack
 * @param  string|string[]  $needles
 * @return bool
 */
function starts_with($haystack, $needles)
{
    foreach ((array) $needles as $needle) {
        if ((string) $needle !== '' && strncmp($haystack, $needle, strlen($needle)) === 0) {
            return true;
        }
    }

    return false;
}

/**
 * Get the user.
 */
function user()
{
    if (! isset($_SERVER['SUDO_USER'])) {
        return $_SERVER['USER'];
    }

    return $_SERVER['SUDO_USER'];
}
