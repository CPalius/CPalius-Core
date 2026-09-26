<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\Account\AccountLandingResolver;
use App\Core\Account\AccountRegistrationService;
use App\Core\Field\FieldValuePersister;
use App\Core\Field\Form\FieldableFormBuilder;
use App\Core\Localization\LocaleProvider;
use App\Core\Mail\CpMailerService;
use App\Core\Security\CaptchaService;
use App\Core\Security\Flood\FloodService;
use App\Core\Security\Password\PasswordChanger;
use App\Core\Security\Http\LoginTargetPath;
use App\Core\Security\Service\LoginDefenseService;
use App\Core\Settings\SettingsRegistry;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Public themed login/register. Form posts to admin_login; post-login target is same-origin only.
 */
final class AccountController extends AbstractController
{
    private const DEFAULT_ROLE = 'member';

    /** Per-IP registration cap; high enough for a shared NAT, low enough to stop a script. */
    private const REGISTER_LIMIT = 5;
    private const REGISTER_WINDOW = 3600;

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
        private readonly TranslatorInterface $translator,
        private readonly AccountRegistrationService $registrationService,
        private readonly CaptchaService $captchaService,
        private readonly LoginDefenseService $loginDefense,
        private readonly PasswordChanger $passwordChanger,
        private readonly CpMailerService $mailerService,
        private readonly LocaleProvider $localeProvider,
        private readonly AccountLandingResolver $landingResolver,
        private readonly FloodService $flood,
        private readonly FieldableFormBuilder $fieldableFormBuilder,
        private readonly FieldValuePersister $fieldValuePersister,
        private readonly FormFactoryInterface $formFactory,
        #[Autowire('%kernel.debug%')]
        private readonly bool $debug,
    ) {
    }

    #[Route('/hesap/giris', name: 'account_login', methods: ['GET'])]
    public function login(Request $request, AuthenticationUtils $authenticationUtils, SettingsRegistry $settings): Response
    {
        if ($this->getUser() !== null) {
            return $this->redirectToRoute('theme_cpalius_website_home');
        }

        // Keep a navigable target_path; poll/JSON URLs and the login door itself are dropped.
        $targetPathKey = '_security.main.target_path';
        $session = $request->getSession();
        $existing = $session->get($targetPathKey);
        if (!\is_string($existing) || !LoginTargetPath::isNavigable($existing)) {
            $referer = $this->sameOriginReferer($request);
            $session->set(
                $targetPathKey,
                ($referer !== null && LoginTargetPath::isNavigable($referer))
                    ? $referer
                    : $this->generateUrl($this->landingResolver->routeName()),
            );
        }

        return $this->render('account/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
            'captchaEnabled' => $this->loginDefense->captchaRequiredOnLogin((string) ($request->getClientIp() ?? '')),
            'captchaConfig' => $this->captchaService->getWidgetConfig(),
            'siteName' => trim((string) $settings->getForLocale('core.site_name', $request->getLocale(), '')) ?: 'CPalius CMF',
        ]);
    }

    #[Route('/hesap/kayit', name: 'account_register', methods: ['GET', 'POST'])]
    public function register(Request $request): Response
    {
        if ($this->getUser() !== null) {
            return $this->redirectToRoute('theme_cpalius_website_home');
        }

        if (!$this->registrationService->isRegistrationEnabled()) {
            throw new NotFoundHttpException($this->translator->trans('account.register.disabled'));
        }

        $formValues = $this->registrationService->defaultFormValues();
        $fieldsForm = $this->registrationFieldsForm($request);

        if ($request->isMethod('POST')) {
            $this->assertValidCsrf($request);
            $this->assertRegistrationNotFlooding($request);

            $email = trim((string) $request->request->get('email'));
            $username = trim((string) $request->request->get('username'));
            $firstName = trim((string) $request->request->get('first_name'));
            $lastName = trim((string) $request->request->get('last_name'));
            $password = (string) $request->request->get('password');
            $passwordConfirm = (string) $request->request->get('password_confirm');
            $locale = (string) $request->request->get('locale', '');
            $termsAccepted = $request->request->getBoolean('terms');

            $formValues = [
                'email' => $email,
                'username' => $username,
                'firstName' => $firstName,
                'lastName' => $lastName,
                // Phase 3: registration locale from active list; invalid falls back silently.
                'locale' => $this->localeProvider->resolve($locale),
            ];

            $errors = $this->registrationService->validate([
                'email' => $email,
                'username' => $username,
                'firstName' => $firstName,
                'lastName' => $lastName,
                'password' => $password,
                'passwordConfirm' => $passwordConfirm,
                'termsAccepted' => $termsAccepted,
                'locale' => $formValues['locale'],
            ]);

            if ($this->captchaService->enabledOnRegister() && !$this->captchaService->verifyRequest($request)) {
                $errors[] = $this->translator->trans('account.captcha.failed');
            }

            if ($errors === []) {
                $user = new User($email);
                $user->setUsername($username !== '' ? $username : null);
                if ($this->registrationService->firstNameMode() !== AccountRegistrationService::FIELD_HIDDEN) {
                    $user->setFirstName($firstName);
                }
                if ($this->registrationService->lastNameMode() !== AccountRegistrationService::FIELD_HIDDEN) {
                    $user->setLastName($lastName);
                }
                $this->passwordChanger->change($user, $password);
                $user->setCpaliusRoles([self::DEFAULT_ROLE]);
                $user->setDataValue('locale', $formValues['locale']);

                $this->entityManager->persist($user);
                $fieldErrors = [];
                if ($fieldsForm->has('fields')) {
                    $submitted = $fieldsForm->get('fields')->getData();
                    $fieldErrors = $this->fieldValuePersister->persist($user, \is_array($submitted) ? $submitted : []);
                }
                if ($fieldErrors !== []) {
                    foreach ($fieldErrors as $violations) {
                        foreach ($violations as $violation) {
                            $this->addFlash('error', $this->translator->trans($violation));
                        }
                    }
                } else {
                    $this->entityManager->flush();

                    $deferLogin = $this->registrationService->applyPostRegistrationState($user);

                    if ($deferLogin) {
                        $emailSent = $this->registrationService->sendVerificationEmail($user);

                        // Approval-pending members get told so in their own language;
                        // without it the only signal is an account that silently
                        // refuses to log in.
                        if ($this->registrationService->isAdminApprovalRequired()) {
                            $this->registrationService->sendPendingApprovalEmail($user);
                        }

                        // Verification URL on screen is debug-only; in prod a null mailer must not skip proof of inbox.
                        if ($this->debug
                            && !$emailSent
                            && $this->registrationService->isEmailVerificationRequired()
                            && !$this->mailerService->canSend()
                        ) {
                            $verifyUrl = $this->registrationService->buildVerificationUrl($user);
                            if ($verifyUrl !== null) {
                                $this->addFlash('info', $this->translator->trans('account.register.verify_link_dev', ['url' => $verifyUrl]));
                            }
                        }

                        return $this->redirectToRoute('account_register_pending');
                    }

                    $this->registrationService->sendWelcomeEmail($user);

                    $this->security->login($user, null, 'main');
                    $this->addFlash('success', $this->translator->trans('account.register.welcome', ['fullName' => $user->getFullName()]));

                    return $this->redirectToRoute($this->landingResolver->routeName());
                }
            }

            foreach ($errors as $error) {
                $this->addFlash('error', $error);
            }
        }

        return $this->render('account/register.html.twig', [
            'formValues' => $formValues,
            'registrationConfig' => [
                'usernameRequired' => $this->registrationService->isUsernameRequired(),
                'termsRequired' => $this->registrationService->isTermsRequired(),
                'firstNameMode' => $this->registrationService->firstNameMode(),
                'lastNameMode' => $this->registrationService->lastNameMode(),
                'emailVerification' => $this->registrationService->isEmailVerificationRequired(),
                'adminApproval' => $this->registrationService->isAdminApprovalRequired(),
            ],
            'captchaEnabled' => $this->captchaService->enabledOnRegister(),
            'captchaConfig' => $this->captchaService->getWidgetConfig(),
            'fieldsForm' => $fieldsForm,
        ]);
    }

    private function registrationFieldsForm(Request $request): FormInterface
    {
        $builder = $this->formFactory->createNamedBuilder('register', FormType::class, null, [
            'csrf_protection' => false,
        ]);
        $this->fieldableFormBuilder->add($builder, 'user', $request->getLocale());
        $form = $builder->getForm();
        $form->handleRequest($request);

        return $form;
    }

    #[Route('/hesap/kayit/beklemede', name: 'account_register_pending', methods: ['GET'])]
    public function registerPending(): Response
    {
        return $this->render('account/register_pending.html.twig', [
            'emailVerification' => $this->registrationService->isEmailVerificationRequired(),
            'adminApproval' => $this->registrationService->isAdminApprovalRequired(),
        ]);
    }

    #[Route('/hesap/dogrula/{token}', name: 'account_verify_email', methods: ['GET'])]
    public function verifyEmail(string $token): Response
    {
        $user = $this->registrationService->verifyEmailByToken($token);
        if ($user === null) {
            $this->addFlash('error', $this->translator->trans('account.verify.invalid_token'));

            return $this->redirectToRoute('account_login');
        }

        $this->addFlash('success', $this->translator->trans('account.verify.success'));

        if ($user->getStatus() === User::STATUS_INACTIVE) {
            return $this->redirectToRoute('account_register_pending');
        }

        return $this->redirectToRoute('account_login');
    }

    private function assertValidCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid('account_register', (string) $request->request->get('_token'))) {
            throw new BadRequestHttpException($this->translator->trans('account.invalid_csrf'));
        }
    }

    /**
     * Caps registrations per IP. Failed attempts count too so a script cannot cycle addresses for free.
     */
    private function assertRegistrationNotFlooding(Request $request): void
    {
        $ip = (string) ($request->getClientIp() ?: '');
        if ($ip === '') {
            return;
        }

        if (!$this->flood->isAllowed(FloodService::EVENT_REGISTER, $ip, self::REGISTER_LIMIT, self::REGISTER_WINDOW)) {
            throw new TooManyRequestsHttpException(null, $this->translator->trans('account.register.too_many'));
        }

        $this->flood->register(FloodService::EVENT_REGISTER, $ip, self::REGISTER_WINDOW);
    }

    /**
     * Same-origin Referer, or null. Compared to this request's scheme+host, not a configured domain.
     */
    private function sameOriginReferer(Request $request): ?string
    {
        $referer = $request->headers->get('referer');
        if (!\is_string($referer) || $referer === '') {
            return null;
        }

        $origin = $request->getSchemeAndHttpHost();

        return $referer === $origin || str_starts_with($referer, $origin.'/') ? $referer : null;
    }
}
