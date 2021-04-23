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

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Save the output interface, this is needed
        // after, when we are going to run PHP-Scoper
        $this->output = $output;
        
        // Extract all options needed to prefix the vendors
        $workingDir = $input->getOption('working-dir');
        $vendorDir = $input->getOption('vendor-dir');
        $tmpDir = $workingDir . self::TMP_DIR;
        $configFile = $input->getOption('config');
        $prefix = $input->getOption('prefix');

        // If the user did not specify a config file
        if (!$configFile) {
            $configFile = realpath(dirname(dirname(__DIR__)) . '/scoper.inc.php');

            // Let's check if the user overrided it, placing a config file
            // into the working directory
            $overridedConfigFile = $workingDir . '/scoper.inc.php';
            if (file_exists($overridedConfigFile) && is_file($overridedConfigFile)) {
                $configFile = $overridedConfigFile;
            }
        }

        // If the user did not specify a prefix
        if (!$prefix) {
            // The prefix should be saved into the composer.json
            $composerJson = new ComposerJson($workingDir . '/composer.json');
            $prefix = $composerJson->get('extra.prestashop-build-tools.prefix');
        }
        
        // Let's prefix the vendor
        $this->prefixVendor(
            $workingDir,
            $vendorDir,
            $tmpDir,
            $configFile,
            $prefix
        );
    }

    /**
     * Prefix the vendors
     */
    protected function prefixVendor(
        $workingDir,
        $vendorDir,
        $tempDir,
        $configFile,
        $prefix
    ) {
        $this->cleanTempDirectory($tempDir);
        $this->copyDummyFile($vendorDir);
        
        // Let's run php-scoper
        $arguments = [
            '--working-dir' => $workingDir,
            '--output-dir' => $tempDir,
            '--config' => $configFile,
            '--prefix' => $prefix,
            '--force' => true,
        ];
        $this->runPHPScoper($arguments);

        $this->moveBackPrefixedVendors($vendorDir, $tempDir);
        $this->dumpComposerAutoload($workingDir);
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