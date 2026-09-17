<?php

declare(strict_types=1);

namespace App\Core\Mail\Template;

use Doctrine\DBAL\Connection;

/**
 * The operator-created mail templates: their keys, names and purpose.
 *
 * DBAL rather than an ORM entity because this is read on every registry build —
 * including the ones behind a transactional account mail — and hydrating three
 * scalar columns through the entity manager there would buy nothing.
 */
final class CustomMailTemplateStore
{
    /** Keys are namespaced so a custom template can never shadow a shipped one. */
    public const KEY_PREFIX = 'custom.';

    /** Matches the VARCHAR(100) column, prefix included. */
    private const MAX_KEY_LENGTH = 100;

    /** @var list<array{key: string, label: string, description: string}>|null */
    private ?array $memo = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly CustomMailTemplateSchema $schema,
    ) {
    }

    /**
     * @return list<array{key: string, label: string, description: string}>
     */
    public function all(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        try {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT template_key, label, description FROM cp_mail_custom_templates ORDER BY label ASC',
            );
        } catch (\Throwable) {
            // The table is created lazily, so "missing" is the normal state of a
            // site that never added a custom template. Creating it here stops
            // the failing SELECT from repeating on every later request.
            $this->schema->ensure();

            return $this->memo = [];
        }

        $templates = [];

        foreach ($rows as $row) {
            $templates[] = [
                'key' => (string) $row['template_key'],
                'label' => (string) $row['label'],
                'description' => (string) $row['description'],
            ];
        }

        return $this->memo = $templates;
    }

    public function exists(string $key): bool
    {
        foreach ($this->all() as $template) {
            if ($template['key'] === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * Creates one, and returns the key it was stored under.
     *
     * @throws \RuntimeException when the name yields no usable key, the key is
     *                           taken, or the table could not be created
     */
    public function create(string $label, string $description): string
    {
        if (!$this->schema->ensure()) {
            throw new \RuntimeException('The custom mail template table could not be created.');
        }

        $label = trim($label);

        if ($label === '') {
            throw new \RuntimeException('A custom mail template needs a name.');
        }

        $key = $this->buildKey($label);

        if ($this->exists($key)) {
            throw new \RuntimeException(sprintf('A custom mail template named "%s" already exists.', $label));
        }

        $this->connection->insert('cp_mail_custom_templates', [
            'template_key' => $key,
            'label' => mb_substr($label, 0, 190),
            'description' => mb_substr(trim($description), 0, 500),
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $this->memo = null;

        return $key;
    }

    /**
     * Removes the template itself. The caller is responsible for its wording
     * rows: dropping those is a separate decision, and doing it here would make
     * this class reach into a table it does not own.
     */
    public function delete(string $key): void
    {
        try {
            $this->connection->delete('cp_mail_custom_templates', ['template_key' => $key]);
        } catch (\Throwable) {
            // Nothing to remove when the table was never created.
        }

        $this->memo = null;
    }

    /**
     * Slug from the name, prefixed. Derived rather than typed because the key
     * is what the URL and every stored wording row hang off: an operator should
     * not have to invent a stable identifier to write one mail.
     */
    private function buildKey(string $label): string
    {
        $slug = strtolower($label);

        // Turkish letters first: the transliteration below would otherwise drop
        // them entirely and turn "Duyuru Şablonu" into "duyuru-ablonu".
        $slug = strtr($slug, [
            'ı' => 'i', 'İ' => 'i', 'ğ' => 'g', 'Ğ' => 'g', 'ü' => 'u', 'Ü' => 'u',
            'ş' => 's', 'Ş' => 's', 'ö' => 'o', 'Ö' => 'o', 'ç' => 'c', 'Ç' => 'c',
        ]);

        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug) ?? '';
        $slug = trim($slug, '_');

        if ($slug === '') {
            // A name written entirely in a script the slug cannot carry still
            // has to produce a key, and the operator sees the label anyway.
            $slug = 'template_'.substr(bin2hex(random_bytes(4)), 0, 8);
        }

        return self::KEY_PREFIX.mb_substr($slug, 0, self::MAX_KEY_LENGTH - \strlen(self::KEY_PREFIX));
    }
}
