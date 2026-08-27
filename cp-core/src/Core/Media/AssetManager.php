<?php

namespace App\Core\Media;

use App\Entity\Asset;
use App\Repository\AssetRepository;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Yüklenen dosyaları cpalius_storage (Flysystem) diskine hash tabanlı bir
 * isimlendirmeyle kaydeder ve karşılığında bir Asset entity'si kalıcı hale
 * getirir. Asset, Node'dan bağımsız 1. sınıf bir vatandaştır (bkz. Asset
 * entity doc-block'u) — bu servis onun tek yazma kapısıdır.
 *
 * Dosya çakışması önleme stratejisi: dosya İÇERİĞİNİN sha256 hash'i
 * hesaplanır. Aynı hash zaten varsa (bkz. AssetRepository::findOneByHash),
 * dosya diskte TEKRAR yazılmaz ve yeni bir Asset satırı oluşturulmaz — var
 * olan Asset doğrudan döndürülür. Bu hem depolamada tekrarı önler hem de
 * "aynı görseli iki kez yükledim" durumunda veritabanının şişmesini engeller.
 */
final class AssetManager
{
    public function __construct(
        private readonly FilesystemOperator $cpaliusStorage,
        private readonly EntityManagerInterface $entityManager,
        private readonly AssetRepository $assetRepository,
    ) {
    }

    public function upload(UploadedFile $uploadedFile): Asset
    {
        $hash = hash_file('sha256', $uploadedFile->getPathname());
        if ($hash === false) {
            throw new \RuntimeException(sprintf('Dosya hash\'i hesaplanamadı: "%s"', $uploadedFile->getClientOriginalName()));
        }

        $existing = $this->assetRepository->findOneByHash($hash);
        if ($existing !== null) {
            return $existing;
        }

        $extension = $uploadedFile->getClientOriginalExtension() ?: ($uploadedFile->guessExtension() ?? 'bin');
        $filename = $hash.'.'.strtolower($extension);
        $path = date('Y/m');
        $storageKey = $path.'/'.$filename;

        $stream = fopen($uploadedFile->getPathname(), 'r');
        if ($stream === false) {
            throw new \RuntimeException(sprintf('Dosya açılamadı: "%s"', $uploadedFile->getPathname()));
        }

        try {
            $this->cpaliusStorage->writeStream($storageKey, $stream);
        } finally {
            if (\is_resource($stream)) {
                fclose($stream);
            }
        }

        $asset = new Asset(
            filename: $filename,
            originalName: $uploadedFile->getClientOriginalName(),
            path: $path,
            mimeType: $uploadedFile->getMimeType() ?? 'application/octet-stream',
            fileSize: $uploadedFile->getSize() ?? 0,
            hash: $hash,
        );

        $this->entityManager->persist($asset);
        $this->entityManager->flush();

        return $asset;
    }
}
