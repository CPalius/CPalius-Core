<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\Account\AccountIdentityChangeService;
use App\Core\Account\AccountLandingResolver;
use App\Core\Account\AccountProfileExtensionInterface;
use App\Core\Account\UserAvatarService;
use App\Core\Field\FieldValuePersister;
use App\Core\Localization\LocaleProvider;
use App\Core\Localization\Service\UserLocaleResolver;
use App\Core\Media\Exception\InvalidUploadException;
use App\Core\Media\Exception\UnsupportedAssetTypeException;
use App\Core\Security\Flood\FloodService;
use App\Core\Security\Password\PasswordChanger;
use App\Core\Security\Password\PasswordPolicy;
use App\Entity\User;
use App\Form\AccountProfileType;
use App\Form\DTO\AccountProfileFormModel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Site-wide account profile: avatar, preferences, password. No AACP/media picker required.
 */
final class AccountProfileController extends AbstractController
{
    private const AVATAR_FLOOD_EVENT = 'avatar_upload';
    private const AVATAR_LIMIT = 10;
    private const AVATAR_WINDOW = 3600;

    /**
     * @param iterable<AccountProfileExtensionInterface> $profileExtensions
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly PasswordPolicy $passwordPolicy,
        private readonly PasswordChanger $passwordChanger,
        private readonly UserAvatarService $avatarService,
        private readonly TranslatorInterface $translator,
        private readonly AccountLandingResolver $landingResolver,
        private readonly FloodService $flood,
        private readonly AccountIdentityChangeService $identityChanges,
        private readonly UserLocaleResolver $userLocale,
        private readonly LocaleProvider $localeProvider,
        private readonly FieldValuePersister $fieldValuePersister,
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
        $dto->locale = $this->userLocale->resolve($user);
        $form = $this->createForm(AccountProfileType::class, $dto, [
            'locale_choices' => $this->localeChoices(),
            'field_locale' => $request->getLocale(),
        ]);
        if ($form->has('fields')) {
            $data = $user->getFieldableData();
            $prefill = [];
            foreach ($form->get('fields')->all() as $name => $child) {
                if (\array_key_exists($name, $data)) {
                    $prefill[$name] = $data[$name];
                }
            }
            $form->get('fields')->setData($prefill);
        }
        foreach ($this->profileExtensions as $extension) {
            foreach ($extension->valuesFromUser($user) as $field => $value) {
                if ($form->has($field)) {
                    $form->get($field)->setData($value);
                }
            }
        }
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Identity first: it is the only part of this form that may be
            // refused or deferred, and the rest must not be saved on the
            // strength of a change that was not accepted.
            $identityErrors = $this->identityChanges->submit($user, $dto->email, $dto->username);

            if ($identityErrors !== []) {
                foreach ($identityErrors as $error) {
                    $form->get('email')->addError(new FormError($error));
                }
            } elseif (!$this->applyPasswordChangeIfRequested($dto, $user, $form)) {
                // errors added to form
            } else {
                $this->mapDtoToUser($dto, $user, $form);
                $this->entityManager->flush();

                $pending = $this->identityChanges->pendingFor($user);

                $this->addFlash('success', $pending !== null
                    ? $this->translator->trans('account.profile.saved_identity_pending')
                    : $this->translator->trans('account.profile.saved'));

                return $this->redirectToRoute('account_profile');
            }
        }

        return $this->render('account/profile.html.twig', [
            'form' => $form,
            'avatarUrl' => $this->avatarService->resolveUrl($user),
            'accountHomeRoute' => $this->landingResolver->routeName(),
            'identityPending' => $this->identityChanges->pendingFor($user),
            'identityApprovalRequired' => $this->identityChanges->isApprovalRequired(),
        ]);
    }

    /**
     * Withdraws the member's own outstanding e-mail/username change.
     */
    #[Route('/hesap/profil/kimlik-talebi/iptal', name: 'account_identity_request_cancel', methods: ['POST'])]
    #[IsGranted('account.profile.edit')]
    public function cancelIdentityRequest(Request $request): Response
    {
        $user = $this->getCurrentUserOrFail();

        if (!$this->isCsrfTokenValid('account_identity_cancel', (string) $request->request->get('_token'))) {
            throw new BadRequestHttpException($this->translator->trans('account.invalid_csrf'));
        }

        if ($this->identityChanges->cancel($user)) {
            $this->addFlash('success', $this->translator->trans('account.profile.identity_request_cancelled'));
        }

        return $this->redirectToRoute('account_profile');
    }

    /**
     * @return array<string, string> label => locale code, for the preference select
     */
    private function localeChoices(): array
    {
        $choices = [];

        foreach ($this->localeProvider->getLocales() as $locale) {
            $choices[$locale->nativeName !== '' ? $locale->nativeName : $locale->name] = $locale->code;
        }

        return $choices;
    }

    #[Route('/hesap/profil/avatar', name: 'account_avatar_upload', methods: ['POST'])]
    #[IsGranted('account.avatar.upload')]
    public function uploadAvatar(Request $request): Response
    {
        $user = $this->getCurrentUserOrFail();

        if (!$this->isCsrfTokenValid('account_avatar_upload', (string) $request->request->get('_token'))) {
            throw new BadRequestHttpException($this->translator->trans('account.invalid_csrf'));
        }

        // Per-account upload cap: each distinct image is kept; replacing does not delete the old file.
        $floodKey = (string) $user->getId();
        if ($floodKey !== '' && !$this->flood->isAllowed(self::AVATAR_FLOOD_EVENT, $floodKey, self::AVATAR_LIMIT, self::AVATAR_WINDOW)) {
            throw new TooManyRequestsHttpException(null, $this->translator->trans('account.profile.avatar_too_many'));
        }
        if ($floodKey !== '') {
            $this->flood->register(self::AVATAR_FLOOD_EVENT, $floodKey, self::AVATAR_WINDOW);
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
            $url = $this->avatarService->urlForAsset($asset);

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

    /**
     * E-mail and username are absent on purpose: AccountIdentityChangeService
     * owns them now, and writing them here as well would apply the change the
     * approval queue was asked to hold.
     */
    private function mapDtoToUser(AccountProfileFormModel $dto, User $user, FormInterface $form): void
    {
        $user->setFirstName(trim($dto->firstName));
        $user->setLastName(trim($dto->lastName));
        $user->setLocation(trim($dto->location));
        $this->userLocale->remember($user, $dto->locale);

        if ($form->has('fields')) {
            $submitted = $form->get('fields')->getData();
            $this->fieldValuePersister->persist($user, \is_array($submitted) ? $submitted : []);
        }

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
