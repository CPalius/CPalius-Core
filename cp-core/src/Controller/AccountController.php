<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Site geneli, public temaya entegre hesap sayfaları (giriş/kayıt) —
 * App\Entity\User çekirdeğini kullanır, bu yüzden burada oluşturulan
 * hesap forum, blog, medya vb. HER modülde aynı oturumla aktiftir.
 *
 * "/login" (App\Controller\Admin\SecurityController::login) BİLİNÇLİ
 * OLARAK dokunulmadan bırakıldı: security.yaml'daki tek firewall'ın
 * form_login "check_path"i hâlâ o rotadır (kimlik doğrulama akışı
 * değişmedi), sadece "login_path" (kullanıcının YÖNLENDİRİLECEĞİ/
 * GÖRECEĞİ sayfa) buradaki temaya entegre görünüme çevrildi — bkz.
 * security.yaml yorumu. Bu sayede AACP/recovery zinciri hâlâ tema
 * bağımlılığı OLMAYAN admin/login.html.twig'e güvenebiliyor (Manifesto
 * Law 2.3), ama normal site/forum ziyaretçisi artık "admin girişi"
 * gibi görünen bir sayfa yerine markaya uygun bir sayfa görüyor. Bu
 * sayfadaki form, kimlik doğrulamayı GERÇEKTEN yapan check_path'e
 * (admin_login) post eder — kendi controller mantığı YOKTUR.
 *
 * security.yaml'daki login_path/default_target_path/logout.target
 * BİLİNÇLİ OLARAK DEĞİŞTİRİLMEDİ: bunlar hâlâ admin_login/admin_dashboard'a
 * işaret eder, çünkü AACP'nin auth-gerektiren korumalı bir sayfaya
 * (/admin, /aacp) yönlendirme akışı bu ayarlara bağlıdır ve tema kırılsa
 * bile çalışabilmesi gerekir. Bunun yerine login() burada, "hedef sayfa"yı
 * (giriş sonrası nereye dönüleceğini) Symfony'nin zaten desteklediği
 * session anahtarına (bkz. login() içindeki $targetPathKey) ELLE yazarak
 * çözer — global config'e dokunmadan, sadece BU sayfadan giriş yapanlar
 * için doğru (forum'a/geldiği sayfaya) yönlendirme sağlar.
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
    ) {
    }

    #[Route('/hesap/giris', name: 'account_login', methods: ['GET'])]
    public function login(Request $request, AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser() !== null) {
            return $this->redirectToRoute('theme_cpalius_website_home');
        }

        // Symfony'nin form_login başarı işleyicisi bu session anahtarı
        // doluysa default_target_path'i (admin_dashboard) YOK SAYAR (bkz.
        // sınıf üstü doküman). Zaten set edilmişse (korumalı bir forum
        // eylemine — ör. yeni konu — erişim engellenip BURAYA yönlendirilmiş
        // olabilir) dokunulmaz; öyle değilse "nereden geldiği" (referer)
        // veya forum ana sayfası hedef alınır.
        $targetPathKey = '_security.main.target_path';
        $session = $request->getSession();
        if (!$session->has($targetPathKey)) {
            $referer = $request->headers->get('referer');
            $session->set($targetPathKey, $referer ?: $this->generateUrl('forum_index'));
        }

        return $this->render('account/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }

    #[Route('/hesap/kayit', name: 'account_register', methods: ['GET', 'POST'])]
    public function register(Request $request): Response
    {
        if ($this->getUser() !== null) {
            return $this->redirectToRoute('theme_cpalius_website_home');
        }

        $formValues = ['email' => '', 'username' => '', 'firstName' => '', 'lastName' => ''];

        if ($request->isMethod('POST')) {
            $this->assertValidCsrf($request);

            $email = trim((string) $request->request->get('email'));
            $username = trim((string) $request->request->get('username'));
            $firstName = trim((string) $request->request->get('first_name'));
            $lastName = trim((string) $request->request->get('last_name'));
            $password = (string) $request->request->get('password');
            $passwordConfirm = (string) $request->request->get('password_confirm');

            $formValues = compact('email', 'username', 'firstName', 'lastName');

            $errors = $this->validateRegistration($email, $username, $password, $passwordConfirm);

            if ($errors === []) {
                $user = new User($email);
                $user->setUsername($username !== '' ? $username : null);
                $user->setFirstName($firstName);
                $user->setLastName($lastName);
                $user->setPassword($this->passwordHasher->hashPassword($user, $password));
                // "member": forum kullanımı için gereken kayıtlı-üye
                // yeteneklerini taşır (bkz. user.role.member.yaml), ancak
                // "editor" rolünün aksine node.post.* (blog yazısı
                // oluşturma/düzenleme/yayınlama) İÇERMEZ — sıradan bir kayıt
                // kullanıcıya içerik yönetimi yetkisi vermemelidir.
                $user->setCpaliusRoles([self::DEFAULT_ROLE]);

                $this->entityManager->persist($user);
                $this->entityManager->flush();

                $this->security->login($user, null, 'main');
                $this->addFlash('success', $this->translator->trans('account.register.welcome', ['fullName' => $user->getFullName()]));

                return $this->redirectToRoute('forum_index');
            }

            foreach ($errors as $error) {
                $this->addFlash('error', $error);
            }
        }

        return $this->render('account/register.html.twig', [
            'formValues' => $formValues,
        ]);
    }

    /** @return string[] */
    private function validateRegistration(string $email, string $username, string $password, string $passwordConfirm): array
    {
        $errors = [];

        if ($email === '' || !filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            $errors[] = $this->translator->trans('account.register.invalid_email');
        } elseif ($this->userRepository->isEmailTakenByAnotherUser($email, null)) {
            $errors[] = $this->translator->trans('account.register.email_taken');
        }

        if ($username !== '' && $this->userRepository->findOneByUsername($username) !== null) {
            $errors[] = $this->translator->trans('account.register.username_taken');
        }

        if (mb_strlen($password) < 8) {
            $errors[] = $this->translator->trans('account.register.password_too_short');
        } elseif ($password !== $passwordConfirm) {
            $errors[] = $this->translator->trans('account.register.password_mismatch');
        }

        return $errors;
    }

    private function assertValidCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid('account_register', (string) $request->request->get('_token'))) {
            throw new BadRequestHttpException($this->translator->trans('account.invalid_csrf'));
        }
    }
}
