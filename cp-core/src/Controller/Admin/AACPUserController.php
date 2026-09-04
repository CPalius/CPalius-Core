<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Core\Account\AccountRegistrationService;
use App\Core\Account\UserAvatarService;
use App\Core\Annotation\CpAdminMenu;
use App\Core\Content\RichTextSanitizer;
use App\Core\Pagination\Paginator;
use App\Core\Security\RoleCapabilityPresenter;
use App\Core\Security\RoleConfigManager;
use App\Core\Security\UserRoleGuardService;
use App\Entity\User;
use App\Form\DTO\ProfileFormModel;
use App\Form\DTO\UserFormModel;
use App\Form\ProfileType;
use App\Form\UserType;
use App\Repository\AssetRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * AACP "Kullanıcı Yönetimi" ve "Profilim" ekranları.
 *
 * PostAdminController (Modules\Blog\Controller\Admin\PostAdminController)
 * ile AYNI iskelet: AbstractController extend eder (form/flash/redirect
 * kısayolları için), DTO tabanlı form akışı, User'a yazım TEK bir
 * yardımcı metotta (mapDtoToUser()) toplanır.
 *
 * Yetkilendirme her zaman CPaliusVoter üzerinden dinamik capability ile
 * yapılır (ROLE_* YASAK, bkz. Manifesto Law 4 ve CPaliusVoter docblock'u):
 *   - Listeleme/Düzenleme/Oluşturma: system.users.view / system.users.manage
 *   - Profilim: capability GEREKMEZ — sadece IS_AUTHENTICATED_FULLY
 *     (security.yaml ^/aacp kuralı) yeterlidir, çünkü her kullanıcı kendi
 *     profiline erişebilmelidir; bu ekran başka bir kullanıcının verisine
 *     hiçbir zaman erişmez (her zaman $this->getUser() üzerinden çalışır).
 */
final class AACPUserController extends AbstractController
{
    private const ADMIN_PER_PAGE = 20;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly RoleConfigManager $roleConfigManager,
        private readonly UserRoleGuardService $userRoleGuard,
        private readonly RoleCapabilityPresenter $roleCapabilityPresenter,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly RichTextSanitizer $richTextSanitizer,
        private readonly AssetRepository $assetRepository,
        private readonly Paginator $paginator,
        private readonly TranslatorInterface $translator,
        private readonly AccountRegistrationService $registrationService,
        private readonly UserAvatarService $avatarService,
    ) {
    }

    #[Route('/aacp/users/pending', name: 'aacp_users_pending', methods: ['GET', 'POST'])]
    #[CpAdminMenu(label: 'aacp.users.pending_menu', icon: 'heroicons:clock', panel: 'aacp', priority: 58, capability: 'system.users.manage', group: 'aacp.group.user', parent: 'aacp_users')]
    #[IsGranted('system.users.manage')]
    public function pending(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $userId = $request->request->getInt('user_id');
            if (!$this->isCsrfTokenValid('aacp_user_approve', (string) $request->request->get('_token'))) {
                throw new BadRequestHttpException($this->translator->trans('aacp.users.invalid_csrf'));
            }

            $user = $this->userRepository->find($userId);
            if ($user instanceof User) {
                $this->registrationService->approveUser($user);
                $this->addFlash('success', $this->translator->trans('aacp.users.approve_success', ['email' => $user->getEmail()]));
            }
        }

        $pending = array_filter(
            $this->userRepository->findPendingApproval(),
            static fn (User $u): bool => (bool) $u->getDataValue('registration_pending_approval', false),
        );

        return $this->render('aacp/users/pending.html.twig', [
            'users' => $pending,
        ]);
    }

    /**
     * Kullanıcı listesi: genel AACP flat tablo standardında (twig:cp:table),
     * durum rozetleri (twig:cp:badge) ile. Blog'un index() aksiyonundaki
     * QueryScopeApplier own/any daraltması burada ANLAMSIZDIR — kullanıcı
     * kaydının bir "sahibi" (author) yoktur, bu yüzden sadece kaba
     * system.users.view kapı kontrolü yeterlidir.
     */
    #[Route('/aacp/users', name: 'aacp_users', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.menu.users', icon: 'heroicons:users', panel: 'aacp', priority: 60, capability: 'system.users.view', group: 'aacp.group.user')]
    #[IsGranted('system.users.view')]
    public function index(Request $request): Response
    {
        $qb = $this->userRepository->createAdminListQueryBuilder($request->query->get('q'));

        $result = $this->paginator->paginate($qb, $request->query->getInt('page', 1), self::ADMIN_PER_PAGE);

        return $this->render('aacp/users/index.html.twig', [
            'users' => $result,
            'search' => (string) $request->query->get('q', ''),
            'roleLabels' => $this->userRoleGuard->roleLabelMap(),
        ]);
    }

    #[Route('/aacp/users/create', name: 'aacp_users_create', methods: ['GET', 'POST'])]
    #[IsGranted('system.users.manage', message: 'Kullanıcı oluşturma yetkiniz yok.', statusCode: 403)]
    public function create(Request $request): Response
    {
        $dto = new UserFormModel();
        $form = $this->createUserForm($dto, isEdit: false);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (trim((string) $dto->plainPassword) === '') {
                $form->get('plainPassword')->addError(
                    new FormError($this->translator->trans('aacp.users.password_required')),
                );
            } elseif ($this->userRepository->isEmailTakenByAnotherUser($dto->email, null)) {
                $form->get('email')->addError(
                    new FormError($this->translator->trans('aacp.users.email_taken')),
                );
            } else {
                $draftUser = new User($dto->email);
                if (!$this->applySecureUserRoles($dto, $draftUser, $this->getUser() instanceof User ? $this->getUser() : null, $form)) {
                    // applySecureUserRoles forma hata ekledi.
                } else {
                $user = new User($dto->email);
                $this->mapDtoToUser($dto, $user);

                $this->entityManager->persist($user);
                $this->entityManager->flush();

                $this->addFlash('success', $this->translator->trans('aacp.users.create_success', ['email' => $user->getEmail()]));

                return $this->redirectToRoute('aacp_users');
                }
            }
        }

        return $this->renderUserForm(null, $form, isEdit: false);
    }

    #[Route('/aacp/users/{id}/edit', name: 'aacp_users_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('system.users.manage', message: 'Kullanıcı düzenleme yetkiniz yok.', statusCode: 403)]
    public function edit(int $id, Request $request): Response
    {
        $user = $this->findUserOrFail($id);
        $dto = UserFormModel::fromUser($user);
        $form = $this->createUserForm($dto, isEdit: true);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($this->userRepository->isEmailTakenByAnotherUser($dto->email, $user->getId())) {
                $form->get('email')->addError(
                    new FormError($this->translator->trans('aacp.users.email_taken')),
                );
            } else {
                $statusError = $this->userRoleGuard->validateStatusChange(
                    $user,
                    $dto->status,
                    $this->getUser() instanceof User ? $this->getUser() : null,
                );
                if ($statusError !== null) {
                    $form->get('status')->addError(new FormError($this->translator->trans($statusError)));
                } elseif (!$this->applySecureUserRoles($dto, $user, $this->getUser() instanceof User ? $this->getUser() : null, $form)) {
                    // applySecureUserRoles forma hata ekledi.
                } else {
                    $user->setEmail($dto->email);
                    $this->mapDtoToUser($dto, $user);

                    $this->entityManager->flush();

                    $this->addFlash('success', $this->translator->trans('aacp.users.update_success', ['email' => $user->getEmail()]));

                    return $this->redirectToRoute('aacp_users');
                }
            }
        }

        return $this->renderUserForm($user, $form, isEdit: true);
    }

    /**
     * Kalıcı silme: User entity SoftDeletable DEĞİLDİR (PostAdminController'ın
     * Node'ları gibi bir çöp kutusu akışı yok), bu yüzden gerçek DELETE
     * uygulanır. Kendi hesabını silme her zaman engellenir — aksi halde bir
     * yönetici oturumu açıkken kendini silip AACP'den kilitlenebilir veya
     * (tek admin olduğu senaryoda) sistemi yöneticisiz bırakabilir.
     */
    #[Route('/aacp/users/{id}/delete', name: 'aacp_users_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('system.users.manage', message: 'Kullanıcı silme yetkiniz yok.', statusCode: 403)]
    public function delete(int $id, Request $request): Response
    {
        $user = $this->findUserOrFail($id);

        if (!$this->isCsrfTokenValid('aacp_user_delete_'.$user->getId(), (string) $request->request->get('_token'))) {
            throw new BadRequestHttpException($this->translator->trans('aacp.users.invalid_csrf'));
        }

        if ($user->getId() === $this->getUser()?->getId()) {
            $this->addFlash('error', $this->translator->trans('aacp.users.delete_self_error'));

            return $this->redirectToRoute('aacp_users');
        }

        $email = $user->getEmail();
        $this->entityManager->remove($user);
        $this->entityManager->flush();

        $this->addFlash('success', $this->translator->trans('aacp.users.delete_success', ['email' => $email]));

        return $this->redirectToRoute('aacp_users');
    }

    /**
     * "Profilim": giriş yapmış yöneticinin kendi bilgilerini güncellediği
     * ekran. Biyografi alanı CKEditor 5 (data-cpeditor) ile zenginleştirilir
     * (bkz. ProfileType::$bio, cp-core/assets/cp-editor-init.js).
     *
     * Bilinçli olarak #[CpAdminMenu] TAŞIMAZ: bu ekrana artık sidebar'dan
     * değil, header'daki profil dropdown'undan (bkz.
     * templates/aacp/_header_actions.html.twig) erişilir — sidebar'da ayrı
     * bir "Hesap" grubu olarak tekrar listelenmesi gereksiz kalabalık
     * yaratırdı.
     */
    #[Route('/aacp/profile', name: 'aacp_users_profile', methods: ['GET', 'POST'])]
    public function profile(Request $request): Response
    {
        $user = $this->getCurrentUserOrFail();
        $dto = ProfileFormModel::fromUser($user);
        $form = $this->createForm(ProfileType::class, $dto);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($this->userRepository->isEmailTakenByAnotherUser($dto->email, $user->getId())) {
                $form->get('email')->addError(
                    new FormError($this->translator->trans('aacp.users.email_taken')),
                );
            } elseif (!$this->applyPasswordChangeIfRequested($dto, $user, $form)) {
                // applyPasswordChangeIfRequested() zaten forma hata eklemiştir.
            } else {
                $this->mapProfileDtoToUser($dto, $user);

                $this->entityManager->flush();

                $this->addFlash('success', $this->translator->trans('aacp.users.profile_update_success'));

                return $this->redirectToRoute('aacp_users_profile');
            }
        }

        return $this->render('aacp/users/profile.html.twig', [
            'form' => $form,
            'avatarUrl' => $this->avatarService->resolveUrl($user),
        ]);
    }

    /**
     * newPassword doldurulmuşsa, currentPassword'ün DOĞRU olduğunu
     * doğrulamadan asla User::setPassword() çağrılmaz — aksi halde
     * oturumu ele geçirilmiş (ama şifresi bilinmeyen) bir saldırgan tek
     * bir form gönderimiyle şifreyi değiştirip hesabı tamamen ele
     * geçirebilirdi. currentPassword boş/yanlışsa forma hata eklenir ve
     * false döner; mapProfileDtoToUser() çağrılmadan akış durur.
     */
    private function applyPasswordChangeIfRequested(ProfileFormModel $dto, User $user, FormInterface $form): bool
    {
        $newPassword = trim((string) $dto->newPassword);
        if ($newPassword === '') {
            return true;
        }

        if (!$this->passwordHasher->isPasswordValid($user, (string) $dto->currentPassword)) {
            $form->get('currentPassword')->addError(
                new FormError($this->translator->trans('aacp.users.current_password_wrong')),
            );

            return false;
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, $newPassword));

        return true;
    }

    private function createUserForm(UserFormModel $dto, bool $isEdit): FormInterface
    {
        $roleChoices = [];
        foreach ($this->roleConfigManager->getAllRoleIds() as $roleId) {
            $roleChoices[$this->roleConfigManager->getLabel($roleId) ?? $roleId] = $roleId;
        }

        return $this->createForm(UserType::class, $dto, [
            'role_choices' => $roleChoices,
            'is_edit' => $isEdit,
        ]);
    }

    /**
     * UserFormModel DTO'sundaki doğrulanmış veriyi User entity'sine güvenli
     * biçimde aktarır — create()/edit() arasında paylaşılan TEK yazma yolu
     * (Manifesto Law 5.3 mass assignment allowlist ilkesiyle aynı desen,
     * bkz. PostAdminController::mapDtoToNode()).
     */
    private function mapDtoToUser(UserFormModel $dto, User $user): void
    {
        $user->setUsername(trim((string) $dto->username) !== '' ? trim((string) $dto->username) : null);
        $user->setStatus($dto->status);
        $user->setFirstName(trim((string) $dto->firstName));
        $user->setLastName(trim((string) $dto->lastName));
        $user->setCpaliusRoles($dto->roles);

        $plainPassword = trim((string) $dto->plainPassword);
        if ($plainPassword !== '') {
            $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
        }
    }

    /**
     * Rol atamasını RoleConfigManager beyaz listesinden geçirir; CPaliusVoter
     * dışında ek güvenlik kurallarını (son admin, kendi rolünü düşürme) uygular.
     */
    private function applySecureUserRoles(UserFormModel $dto, User $user, ?User $actor, FormInterface $form): bool
    {
        $sanitized = $this->userRoleGuard->sanitizeRoles($dto->roles);

        if ($sanitized === [] && $user->getId() === null) {
            $sanitized = $this->userRoleGuard->defaultRolesForNewUser();
        }

        $errorKey = $this->userRoleGuard->validateAssignment($user, $sanitized, $actor);
        if ($errorKey !== null) {
            $form->get('roles')->addError(new FormError($this->translator->trans($errorKey)));

            return false;
        }

        $dto->roles = $sanitized;

        return true;
    }

    private function renderUserForm(?User $user, FormInterface $form, bool $isEdit): Response
    {
        $selectedRoles = $form->get('roles')->getData();
        if (!\is_array($selectedRoles)) {
            $selectedRoles = [];
        }

        return $this->render('aacp/users/form.html.twig', [
            'user' => $user,
            'form' => $form,
            'roleCatalog' => $this->roleCapabilityPresenter->buildRoleCatalog(),
            'effectiveSummary' => $this->roleCapabilityPresenter->summarizeSelectedRoles($selectedRoles),
            'roleLabels' => $this->userRoleGuard->roleLabelMap(),
            'isEdit' => $isEdit,
        ]);
    }

    /**
     * ProfileFormModel -> User yazım yolu. 'bio' RichTextSanitizer'dan
     * GEÇMEDEN asla User::setBio()'ya yazılmaz (Manifesto Law 5.3), tıpkı
     * PostAdminController::mapDtoToNode()'un 'body' için yaptığı gibi.
     * 'status'/'roles' BİLİNÇLİ OLARAK burada YOKTUR (bkz. ProfileFormModel
     * sınıf üstü doküman).
     */
    private function mapProfileDtoToUser(ProfileFormModel $dto, User $user): void
    {
        $user->setEmail($dto->email);
        $user->setUsername(trim((string) $dto->username) !== '' ? trim((string) $dto->username) : null);
        $user->setFirstName(trim((string) $dto->firstName));
        $user->setLastName(trim((string) $dto->lastName));
        $user->setBio($this->richTextSanitizer->sanitize((string) $dto->bio));
        $user->setAvatarAssetId($dto->avatarAssetId);
    }

    private function findUserOrFail(int $id): User
    {
        $user = $this->userRepository->find($id);

        if (!$user instanceof User) {
            throw new NotFoundHttpException($this->translator->trans('aacp.users.not_found'));
        }

        return $user;
    }

    private function getCurrentUserOrFail(): User
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException($this->translator->trans('aacp.users.session_not_found'));
        }

        return $user;
    }
}
