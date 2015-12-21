<?php

namespace Riimu\PhpConfigure;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command for configuring PHP installations.
 * @author Riikka Kalliomäki <riikka.kalliomaki@gmail.com>
 * @copyright Copyright (c) 2015, Riikka Kalliomäki
 * @license http://opensource.org/licenses/mit-license.php MIT License
 */
class ConfigureCommand extends Command
{
    /** @var OutputInterface The output interface */
    private $output;

    /** @var string[] Glob patterns for fetching php paths */
    private $paths;

    /** @var string[] File names for base php configuration */
    private $baseFiles;

    /** @var string[] Settings to configure in configuration */
    private $settings;

    /** @var string[] Extensions to enable the configuration */
    private $extensions;

    /**
     * Configures the PHP Configuration command.
     */
    public function configure()
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
     * Runs the PHP configuration commands.
     * @param InputInterface $input The input interface
     * @param OutputInterface $output The output interface
     * @return void
     */
    public function execute(InputInterface $input, OutputInterface $output)
    {
        $this->output = $output;
        $this->loadConfiguration($input->getArgument('configuration'));

        foreach ($this->getNextPath() as $path) {
            try {
                $this->configurePhp($path);
            } catch (\Exception $exception) {
                $output->writeln("Exception: " . $exception->getMessage());
            }
        }
    }

    /**
     * Loads the configuration from a json file
     * @param string $path Path to the json configuration file
     * @return void
     */
    private function loadConfiguration($path)
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
    private function getNextPath()
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
    private function configurePhp($path)
    {
        $version = $this->getPhpVersion($path);
        $iniPath = $path . DIRECTORY_SEPARATOR . 'php.ini';

        $this->output->writeln("Configuring PHP $version @ $path");

        if (!file_exists($iniPath)) {
            if (!$this->createIniFile($path, $iniPath)) {
                throw new \RuntimeException("Could not create new ini file");
            }
        }

        $ini = file_get_contents($iniPath);
        $modified = false;
        $ws = '[ \\t]*';

        foreach ($this->extensions as $extension) {
            $pattern = sprintf(
                "{$ws}extension{$ws}={$ws}%s{$ws}(?=\\r|\\n)",
                preg_quote($extension, '/')
            );

            if (preg_match("/^$pattern/im", $ini)) {
                continue;
            }

            $ini = preg_replace("/^;$pattern/im", "extension=$extension", $ini, 1, $count);

            if ($count > 0) {
                $modified = true;
                $this->output->writeln(" - Enabled extension $extension");
            } else {
                $this->output->writeln(" - Could not find extension $extension");
            }
        }

        $placeHolders = [
            '{PATH}' => $path,
        ];

        foreach ($this->settings as $name => $value) {
            $value = strtr($value, $placeHolders);
            $pattern = $pattern = sprintf(
                "{$ws}(?i)%s(?-i){$ws}={$ws}(.*?){$ws}(?=\\r|\\n)",
                preg_quote($name, '/')
            );

            if (preg_match("/^$pattern/m", $ini, $matches)) {
                if ($matches[1] === $value) {
                    continue;
                }

                $ini = preg_replace("/^$pattern/m", addcslashes("$name = $value", '\\'), $ini, 1, $count);
            } else {
                $ini = preg_replace_callback("/^;$pattern/m", function ($matches) use ($name, $value) {
                    return $matches[0] . PHP_EOL . "$name = $value";
                }, $ini, 1, $count);
            }

            if ($count > 0) {
                $modified = true;
                $this->output->writeln(" - Set $name to $value");
            } else {
                $this->output->writeln(" - Could not set $name");
            }
        }

        if ($modified && !file_put_contents($iniPath, $ini)) {
            throw new \RuntimeException("Could not save ini file $iniPath");
        }
    }

    /**
     * Returns the version of the PHP in given directory.
     * @param string $path Path to the PHP installation
     * @return string The PHP version number
     */
    private function getPhpVersion($path)
    {
        $executable = realpath("$path/php.exe");

        if ($executable === false) {
            throw new \RuntimeException("Could not determine PHP executable in $path");
        }

        $version = shell_exec(sprintf(
            '%s -r "echo PHP_VERSION;"',
            escapeshellarg($executable)
        ));

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
    private function createIniFile($path, $iniPath)
    {
        foreach ($this->baseFiles as $file) {
            $full = $path . DIRECTORY_SEPARATOR . $file;

            if (file_exists($full)) {
                $this->output->writeln(" - Copying $full to $iniPath");
                return copy($full, $iniPath);
            }
        }

        return false;
    }
}
