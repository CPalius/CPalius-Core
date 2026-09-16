<?php

declare(strict_types=1);

namespace Modules\Messages\Mail;

use App\Core\Mail\Template\MailTemplateDefinition;
use App\Core\Mail\Template\MailTemplateProviderInterface;

final class MessagesMailTemplates implements MailTemplateProviderInterface
{
    public const NEW_MESSAGE = 'messages.new';

    public function mailTemplates(): array
    {
        return [
            new MailTemplateDefinition(
                key: self::NEW_MESSAGE,
                labelKey: 'messages.mail.new.label',
                descriptionKey: 'messages.mail.new.description',
                subjectKey: 'messages.mail.new.subject',
                htmlKey: 'messages.mail.new.body_html',
                textKey: 'messages.mail.new.body_text',
                parameters: ['actor_name', 'preview', 'url'],
            ),
        ];
    }
}
