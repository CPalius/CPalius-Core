<?php

declare(strict_types=1);

namespace App\Core\Mail\Template;

/**
 * Every mail the running installation knows how to send, collected from the
 * tagged providers.
 *
 * Unknown key = not offered and not rendered, deliberately: a mail key that no
 * provider declares is either a typo or code from a module that has been turned
 * off, and inventing a template for it would mail users text nobody reviewed.
 */
final class MailTemplateRegistry
{
    /** @var array<string, MailTemplateDefinition>|null */
    private ?array $memo = null;

    /**
     * @param iterable<MailTemplateProviderInterface> $providers
     */
    public function __construct(
        private readonly iterable $providers,
    ) {
    }

    /**
     * @return array<string, MailTemplateDefinition> keyed by template key, in declaration order
     */
    public function all(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $definitions = [];

        foreach ($this->providers as $provider) {
            foreach ($provider->mailTemplates() as $definition) {
                // First declaration wins: core ships before modules are
                // iterated, so a module cannot quietly take over an account
                // mail by reusing its key.
                $definitions[$definition->key] ??= $definition;
            }
        }

        return $this->memo = $definitions;
    }

    public function get(string $key): ?MailTemplateDefinition
    {
        return $this->all()[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->all()[$key]);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->all());
    }
}
