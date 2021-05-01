<?php

namespace Dartmoon\PrestaShopBuildTools\Command;

use Dartmoon\PrestaShopBuildTools\ComposerJson;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;

class BuildModuleCommand extends Command
{
    /**
     * Temp directory where to store everything we need
     */
    protected const TMP_DIR = '/.pbt';

    /**
     * Working directory
     */
    protected $workingDir;

    /**
     * Output directory
     */
    protected $outputDir;

    /**
     * Tmp directory
     */
    protected $tmpDir;
    
    /**
     * Module name
     */
    protected $moduleName;
    
    /**
     * Exclude files
     */
    protected $excludeFile;
    
    /**
     * Index php file to use
     */
    protected $indexPhpFile;
    
    /**
     * Whether using authoritative classmap
     */
    protected $authoritativeClassmap;

    /**
     * Configures the current command.
     */
    protected function configure(): void
    {
        $this
            ->setname('prefix-vendor')
            ->setDescription('Create the publishable artifact for the current module')
            ->addOption('working-dir', 'd', InputOption::VALUE_OPTIONAL, 'Use the given directory as working directory', getcwd())
            ->addOption('output-dir', 'b', InputOption::VALUE_OPTIONAL, 'User the given directory as output directory for the artifact', getcwd())
            ->addOption('module-name', 'm', InputOption::VALUE_OPTIONAL, 'Name of the module you are building', null)
            ->addOption('exclude', 'e', InputOption::VALUE_OPTIONAL, 'rsync-like exclude file to exclude files from artifact', null)
            ->addOption('authoritative', 'a', InputOption::VALUE_NONE, 'Generate classmap authoritative autoload')
        ;
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        // Extract all options needed to build the module artifact
        $this->workingDir = $input->getOption('working-dir');
        $this->outputDir = $input->getOption('output-dir');
        $this->tmpDir = $this->workingDir . self::TMP_DIR;
        $this->moduleName = $input->getOption('module-name');
        $this->excludeFile = $input->getOption('exclude');
        $this->indexPhpFile = realpath(dirname(dirname(__DIR__))) . '/index.php';
        $this->authoritativeClassmap = $input->getOption('authoritative');

        // If the user did not specify an exludes file
        if (!$this->excludeFile) {
            $this->excludeFile = realpath(dirname(dirname(__DIR__)) . '/excludes.txt');

            // Let's check if the user overrided it, placing an exludes file
            // into the working directory
            $overridedExcludedFile = $this->workingDir . '/excludes.txt';
            if (file_exists($overridedExcludedFile) && is_file($overridedExcludedFile)) {
                $this->excludeFile = $overridedExcludedFile;
            }
        }

        // If the user did not specify a config file
        $overridedIndexPhpFile = $this->workingDir . '/index.php';
        if (file_exists($overridedIndexPhpFile) && is_file($overridedIndexPhpFile)) {
            $this->indexPhpFile = $overridedIndexPhpFile;
        }

        // If the user did not specify a module name
        if (!$this->moduleName) {
            // The prefix should be saved into the composer.json
            $composerJson = new ComposerJson($this->workingDir . '/composer.json');
            $this->moduleName = $composerJson->get('extra.prestashop-build-tools.name');
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Composer the build directory
        $buildDir = $this->tmpDir . '/' . $this->moduleName;
        $artifactName = $this->moduleName . '.zip';

        // If the user chosen to generate authoritatative classmap autoload
        if ($this->authoritativeClassmap) {
            $this->generateAuthoritativeClassmap($this->workingDir);
        }

        $this->cleanTempDirectory($buildDir);
        $this->removeArtifact($this->outputDir, $artifactName);
        $this->copyFiles($this->workingDir, $buildDir, $this->excludeFile);
        $this->addIndexPhpFile($buildDir, $this->indexPhpFile);
        $this->generateArtifact($this->tmpDir, $this->moduleName, $artifactName);
        $this->moveArtifact($this->tmpDir, $this->outputDir, $artifactName);
    }

    /**
     * Generate authoritative classmap autoload
     */
    protected function generateAuthoritativeClassmap($workingDir)
    {
        $process = new Process(['composer', 'dump-autoload', '--working-dir=' . $workingDir, '--classmap-authoritative', '--quiet']);
        $process->run();
    }

    /**
     * Clean the output directory
     */
    protected function cleanTempDirectory($tempDir)
    {
        $filesystem = new Filesystem();
        $filesystem->remove($tempDir);
        $filesystem->mkdir($tempDir);
    }

    /**
     * Remove artifact if exists
     */
    protected function removeArtifact($outputDir, $artifactName)
    {
        $filesystem = new Filesystem();
        if ($filesystem->exists($outputDir . '/' . $artifactName)) {
            $filesystem->remove($outputDir . '/' . $artifactName);
        }
    }

    /**
     * Copy module files into build directory
     * 
     * We are using rsync for speed and the possibility
     * to ignore files to copy
     */
    protected function copyFiles($workingDir, $buildDir, $excludeFile)
    {
        $process = new Process([
            'rsync', 
            '-a', 
            '--exclude-from=' . $excludeFile, 
            '--prune-empty-dirs', // We don't want empty directories
            '--min-size=1', // We don't want empty files
            $workingDir . '/',
            $buildDir,
            '--quiet'
        ]);
        $process->run();
    }

    /**
     * PrestaShop demand an index.php into every single directory
     */
    protected function addIndexPhpFile($buildDir, $indexPhpFile)
    {
        $filesystem = new Filesystem();

        $finder = new Finder();
        $finder->directories()
            ->in($buildDir);

        foreach ($finder as $directory) {
            if (!$filesystem->exists($directory . '/index.php')) {
                $filesystem->copy($indexPhpFile, $directory . '/index.php');
            }
        }
    }

    /**
     * Generate the module artifact
     */
    protected function generateArtifact($tmpDir, $moduleName, $artifactName)
    {
        // We are using zip to be more efficient
        // and less error prone. ZipArchive can
        // sometimes be problematic
        $process = new Process(['zip', '-r', $artifactName, $moduleName, '--quiet'], $tmpDir);
        $process->run();
    }

    /**
     * Move the artifact to the right output directory
     */
    protected function moveArtifact($tmpDir, $outputDir, $artifactName)
    {
        $filesystem = new Filesystem();
        $filesystem->rename($tmpDir . '/' . $artifactName, $outputDir  . '/' . $artifactName);
    }
}