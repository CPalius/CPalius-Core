<?php

declare(strict_types=1);

namespace Modules\DnsTools\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Settings\SettingDefinition;
use App\Core\Settings\SettingsRegistry;
use App\Core\Settings\SystemSettingsService;
use App\Entity\Setting;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Modules\DnsTools\Install\DnsToolsSettingsSeeder;
use Modules\DnsTools\Service\ImapInboxReader;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/dnstools', name: 'admin_dnstools_')]
#[IsGranted('dnstools.manage')]
final class DnsToolsSettingsAdminController extends AbstractController
{
    public function __construct(
        private readonly SettingsRegistry $settingsRegistry,
        private readonly SettingRepository $settingRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly SystemSettingsService $systemSettings,
        private readonly TranslatorInterface $translator,
        private readonly ImapInboxReader $imap,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[CpAdminMenu(
        label: 'dnstools.menu.settings',
        icon: 'heroicons:globe-alt',
        panel: 'studio',
        priority: 42,
        capability: 'dnstools.manage',
        group: 'studio.group.content',
    )]
    public function index(): Response
    {
        DnsToolsSettingsSeeder::seed($this->entityManager->getConnection());
        $definitions = $this->definitions();

        return $this->render('@DnsToolsModule/admin/settings/index.html.twig', [
            'definitions' => $definitions,
            'values' => $this->systemSettings->currentValuesForDefinitions($definitions, $this->settingsRegistry),
        ]);
    }

    #[Route('', name: 'update', methods: ['POST'])]
    public function update(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('admin_dnstools_settings', (string) $request->request->get('_token'))) {
            throw new BadRequestHttpException($this->translator->trans('aacp.common.error.invalid_csrf'));
        }

        /** @var array<string, mixed> $submitted */
        $submitted = $request->request->all('settings');
        $definitions = $this->definitions();
        $keys = array_map(static fn (SettingDefinition $d) => $d->key, $definitions);
        $existing = $this->settingRepository->findIndexedByKeys($keys);

        foreach ($definitions as $definition) {
            $raw = $submitted[$definition->key] ?? null;
            $value = match ($definition->type) {
                'checkbox', 'boolean' => $raw !== null ? '1' : '0',
                default => $raw !== null ? trim((string) $raw) : null,
            };
            if ($value === null) {
                continue;
            }
            if ($definition->type === 'password' && $value === '') {
                continue;
            }
            if ($definition->type === 'integer' && preg_match('/^-?\d+$/', $value) !== 1) {
                continue;
            }
            $this->persist($existing, $definition, $value);
        }

        $this->entityManager->flush();
        $this->settingsRegistry->clearCache();
        $this->addFlash('success', $this->translator->trans('dnstools.settings.saved'));

        return $this->redirectToRoute('admin_dnstools_index');
    }

    #[Route('/imap-test', name: 'imap_test', methods: ['POST'])]
    public function imapTest(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('admin_dnstools_imap', (string) $request->request->get('_token'))) {
            throw new BadRequestHttpException($this->translator->trans('aacp.common.error.invalid_csrf'));
        }

        $result = $this->imap->probe();
        if (!$result['ok']) {
            $this->addFlash('error', $this->translator->trans('dnstools.imap.test_failed', [
                'reason' => $this->imapReason($result['error']),
            ]));

            return $this->redirectToRoute('admin_dnstools_index');
        }

        $matches = $result['matches'] === []
            ? $this->translator->trans('dnstools.imap.no_match')
            : implode(', ', $result['matches']);
        $this->addFlash('success', $this->translator->trans('dnstools.imap.test_ok', [
            'count' => $result['messages'],
            'matches' => $matches,
        ]));

        return $this->redirectToRoute('admin_dnstools_index');
    }

    private function imapReason(string $error): string
    {
        return match ($error) {
            'php_imap_missing' => $this->translator->trans('dnstools.imap.missing_extension'),
            'imap_incomplete' => $this->translator->trans('dnstools.imap.incomplete'),
            default => $error,
        };
    }

    /**
     * @param array<string, Setting> $existing
     */
    private function persist(array &$existing, SettingDefinition $definition, string $value): void
    {
        $setting = $existing[$definition->key] ?? null;
        if (!$setting instanceof Setting) {
            $setting = new Setting($definition->key, $definition->module);
            $this->entityManager->persist($setting);
            $existing[$definition->key] = $setting;
        }
        $setting->setSettingValue($value);
    }

    /**
     * @return list<SettingDefinition>
     */
    private function definitions(): array
    {
        return array_values(array_filter(
            $this->settingsRegistry->all(),
            static fn (SettingDefinition $definition) => $definition->module === 'dnstools',
        ));
    }
}
