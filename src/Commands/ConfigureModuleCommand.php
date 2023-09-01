<?php

/*
 * This file is part of the dartmoon/prestashop-build-tools package.
 *
 * Copyright (c) 2023 Dartmoon S.r.l. <hello@dartmoon.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Dartmoon\PrestaShopBuildTools\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;

class ConfigureModuleCommand extends Command
{
    /**
     * Module working dir
     * 
     * @var string
     */
    protected $workingDir;

    /**
     * Module data
     * 
     * @var array
     */
    protected $data = [
        'NAME' => '',
        'DISPLAY_NAME' => '',
        'VERSION' => '1.0.0',
        'DESCRIPTION' => '',
        'AUTHOR' => '',
        'CLASS_NAME' => '',
        'NAMESPACE' => '',
        'VENDOR_PREFIX' => '',
        'NAME_UPPERCASE' => '',
        'YEAR' => '',
        'VENDOR_PREFIX_ESCAPED' => '',
        'NAMESPACE_ESCAPED' => '',
    ];

    /**
     * Files to replace
     * 
     * true if using "{}" as placeholder delimiters
     */
    protected $filesToReplace = [
        'composer.json',
        'copyright.txt',
        '___NAME___.php',
    ];

    protected $foldersToReplace = [
        'src/',
        'views/templates/',
    ];

    protected $mainFileName = '___NAME___.php';

    protected $placeholderPrefix = '___';

    /**
     * Configures the current command.
     */
    protected function configure(): void
    {
        $this
            ->setname('install')
            ->setDescription('Configure the module for the first installation')
        ;
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $this->workingDir = getcwd();

        // Default values
        $this->data['NAME'] = strtolower(str_replace(' ', '', basename($this->workingDir)));
        $this->data['DISPLAY_NAME'] = ucwords(str_replace(['-', '_'], [' ', ' '], $this->data['NAME']));
        $this->data['CLASS_NAME'] = preg_replace('/\W+/', '', strip_tags($this->data['DISPLAY_NAME']));
        $this->data['YEAR'] = date('Y');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $helper = $this->getHelper('question');

        // Module name
        $valid = false;
        do {
            $question = new Question("Module name ({$this->data['NAME']}): ", $this->data['NAME']);
            $name = $helper->ask($input, $output, $question);
            $valid = preg_match('/^[a-zA-Z0-9_-]+$/', $name);
        } while (!$valid);
        $this->data['NAME'] = $name;

        // Module display name
        $valid = false;
        do {
            $question = new Question("Module display name ({$this->data['DISPLAY_NAME']}): ", $this->data['DISPLAY_NAME']);
            $displayName = $helper->ask($input, $output, $question);
            $valid = preg_match('/^[^<>;=#{}]*$/u', $displayName);
        } while (!$valid);
        $this->data['DISPLAY_NAME'] = $displayName;

        // Module version
        $valid = false;
        do {
            $question = new Question("Module version ({$this->data['VERSION']}): ", $this->data['VERSION']);
            $version = $helper->ask($input, $output, $question);
            $valid = !empty($version) && version_compare($version, '0.0.1', '>=' ) >= 0;
        } while (!$valid);
        $this->data['VERSION'] = $version;

        // Module description
        $valid = false;
        do {
            $question = new Question("Module description: ", '');
            $description = $helper->ask($input, $output, $question);
            $valid = true; // All valid 
        } while (!$valid);
        $this->data['DESCRIPTION'] = $description;

        // Module author
        $valid = false;
        do {
            $question = new Question("Module author: ");
            $author = $helper->ask($input, $output, $question);
            $valid = !empty($author);
        } while (!$valid);
        $this->data['AUTHOR'] = $author;

        // Module class name
        $valid = false;
        do {
            $question = new Question("Module class name ({$this->data['CLASS_NAME']}): ", $this->data['CLASS_NAME']);
            $className = $helper->ask($input, $output, $question);
            $valid = preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/', $className);
        } while (!$valid);
        $this->data['CLASS_NAME'] = $className;

        // Module namespace
        $this->data['NAMESPACE'] = preg_replace('/\W+/', '', strip_tags($this->data['AUTHOR'])) . '\\' . $this->data['CLASS_NAME'];
        $valid = false;
        do {
            $question = new Question("Module namespace ({$this->data['NAMESPACE']}): ", $this->data['NAMESPACE']);
            $namespace = $helper->ask($input, $output, $question);

            $valid = array_reduce(explode('\\', $namespace), function ($carry, $className) {
                return $carry && preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/', $className);
            }, true);
        } while (!$valid);
        $this->data['NAMESPACE'] = $namespace;

        // Module vendor prefix
        $this->data['VENDOR_PREFIX'] = $this->data['NAMESPACE'] . '\\Vendor';
        $valid = false;
        do {
            $question = new Question("Module vendor prefix ({$this->data['VENDOR_PREFIX']}): ",  $this->data['VENDOR_PREFIX']);
            $vendorPrefix = $helper->ask($input, $output, $question);
            $valid = array_reduce(explode('\\', $vendorPrefix), function ($carry, $className) {
                return $carry && preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/', $className);
            }, true);
        } while (!$valid);
        $this->data['VENDOR_PREFIX'] = $vendorPrefix;

        // Module name uppercase
        $this->data['NAME_UPPERCASE'] = strtoupper($this->data['NAME']);

        // Configure the module
        $this->configureModule();
    }

    protected function configureModule()
    {
        // Fix namespaces
        $this->data['NAMESPACE_ESCAPED'] = str_replace('\\', '\\\\', $this->data['NAMESPACE']);
        $this->data['VENDOR_PREFIX_ESCAPED'] = str_replace('\\', '\\\\', $this->data['VENDOR_PREFIX']);

        //Let's find all files with placeholders to replace
        $finder = new Finder();
        $finder
            ->files()
            ->in(array_map(fn ($file) => $this->workingDir . '/' . $file, $this->foldersToReplace))
            ->contains('/' . array_reduce($this->getPlaceholders(), fn ($carry, $placeholder) => $carry . $placeholder . '|', '') . '/');

        // Replace finded files
        foreach ($finder as $file) {
            $this->replacePlaceholderInFile($file->getPathname());
        }

        // Replace files
        foreach ($this->filesToReplace as $file) {
            $this->replacePlaceholderInFile($this->workingDir . '/' . $file);
        }

        // Rename main file
        rename($this->workingDir . '/' . $this->mainFileName, $this->workingDir . '/' . $this->data['NAME'] . '.php');

        // Execute composer update
        $this->executeComposerUpdate();
    }

    protected function replacePlaceholderInFile($file)
    {
        $content = file_get_contents($file);
        $content = str_replace(
            $this->getPlaceholders(),
            array_values($this->data),
            $content
        );
        file_put_contents($file, $content);
    }

    protected function executeComposerUpdate()
    {
        $process = new Process(['composer', 'update']);
        $process->setWorkingDirectory($this->workingDir);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException($process->getErrorOutput());
        }
    }

    protected function getPlaceholders()
    {
        return array_map(function ($key) {
            return $this->placeholderPrefix . $key . $this->placeholderPrefix;
        }, array_keys($this->data));
    }
}