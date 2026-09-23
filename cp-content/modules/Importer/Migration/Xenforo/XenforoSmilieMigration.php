<?php

declare(strict_types=1);

namespace Modules\Importer\Migration\Xenforo;

use App\Core\Media\AssetManager;
use App\Core\Media\AssetUrlGenerator;
use App\Core\Migrate\ConfigurableMigrationInterface;
use App\Core\Migrate\MigrationDestinationInterface;
use App\Core\Migrate\MigrationOption;
use App\Core\Migrate\MigrationOptionResolver;
use App\Core\Migrate\MigrationRow;
use App\Core\Migrate\MigrationSourceInterface;
use App\Core\Migrate\Source\DatabaseSource;
use App\Core\Migrate\Source\ForeignDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Forum\Migrate\ForumSmilieDestination;
use Modules\Forum\Service\ForumSmilieCatalog;
use Modules\Importer\Migration\DatabaseOptions;
use Modules\Importer\Source\ForumDataFolder;
use Modules\Importer\Source\LocalAssetIntake;

/**
 * XenForo smilies become the forum smilie catalog.
 *
 * Posts keep the trigger (":cool:") in the stored HTML. The catalog is what
 * turns that into the same picture XenForo showed, so this step can run
 * after the posts — or before — without rewriting them.
 *
 * image_url is often a path under the old board ("data/smilies/cool.png").
 * siteUrl turns that into something the browser can fetch; without it the
 * trigger still maps to the built-in emoji for the codes XenForo ships.
 */
final class XenforoSmilieMigration implements ConfigurableMigrationInterface
{
    public const ID = 'xenforo.smilies';

    /** @var array<string, string> */
    private readonly array $options;

    /**
     * @param array<string, string> $options
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        array $options = [],
        private readonly ?ForeignDatabase $suppliedDatabase = null,
        private readonly ?AssetManager $assetManager = null,
        private readonly ?AssetUrlGenerator $urls = null,
    ) {
        $this->options = $options;
    }

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'XenForo smilies';
    }

    public function dependsOn(): array
    {
        return [];
    }

    public function options(): array
    {
        return [
            ...DatabaseOptions::all('xf_'),
            MigrationOption::optional('siteUrl', 'Old board URL, used to turn data/smilies/… into a fetchable image', ''),
        ];
    }

    public function withOptions(array $values): static
    {
        return new static($this->entityManager, MigrationOptionResolver::resolve($this->options(), $values), $this->suppliedDatabase, $this->assetManager, $this->urls);
    }

    public function source(): MigrationSourceInterface
    {
        $database = $this->database();
        $database->assertTables(['smilie']);

        $smilie = $database->table('smilie');

        return new DatabaseSource(
            $database,
            $smilie.' s',
            's.smilie_id',
            's.smilie_id, s.title, s.smilie_text, s.image_url, s.smilie_category_id, s.display_order, s.display_in_editor',
            '',
            200,
            sprintf('XenForo smilies in %s', $smilie),
        );
    }

    public function destination(): MigrationDestinationInterface
    {
        return new ForumSmilieDestination($this->entityManager);
    }

    public function transform(MigrationRow $row): ?MigrationRow
    {
        $codes = $this->codes($row->getString('smilie_text'));

        if ($codes === []) {
            return null;
        }

        return $row->withData([
            'code' => $codes[0],
            'extraCodes' => implode("\n", \array_slice($codes, 1)),
            'title' => trim($row->getString('title')) ?: $codes[0],
            'imageUrl' => $this->imageUrl(trim($row->getString('image_url'))),
            'emoji' => ForumSmilieCatalog::defaultEmojiFor($codes),
            'category' => trim($row->getString('smilie_category_id')) ?: 'default',
            'sortOrder' => trim($row->getString('display_order')),
            'displayInEditor' => trim($row->getString('display_in_editor')) === '0' ? '0' : '1',
            'importedFrom' => 'xenforo',
        ]);
    }

    /**
     * @return list<string>
     */
    private function codes(string $text): array
    {
        $out = [];

        foreach (preg_split('/\r\n|\n|\r/', $text) ?: [] as $line) {
            $code = trim($line);
            if ($code !== '') {
                $out[] = $code;
            }
        }

        return array_values(array_unique($out));
    }

    private function imageUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        $files = ForumDataFolder::fromOption($this->options['data'] ?? '');
        $local = $files?->xenforoRelative($url);

        if ($local !== null && $this->assetManager !== null) {
            $id = (new LocalAssetIntake($this->entityManager, $this->assetManager, $this->urls))->store($local, basename($local));

            if ($id !== null) {
                $urls = (new LocalAssetIntake($this->entityManager, $this->assetManager, $this->urls))->urlsById([$id]);

                if (isset($urls[$id])) {
                    return $urls[$id];
                }
            }
        }

        $site = rtrim($this->options['siteUrl'] ?? '', '/');

        return $site === '' ? $url : $site.'/'.ltrim($url, '/');
    }

    private function database(): ForeignDatabase
    {
        if ($this->suppliedDatabase !== null) {
            return $this->suppliedDatabase;
        }

        if ($this->options === []) {
            throw new \LogicException('This migration has not been configured; fill in the source database fields.');
        }

        return DatabaseOptions::connect($this->options, 'xf_', $this->entityManager->getConnection());
    }
}
