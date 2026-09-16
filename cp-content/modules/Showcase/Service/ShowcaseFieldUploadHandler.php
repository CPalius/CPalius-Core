<?php

declare(strict_types=1);

namespace Modules\Showcase\Service;

use App\Core\Field\FieldDefinitionRegistry;
use App\Core\Media\AssetManager;
use App\Core\Media\Exception\AssetUploadException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

/**
 * Turns file inputs on the showcase form into asset ids for image/file custom
 * fields.
 *
 * The Field API stores an asset id for those types; it does not accept an
 * upload. In the admin panel that gap is filled by the media picker, but the
 * member-facing form has no picker, so the browser posts a file and this handler
 * converts it — through the core AssetManager, which is the only component
 * allowed to write uploads (Law 5.3).
 *
 * Inputs are named `field_upload[<field name>]`, and a field name that does not
 * belong to the bundle, or is not of a file-backed type, is ignored outright.
 */
final class ShowcaseFieldUploadHandler
{
    private const IMAGE_PREFIXES = ['image/'];

    public function __construct(
        private readonly AssetManager $assetManager,
        private readonly FieldDefinitionRegistry $definitions,
        private readonly ShowcaseConfig $config,
    ) {
    }

    /**
     * @param array<string, mixed> $submittedFields values already read from the form
     *
     * @return array{fields: array<string, mixed>, errors: list<string>}
     */
    public function merge(Request $request, string $bundle, array $submittedFields): array
    {
        $uploads = $request->files->all()['field_upload'] ?? [];

        if (!\is_array($uploads) || $uploads === []) {
            return ['fields' => $submittedFields, 'errors' => []];
        }

        $errors = [];

        foreach ($this->definitions->getFieldsForBundle($bundle) as $definition) {
            $type = $definition->getType();

            if ($type !== 'image' && $type !== 'file') {
                continue;
            }

            $name = $definition->getName();
            $file = $uploads[$name] ?? null;

            if (!$file instanceof UploadedFile) {
                continue;
            }

            try {
                $asset = $this->assetManager->upload(
                    $file,
                    $type === 'image' ? self::IMAGE_PREFIXES : null,
                    $this->config->maxImageBytes(),
                );
            } catch (AssetUploadException) {
                $errors[] = 'showcase.media.error.upload_failed';
                continue;
            }

            $assetId = $asset->getId();

            if ($assetId !== null) {
                $submittedFields[$name] = $assetId;
            }
        }

        return ['fields' => $submittedFields, 'errors' => array_values(array_unique($errors))];
    }
}
