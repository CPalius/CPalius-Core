<?php

/*
 * This file is part of the SymfonyCasts TailwindBundle package.
 * Copyright (c) SymfonyCasts <https://symfonycasts.com/>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfonycasts\TailwindBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Yaml\Yaml;

/**
 * @internal
 */
abstract class TailwindConfigCommand extends Command
{
    public function __construct(
        protected string $rootDir,
    ) {
        parent::__construct();
    }

    protected function bundleConfigFile(): string
    {
        return $this->rootDir.'/config/packages/symfonycasts_tailwind.yaml';
    }

    protected function bundleConfig(): array
    {
        if (!class_exists(Yaml::class)) {
            throw new \RuntimeException('You are using a non-standard Symfony setup. You will need to manage the bundle configuration manually.');
        }

        if (!file_exists($this->bundleConfigFile())) {
            return [];
        }

        return Yaml::parseFile($this->bundleConfigFile());
    }

    protected function writeBundleConfig(array $bundleConfig): void
    {
        file_put_contents($this->bundleConfigFile(), Yaml::dump($bundleConfig));
    }
}
