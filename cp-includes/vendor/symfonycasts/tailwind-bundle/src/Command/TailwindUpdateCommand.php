<?php

/*
 * This file is part of the SymfonyCasts TailwindBundle package.
 * Copyright (c) SymfonyCasts <https://symfonycasts.com/>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfonycasts\TailwindBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfonycasts\TailwindBundle\TailwindVersionFinder;

/**
 * @internal
 */
#[AsCommand(
    name: 'tailwind:update',
    description: 'Updates the Tailwind CSS binary to the latest version within the current major',
)]
final class TailwindUpdateCommand extends TailwindConfigCommand
{
    public function __construct(
        private TailwindVersionFinder $versionFinder,
        string $rootDir,
        private ?string $binaryPath,
        private ?string $binaryVersion,
    ) {
        parent::__construct($rootDir);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($this->binaryPath) {
            throw new \RuntimeException('Cannot update: you are managing your own Tailwind CSS binary. Update it manually.');
        }

        if (!$this->binaryVersion) {
            throw new \RuntimeException('Cannot determine the current version. Set "binary_version" in your symfonycasts_tailwind config (or run "tailwind:init").');
        }

        $io->note(\sprintf('Current version: %s — looking for the latest release in the same major...', $this->binaryVersion));

        $latestVersion = $this->versionFinder->latestVersionFor($this->binaryVersion);

        if ($latestVersion === $this->binaryVersion) {
            $io->success(\sprintf('Already on the latest version (%s). Nothing to do!', $latestVersion));

            return self::SUCCESS;
        }

        $bundleConfig = $this->bundleConfig();

        if (!$bundleConfig) {
            throw new \RuntimeException('You are using a non-standard Symfony setup. Update "binary_version" in your config manually.');
        }

        $bundleConfig['symfonycasts_tailwind']['binary_version'] = $latestVersion;
        $this->writeBundleConfig($bundleConfig);

        $io->success(\sprintf('Tailwind CSS updated from %s to %s!', $this->binaryVersion, $latestVersion));
        $io->note('If "tailwind:build --watch" is running, restart it to use the new version.');

        return self::SUCCESS;
    }
}
