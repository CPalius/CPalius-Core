<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Backup\BackupShipper;
use App\Core\Media\MediaOffloader;
use App\Core\Settings\SettingsRegistry;
use App\Core\Storage\Driver\FtpTarget;
use App\Core\Storage\StorageTargetRegistry;
use App\Entity\Setting;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * AACP storage desk: where uploaded files go, where backups are sent, and which
 * hostname serves images.
 *
 * Three concerns on one screen because they are one decision in an operator's
 * head — "where do my files live and how do they reach visitors" — and because
 * two of them share credentials. Splitting them would have meant entering the
 * same bucket key twice.
 *
 * Secrets are never rendered back. The form posts an empty password field when
 * the operator did not retype it, and StorageTargetRegistry reads that as
 * "leave the stored value alone" — the same contract the System Settings screen
 * uses, so the two behave identically and neither surprises someone who has
 * learned the other.
 */
final class AACPStorageController extends AbstractController
{
    private const CSRF_TOKEN_ID = 'aacp_storage';

    public function __construct(
        private readonly StorageTargetRegistry $targets,
        private readonly SettingsRegistry $settings,
        private readonly SettingRepository $settingRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MediaOffloader $offloader,
        private readonly BackupShipper $shipper,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/aacp/storage', name: 'aacp_storage', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.storage.menu', icon: 'heroicons:cloud-arrow-up', panel: 'aacp', priority: 80, capability: 'system.settings.manage', parent: 'aacp_hub_maintenance')]
    #[IsGranted('system.settings.manage')]
    public function index(): Response
    {
        $providers = [];

        foreach (StorageTargetRegistry::TYPES as $type) {
            $state = $this->targets->testState()[$type] ?? null;

            $providers[$type] = [
                'fields' => $this->maskedConfig($type),
                'verified' => $this->targets->isVerified($type),
                'checkedAt' => $state['at'] ?? '',
                'message' => $state['message'] ?? '',
                'supported' => $type !== 'ftp' || FtpTarget::isSupported(),
            ];
        }

        $response = $this->render('aacp/storage/index.html.twig', [
            'providers' => $providers,
            'mediaTarget' => $this->targets->selectedType(StorageTargetRegistry::PURPOSE_MEDIA),
            'backupTarget' => $this->targets->selectedType(StorageTargetRegistry::PURPOSE_BACKUP),
            'mediaActive' => $this->offloader->isEnabled(),
            'backupActive' => $this->shipper->isConfigured(),
            'backupLabel' => $this->shipper->targetLabel(),
            'sweepEnabled' => (bool) $this->settings->get('storage.media.sweep_enabled', false),
            'sweepBatch' => (int) $this->settings->get('storage.media.sweep_batch', 200),
            'backupPrefix' => (string) $this->settings->get('storage.backup.prefix', 'cpalius-backups'),
            'keepLocal' => (bool) $this->settings->get('storage.backup.keep_local', true),
            'remoteRetention' => (int) $this->settings->get('storage.backup.remote_retention', 10),
            'cdnEnabled' => (bool) $this->settings->get('cdn.enabled', false),
            'cdnBaseUrl' => (string) $this->settings->get('cdn.base_url', ''),
            'cdnImagesOnly' => (bool) $this->settings->get('cdn.images_only', true),
            'csrf_token' => $this->csrfTokenManager->getToken(self::CSRF_TOKEN_ID)->getValue(),
        ]);

        // Credentials are on this page even masked, and the destination
        // hostnames are not something to leave in a shared browser cache.
        $response->headers->set('Cache-Control', 'private, no-store, no-cache, must-revalidate');

        return $response;
    }

    /**
     * Saves one provider's credentials and immediately probes them.
     *
     * One action, not Save-then-Test: two buttons let an operator save a change,
     * skip the test, and leave a green badge describing a configuration that no
     * longer exists.
     */
    #[Route('/aacp/storage/{type}/test', name: 'aacp_storage_test', methods: ['POST'], requirements: ['type' => 's3|r2|ftp'])]
    #[IsGranted('system.settings.manage')]
    public function test(string $type, Request $request): JsonResponse
    {
        if (!$this->isValidToken($request)) {
            return new JsonResponse(['success' => false, 'message' => $this->translator->trans('aacp.storage.invalid_csrf')], 400);
        }

        /** @var array<string, string> $submitted */
        $submitted = $request->request->all('config');

        $result = $this->targets->saveConfigAndTest($type, $submitted);

        return new JsonResponse([
            'success' => $result->success,
            'message' => $this->translator->trans($result->messageKey, $result->messageParams),
            'latencyMs' => $result->latencyMs,
            'describe' => $result->describe,
        ]);
    }

    /**
     * Saves the routing decisions — which provider media and backups use, and
     * the CDN — as a normal form POST.
     *
     * Deliberately not JSON like the probe above: these settings change what the
     * public site emits, and a full round trip means the operator sees the
     * screen redraw with the values that are actually stored.
     */
    #[Route('/aacp/storage/assign', name: 'aacp_storage_assign', methods: ['POST'])]
    #[IsGranted('system.settings.manage')]
    public function assign(Request $request): RedirectResponse
    {
        if (!$this->isValidToken($request)) {
            throw new BadRequestHttpException($this->translator->trans('aacp.storage.invalid_csrf'));
        }

        $mediaTarget = $this->normalizeTarget((string) $request->request->get('media_target', 'off'));
        $backupTarget = $this->normalizeTarget((string) $request->request->get('backup_target', 'off'));

        // Choosing an unverified provider is allowed but explained: the value is
        // stored so the operator does not lose the selection, and resolveFor()
        // still refuses to use it until a probe passes. Refusing the save
        // outright would make "pick a target, then test it" impossible.
        foreach (['media' => $mediaTarget, 'backup' => $backupTarget] as $purpose => $type) {
            if ($type !== 'off' && !$this->targets->isVerified($type)) {
                $this->addFlash('warning', $this->translator->trans('aacp.storage.selected_unverified', [
                    'purpose' => $this->translator->trans('aacp.storage.purpose.'.$purpose),
                    'target' => $this->translator->trans('aacp.storage.target.'.$type),
                ]));
            }
        }

        $cdnBase = rtrim(trim((string) $request->request->get('cdn_base_url', '')), '/');

        if ($cdnBase !== '' && !str_starts_with($cdnBase, 'https://')) {
            // AssetUrlGenerator would refuse this at render time and silently
            // fall back to local URLs. Saying so here is the difference between
            // "the CDN is not working" and "the CDN was never accepted".
            $this->addFlash('error', $this->translator->trans('aacp.storage.cdn_must_be_https'));
            $cdnBase = '';
        }

        $this->write('storage.media.target', $mediaTarget);
        $this->write('storage.backup.target', $backupTarget);
        $this->write('storage.media.sweep_enabled', $request->request->get('sweep_enabled') !== null ? '1' : '0');
        $this->write('storage.media.sweep_batch', (string) max(1, min(2000, (int) $request->request->get('sweep_batch', 200))));
        $this->write('storage.backup.prefix', trim(str_replace('\\', '/', (string) $request->request->get('backup_prefix', 'cpalius-backups')), '/'));
        $this->write('storage.backup.keep_local', $request->request->get('keep_local') !== null ? '1' : '0');
        $this->write('storage.backup.remote_retention', (string) max(1, min(200, (int) $request->request->get('remote_retention', 10))));
        $this->write('cdn.enabled', $request->request->get('cdn_enabled') !== null && $cdnBase !== '' ? '1' : '0');
        $this->write('cdn.base_url', $cdnBase);
        $this->write('cdn.images_only', $request->request->get('cdn_images_only') !== null ? '1' : '0');

        $this->entityManager->flush();
        $this->settings->clearCache();

        $this->addFlash('success', $this->translator->trans('aacp.storage.saved'));

        return new RedirectResponse('/aacp/storage');
    }

    /**
     * Runs one sweep batch on demand.
     *
     * Bounded by the configured batch size for the same reason the cron job is:
     * a request that tried to upload an entire media library would hit the PHP
     * time limit somewhere in the middle, and on shared hosting the operator
     * would see a blank 504 rather than a report.
     */
    #[Route('/aacp/storage/sweep', name: 'aacp_storage_sweep', methods: ['POST'])]
    #[IsGranted('system.settings.manage')]
    public function sweep(Request $request): RedirectResponse
    {
        if (!$this->isValidToken($request)) {
            throw new BadRequestHttpException($this->translator->trans('aacp.storage.invalid_csrf'));
        }

        if (!$this->offloader->isEnabled()) {
            $this->addFlash('error', $this->translator->trans('aacp.storage.sweep_no_target'));

            return new RedirectResponse('/aacp/storage');
        }

        @set_time_limit(0);
        $report = $this->offloader->sweep();

        $this->addFlash('success', $this->translator->trans('aacp.storage.sweep_done', [
            'scanned' => $report['scanned'],
            'uploaded' => $report['uploaded'],
            'skipped' => $report['skipped'],
            'failed' => $report['failed'],
        ]));

        return new RedirectResponse('/aacp/storage');
    }

    /**
     * Stored values with every secret replaced by an empty string.
     *
     * Not a bullet mask: an empty field is what the save path already reads as
     * "unchanged", so what the operator sees and what the form means are the
     * same thing. A mask would have to be recognised and stripped on the way
     * back in, and a mask that leaked through would be written to the database
     * as the literal secret.
     *
     * @return array<string, mixed>
     */
    private function maskedConfig(string $type): array
    {
        $config = [];

        foreach (StorageTargetRegistry::fieldsFor($type) as $field) {
            $config[$field] = in_array($field, ['secret_key', 'password'], true)
                ? ''
                : $this->settings->get('storage.'.$type.'.'.$field);
        }

        // Shown so the operator can see a secret IS stored without seeing it.
        $config['has_secret'] = $this->hasStoredSecret($type);

        return $config;
    }

    private function hasStoredSecret(string $type): bool
    {
        $field = $type === 'ftp' ? 'password' : 'secret_key';

        return trim((string) $this->settings->get('storage.'.$type.'.'.$field, '')) !== '';
    }

    private function normalizeTarget(string $value): string
    {
        return StorageTargetRegistry::isKnownType($value) ? $value : 'off';
    }

    private function write(string $key, string $value): void
    {
        $setting = $this->settingRepository->findOneBy(['settingKey' => $key]);

        if (!$setting instanceof Setting) {
            $setting = new Setting($key, 'core');
            $this->entityManager->persist($setting);
        }

        $setting->setSettingValue($value);
    }

    private function isValidToken(Request $request): bool
    {
        return $this->csrfTokenManager->isTokenValid(
            new CsrfToken(self::CSRF_TOKEN_ID, (string) $request->request->get('_token')),
        );
    }
}
