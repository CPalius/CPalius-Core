<?php

declare(strict_types=1);

namespace Modules\Messages\Hooks;

use App\Core\Hook\Attribute\CpHook;
use App\Core\Hook\HookContext;
use App\Entity\User;
use Modules\Messages\Inbox\MessagesInboxPulseChannel;
use Modules\Messages\Service\MessagesConfig;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Messages attaches itself to hook points other modules publish.
 *
 * This class never imports Forum or Showcase. If those modules are absent the
 * listeners are simply never called.
 */
final class MessagesAttributeHooks
{
    public function __construct(
        private readonly MessagesConfig $config,
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly TranslatorInterface $translator,
        private readonly MessagesInboxPulseChannel $pulse,
        private readonly Environment $twig,
    ) {
    }

    #[CpHook('forum.user.actions', priority: 40)]
    public function onForumUserActions(HookContext $context): HookContext
    {
        $target = $context->get('user');
        if (!$target instanceof User) {
            return $context;
        }

        return $this->appendComposeLink($context, $target, 'messages.action.send', [
            'context_type' => 'forum_user',
            'context_id' => (string) $target->getId(),
            'context_label' => $target->getPublicDisplayName(),
        ], 'btn btn-sm btn-outline messages-hook messages-hook--forum-user');
    }

    #[CpHook('forum.post.author.actions', priority: 40)]
    public function onForumPostAuthor(HookContext $context): HookContext
    {
        $target = $context->get('user') ?? $context->get('author');
        if (!$target instanceof User) {
            return $context;
        }

        return $this->appendComposeLink($context, $target, 'messages.action.send', [
            'context_type' => 'forum_user',
            'context_id' => (string) $target->getId(),
            'context_label' => $target->getPublicDisplayName(),
        ], 'forum-tool-btn forum-postbit__pm messages-hook--forum-post', iconOnly: true);
    }

    #[CpHook('showcase.item.actions', priority: 40)]
    public function onShowcaseItemActions(HookContext $context): HookContext
    {
        $owner = $context->get('owner') ?? $context->get('user');
        if (!$owner instanceof User) {
            return $context;
        }

        $label = trim((string) $context->get('label', ''));
        $itemId = $context->get('item_id') ?? $context->get('id');
        $url = trim((string) $context->get('url', ''));

        return $this->appendComposeLink($context, $owner, 'messages.action.contact_seller', [
            'context_type' => 'showcase_item',
            'context_id' => is_numeric($itemId) ? (string) (int) $itemId : '',
            'context_label' => $label !== '' ? $label : $owner->getPublicDisplayName(),
            'context_url' => $url,
        ], 'showcase-btn showcase-btn--block messages-hook--showcase', '<p style="margin-top:.75rem">', '</p>');
    }

    #[CpHook('theme.header.alerts', priority: 40)]
    public function onHeaderAlerts(HookContext $context): HookContext
    {
        return $this->appendPulseFragment($context, '@MessagesModule/front/partials/header_alert.html.twig');
    }

    /**
     * The unread count on the avatar. Separate from the tab because the two sit
     * in different places in the markup: the badge is on the closed trigger,
     * the tab only exists once the menu is open.
     */
    #[CpHook('theme.header.user_badge', priority: 40)]
    public function onHeaderUserBadge(HookContext $context): HookContext
    {
        return $this->appendPulseFragment($context, '@MessagesModule/front/partials/header_badge.html.twig');
    }

    private function appendPulseFragment(HookContext $context, string $template): HookContext
    {
        $viewer = $this->security->getUser();
        if (!$this->config->enabled() || !$viewer instanceof User || !$this->security->isGranted('messages.send')) {
            return $context;
        }

        try {
            $html = $this->twig->render($template, [
                'channel' => $this->pulse->pulse($viewer),
            ]);
        } catch (\Throwable) {
            // A header that renders without the messages badge beats a header
            // that does not render at all.
            return $context;
        }

        return $context->appendHtml($html);
    }

    /**
     * @param array<string, string> $params
     */
    private function appendComposeLink(
        HookContext $context,
        User $target,
        string $labelKey,
        array $params,
        string $class,
        string $wrapStart = '',
        string $wrapEnd = '',
        bool $iconOnly = false,
    ): HookContext
    {
        $viewer = $this->security->getUser();
        if (!$this->config->enabled() || !$viewer instanceof User) {
            return $context;
        }

        if ($viewer->getId() === $target->getId() || !$this->security->isGranted('messages.send')) {
            return $context;
        }

        try {
            $url = $this->urlGenerator->generate('messages_compose', $this->composeParams($target, $params));
        } catch (RoutingException) {
            return $context;
        }

        $label = htmlspecialchars($this->translator->trans($labelKey), ENT_QUOTES, 'UTF-8');
        $class = htmlspecialchars($class, ENT_QUOTES, 'UTF-8');
        $icon = '<i class="bi bi-envelope" aria-hidden="true"></i>';
        $inner = $iconOnly ? $icon : $icon.' '.$label;
        $title = $iconOnly ? ' title="'.$label.'" aria-label="'.$label.'"' : '';

        return $context->appendHtml($wrapStart.'<a href="'.$url.'" class="'.$class.'"'.$title.'>'.$inner.'</a>'.$wrapEnd);
    }

    /**
     * @param array<string, string> $params
     *
     * @return array<string, string>
     */
    private function composeParams(User $target, array $params): array
    {
        $out = ['to' => $target->getUsername() ?: (string) $target->getId()];
        foreach ($params as $key => $value) {
            if ($value !== '') {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
