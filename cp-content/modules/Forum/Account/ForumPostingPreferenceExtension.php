<?php

declare(strict_types=1);

namespace Modules\Forum\Account;

use App\Core\Account\AccountProfileExtensionInterface;
use App\Core\Settings\SettingsRegistry;
use App\Entity\User;
use Modules\Forum\Service\ForumWordFilterService;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;

/**
 * The two forum preferences a member owns about their own posting: the signature
 * shown under their posts, and the title terms they never want to see in a list.
 *
 * Both hang off the account profile screen through the core extension point
 * rather than getting a page of their own — a module that needs one more field
 * on an existing form should not be minting routes.
 */
final class ForumPostingPreferenceExtension implements AccountProfileExtensionInterface
{
    public const FIELD_SIGNATURE = 'forumSignature';
    public const FIELD_WORD_FILTERS = 'forumWordFilters';

    /** User::setSignature() truncates here, so the form must not promise more. */
    private const SIGNATURE_HARD_CAP = 500;

    public function __construct(
        private readonly SettingsRegistry $settings,
        private readonly ForumWordFilterService $wordFilter,
    ) {
    }

    public function section(): array
    {
        return [
            'id' => 'forum_posting',
            'legendKey' => 'account.profile.section_forum_posting',
            'hintKey' => 'account.profile.forum_posting_hint',
            'fields' => $this->enabledFields(),
        ];
    }

    public function buildForm(FormBuilderInterface $builder): void
    {
        if ($this->signaturesEnabled()) {
            $builder->add(self::FIELD_SIGNATURE, TextareaType::class, [
                'label' => 'account.profile.forum_signature',
                'help' => 'account.profile.forum_signature_help',
                'help_translation_parameters' => ['limit' => $this->signatureLimit()],
                'required' => false,
                'mapped' => false,
                'attr' => ['rows' => 3, 'maxlength' => $this->signatureLimit()],
            ]);
        }

        if ($this->wordFilter->isEnabled()) {
            $builder->add(self::FIELD_WORD_FILTERS, TextareaType::class, [
                'label' => 'account.profile.forum_word_filters',
                'help' => 'account.profile.forum_word_filters_help',
                'help_translation_parameters' => ['limit' => $this->wordFilter->maxTerms()],
                'required' => false,
                'mapped' => false,
                'attr' => ['rows' => 4, 'placeholder' => "yapay zeka\nkripto"],
            ]);
        }
    }

    public function valuesFromUser(User $user): array
    {
        $values = [];

        if ($this->signaturesEnabled()) {
            $values[self::FIELD_SIGNATURE] = $user->getSignature();
        }

        if ($this->wordFilter->isEnabled()) {
            $values[self::FIELD_WORD_FILTERS] = $this->wordFilter->asText($user);
        }

        return $values;
    }

    public function saveToUser(User $user, FormInterface $form): void
    {
        if ($form->has(self::FIELD_SIGNATURE)) {
            // Plain text on purpose: the postbit prints the signature escaped, so
            // markup here would show as angle brackets rather than as formatting,
            // and a rich-text signature is a link-farm surface nobody moderates.
            $signature = trim((string) $form->get(self::FIELD_SIGNATURE)->getData());
            $user->setSignature(mb_substr($signature, 0, $this->signatureLimit()));
        }

        if ($form->has(self::FIELD_WORD_FILTERS)) {
            // Writes through the entity; the shared flush in the controller commits it.
            $user->setDataValue(
                ForumWordFilterService::DATA_KEY,
                $this->wordFilter->parse((string) $form->get(self::FIELD_WORD_FILTERS)->getData()),
            );
        }
    }

    /**
     * @return list<string>
     */
    private function enabledFields(): array
    {
        $fields = [];

        if ($this->signaturesEnabled()) {
            $fields[] = self::FIELD_SIGNATURE;
        }

        if ($this->wordFilter->isEnabled()) {
            $fields[] = self::FIELD_WORD_FILTERS;
        }

        return $fields;
    }

    private function signaturesEnabled(): bool
    {
        return (bool) $this->settings->get('forum.signatures_enabled', true);
    }

    private function signatureLimit(): int
    {
        $configured = (int) $this->settings->get('forum.signature_max_length', 300);

        return max(1, min(self::SIGNATURE_HARD_CAP, $configured));
    }
}
