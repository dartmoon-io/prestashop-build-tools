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
     * Vendor prefixed directory
     */
    protected $vendorPrefixedDir;
    
    /**
     * Config file for php-scooper
     */
    protected $configFile;
    
    /**
     * Vendor prefix
     */
    protected $prefix;

    /**
     * Move prefixed vendors back into vendor directory
     */
    protected $moveBackPrefixedVendor;

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
            ->addOption('vendor-prefixed-dir', 'o', InputOption::VALUE_OPTIONAL, 'Use the given directory as the output for the prefixed vendors', getcwd() . '/vendor-prefixed')
            ->addOption('config', 'c', InputOption::VALUE_OPTIONAL, 'PHP-Scoper config file', null)
            ->addOption('prefix', 'p', InputOption::VALUE_OPTIONAL, 'PHP-Scoper namespace prefix', null)
            ->addOption('move-vendor', 'm', InputOption::VALUE_OPTIONAL, 'Move prefixed vendors back into vendor directory', false)
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
        $this->vendorPrefixedDir = $input->getOption('vendor-prefixed-dir');
        $this->configFile = $input->getOption('config');
        $this->prefix = $input->getOption('prefix');
        $this->moveBackPrefixedVendor = $input->getOption('move-vendor');

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
        $this->cleanVendorPrefixedDirectory($this->vendorPrefixedDir);
        $this->copyDummyFile($this->vendorDir);
        
        // Let's prefix the vendor
        $this->prefixVendor(
            $this->workingDir, 
            $this->vendorPrefixedDir,
            $this->configFile,
            $this->prefix
        );

        $this->removePrefixedVendor($this->vendorDir, $this->vendorPrefixedDir);
        if ($this->moveBackPrefixedVendor) {
            $this->moveBackPrefixedVendors($this->vendorDir, $this->vendorPrefixedDir);
        }
        $this->dumpComposerAutoload($this->workingDir);
    }

    /**
     * Clean the output directory
     */
    protected function cleanVendorPrefixedDirectory($vendorPrefixedDir)
    {
        $filesystem = new Filesystem();
        $filesystem->remove($vendorPrefixedDir);
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
    protected function prefixVendor($workingDir, $outputDir, $configFile, $prefix)
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

        $arguments = [
            '--working-dir' => $workingDir,
            '--output-dir' => $outputDir,
            '--config' => $configFile,
            '--prefix' => $prefix,
            '--force' => true,
        ];

        // Let's execute the command
        $commandInput = new ArrayInput($arguments);
        $command->run($commandInput, $this->output);
    }

    /**
     * Remove prefixed vendor into vendor directory
     */
    protected function removePrefixedVendor($vendorDir, $vendorPrefixedDir)
    {
        // We need to remove the old packages or composer will autoload them
        // creating conflicts during development
        $finder = new Finder();
        $finder->directories()
            ->in($vendorPrefixedDir)
            ->depth(1);

        $filesystem = new Filesystem();
        foreach ($finder as $directory) {
            // Otain the path of the package relative to the outputDir
            // and then remove the original package
            $relativePath = substr($directory, strlen($vendorPrefixedDir));
            $filesystem->remove($vendorDir . '/' . $relativePath);
        }
    }

    /**
     * Remove prefixed vendors from the vendor dir
     */
    protected function moveBackPrefixedVendors($vendorDir, $vendorPrefixedDir)
    {
        // We need to remove the old packages or composer will autoload them
        // creating conflicts during development
        $finder = new Finder();
        $finder->directories()
            ->in($vendorPrefixedDir)
            ->depth(1);

        $filesystem = new Filesystem();
        foreach ($finder as $directory) {
            // Otain the path of the package relative to the outputDir
            // and then remove the original packages and copy back 
            // the prefixed one
            $relativePath = substr($directory, strlen($vendorPrefixedDir));
            $filesystem->remove($vendorDir . '/' . $relativePath); // Just to be sure
            $filesystem->mirror($directory, $vendorDir . '/' . $relativePath);
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