<?php

declare(strict_types=1);

namespace App\Core\Update;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Updates only add and overwrite files, so a file a release deleted stays on every site that updated across it.
 * 2.2.47 replaced the first extension screen (/aacp/extensions) with the store, yet the old controller kept
 * serving a second, simpler "store" page and menu entry. This removes it; the list grows when a release deletes a controller or template.
 */
final class RemoveObsoleteFilesHook implements UpdateHookInterface
{
    private const OBSOLETE = [
        'cp-core/src/Controller/Admin/AACPExtensionStoreController.php',
        'cp-core/templates/aacp/extensions/index.html.twig',
        'cp-core/templates/aacp/extensions',
    ];

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function id(): string
    {
        return 'core.2_2_56.remove_obsolete_files';
    }

    public function version(): string
    {
        return '2.2.56';
    }

    public function description(): string
    {
        return 'Deletes files that earlier releases removed but updates left behind (the old /aacp/extensions store screen).';
    }

    public function run(): ?string
    {
        $removed = [];

        foreach (self::OBSOLETE as $relative) {
            $path = $this->projectDir.'/'.$relative;

            if (is_file($path) && @unlink($path)) {
                $removed[] = $relative;
            } elseif (is_dir($path) && @rmdir($path)) {
                $removed[] = $relative;
            }
        }

        return $removed === [] ? null : 'Removed: '.implode(', ', $removed);
    }
}
