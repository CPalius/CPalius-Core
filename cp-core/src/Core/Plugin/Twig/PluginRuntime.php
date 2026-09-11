<?php

declare(strict_types=1);

namespace App\Core\Plugin\Twig;

use App\Core\Plugin\PluginInterface;
use App\Core\Plugin\PluginRegistry;
use App\Core\Plugin\PluginToggleRepository;
use Psr\Log\LoggerInterface;
use Throwable;
use Twig\Extension\RuntimeExtensionInterface;

/**
 * Data source for {{ cp_plugin('name', {...}) }}; requires RuntimeExtensionInterface so Twig keeps the service.
 * Fail-safe chain (Core Never Dies): missing/inactive/disabled/throwing plugins return '' without breaking the page.
 */
final class PluginRuntime implements RuntimeExtensionInterface
{
    public function __construct(
        private readonly PluginRegistry $pluginRegistry,
        private readonly PluginToggleRepository $pluginToggleRepository,
        private readonly LoggerInterface $logger,
        private readonly string $projectDir,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function render(string $name, array $context = []): string
    {
        $plugin = $this->pluginRegistry->getPlugin($name);

        if (!$plugin instanceof PluginInterface) {
            return '';
        }

        if (!$plugin->isActive()) {
            return '';
        }

        if ($this->pluginToggleRepository->isDisabled($name)) {
            return '';
        }

        try {
            return $plugin->render($context);
        } catch (Throwable $e) {
            $this->logPluginFailure($plugin, $e);

            return '';
        }
    }

    private function logPluginFailure(PluginInterface $plugin, Throwable $e): void
    {
        $this->logger->warning('Module plugin skipped because render() failed.', [
            'plugin' => $plugin->getName(),
            'exception' => $e->getMessage(),
        ]);

        $logFile = $this->projectDir.'/cp-core/var/log/module_quarantine.log';
        $dir = \dirname($logFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $line = sprintf(
            '[%s] %s plugin skipped at runtime because render() failed. Reason: %s',
            date('Y-m-d H:i:s'),
            $plugin->getName(),
            $e->getMessage(),
        );

        @file_put_contents($logFile, $line.PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
