<?php

/*
 * This file is part of the dartmoon/prestashop-build-tools package.
 *
 * Copyright (c) 2023 Dartmoon S.r.l. <hello@dartmoon.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Dartmoon\PrestaShopBuildTools;

use Adbar\Dot;
use Exception;

class ComposerJson
{
    /**
     * @var Dot
     */
    protected $composerJson;

    public function __construct($composerJsonFile)
    {
        if (!file_exists($composerJsonFile) || !is_file($composerJsonFile)) {
            throw new Exception('This is not a valid composer.json file: \'' . $composerJsonFile . '\'');
        }

        $this->readComposerJson($composerJsonFile);
    }

    protected function readComposerJson($composerJsonFile)
    {
        $composerJson = json_decode(file_get_contents($composerJsonFile), true);
        if (json_last_error() != JSON_ERROR_NONE) {
            throw new Exception('Cannot read the composer.json file: \'' . $composerJsonFile . '\'');
        }

        $this->composerJson = new Dot($composerJson);
    }

    public function get($key, $default = null)
    {
        return $this->composerJson->get($key, $default);
    }
}