<?php

declare(strict_types=1);

namespace Modules\Seo\Command;

use App\Core\Settings\SettingsRegistry;
use Doctrine\DBAL\Connection;
use Modules\Seo\Install\SeoSettingsSeeder;
use Modules\Seo\Sitemap\SitemapBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'cp:seo:rebuild-sitemap', description: 'Seed missing SEO defaults and clear the sitemap cache')]
final class RebuildSitemapCommand extends Command
{
    public function __construct(
        private readonly SitemapBuilder $sitemaps,
        private readonly Connection $connection,
        private readonly SettingsRegistry $settings,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $written = SeoSettingsSeeder::seed($this->connection);
        $this->settings->clearCache();
        $this->sitemaps->clearCache();
        $output->writeln(sprintf('SEO defaults written: %d keys.', $written));
        $output->writeln('Sitemap cache cleared.');

        return Command::SUCCESS;
    }
}
