<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\Account\AccountLandingResolver;
use App\Core\Account\AccountRegistrationService;
use App\Core\Localization\LocaleProvider;
use App\Core\Mail\CpMailerService;
use App\Core\Security\CaptchaService;
use App\Core\Security\Password\PasswordChanger;
use App\Core\Security\Service\LoginDefenseService;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Public themed login/register pages using App\Entity\User (one session site-wide).
 * Form posts to admin_login check_path; login_path points here for themed UX.
 * Post-login redirect is set via session target_path without changing security.yaml defaults.
 */
final class AccountController extends AbstractController
{
    private const DEFAULT_ROLE = 'member';

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
    ) {
    }

    #[Route('/hesap/giris', name: 'account_login', methods: ['GET'])]
    public function login(Request $request, AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser() !== null) {
            return $this->redirectToRoute('theme_cpalius_website_home');
        }

        // When set, form_login ignores default_target_path. Keep existing target;
        // otherwise use referer or the account landing route.
        $targetPathKey = '_security.main.target_path';
        $session = $request->getSession();
        if (!$session->has($targetPathKey)) {
            $referer = $request->headers->get('referer');
            $session->set($targetPathKey, $referer ?: $this->generateUrl($this->landingResolver->routeName()));
        }

        return $this->render('account/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
            'captchaEnabled' => $this->loginDefense->captchaRequiredOnLogin((string) ($request->getClientIp() ?? '')),
            'captchaConfig' => $this->captchaService->getWidgetConfig(),
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

        if ($request->isMethod('POST')) {
            $this->assertValidCsrf($request);

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
                $this->entityManager->flush();

                $deferLogin = $this->registrationService->applyPostRegistrationState($user);

                if ($deferLogin) {
                    $emailSent = $this->registrationService->sendVerificationEmail($user);
                    if (!$emailSent && $this->registrationService->isEmailVerificationRequired()) {
                        $verifyUrl = $this->registrationService->buildVerificationUrl($user);
                        if ($verifyUrl !== null && !$this->mailerService->canSend()) {
                            $this->addFlash('info', $this->translator->trans('account.register.verify_link_dev', ['url' => $verifyUrl]));
                        }
                    }

                    return $this->redirectToRoute('account_register_pending');
                }

                $this->security->login($user, null, 'main');
                $this->addFlash('success', $this->translator->trans('account.register.welcome', ['fullName' => $user->getFullName()]));

                return $this->redirectToRoute($this->landingResolver->routeName());
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
        ]);
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
}
