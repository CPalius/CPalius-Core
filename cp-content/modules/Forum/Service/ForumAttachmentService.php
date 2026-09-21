<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

use App\Core\Media\AssetManager;
use App\Core\Media\Exception\InvalidUploadException;
use App\Core\Media\Exception\UnsupportedAssetTypeException;
use App\Core\Settings\SettingsRegistry;
use App\Entity\Asset;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Forum\Entity\ForumNodePermission;
use Modules\Forum\Entity\ForumPost;
use Modules\Forum\Entity\ForumPostAttachment;
use Modules\Forum\Entity\ForumSection;
use Modules\Forum\Repository\ForumPostAttachmentRepository;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ForumAttachmentService
{
    public function __construct(
        private readonly AssetManager $assetManager,
        private readonly ForumPostAttachmentRepository $attachmentRepository,
        private readonly ForumPermissionService $permissionService,
        private readonly SettingsRegistry $settingsRegistry,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool) $this->settingsRegistry->get('forum.attachments_enabled', true);
    }

    public function canUpload(ForumPost $post, ?User $user): bool
    {
        return $this->canUploadInSection($post->getSection(), $user);
    }

    public function canUploadInSection(ForumSection $section, ?User $user): bool
    {
        return $this->isEnabled()
            && $user !== null
            && $this->permissionService->isAllowed($section, $user, ForumNodePermission::PERM_UPLOAD);
    }

    /**
     * Store an image while the composer is still open — the post does not exist yet.
     *
     * @throws InvalidUploadException
     * @throws UnsupportedAssetTypeException
     */
    public function storeComposerImage(UploadedFile $file): Asset
    {
        $maxBytes = max(1, (int) $this->settingsRegistry->get('forum.attachments_max_kb', 2048)) * 1024;

        return $this->assetManager->upload($file, ['image/'], $maxBytes);
    }

    /**
     * @param list<UploadedFile> $files
     *
     * @return list<ForumPostAttachment>
     */
    public function attachUploaded(ForumPost $post, User $user, array $files): array
    {
        if (!$this->canUpload($post, $user)) {
            return [];
        }

        $maxPerPost = max(1, (int) $this->settingsRegistry->get('forum.attachments_max_per_post', 5));
        $maxBytes = max(1, (int) $this->settingsRegistry->get('forum.attachments_max_kb', 2048)) * 1024;
        $existing = $post->getAttachCount();
        $created = [];

        foreach ($files as $file) {
            if (!$file instanceof UploadedFile || !$file->isValid()) {
                continue;
            }
            if ($existing + \count($created) >= $maxPerPost) {
                break;
            }
            if ($file->getSize() > $maxBytes) {
                continue;
            }

            try {
                $asset = $this->assetManager->upload($file);
            } catch (InvalidUploadException|UnsupportedAssetTypeException) {
                continue;
            }

            $attachment = new ForumPostAttachment($post, $asset);
            $this->entityManager->persist($attachment);
            $created[] = $attachment;
        }

        if ($created !== []) {
            $post->setAttachCount($existing + \count($created));
            $this->entityManager->flush();
        }

        return $created;
    }

    /**
     * @param list<int> $postIds
     *
     * @return array<int, list<ForumPostAttachment>>
     */
    public function groupedByPostIds(array $postIds): array
    {
        return $this->attachmentRepository->findGroupedByPostIds($postIds);
    }

    public function incrementDownload(ForumPostAttachment $attachment): void
    {
        $attachment->setDownloadCount($attachment->getDownloadCount() + 1);
        $this->entityManager->flush();
    }
}
