<?php

namespace Riimu\PhpConfigure;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command for configuring PHP installations.
 * @author Riikka Kalliomäki <riikka.kalliomaki@gmail.com>
 * @copyright Copyright (c) 2015, Riikka Kalliomäki
 * @license http://opensource.org/licenses/mit-license.php MIT License
 */
class ConfigureCommand extends Command
{
    /** @var OutputInterface|null The output interface */
    private $output;

    /** @var string[] Glob patterns for fetching php paths */
    private $paths = [];

    /** @var string[] File names for base php configuration */
    private $baseFiles = [];

    /** @var string[] Settings to configure in configuration */
    private $settings = [];

    /** @var string[] Extensions to enable the configuration */
    private $extensions = [];

    /** @var string The contents of the ini file being configured */
    private $iniContents = '';

    /** @var bool Whether the ini file has been modified or not */
    private $modified = false;

    /**
     * Configures the PHP Configuration command.
     */
    public function configure(): void
    {
        $this
            ->setName('configure')
            ->setDescription('Configures provided php installations')
            ->addArgument(
                'configuration',
                InputArgument::OPTIONAL,
                'The configuration file',
                'configure.json'
            );
    }

    /**
     * Writes a line of text to the output.
     * @param string $line Line of text to write
     */
    private function write(string $line): void
    {
        if ($this->output instanceof OutputInterface) {
            $this->output->writeln($line);
        }
    }

    /**
     * Writes a line of text to error output.
     * @param string $line Line of text to write
     */
    private function error(string $line): void
    {
        if ($this->output instanceof ConsoleOutputInterface) {
            $this->output->getErrorOutput()->writeln($line);
        } elseif ($this->output instanceof OutputInterface) {
            $this->output->writeln($line);
        }
    }

    /**
     * Runs the PHP configuration commands.
     * @param InputInterface $input The input interface
     * @param OutputInterface $output The output interface
     * @return int The command exit status
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->output = $output;
        $this->loadConfiguration($input->getArgument('configuration'));

        foreach ($this->getNextPath() as $path) {
            try {
                $this->configurePhp($path);
            } catch (\Throwable $exception) {
                $this->error('Exception: ' . $exception->getMessage());
                return 1;
            }
        }

        return 0;
    }

    /**
     * Loads the configuration from a json file
     * @param string $path Path to the json configuration file
     * @return void
     */
    private function loadConfiguration(string $path): void
    {
        if (!file_exists($path)) {
            throw new \RuntimeException("The configuration file $path does not exist");
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new \RuntimeException("Could not read configuration file $path");
        }

        $json = json_decode($contents, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException(
                "Error parsing configuration file $path: " . json_last_error_msg()
            );
        }

        $this->paths = $json['paths'];
        $this->baseFiles = $json['base'];
        $this->settings = $json['settings'];
        $this->extensions = $json['extensions'];
    }

    /**
     * Returns the generator that returns paths to PHP installations.
     * @return \Generator The generator that returns paths to PHP.
     */
    private function getNextPath(): \Generator
    {
        foreach ($this->paths as $pattern) {
            foreach (glob($pattern, GLOB_ONLYDIR) as $path) {
                $real = realpath($path);

                if ($real !== false) {
                    yield $path;
                }
            }
        }
    }

    /**
     * Configures the PHP installation in the given directory.
     * @param string $path Path to the PHP installation
     * @return void
     */
    private function configurePhp(string $path): void
    {
        $version = $this->getPhpVersion($path);
        $iniPath = $path . DIRECTORY_SEPARATOR . 'php.ini';

        $this->write("Configuring PHP $version @ $path");

        if (!file_exists($iniPath)) {
            $success = $this->createIniFile($path, $iniPath);

            if (!$success) {
                throw new \RuntimeException('Could not create new ini file');
            }
        }

        $contents = file_get_contents($iniPath);

        if ($contents === false) {
            throw new \RuntimeException('Could not read the ini file');
        }

        $this->iniContents = $contents;
        $this->modified = false;

        $this->configureExtensions();
        $this->configureSettings([
            '{PATH}' => $path,
        ]);

        if ($this->modified && !file_put_contents($iniPath, $this->iniContents)) {
            throw new \RuntimeException("Could not save ini file $iniPath");
        }
    }

    /**
     * Returns the version of the PHP in given directory.
     * @param string $path Path to the PHP installation
     * @return string The PHP version number
     */
    private function getPhpVersion(string $path): string
    {
        $executable = realpath("$path/php.exe");

        if ($executable === false) {
            throw new \RuntimeException("Could not determine PHP executable in $path");
        }

        $escapedPath = escapeshellarg($executable);
        $version = exec("$escapedPath -r \"echo PHP_VERSION;\"");

        if (!preg_match('/^\\d+\\.\\d+\\.\\d+/', $version)) {
            throw new \RuntimeException("Could not determine PHP version in $path");
        }

        return $version;
    }

    /**
     * Creates a new ini file from the base configuration.
     * @param string $path Path to the PHP installation
     * @param string $iniPath Path to the actual ini file
     * @return bool True if the ini file was created, false if not
     */
    private function createIniFile(string $path, string $iniPath): bool
    {
        foreach ($this->baseFiles as $file) {
            $full = $path . DIRECTORY_SEPARATOR . $file;

            if (file_exists($full)) {
                $this->write(" - Copying $full to $iniPath");
                return copy($full, $iniPath);
            }
        }

        return false;
    }

    /**
     * Enables the extensions in the loaded ini file.
     */
    private function configureExtensions(): void
    {
        foreach ($this->extensions as $extension) {
            $pattern = sprintf(
                "[ \\t]*extension[ \\t]*=[ \\t]*((php_)?%s(\.(dll|so))?)[ \\t]*(;.*)?(?=[\\r\\n]|\$)",
                preg_quote($extension, '/')
            );

            if (preg_match("/^$pattern/im", $this->iniContents)) {
                continue;
            }

            $this->iniContents = preg_replace("/^;$pattern/im", 'extension=$1', $this->iniContents, 1, $count);

            if ($count !== 1) {
                $this->write(" - Could not find extension $extension");
                continue;
            }

            $this->modified = true;
            $this->write(" - Enabled extension $extension");
        }
    }

    /**
     * Configures the settings in the loaded ini file.
     * @param array $placeHolders The replacement values for placeholders
     */
    private function configureSettings(array $placeHolders): void
    {
        foreach ($this->settings as $name => $value) {
            $changed = $this->changeSetting($name, strtr($value, $placeHolders));

            if ($changed === null) {
                continue;
            }

            if (!$changed) {
                $this->write(" - Could not set $name");
                continue;
            }

            $this->modified = true;
            $this->write(" - Set $name to $value");
        }
    }

    /**
     * Changes the value of the given setting.
     * @param string $name The name of the setting
     * @param string $value The new value for the setting
     * @return bool|null True if settings was changed, null if not changed, and false if the settings could not be found
     */
    private function changeSetting(string $name, string $value): ?bool
    {
        $pattern = $pattern = sprintf(
            "[ \\t]*(?i)%s(?-i)[ \\t]*=[ \\t]*(.*?)[ \\t]*(?=\\r|\\n)",
            preg_quote($name, '/')
        );

        if (preg_match("/^$pattern/m", $this->iniContents, $matches)) {
            if ($matches[1] === $value) {
                return null;
            }

            $replacement = addcslashes("$name = $value", '\\');
            $this->iniContents = preg_replace("/^$pattern/m", $replacement, $this->iniContents, 1, $count);
            return $count === 1;
        }

        $replacement = function (array $matches) use ($name, $value): string {
            return $matches[0] . PHP_EOL . "$name = $value";
        };
        $this->iniContents = preg_replace_callback("/^;$pattern/m", $replacement, $this->iniContents, 1, $count);
        return $count === -1;
    }
}
