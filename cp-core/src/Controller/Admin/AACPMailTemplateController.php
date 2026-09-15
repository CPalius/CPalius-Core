<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Mail\CpMailerService;
use App\Core\Mail\Template\MailTemplateManager;
use App\Core\Mail\Template\MailTemplateRegistry;
use App\Core\Mail\Template\MailTemplateRenderer;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * AACP → System → Mail templates.
 *
 * One screen per mail, one tab per active language. The list of languages comes
 * from LocaleProvider, so switching a locale on in AACP → Locales immediately
 * adds a column here — there is no second place to register a language.
 *
 * Saving text identical to the shipped default deletes the row instead of
 * storing it (see MailTemplateManager::save()), which is what makes "edit,
 * change your mind, undo" leave no trace and keeps an untouched installation
 * tracking catalogue improvements from later releases.
 */
final class AACPMailTemplateController extends AbstractController
{
    private const CSRF_SAVE = 'aacp_mail_template_save';
    private const CSRF_RESET = 'aacp_mail_template_reset';
    private const CSRF_PREVIEW = 'aacp_mail_template_preview';

    public function __construct(
        private readonly MailTemplateRegistry $registry,
        private readonly MailTemplateManager $manager,
        private readonly MailTemplateRenderer $renderer,
        private readonly CpMailerService $mailer,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/aacp/mail-templates', name: 'aacp_mail_templates', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.mail_templates.menu', icon: 'heroicons:envelope', panel: 'aacp', priority: 26, capability: 'system.settings.manage', parent: 'aacp_hub_system')]
    #[IsGranted('system.settings.manage')]
    public function index(): Response
    {
        $this->manager->ensureSchema();

        return $this->render('aacp/mail_templates/index.html.twig', [
            'rows' => $this->manager->overview(),
            'locales' => $this->manager->locales(),
            'mailConfigured' => $this->mailer->canSend(),
        ]);
    }

    #[Route('/aacp/mail-templates/{key}', name: 'aacp_mail_templates_edit', methods: ['GET', 'POST'], requirements: ['key' => '[a-z0-9_.]+'])]
    #[IsGranted('system.settings.manage')]
    public function edit(Request $request, string $key): Response
    {
        $definition = $this->registry->get($key);

        if ($definition === null) {
            throw new NotFoundHttpException($this->translator->trans('aacp.mail_templates.unknown'));
        }

        $this->manager->ensureSchema();

        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, self::CSRF_SAVE);

            $saved = 0;

            foreach ($this->manager->locales() as $locale) {
                $code = $locale->code;

                // A locale whose fieldset was not submitted is left alone
                // rather than emptied: a form rendered before a new language
                // was activated must not wipe the language it never showed.
                if (!$request->request->has('subject_'.$code)) {
                    continue;
                }

                $this->manager->save(
                    $key,
                    $code,
                    (string) $request->request->get('subject_'.$code, ''),
                    (string) $request->request->get('html_'.$code, ''),
                    (string) $request->request->get('text_'.$code, ''),
                    $request->request->getBoolean('enabled_'.$code, true),
                );
                ++$saved;
            }

            $this->addFlash('success', $this->translator->trans('aacp.mail_templates.saved', ['count' => $saved]));

            return $this->redirectToRoute('aacp_mail_templates_edit', ['key' => $key]);
        }

        return $this->render('aacp/mail_templates/edit.html.twig', [
            'definition' => $definition,
            'locales' => $this->manager->locales(),
            'rows' => $this->manager->editorRows($definition),
            'previews' => $this->buildPreviews($key),
            'mailConfigured' => $this->mailer->canSend(),
        ]);
    }

    #[Route('/aacp/mail-templates/{key}/reset', name: 'aacp_mail_templates_reset', methods: ['POST'], requirements: ['key' => '[a-z0-9_.]+'])]
    #[IsGranted('system.settings.manage')]
    public function reset(Request $request, string $key): Response
    {
        if (!$this->registry->has($key)) {
            throw new NotFoundHttpException($this->translator->trans('aacp.mail_templates.unknown'));
        }

        $this->assertCsrf($request, self::CSRF_RESET);

        $locale = trim((string) $request->request->get('locale', ''));
        $removed = $this->manager->reset($key, $locale !== '' ? $locale : null);

        $this->addFlash('success', $this->translator->trans('aacp.mail_templates.reset_done', ['count' => $removed]));

        return $this->redirectToRoute('aacp_mail_templates_edit', ['key' => $key]);
    }

    /**
     * Sends the template as it stands to the logged-in administrator.
     *
     * To their own address and nowhere else, on purpose: a preview that accepts
     * a recipient is an open relay for anyone who reaches this screen, and the
     * question an operator actually has — "does my wording look right in a real
     * client" — is answered just as well by their own inbox.
     */
    #[Route('/aacp/mail-templates/{key}/preview', name: 'aacp_mail_templates_preview', methods: ['POST'], requirements: ['key' => '[a-z0-9_.]+'])]
    #[IsGranted('system.settings.manage')]
    public function preview(Request $request, string $key): Response
    {
        $definition = $this->registry->get($key);

        if ($definition === null) {
            throw new NotFoundHttpException($this->translator->trans('aacp.mail_templates.unknown'));
        }

        $this->assertCsrf($request, self::CSRF_PREVIEW);

        $user = $this->getUser();
        $locale = trim((string) $request->request->get('locale', ''));

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        try {
            $mail = $this->renderer->render(
                $key,
                $locale !== '' ? $locale : null,
                $this->sampleParameters($definition->parameters),
                ['user' => $user],
            );

            $this->mailer->sendNow($user->getEmail(), '[TEST] '.$mail->subject, $mail->html, $mail->text);
            $this->addFlash('success', $this->translator->trans('aacp.mail_templates.preview_sent', ['email' => $user->getEmail()]));
        } catch (\Throwable $e) {
            $this->addFlash('error', $this->translator->trans('aacp.mail_templates.preview_failed', ['reason' => $e->getMessage()]));
        }

        return $this->redirectToRoute('aacp_mail_templates_edit', ['key' => $key]);
    }

    /**
     * Rendered-as-it-would-send text for each locale, so the screen can show
     * what the recipient gets without the operator having to mail themselves.
     *
     * @return array<string, array{subject: string, html: string}>
     */
    private function buildPreviews(string $key): array
    {
        $definition = $this->registry->get($key);
        $previews = [];

        if ($definition === null) {
            return $previews;
        }

        $sample = $this->sampleParameters($definition->parameters);

        foreach ($this->manager->locales() as $locale) {
            try {
                $mail = $this->renderer->render($key, $locale->code, $sample);
                $previews[$locale->code] = ['subject' => $mail->subject, 'html' => $mail->html];
            } catch (\Throwable) {
                // A template that cannot render is a problem worth seeing on the
                // edit form, not a 500 on the screen that would let you fix it.
                $previews[$locale->code] = ['subject' => '', 'html' => ''];
            }
        }

        return $previews;
    }

    /**
     * @param list<string> $parameters
     *
     * @return array<string, string>
     */
    private function sampleParameters(array $parameters): array
    {
        $samples = [];

        foreach ($parameters as $parameter) {
            $samples[$parameter] = match ($parameter) {
                'url', 'login_url', 'inbox_url' => $this->generateUrl('aacp_mail_templates', [], UrlGeneratorInterface::ABSOLUTE_URL),
                'count' => '3',
                'content' => $this->translator->trans('aacp.mail_templates.sample_content'),
                default => $this->translator->trans('aacp.mail_templates.sample_value'),
            };
        }

        return $samples;
    }

    private function assertCsrf(Request $request, string $tokenId): void
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken($tokenId, (string) $request->request->get('_token')))) {
            throw new BadRequestHttpException($this->translator->trans('aacp.common.error.invalid_csrf'));
        }
    }
}
