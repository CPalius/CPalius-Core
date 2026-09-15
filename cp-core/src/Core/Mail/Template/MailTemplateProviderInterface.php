<?php

declare(strict_types=1);

namespace App\Core\Mail\Template;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Declares the mails one slice or module can send.
 *
 * Tagged rather than listed in core so a module that sends mail gets its own
 * rows on the AACP screen without core learning the module's name — the same
 * rule that kept "whitepaper" out of the core vocabulary.
 */
#[AutoconfigureTag('cpalius.mail.template_provider')]
interface MailTemplateProviderInterface
{
    /**
     * @return list<MailTemplateDefinition>
     */
    public function mailTemplates(): array;
}
