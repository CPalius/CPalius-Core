<?php

declare(strict_types=1);

namespace Modules\Forum\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Settings\SettingsRegistry;
use App\Entity\Setting;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Forum ayarları — AACP'nin genel "Modül Ayarları" tarayıcısıyla (bkz.
 * AACPController::settingsModules/updateSettings) AYNI depoyu (cp_settings
 * tablosu, App\Entity\Setting) kullanır; burada sadece "forum" modülüne
 * ait tanımlar, Studio içinde daha kullanışlı, ayrık bir ekranda sunulur.
 * AACP'nin genel ekranı kasıtlı olarak dokunulmadan bırakıldı — o, "Core
 * Never Dies" kurtarma senaryosunda modülsüz de çalışması gereken bir
 * çekirdek/kurtarma yüzeyi (Manifesto Law 2.3), forum'a özel bir UI değil.
 */
#[Route('/admin/forum/settings', name: 'admin_forum_settings_')]
#[IsGranted('forum.section.manage')]
final class ForumSettingsAdminController extends AbstractController
{
    public function __construct(
        private readonly SettingsRegistry $settingsRegistry,
        private readonly SettingRepository $settingRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[CpAdminMenu(label: 'Forum Ayarları', icon: 'heroicons:cog-6-tooth', panel: 'studio', priority: 26, capability: 'forum.section.manage', group: 'İçerik', parent: 'admin_forum_dashboard')]
    public function index(): Response
    {
        $definitions = array_values(array_filter(
            $this->settingsRegistry->all(),
            static fn ($definition) => $definition->module === 'forum',
        ));

        $values = [];
        foreach ($definitions as $definition) {
            $values[$definition->key] = $this->settingsRegistry->get($definition->key);
        }

        return $this->render('@ForumModule/admin/settings/index.html.twig', [
            'definitions' => $definitions,
            'values' => $values,
        ]);
    }

    #[Route('', name: 'update', methods: ['POST'])]
    #[IsGranted('forum.section.manage')]
    public function update(Request $request): Response
    {
        $submittedToken = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid('admin_forum_settings', $submittedToken)) {
            throw new BadRequestHttpException($this->translator->trans('studio.forum.settings.csrf_invalid'));
        }

        /** @var array<string, string> $submitted */
        $submitted = $request->request->all('settings');

        $forumDefinitions = array_values(array_filter(
            $this->settingsRegistry->all(),
            static fn ($definition) => $definition->module === 'forum',
        ));

        $keys = array_map(static fn ($definition) => $definition->key, $forumDefinitions);
        $existing = $this->settingRepository->findIndexedByKeys($keys);

        foreach ($forumDefinitions as $definition) {
            $raw = $submitted[$definition->key] ?? null;
            $value = match ($definition->type) {
                'checkbox', 'boolean' => $raw !== null ? '1' : '0',
                default => $raw !== null ? trim($raw) : null,
            };

            if ($value === null) {
                continue;
            }

            if ($definition->type === 'integer' && preg_match('/^-?\d+$/', $value) !== 1) {
                $this->addFlash('error', $this->translator->trans('studio.forum.settings.invalid_number', ['label' => $definition->label, 'value' => $value]));

                continue;
            }

            $setting = $existing[$definition->key] ?? null;
            if (!$setting instanceof Setting) {
                $setting = new Setting($definition->key, $definition->module);
                $this->entityManager->persist($setting);
                $existing[$definition->key] = $setting;
            }

            $setting->setSettingValue($value);
        }

        $this->entityManager->flush();
        $this->settingsRegistry->clearCache();
        $this->addFlash('success', $this->translator->trans('studio.forum.settings.updated'));

        return $this->redirectToRoute('admin_forum_settings_index');
    }
}
