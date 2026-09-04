<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Core\Localization\LocaleDefinition;
use App\Core\Localization\LocaleProvider;
use App\Core\Localization\TranslationManager;
use App\Core\Localization\TranslationManagerException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * AACP "Dil Yönetimi" ekranı (/aacp/localization) — sistemdeki tüm çeviri
 * YAML dosyalarını (çekirdek + modüller, tüm domain'ler) tek bir
 * Translation Explorer tablosunda listeler, AJAX ile inline düzenlemeye
 * izin verir, tam matrisin JSON/YAML dışa aktarımını ve toplu içe
 * aktarımını sağlar.
 *
 * FAZ 3: sütunlar artık SABİT tr/en DEĞİLDİR — LocaleProvider'dan gelen
 * aktif dil listesine göre üretilir. Tablo üstündeki sekmeler dil
 * odaklıdır: bir sekme seçildiğinde o dilde ÇEVRİLMEMİŞ anahtarlar öne
 * çıkarılabilir (bkz. aacp-localization.js).
 *
 * AACPApiKeyController ile AYNI iskelet: plain class + inject edilen
 * Twig\Environment, index() normal bir sayfa render eder, mutasyon
 * action'ları (update/import) AJAX ile çağrılıp JsonResponse döner.
 *
 * Güvenlik: 'system.localization.manage' capability'sine sahip TEK rol
 * "admin"dir (bkz. cp-content/config/sync/user.role.admin.yaml, '*' joker
 * capability). Dosya sistemine yazma yetkisi olan bir panel olduğu için
 * bilinçli olarak en dar yetki kapsamında tutulur.
 *
 * Ana AACP menüsünde ARTIK AYRI BİR ÖĞE DEĞİLDİR (#[CpAdminMenu] kasıtlı
 * olarak kaldırıldı) — birincil giriş noktası "Yönetim" sayfasındaki
 * (AACPPlaceholderController::advancedManagement()) Diller listesindeki
 * "Dili Yönet" linkidir; ?locale= query param'ı geldiyse tablo o dile
 * odaklı açılır.
 */
final class AACPLocalizationController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly TranslationManager $translationManager,
        private readonly LocaleProvider $localeProvider,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/aacp/localization', name: 'aacp_localization', methods: ['GET'])]
    #[IsGranted('system.localization.manage')]
    public function index(Request $request): Response
    {
        $locales = $this->localeProvider->getLocales();
        $codes = array_map(static fn (LocaleDefinition $locale): string => $locale->code, $locales);

        $entries = array_map(
            static fn ($entry): array => $entry->toArray($codes),
            $this->translationManager->listAll(),
        );

        $focusLocale = (string) $request->query->get('locale', '');

        $html = $this->twig->render('aacp/localization/index.html.twig', [
            'entries' => $entries,
            'locales' => $locales,
            'groups' => $this->collectGroups($entries),
            'incompleteCount' => \count(array_filter($entries, static fn (array $entry): bool => $entry['incomplete'])),
            'csrf_token' => $this->csrfTokenManager->getToken('aacp_localization')->getValue(),
            'focusLocale' => $this->localeProvider->isSupported($focusLocale) ? $focusLocale : null,
        ]);

        return new Response($html);
    }

    #[Route('/aacp/localization/update', name: 'aacp_localization_update', methods: ['POST'])]
    #[IsGranted('system.localization.manage')]
    public function update(Request $request): JsonResponse
    {
        $this->assertValidCsrfToken($request);

        $group = trim((string) $request->request->get('group'));
        $key = trim((string) $request->request->get('key'));
        $locale = trim((string) $request->request->get('locale'));
        $value = (string) $request->request->get('value', '');

        if ($group === '' || $key === '' || $locale === '') {
            return new JsonResponse(
                ['error' => $this->translator->trans('aacp.localization.missing_params')],
                Response::HTTP_BAD_REQUEST,
            );
        }

        try {
            $this->translationManager->updateValue($group, $key, $locale, $value);
        } catch (TranslationManagerException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse(['success' => true, 'value' => $value]);
    }

    #[Route('/aacp/localization/export/{format}', name: 'aacp_localization_export', methods: ['GET'], requirements: ['format' => 'json|yaml'])]
    #[IsGranted('system.localization.manage')]
    public function export(string $format): Response
    {
        try {
            $content = $format === 'json'
                ? $this->translationManager->exportAsJson()
                : $this->translationManager->exportAsYaml();
        } catch (TranslationManagerException $e) {
            return new Response($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $contentType = $format === 'json' ? 'application/json' : 'application/x-yaml';
        $filename = sprintf('translations-export-%s.%s', date('Ymd-His'), $format);

        $response = new Response($content);
        $response->headers->set('Content-Type', $contentType.'; charset=utf-8');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $filename));

        return $response;
    }

    #[Route('/aacp/localization/import', name: 'aacp_localization_import', methods: ['POST'])]
    #[IsGranted('system.localization.manage')]
    public function import(Request $request): JsonResponse
    {
        $this->assertValidCsrfToken($request);

        $file = $request->files->get('file');

        if ($file === null) {
            return new JsonResponse(
                ['error' => $this->translator->trans('aacp.localization.no_file_selected')],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $extension = strtolower((string) $file->getClientOriginalExtension());
        $format = match ($extension) {
            'json' => 'json',
            'yaml', 'yml' => 'yaml',
            default => null,
        };

        if ($format === null) {
            return new JsonResponse(
                ['error' => $this->translator->trans('aacp.localization.unsupported_file_type')],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $content = @file_get_contents($file->getPathname());

        if ($content === false) {
            return new JsonResponse(
                ['error' => $this->translator->trans('aacp.localization.file_read_error')],
                Response::HTTP_BAD_REQUEST,
            );
        }

        try {
            $updatedCount = $this->translationManager->importFromString($content, $format);
        } catch (TranslationManagerException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse(['success' => true, 'updatedCount' => $updatedCount]);
    }

    /**
     * Tablonun grup (domain) filtresi için benzersiz grup etiketleri.
     *
     * @param list<array{group: string, key: string, values: array<string, string>, incomplete: bool}> $entries
     *
     * @return list<string>
     */
    private function collectGroups(array $entries): array
    {
        $groups = array_values(array_unique(array_column($entries, 'group')));
        sort($groups);

        return $groups;
    }

    private function assertValidCsrfToken(Request $request): void
    {
        $submittedToken = (string) ($request->request->get('_token') ?? $request->headers->get('X-CSRF-Token'));

        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken('aacp_localization', $submittedToken))) {
            throw new BadRequestHttpException($this->translator->trans('aacp.localization.invalid_csrf'));
        }
    }
}
