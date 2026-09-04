<?php

declare(strict_types=1);

namespace Modules\Forum\Hooks;

use App\Core\Hook\Attribute\CpHook;
use App\Core\Hook\HookContext;
use Psr\Log\LoggerInterface;

/**
 * Sample #[CpHook] listeners; real notifications come from EventSubscriber.
 * Proves hook points resolve via DI and writes a debug log.
 */
final class ForumAttributeHooks
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    #[CpHook('forum.reply', priority: 200)]
    public function onReply(HookContext $context): HookContext
    {
        $this->logger->debug('forum.reply hook fired');

        return $context;
    }

    #[CpHook('forum.like', priority: 200)]
    public function onLike(HookContext $context): HookContext
    {
        $this->logger->debug('forum.like hook fired');

        return $context;
    }

    #[CpHook('forum.reputation', priority: 200)]
    public function onReputation(HookContext $context): HookContext
    {
        $this->logger->debug('forum.reputation hook fired', [
            'value' => $context->get('value'),
            'reason' => $context->get('reason'),
        ]);

        return $context;
    }

    #[CpHook('forum.quote', priority: 200)]
    public function onQuote(HookContext $context): HookContext
    {
        $this->logger->debug('forum.quote hook fired');

        return $context;
    }
}
