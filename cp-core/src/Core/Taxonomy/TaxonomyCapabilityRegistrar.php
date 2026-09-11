<?php

declare(strict_types=1);

namespace App\Core\Taxonomy;

use App\Core\Security\CapabilityRegistry;
use App\Core\Taxonomy\Repository\VocabularyRepository;

/**
 * Runtime capabilities taxonomy.{machine_name}.manage — compile-time YAML cannot
 * know vocabularies that operators create after deploy.
 */
final class TaxonomyCapabilityRegistrar
{
    public function __construct(
        private readonly VocabularyRepository $vocabularies,
        private readonly CapabilityRegistry $capabilities,
    ) {
    }

    public function sync(): void
    {
        foreach ($this->vocabularies->findAllOrdered() as $vocabulary) {
            $machine = $vocabulary->getMachineName();
            if ($machine === '') {
                continue;
            }
            $this->capabilities->register('taxonomy.'.$machine.'.manage', 'core');
        }
    }

    public static function capabilityFor(string $machineName): string
    {
        return 'taxonomy.'.$machineName.'.manage';
    }
}
