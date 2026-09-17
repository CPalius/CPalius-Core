<?php

declare(strict_types=1);

namespace App\Core\Mail\Template;

/**
 * Puts the operator's own templates into the registry alongside the shipped ones.
 *
 * Going through the normal provider tag rather than a parallel path is the
 * whole point: a custom template then gets the per-language editor, the token
 * list, the preview and the renderer's fallback chain without any of those
 * learning that it came from a table.
 *
 * Declared last (low priority) so a custom key can never take over a shipped
 * one — MailTemplateRegistry keeps the first declaration of a key.
 */
final class CustomMailTemplateProvider implements MailTemplateProviderInterface
{
    public function __construct(
        private readonly CustomMailTemplateStore $store,
    ) {
    }

    public function mailTemplates(): array
    {
        $definitions = [];

        foreach ($this->store->all() as $template) {
            $definitions[] = new MailTemplateDefinition(
                key: $template['key'],
                // Literal text, not translation keys: there is no catalogue
                // behind an operator's template. $custom tells every consumer
                // to print these rather than translate them.
                labelKey: $template['label'],
                descriptionKey: $template['description'],
                subjectKey: $template['label'],
                htmlKey: '',
                textKey: null,
                // No sending code means no ICU parameters. Tokens still work:
                // they are resolved from the recipient at send time.
                parameters: [],
                custom: true,
            );
        }

        return $definitions;
    }
}
