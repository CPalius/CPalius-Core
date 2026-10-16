<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Localization\Contract\UiStringTranslatorInterface;
use App\Core\Localization\LanguageCatalog;
use App\Core\Localization\LocaleProvider;
use App\Core\Localization\TranslationManager;
use App\Core\Localization\TranslationManagerException;
use App\Entity\Locale;
use App\Entity\Node;
use App\Repository\LocaleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * One screen for site languages: add (from a catalogue or by hand), edit,
 * default, on/off, delete, how complete each translation is, and filling the
 * gaps with the AI module. The translation text editor stays on /aacp/localization.
 */
#[Route('/aacp/languages', name: 'aacp_languages_')]
#[IsGranted('system.localization.manage')]
final class AACPLanguageController extends AbstractController
{
    public const CSRF = 'aacp_languages';

    public function __construct(
        private readonly LocaleRepository $locales,
        private readonly LocaleProvider $provider,
        private readonly TranslationManager $translations,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly ?UiStringTranslatorInterface $aiTranslator = null,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.menu.languages', icon: 'heroicons:language', panel: 'aacp', priority: 25, capability: 'system.localization.manage', parent: 'aacp_hub_system')]
    public function index(): Response
    {
        $all = $this->locales->findBy([], ['sortOrder' => 'ASC', 'id' => 'ASC']);
        $default = $this->locales->findDefault();
        $sourceCode = $default?->getCode() ?? (($all[0] ?? null)?->getCode() ?? 'en');

        $completeness = $this->translations->completeness($sourceCode, array_map(static fn (Locale $l): string => $l->getCode(), $all));

        $usage = [];
        foreach ($this->entityManager->createQueryBuilder()
            ->select('n.locale AS code, COUNT(n.id) AS c')->from(Node::class, 'n')->groupBy('n.locale')
            ->getQuery()->getArrayResult() as $row) {
            $usage[(string) $row['code']] = (int) $row['c'];
        }

        $existing = array_map(static fn (Locale $l): string => $l->getCode(), $all);

        return $this->render('aacp/languages/index.html.twig', [
            'locales' => $all,
            'sourceCode' => $sourceCode,
            'completeness' => $completeness,
            'usage' => $usage,
            'presets' => array_diff_key(LanguageCatalog::all(), array_flip($existing)),
            'aiAvailable' => $this->aiTranslator?->isAvailable() === true,
        ]);
    }

    #[Route('/add', name: 'add', methods: ['POST'])]
    public function add(Request $request): RedirectResponse
    {
        $this->assertToken($request);

        $code = strtolower(trim((string) $request->request->get('code')));
        $name = trim((string) $request->request->get('name'));
        $native = trim((string) $request->request->get('native_name'));

        // Picking from the catalogue fills what the operator left blank.
        $preset = LanguageCatalog::find((string) $request->request->get('preset'));
        if ($preset !== null) {
            $code = $code !== '' ? $code : strtolower((string) $request->request->get('preset'));
            $name = $name !== '' ? $name : $preset['name'];
            $native = $native !== '' ? $native : $preset['native'];
        }

        $error = $this->validate($code, $name, $native);
        if ($error !== null) {
            $this->addFlash('error', $error);

            return $this->redirectToRoute('aacp_languages_index');
        }

        $locale = new Locale($code, $name, $native);
        $locale->setSortOrder($this->locales->countAll());
        $this->entityManager->persist($locale);
        $this->entityManager->flush();
        $this->provider->invalidate();

        try {
            $this->translations->seedLocale($code);
        } catch (TranslationManagerException) {
            // The row exists; files are created on the first edit.
        }

        $this->addFlash('success', $this->translator->trans('aacp.languages.flash.added', ['name' => $name]));

        return $this->redirectToRoute('aacp_languages_index');
    }

    #[Route('/{id}/default', name: 'default', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function makeDefault(int $id, Request $request): RedirectResponse
    {
        $this->assertToken($request);
        $locale = $this->find($id);

        if (!$locale->isActive()) {
            $this->addFlash('error', $this->translator->trans('aacp.languages.error.default_inactive'));

            return $this->redirectToRoute('aacp_languages_index');
        }

        $this->entityManager->wrapInTransaction(function () use ($locale): void {
            foreach ($this->locales->findAll() as $existing) {
                $existing->setIsDefault(false);
            }
            $locale->setIsDefault(true);
            $this->entityManager->flush();
        });
        $this->provider->invalidate();

        $this->addFlash('success', $this->translator->trans('aacp.languages.flash.default', ['name' => $locale->getName()]));

        return $this->redirectToRoute('aacp_languages_index');
    }

    #[Route('/{id}/toggle', name: 'toggle', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function toggle(int $id, Request $request): RedirectResponse
    {
        $this->assertToken($request);
        $locale = $this->find($id);

        if ($locale->isDefault() && $locale->isActive()) {
            $this->addFlash('error', $this->translator->trans('aacp.locales.default_cannot_deactivate'));

            return $this->redirectToRoute('aacp_languages_index');
        }

        $locale->setIsActive(!$locale->isActive());
        $this->entityManager->flush();
        $this->provider->invalidate();

        return $this->redirectToRoute('aacp_languages_index');
    }

    /**
     * Refused while content still uses the language: nodes keep the locale as a
     * plain code, so deleting it would leave pages nobody can reach or edit.
     * Deactivating is the way to retire a language that has content.
     */
    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request): RedirectResponse
    {
        $this->assertToken($request);
        $locale = $this->find($id);

        if ($locale->isDefault()) {
            $this->addFlash('error', $this->translator->trans('aacp.languages.error.delete_default'));

            return $this->redirectToRoute('aacp_languages_index');
        }

        $used = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(n.id)')->from(Node::class, 'n')->where('n.locale = :c')->setParameter('c', $locale->getCode())
            ->getQuery()->getSingleScalarResult();

        if ($used > 0) {
            $this->addFlash('error', $this->translator->trans('aacp.languages.error.delete_in_use', ['name' => $locale->getName(), 'count' => $used]));

            return $this->redirectToRoute('aacp_languages_index');
        }

        // Goes to the recycle bin; the translation files are left in place so a
        // restored (or re-added) language comes back with its texts.
        $this->entityManager->remove($locale);
        $this->entityManager->flush();
        $this->provider->invalidate();

        $this->addFlash('success', $this->translator->trans('aacp.languages.flash.deleted', ['name' => $locale->getName()]));

        return $this->redirectToRoute('aacp_languages_index');
    }

    /**
     * One batch of the AI fill-in. The browser calls this repeatedly until
     * `finished`, so a few thousand strings never hit a PHP time limit and the
     * operator sees progress.
     */
    #[Route('/{code}/ai-translate', name: 'ai_translate', methods: ['POST'], requirements: ['code' => '[a-z]{2,5}'])]
    public function aiTranslate(string $code, Request $request): JsonResponse
    {
        $this->assertToken($request, header: true);

        if ($this->aiTranslator === null || !$this->aiTranslator->isAvailable()) {
            return new JsonResponse(['error' => $this->translator->trans('aacp.languages.error.ai_unavailable')], 400);
        }

        $target = $this->locales->findOneBy(['code' => $code]);
        $source = $this->locales->findDefault();
        if (!$target instanceof Locale || !$source instanceof Locale || $target->getCode() === $source->getCode()) {
            return new JsonResponse(['error' => $this->translator->trans('aacp.languages.error.ai_target')], 400);
        }
        if (!$target->isActive()) {
            return new JsonResponse(['error' => $this->translator->trans('aacp.languages.error.ai_inactive')], 400);
        }

        $body = json_decode($request->getContent(), true);
        $offset = max(0, (int) (\is_array($body) ? ($body['offset'] ?? 0) : 0));

        $batch = $this->translations->missing($target->getCode(), $source->getCode(), max(1, $this->aiTranslator->batchSize()), $offset);

        $written = 0;
        $failed = 0;

        if ($batch !== []) {
            $strings = [];
            foreach ($batch as $i => $item) {
                $strings['k'.$i] = $item['source'];
            }

            try {
                $result = $this->aiTranslator->translateBatch($source->getCode(), $target->getCode(), $strings);
            } catch (\Throwable $e) {
                $message = str_starts_with($e->getMessage(), 'ai.') ? $this->translator->trans($e->getMessage()) : $e->getMessage();

                return new JsonResponse(['error' => $message], 502);
            }

            $byGroup = [];
            foreach ($batch as $i => $item) {
                $translated = trim((string) ($result['k'.$i] ?? ''));
                if ($translated === '' || !self::sameMarkers($item['source'], $translated)) {
                    ++$failed;
                    continue;
                }
                $byGroup[$item['group']][$item['key']] = $translated;
            }

            try {
                foreach ($byGroup as $group => $values) {
                    $written += $this->translations->updateMany((string) $group, $target->getCode(), $values);
                }
            } catch (TranslationManagerException $e) {
                return new JsonResponse(['error' => $e->getMessage()], 500);
            }
        }

        $progress = $this->translations->completeness($source->getCode(), [$target->getCode()])[$target->getCode()];

        return new JsonResponse([
            'translated' => $written,
            'failed' => $failed,
            'percent' => $progress['percent'],
            'remaining' => $progress['total'] - $progress['translated'],
            // Failed strings stay missing, so the browser skips them with `offset`; the run ends when nothing is left past it.
            'finished' => $batch === [],
        ]);
    }

    /**
     * A translation must keep the machine-readable parts of the source intact —
     * {placeholders}, %tokens% and HTML tags — or the page it lands on breaks.
     * ICU plural/select messages are never machine-translated: their arms hold
     * words inside braces, which this check cannot tell from placeholders.
     */
    public static function sameMarkers(string $source, string $translated): bool
    {
        if (preg_match('/\{[^{}]*,\s*(plural|select|selectordinal)\s*,/i', $source) === 1) {
            return false;
        }

        $extract = static function (string $text): array {
            preg_match_all('/\{[^{}]*\}|%[A-Za-z0-9_.]+%|%(?:\d+\$)?[sdf]|<\/?[A-Za-z][^>]*>/', $text, $m);
            sort($m[0]);

            return $m[0];
        };

        return $extract($source) === $extract($translated);
    }

    private function validate(string $code, string $name, string $native): ?string
    {
        if ($code === '' || $name === '' || $native === '') {
            return $this->translator->trans('aacp.locales.validation.required');
        }
        if (preg_match('/^[a-z]{2,5}$/', $code) !== 1) {
            return $this->translator->trans('aacp.locales.validation.invalid_code');
        }
        if ($this->locales->codeExists($code, null)) {
            return $this->translator->trans('aacp.locales.validation.code_taken', ['code' => $code]);
        }

        return null;
    }

    private function find(int $id): Locale
    {
        $locale = $this->locales->find($id);
        if (!$locale instanceof Locale) {
            throw new NotFoundHttpException($this->translator->trans('aacp.locales.not_found'));
        }

        return $locale;
    }

    private function assertToken(Request $request, bool $header = false): void
    {
        $token = $header ? (string) $request->headers->get('X-CSRF-Token') : (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid(self::CSRF, $token)) {
            throw new BadRequestHttpException($this->translator->trans('aacp.common.error.invalid_csrf'));
        }
    }
}
