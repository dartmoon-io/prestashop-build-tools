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

use Dartmoon\PrestaShopBuildTools\ComposerJson;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

class ConfigureModuleCommand extends Command
{
    /**
     * Module working dir
     * 
     * @var string
     */
    protected $workingDir;

    /**
     * Composer.json file
     * 
     * @var ComposerJson
     */
    protected $composerJson;

    /**
     * Module data
     * 
     * @var string
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
    ];

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
        $this->composerJson = new ComposerJson($this->workingDir . '/composer.json');

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
            $valid = preg_match('/^[^0-9!<>,;?=+()@#"°{}_$%:¤|]*$/u', $displayName);
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
    }
}