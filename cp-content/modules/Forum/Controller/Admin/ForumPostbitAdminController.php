<?php

declare(strict_types=1);

namespace Modules\Forum\Controller\Admin;

use App\Core\Settings\SettingsRegistry;
use Modules\Forum\Attribute\ForumSettingsCard;
use App\Entity\Setting;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Forum\Service\ForumPostbitLayout;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Studio screen for the postbit: which blocks show, in what order, plus a CSS box.
 *
 * Its own screen rather than three rows on the settings form, because the thing
 * being edited is an ordered list — a generic key/value form would hand the
 * operator a text box containing "avatar,name,rank" and call it configuration.
 *
 * There is deliberately no JavaScript field beside the CSS one. Script entered
 * in an admin panel runs in every visitor's browser, which turns "can arrange
 * the postbit" into "can run code on the whole site"; styling needs no such
 * power, and behaviour belongs in the theme where it gets reviewed.
 */
#[Route('/admin/forum/postbit', name: 'admin_forum_postbit_')]
#[IsGranted('forum.section.manage')]
final class ForumPostbitAdminController extends AbstractController
{
    private const CSRF_TOKEN = 'admin_forum_postbit';

    /** Long enough for real postbit styling, short of a whole theme. */
    private const MAX_CSS_LENGTH = 20000;

    public function __construct(
        private readonly ForumPostbitLayout $layout,
        private readonly SettingsRegistry $settingsRegistry,
        private readonly SettingRepository $settingRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[ForumSettingsCard(
        label: 'studio.forum.settings.card.postbit',
        description: 'studio.forum.settings.card.postbit_desc',
        icon: 'heroicons:identification',
        group: 'studio.forum.settings.hub.appearance',
        priority: 90,
    )]
    public function index(): Response
    {
        $hidden = $this->layout->hiddenElements();

        // One row per block, already in the operator's order, each carrying
        // whether it is on — the template then has no logic of its own.
        $rows = [];
        foreach ($this->layout->orderedElements() as $element) {
            $rows[] = [
                'id' => $element,
                'visible' => !\in_array($element, $hidden, true),
            ];
        }

        return $this->render('@ForumModule/admin/postbit/index.html.twig', [
            'rows' => $rows,
            'css' => (string) $this->settingsRegistry->get(ForumPostbitLayout::SETTING_CSS, ''),
            'csrfToken' => self::CSRF_TOKEN,
            'maxCssLength' => self::MAX_CSS_LENGTH,
        ]);
    }

    #[Route('', name: 'update', methods: ['POST'])]
    public function update(Request $request): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN, (string) $request->request->get('_token'))) {
            throw new BadRequestHttpException($this->translator->trans('studio.forum.postbit.csrf_invalid'));
        }

        /** @var list<string> $order */
        $order = $request->request->all('order');
        /** @var list<string> $visible */
        $visible = $request->request->all('visible');

        // The form posts the blocks that are ON; the setting stores the ones
        // that are OFF, so a block added by a later release defaults to shown.
        $hidden = array_values(array_diff(ForumPostbitLayout::ELEMENTS, $visible));

        $encoded = $this->layout->encode($order, $hidden);
        $css = mb_substr(trim((string) $request->request->get('css', '')), 0, self::MAX_CSS_LENGTH);

        $this->save(ForumPostbitLayout::SETTING_ORDER, $encoded['order']);
        $this->save(ForumPostbitLayout::SETTING_HIDDEN, $encoded['hidden']);
        $this->save(ForumPostbitLayout::SETTING_CSS, $css);

        $this->entityManager->flush();
        $this->settingsRegistry->clearCache(
            ForumPostbitLayout::SETTING_ORDER,
            ForumPostbitLayout::SETTING_HIDDEN,
            ForumPostbitLayout::SETTING_CSS,
        );

        $this->addFlash('success', $this->translator->trans('studio.forum.postbit.saved'));

        return $this->redirectToRoute('admin_forum_postbit_index');
    }

    /**
     * Resets to the shipped arrangement by deleting the rows rather than
     * writing the defaults, so a later release that changes the order is
     * followed instead of frozen.
     */
    #[Route('/reset', name: 'reset', methods: ['POST'])]
    public function reset(Request $request): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN, (string) $request->request->get('_token'))) {
            throw new BadRequestHttpException($this->translator->trans('studio.forum.postbit.csrf_invalid'));
        }

        $keys = [ForumPostbitLayout::SETTING_ORDER, ForumPostbitLayout::SETTING_HIDDEN];
        foreach ($this->settingRepository->findIndexedByKeys($keys) as $setting) {
            $this->entityManager->remove($setting);
        }

        $this->entityManager->flush();
        $this->settingsRegistry->clearCache(...$keys);

        $this->addFlash('success', $this->translator->trans('studio.forum.postbit.reset_done'));

        return $this->redirectToRoute('admin_forum_postbit_index');
    }

    private function save(string $key, string $value): void
    {
        $setting = $this->settingRepository->findIndexedByKeys([$key])[$key] ?? null;

        if (!$setting instanceof Setting) {
            $setting = new Setting($key, 'forum_postbit');
            $this->entityManager->persist($setting);
        }

        $setting->setSettingValue($value);
    }
}
