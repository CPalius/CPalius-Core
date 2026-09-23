<?php

declare(strict_types=1);

namespace Modules\Importer\Markup;

use App\Core\Migrate\MigrationLookup;
use Modules\Importer\Source\LocalAssetIntake;

/**
 * Turns leftover [ATTACH] / [attachment=] tags into [img] before BBCode
 * conversion, using assets a previous attachments step already imported.
 */
final class ForumAttachMarkup
{
    public function __construct(
        private readonly MigrationLookup $lookup,
        private readonly LocalAssetIntake $assets,
        private readonly string $migrationId,
    ) {
    }

    public function rewrite(string $bbcode): string
    {
        if ($bbcode === '' || !str_contains(strtolower($bbcode), 'attach')) {
            return $bbcode;
        }

        $ids = [];

        if (preg_match_all('#\[attach(?:\s[^\]]*)?(?:=[^\]]*)?\](\d+)\[/attach\]#i', $bbcode, $xf) > 0) {
            foreach ($xf[1] as $id) {
                $ids[] = (string) $id;
            }
        }

        if (preg_match_all('#\[attachment=(\d+)\]#i', $bbcode, $my) > 0) {
            foreach ($my[1] as $id) {
                $ids[] = (string) $id;
            }
        }

        if ($ids === []) {
            return $bbcode;
        }

        $dest = [];
        foreach (array_unique($ids) as $sourceId) {
            $assetId = $this->lookup->findInt($this->migrationId, $sourceId);

            if ($assetId !== null) {
                $dest[$sourceId] = $assetId;
            }
        }

        $urls = $this->assets->urlsById(array_values($dest));

        $replace = function (array $m) use ($dest, $urls): string {
            $sourceId = $m[1];
            $assetId = $dest[$sourceId] ?? null;
            $url = $assetId !== null ? ($urls[$assetId] ?? '') : '';

            return $url !== '' ? '[img]'.$url.'[/img]' : '';
        };

        $bbcode = (string) preg_replace_callback(
            '#\[attach(?:\s[^\]]*)?(?:=[^\]]*)?\](\d+)\[/attach\]#i',
            $replace,
            $bbcode,
        );

        return (string) preg_replace_callback('#\[attachment=(\d+)\]#i', $replace, $bbcode);
    }
}
