<?php

declare(strict_types=1);

namespace App\Core\Hook\Twig;

use App\Core\Hook\HookContext;
use App\Core\Hook\HookManager;
use Twig\Extension\RuntimeExtensionInterface;

/**
 * Twig runtime for {{ cp_hook() }}. Fail-safe lives in HookManager::trigger(), not here.
 */
final class HookRuntime implements RuntimeExtensionInterface
{
    public function __construct(
        private readonly HookManager $hookManager,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function render(string $hookPoint, array $context = []): string
    {
        $hookContext = new HookContext($context);

        $result = $this->hookManager->trigger($hookPoint, $hookContext);

        return $result->getHtml();
    }
}
