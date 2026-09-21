<?php

declare(strict_types=1);

namespace Modules\Forum;

use Modules\Forum\DependencyInjection\Compiler\ForumSettingsCardPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class ForumModule extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new ForumSettingsCardPass());
    }
}
