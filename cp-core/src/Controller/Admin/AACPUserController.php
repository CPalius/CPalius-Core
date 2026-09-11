<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Core\Account\AccountRegistrationService;
use App\Core\Account\UserAvatarService;
use App\Core\Annotation\CpAdminMenu;
use App\Core\Content\RichTextSanitizer;
use App\Core\Field\FieldDefinitionRegistry;
use App\Core\Field\FieldValuePersister;
use App\Core\Pagination\Paginator;
use App\Core\Pagination\PaginatedResult;
use App\Core\Security\Password\PasswordChanger;
use App\Core\Security\Password\PasswordPolicy;
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
 * AACP user management and "My profile" screens (PostAdminController-style DTO flow).
 * Auth via CPaliusVoter capabilities; profile uses only the current user.
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
        private readonly PasswordPolicy $passwordPolicy,
        private readonly PasswordChanger $passwordChanger,
        private readonly RichTextSanitizer $richTextSanitizer,
        private readonly AssetRepository $assetRepository,
        private readonly Paginator $paginator,
        private readonly TranslatorInterface $translator,
        private readonly AccountRegistrationService $registrationService,
        private readonly UserAvatarService $avatarService,
        private readonly FieldValuePersister $fieldValuePersister,
        private readonly FieldDefinitionRegistry $fieldDefinitions,
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
     * User list (flat AACP table); no own/any scope — users have no author owner.
     */
    #[Route('/aacp/users', name: 'aacp_users', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.menu.users', icon: 'heroicons:users', panel: 'aacp', priority: 60, capability: 'system.users.view', group: 'aacp.group.user')]
    #[IsGranted('system.users.view')]
    public function index(Request $request): Response
    {
        return $this->renderUserList($request, null);
    }

    #[Route('/aacp/users/role/admin', name: 'aacp_users_admin', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.menu.administrators', icon: 'heroicons:shield-check', panel: 'aacp', priority: 61, capability: 'system.users.view', parent: 'aacp_users')]
    #[IsGranted('system.users.view')]
    public function administrators(Request $request): Response
    {
        return $this->renderUserList($request, 'admin');
    }

    #[Route('/aacp/users/role/member', name: 'aacp_users_member', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.menu.members', icon: 'heroicons:user-group', panel: 'aacp', priority: 62, capability: 'system.users.view', parent: 'aacp_users')]
    #[IsGranted('system.users.view')]
    public function members(Request $request): Response
    {
        return $this->renderUserList($request, 'member');
    }

    #[Route('/aacp/users/role/editor', name: 'aacp_users_editor', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.menu.editors', icon: 'heroicons:pencil-square', panel: 'aacp', priority: 63, capability: 'system.users.view', parent: 'aacp_users')]
    #[IsGranted('system.users.view')]
    public function editors(Request $request): Response
    {
        return $this->renderUserList($request, 'editor');
    }

    #[Route('/aacp/administrators', name: 'aacp_administrators', methods: ['GET'])]
    public function administratorsLegacy(): Response
    {
        return $this->redirectToRoute('aacp_users_admin', status: 301);
    }

    #[Route('/aacp/isolation', name: 'aacp_isolation', methods: ['GET'])]
    public function isolationLegacy(): Response
    {
        return $this->redirectToRoute('aacp_users', status: 301);
    }

    #[Route('/aacp/users/create', name: 'aacp_users_create', methods: ['GET', 'POST'])]
    #[IsGranted('system.users.manage', message: 'You are not allowed to create users.', statusCode: 403)]
    public function create(Request $request): Response
    {
        $dto = new UserFormModel();
        $form = $this->createUserForm($dto, isEdit: false, request: $request);
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
                    // applySecureUserRoles added form errors.
                } elseif (!$this->validateAssignedPassword($dto, $draftUser, $form)) {
                    // validateAssignedPassword added form errors.
                } else {
                $user = new User($dto->email);
                $this->mapDtoToUser($dto, $user);

                if (!$this->persistUserFields($user, $form)) {
                    // persistUserFields added violations to the "fields" sub-form.
                } else {
                    $this->entityManager->persist($user);
                    $this->entityManager->flush();

                    $this->addFlash('success', $this->translator->trans('aacp.users.create_success', ['email' => $user->getEmail()]));

                    return $this->redirectToRoute('aacp_users');
                }
                }
            }
        }

        return $this->renderUserForm(null, $form, isEdit: false);
    }

    #[Route('/aacp/users/{id}/edit', name: 'aacp_users_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('system.users.manage', message: 'You are not allowed to edit users.', statusCode: 403)]
    public function edit(int $id, Request $request): Response
    {
        $user = $this->findUserOrFail($id);
        $dto = UserFormModel::fromUser($user);
        $form = $this->createUserForm($dto, isEdit: true, request: $request);
        if ($form->has('fields')) {
            $form->get('fields')->setData($this->currentUserFieldValues($user));
        }
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
                    // applySecureUserRoles added form errors.
                } elseif (!$this->persistUserFields($user, $form)) {
                    // persistUserFields added violations to the "fields" sub-form.
                } elseif (!$this->validateAssignedPassword($dto, $user, $form)) {
                    // validateAssignedPassword added form errors.
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
     * Hard delete (User is not soft-deletable); self-delete is always blocked.
     */
    #[Route('/aacp/users/{id}/delete', name: 'aacp_users_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('system.users.manage', message: 'You are not allowed to delete users.', statusCode: 403)]
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
     * My profile for the signed-in admin (CKEditor bio); linked from header dropdown, not sidebar.
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
                // applyPasswordChangeIfRequested() already added form errors.
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
     * Requires valid currentPassword before setPassword(); otherwise adds form errors and stops.
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

        $violations = $this->passwordPolicy->validate($newPassword, $this->passwordChanger->identityOf($user), $user);
        if ($violations !== []) {
            foreach ($violations as $violation) {
                $form->get('newPassword')->addError(new FormError($violation));
            }

            return false;
        }

        $this->passwordChanger->change($user, $newPassword);

        return true;
    }

    private function createUserForm(UserFormModel $dto, bool $isEdit, Request $request): FormInterface
    {
        $roleChoices = [];
        foreach ($this->roleConfigManager->getAllRoleIds() as $roleId) {
            $roleChoices[$this->translator->trans($this->roleConfigManager->getLabel($roleId) ?? $roleId)] = $roleId;
        }

        return $this->createForm(UserType::class, $dto, [
            'role_choices' => $roleChoices,
            'is_edit' => $isEdit,
            'field_locale' => $request->getLocale(),
        ]);
    }

    /**
     * Existing "user" bundle field values, keyed by field name, for form pre-fill.
     *
     * @return array<string, mixed>
     */
    private function currentUserFieldValues(User $user): array
    {
        $data = $user->getFieldableData();
        $values = [];
        foreach ($this->fieldDefinitions->getFieldsForBundle('user') as $definition) {
            if (\array_key_exists($definition->getName(), $data)) {
                $values[$definition->getName()] = $data[$definition->getName()];
            }
        }

        return $values;
    }

    /**
     * Runs FieldValuePersister for the "user" bundle and mirrors any violations
     * back onto the "fields" sub-form. Returns true when the entity is clean.
     */
    private function persistUserFields(User $user, FormInterface $form): bool
    {
        if (!$form->has('fields')) {
            return true;
        }

        $submitted = $form->get('fields')->getData();
        $errors = $this->fieldValuePersister->persist($user, \is_array($submitted) ? $submitted : []);

        foreach ($errors as $fieldName => $violations) {
            if (!$form->get('fields')->has($fieldName)) {
                continue;
            }
            foreach ($violations as $violation) {
                $form->get('fields')->get($fieldName)->addError(new FormError($this->translator->trans($violation)));
            }
        }

        return $errors === [];
    }

    /**
     * Single shared write path from UserFormModel to User (Law 5.3 allowlist pattern).
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
            $this->passwordChanger->change($user, $plainPassword);
        }
    }

    /**
     * An operator-assigned password goes through the same policy as a self-service
     * change: "the admin set it" is not a reason to allow a breached password.
     */
    private function validateAssignedPassword(UserFormModel $dto, User $user, FormInterface $form): bool
    {
        $plainPassword = trim((string) $dto->plainPassword);
        if ($plainPassword === '') {
            return true;
        }

        $identity = array_values(array_filter([
            $dto->email,
            (string) $dto->username,
            (string) $dto->firstName,
            (string) $dto->lastName,
        ], static fn (string $value): bool => trim($value) !== ''));

        $violations = $this->passwordPolicy->validate(
            $plainPassword,
            $identity,
            $user->getId() === null ? null : $user,
        );

        foreach ($violations as $violation) {
            $form->get('plainPassword')->addError(new FormError($violation));
        }

        return $violations === [];
    }

    /**
     * Sanitizes roles via RoleConfigManager and enforces last-admin/self-demotion rules.
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
     * ProfileFormModel -> User; bio is sanitized (Law 5.3). No status/roles here.
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

    private function renderUserList(Request $request, ?string $role): Response
    {
        $search = (string) $request->query->get('q', '');
        $page = $request->query->getInt('page', 1);

        if ($role === null) {
            $result = $this->paginator->paginate(
                $this->userRepository->createAdminListQueryBuilder($search),
                $page,
                self::ADMIN_PER_PAGE,
            );
        } else {
            $result = $this->paginateRoleList($role, $search, $page);
        }

        $listRoute = match ($role) {
            'admin' => 'aacp_users_admin',
            'member' => 'aacp_users_member',
            'editor' => 'aacp_users_editor',
            default => 'aacp_users',
        };

        $header = match ($role) {
            'admin' => 'aacp.menu.administrators',
            'member' => 'aacp.menu.members',
            'editor' => 'aacp.menu.editors',
            default => 'aacp.users.header',
        };

        return $this->render('aacp/users/index.html.twig', [
            'users' => $result,
            'search' => $search,
            'roleLabels' => $this->userRoleGuard->roleLabelMap(),
            'filterRole' => $role,
            'listRoute' => $listRoute,
            'listHeader' => $header,
        ]);
    }

    private function paginateRoleList(string $role, string $search, int $page): PaginatedResult
    {
        $needle = mb_strtolower(trim($search));
        $filtered = [];
        foreach ($this->userRepository->findByRole($role) as $user) {
            if ($needle === '' || str_contains(mb_strtolower($user->getEmail()), $needle)) {
                $filtered[] = $user;
            }
        }

        $page = max(1, $page);
        $offset = ($page - 1) * self::ADMIN_PER_PAGE;

        return new PaginatedResult(
            array_values(array_slice($filtered, $offset, self::ADMIN_PER_PAGE)),
            \count($filtered),
            $page,
            self::ADMIN_PER_PAGE,
        );
    }
}
