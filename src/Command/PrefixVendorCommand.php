<?php

namespace Dartmoon\PrestaShopBuildTools\Command;

use Dartmoon\PrestaShopBuildTools\ComposerJson;
use Humbug\PhpScoper\Console\Command\AddPrefixCommand;
use Humbug\PhpScoper\Container;
use Isolated\Symfony\Component\Finder\Finder as IsolatedFinder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;

class PrefixVendorCommand extends Command
{
    /**
     * Temp directory where to store everything we need
     */
    protected const TMP_DIR = '/.pbt/vendor';

    /**
     * @var OutputInterface
     */
    protected $output;

    /**
     * Working directory
     */
    protected $workingDir;
    
    /**
     * Vendor directory
     */
    protected $vendorDir;
    
    /**
     * Temp directory
     */
    protected $tmpDir;
    
    /**
     * Config file for php-scooper
     */
    protected $configFile;
    
    /**
     * Vendor prefix
     */
    protected $prefix;

    /**
     * Configures the current command.
     */
    protected function configure(): void
    {
        $this
            ->setname('prefix-vendor')
            ->setDescription('Prefix composer vendor')
            ->addOption('working-dir', 'd', InputOption::VALUE_OPTIONAL, 'Use the given directory as working directory', getcwd())
            ->addOption('vendor-dir', 'i', InputOption::VALUE_OPTIONAL, 'Use the given directory as the vendor directory', getcwd() . '/vendor')
            ->addOption('config', 'c', InputOption::VALUE_OPTIONAL, 'PHP-Scoper config file', null)
            ->addOption('prefix', 'p', InputOption::VALUE_OPTIONAL, 'PHP-Scoper namespace prefix', null)
        ;
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        // Save the output interface, this is needed
        // after, when we are going to run PHP-Scoper
        $this->output = $output;
        
        // Extract all options needed to prefix the vendors
        $this->workingDir = $input->getOption('working-dir');
        $this->vendorDir = $input->getOption('vendor-dir');
        $this->tmpDir = $this->workingDir . self::TMP_DIR;
        $this->configFile = $input->getOption('config');
        $this->prefix = $input->getOption('prefix');

        // If the user did not specify a config file
        if (!$this->configFile) {
            $this->configFile = realpath(dirname(dirname(__DIR__)) . '/scoper.inc.php');

            // Let's check if the user overrided it, placing a config file
            // into the working directory
            $overridedConfigFile = $this->workingDir . '/scoper.inc.php';
            if (file_exists($overridedConfigFile) && is_file($overridedConfigFile)) {
                $this->configFile = $overridedConfigFile;
            }
        }

        // If the user did not specify a prefix
        if (!$this->prefix) {
            // The prefix should be saved into the composer.json
            $composerJson = new ComposerJson($this->workingDir . '/composer.json');
            $this->prefix = $composerJson->get('extra.prestashop-build-tools.prefix');
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->cleanTempDirectory($this->tempDir);
        $this->copyDummyFile($this->vendorDir);
        
        // Let's run php-scoper
        $arguments = [
            '--working-dir' => $this->workingDir,
            '--output-dir' => $this->tempDir,
            '--config' => $this->configFile,
            '--prefix' => $this->prefix,
            '--force' => true,
        ];
        $this->runPHPScoper($arguments);

        $this->moveBackPrefixedVendors($this->vendorDir, $this->tempDir);
        $this->dumpComposerAutoload($this->workingDir);
    }

    /**
     * Clean the output directory
     */
    protected function cleanTempDirectory($tempDir)
    {
        $filesystem = new Filesystem();
        $filesystem->remove($tempDir);
    }

    /**
     * Copy dummy file into vendor directory
     */
    public function copyDummyFile($vendorDir)
    {
        // So php-scoper do not retain the directory structure given
        // by composer (vendor_name/package_name). To overcome this
        // we are going to trick it putting an empty txt file inside
        // the vendor folder. With this the "common path" of all vendors
        // is exactly the vendor folder and so the directory structure is preserved
        $filesystem = new Filesystem();
        $source = dirname(dirname(__DIR__)) . '/build-tools.txt';
        $destination = $vendorDir . '/build-tools.txt';
        $filesystem->copy($source, $destination, true);
    }

    /**
     * Run PHPScoper
     */
    protected function runPHPScoper($arguments = [])
    {
        // Exposes the finder used by PHP-Scoper PHAR to allow its usage in the configuration file.
        if (false === class_exists(IsolatedFinder::class)) {
            class_alias(Finder::class, IsolatedFinder::class);
        }

        // Let's create the container
        // and instantiate the command
        $container = new Container();
        $command = new AddPrefixCommand(
            new Filesystem(),
            $container->getScoper()
        );
        $command->setApplication($this->getApplication());

        // Let's execute the command
        $commandInput = new ArrayInput($arguments);
        $command->run($commandInput, $this->output);
    }

    /**
     * Remove prefixed vendors from the vendor dir
     */
    protected function moveBackPrefixedVendors($vendorDir, $outputDir)
    {
        // We need to remove the old packages or composer will autoload them
        // creating conflicts during development
        $finder = new Finder();
        $finder->directories()
            ->in($outputDir)
            ->depth(1);

        $filesystem = new Filesystem();
        foreach ($finder as $directory) {
            // Otain the path of the package relative to the outputDir
            // and then remove the original packages and copy back 
            // the prefixed one
            $relativePath = substr($directory, strlen($outputDir));
            $filesystem->remove($vendorDir . '/' . $relativePath);
            $filesystem->copy($directory, $vendorDir . '/' . $relativePath);
        }
    }

    /**
     * Dump composer autoload
     */
    protected function dumpComposerAutoload($workingDir)
    {
        $process = new Process(['composer', 'dump-autoload', '--working-dir=' . $workingDir, '--quiet']);
        $process->run();
    }
}