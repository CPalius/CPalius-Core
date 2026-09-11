<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\Account\AccountLandingResolver;
use App\Core\Account\AccountProfileExtensionInterface;
use App\Core\Account\UserAvatarService;
use App\Core\Media\Exception\InvalidUploadException;
use App\Core\Media\Exception\UnsupportedAssetTypeException;
use App\Core\Security\Password\PasswordChanger;
use App\Core\Security\Password\PasswordPolicy;
use App\Entity\User;
use App\Form\AccountProfileType;
use App\Form\DTO\AccountProfileFormModel;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Site-wide account profile: avatar, preferences, password. No AACP/media picker required.
 */
final class AccountProfileController extends AbstractController
{
    /**
     * @param iterable<AccountProfileExtensionInterface> $profileExtensions
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly PasswordPolicy $passwordPolicy,
        private readonly PasswordChanger $passwordChanger,
        private readonly UserAvatarService $avatarService,
        private readonly TranslatorInterface $translator,
        private readonly AccountLandingResolver $landingResolver,
        #[TaggedIterator('cpalius.account.profile_extension')]
        private readonly iterable $profileExtensions = [],
    ) {
    }

    #[Route('/hesap/profil', name: 'account_profile', methods: ['GET', 'POST'])]
    #[IsGranted('account.profile.edit')]
    public function profile(Request $request): Response
    {
        $user = $this->getCurrentUserOrFail();
        $dto = AccountProfileFormModel::fromUser($user);
        $form = $this->createForm(AccountProfileType::class, $dto);
        foreach ($this->profileExtensions as $extension) {
            foreach ($extension->valuesFromUser($user) as $field => $value) {
                if ($form->has($field)) {
                    $form->get($field)->setData($value);
                }
            }
        }
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($this->userRepository->isEmailTakenByAnotherUser($dto->email, $user->getId())) {
                $form->get('email')->addError(new FormError($this->translator->trans('account.profile.email_taken')));
            } elseif (!$this->applyPasswordChangeIfRequested($dto, $user, $form)) {
                // errors added to form
            } else {
                $this->mapDtoToUser($dto, $user, $form);
                $this->entityManager->flush();

                $this->addFlash('success', $this->translator->trans('account.profile.saved'));

                return $this->redirectToRoute('account_profile');
            }
        }

        return $this->render('account/profile.html.twig', [
            'form' => $form,
            'avatarUrl' => $this->avatarService->resolveUrl($user),
            'accountHomeRoute' => $this->landingResolver->routeName(),
        ]);
    }

    #[Route('/hesap/profil/avatar', name: 'account_avatar_upload', methods: ['POST'])]
    #[IsGranted('account.avatar.upload')]
    public function uploadAvatar(Request $request): Response
    {
        $user = $this->getCurrentUserOrFail();

        if (!$this->isCsrfTokenValid('account_avatar_upload', (string) $request->request->get('_token'))) {
            throw new BadRequestHttpException($this->translator->trans('account.invalid_csrf'));
        }

        $file = $request->files->get('avatar');
        if ($file === null) {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['error' => $this->translator->trans('account.profile.avatar_missing')], 400);
            }
            $this->addFlash('error', $this->translator->trans('account.profile.avatar_missing'));

            return $this->redirectToRoute('account_profile');
        }

        try {
            $asset = $this->avatarService->upload($user, $file);
            $url = '/uploads/'.$asset->getStorageKey();

            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['url' => $url, 'assetId' => $asset->getId()]);
            }

            $this->addFlash('success', $this->translator->trans('account.profile.avatar_updated'));
        } catch (InvalidUploadException|UnsupportedAssetTypeException $e) {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['error' => $this->translator->trans('account.profile.avatar_invalid')], 400);
            }
            $this->addFlash('error', $this->translator->trans('account.profile.avatar_invalid'));
        }

        return $this->redirectToRoute('account_profile');
    }

    #[Route('/hesap/profil/avatar/kaldir', name: 'account_avatar_remove', methods: ['POST'])]
    #[IsGranted('account.avatar.upload')]
    public function removeAvatar(Request $request): Response
    {
        $user = $this->getCurrentUserOrFail();

        if (!$this->isCsrfTokenValid('account_avatar_remove', (string) $request->request->get('_token'))) {
            throw new BadRequestHttpException($this->translator->trans('account.invalid_csrf'));
        }

        $this->avatarService->remove($user);
        $this->addFlash('success', $this->translator->trans('account.profile.avatar_removed'));

        return $this->redirectToRoute('account_profile');
    }

    private function applyPasswordChangeIfRequested(AccountProfileFormModel $dto, User $user, FormInterface $form): bool
    {
        $newPassword = trim((string) $dto->newPassword);
        if ($newPassword === '') {
            return true;
        }

        if (!$this->passwordHasher->isPasswordValid($user, (string) $dto->currentPassword)) {
            $form->get('currentPassword')->addError(
                new FormError($this->translator->trans('account.profile.current_password_wrong')),
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

    private function mapDtoToUser(AccountProfileFormModel $dto, User $user, FormInterface $form): void
    {
        $user->setEmail($dto->email);
        $user->setUsername(trim((string) $dto->username) !== '' ? trim((string) $dto->username) : null);
        $user->setFirstName(trim($dto->firstName));
        $user->setLastName(trim($dto->lastName));

        foreach ($this->profileExtensions as $extension) {
            $extension->saveToUser($user, $form);
        }
    }

    private function getCurrentUserOrFail(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
