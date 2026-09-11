<?php

declare(strict_types=1);

namespace Modules\Blog\Install;

use App\Core\Module\AbstractSqlModuleInstaller;
use App\Core\Module\ModuleInstallContext;
use App\Core\Taxonomy\DefaultVocabularies;
use App\Core\Taxonomy\VocabularySeeder;

/**
 * Blog stores posts as core Node rows. Comments live in blog_comments and are dropped on purge.
 * GC2 seeds blog_category / blog_tag vocabularies (idempotent).
 */
final class ModuleInstaller extends AbstractSqlModuleInstaller
{
    public function __construct(
        private readonly VocabularySeeder $vocabularySeeder,
    ) {
    }

    protected function moduleId(): string
    {
        return 'blog';
    }

    /**
     * @return list<string>
     */
    protected function tables(): array
    {
        return ['blog_comments'];
    }

    public function install(ModuleInstallContext $context): void
    {
        parent::install($context);
        $this->seedVocabularies();
    }

    public function upgrade(ModuleInstallContext $context, string $fromVersion, string $toVersion): void
    {
        parent::upgrade($context, $fromVersion, $toVersion);
        $this->seedVocabularies();
    }

    private function seedVocabularies(): void
    {
        $this->vocabularySeeder->ensure([
            [
                'machine_name' => DefaultVocabularies::BLOG_CATEGORY,
                'label' => 'Blog categories',
                'hierarchical' => true,
                'weight' => 10,
            ],
            [
                'machine_name' => DefaultVocabularies::BLOG_TAG,
                'label' => 'Blog tags',
                'hierarchical' => false,
                'weight' => 20,
            ],
        ]);
    }
}
