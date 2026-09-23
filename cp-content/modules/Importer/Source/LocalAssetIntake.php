<?php

declare(strict_types=1);

namespace Modules\Importer\Source;

use App\Core\Media\AssetManager;
use App\Core\Media\AssetUrlGenerator;
use App\Entity\Asset;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Puts one local file through AssetManager and returns a public URL.
 */
final class LocalAssetIntake
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ?AssetManager $assetManager = null,
        private readonly ?AssetUrlGenerator $urls = null,
    ) {
    }

    public function store(string $path, string $originalName = ''): ?int
    {
        if ($this->assetManager === null || !is_file($path) || !is_readable($path)) {
            return null;
        }

        try {
            $asset = $this->assetManager->upload(new UploadedFile(
                $path,
                $originalName !== '' ? $originalName : basename($path),
                null,
                null,
                true,
            ));
        } catch (\Throwable) {
            return null;
        }

        return $asset->getId();
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, string>
     */
    public function urlsById(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return [];
        }

        /** @var list<Asset> $assets */
        $assets = $this->entityManager->getRepository(Asset::class)->findBy(['id' => $ids]);
        $urls = [];

        foreach ($assets as $asset) {
            $id = $asset->getId();

            if ($id !== null) {
                $urls[$id] = $this->url($asset);
            }
        }

        return $urls;
    }

    public function url(Asset $asset): string
    {
        if ($this->urls !== null) {
            return $this->urls->forKey($asset->getStorageKey());
        }

        return '/uploads/'.trim($asset->getPath(), '/').'/'.$asset->getFilename();
    }
}
