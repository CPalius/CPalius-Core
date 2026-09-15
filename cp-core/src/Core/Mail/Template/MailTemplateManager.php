<?php

declare(strict_types=1);

namespace App\Core\Mail\Template;

use App\Core\Localization\LocaleDefinition;
use App\Core\Localization\LocaleProvider;
use App\Core\Mail\Entity\MailTemplate;
use App\Core\Mail\Repository\MailTemplateRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Read and write side of the AACP mail-template screen.
 *
 * Sits between the controller and the two stores the screen shows at once — the
 * operator's rows and the shipped catalogue — so the controller never has to
 * know which of the two a given textarea came from.
 *
 * Deliberately NOT sanitising the HTML body. Mail HTML is not site HTML: an
 * operator writing a mail legitimately needs tables and inline styles, which is
 * exactly what RichTextSanitizer strips, and the capability that opens this
 * screen (`system.settings.manage`) already permits changing the SMTP host the
 * site authenticates against. Sanitising here would break the honest use and
 * stop nothing.
 */
final class MailTemplateManager
{
    public function __construct(
        private readonly MailTemplateRegistry $registry,
        private readonly MailTemplateRepository $templates,
        private readonly MailTemplateRenderer $renderer,
        private readonly LocaleProvider $localeProvider,
        private readonly EntityManagerInterface $entityManager,
        private readonly MailTemplateSchema $schema,
    ) {
    }

    /**
     * Makes sure the table this screen edits exists. The admin screen is the
     * first place the patch's missing table would be noticed, so it is the
     * right place to fix it rather than report it.
     */
    public function ensureSchema(): bool
    {
        return $this->schema->ensure();
    }

    /**
     * @return list<LocaleDefinition>
     */
    public function locales(): array
    {
        return $this->localeProvider->getLocales();
    }

    /**
     * One row per declared template, with the languages an operator has already
     * rewritten. Everything else on the screen is derived from this.
     *
     * @return list<array{definition: MailTemplateDefinition, customised: list<string>, missing: list<string>}>
     */
    public function overview(): array
    {
        $customisedByKey = $this->templates->customisedLocalesByKey();
        $codes = $this->localeProvider->getCodes();
        $rows = [];

        foreach ($this->registry->all() as $definition) {
            $customised = array_values(array_intersect($codes, $customisedByKey[$definition->key] ?? []));

            $rows[] = [
                'definition' => $definition,
                'customised' => $customised,
                'missing' => array_values(array_diff($codes, $customised)),
            ];
        }

        return $rows;
    }

    /**
     * Editor state for every active locale of one template.
     *
     * A locale with no row is pre-filled with the shipped default rather than
     * left blank — an operator editing Turkish should see the Turkish wording
     * that is going out today, not an empty box that looks like a bug.
     *
     * @return array<string, array{subject: string, html: string, text: string, enabled: bool, customised: bool}>
     */
    public function editorRows(MailTemplateDefinition $definition): array
    {
        $stored = $this->templates->findByKeyIndexedByLocale($definition->key);
        $rows = [];

        foreach ($this->localeProvider->getCodes() as $code) {
            $row = $stored[$code] ?? null;

            if ($row instanceof MailTemplate && !$row->isBlank()) {
                $rows[$code] = [
                    'subject' => $row->getSubject(),
                    'html' => $row->getBodyHtml(),
                    'text' => (string) $row->getBodyText(),
                    'enabled' => $row->isEnabled(),
                    'customised' => true,
                ];

                continue;
            }

            $defaults = $this->renderer->shippedDefaults($definition, $code);

            $rows[$code] = [
                'subject' => $defaults['subject'],
                'html' => $defaults['html'],
                'text' => $defaults['text'],
                'enabled' => true,
                'customised' => false,
            ];
        }

        return $rows;
    }

    /**
     * @return array{subject: string, html: string, text: string}
     */
    public function shippedDefaults(MailTemplateDefinition $definition, string $locale): array
    {
        return $this->renderer->shippedDefaults($definition, $locale);
    }

    /**
     * Stores one language of one template. A submission that matches the
     * shipped default exactly deletes the row instead of writing it: the
     * difference matters, because a stored copy stops tracking the catalogue
     * and would keep the old wording through an upgrade that improved it.
     *
     * @throws \InvalidArgumentException on an unknown key or an inactive locale
     */
    public function save(
        string $key,
        string $locale,
        string $subject,
        string $html,
        ?string $text,
        bool $enabled = true,
    ): void {
        $definition = $this->assertKnown($key, $locale);

        $subject = trim($subject);
        $html = trim($html);
        $text = $text === null ? null : trim($text);

        $row = $this->templates->findOneFor($key, $locale);
        $defaults = $this->renderer->shippedDefaults($definition, $locale);

        $matchesShipped = $subject === trim($defaults['subject'])
            && $html === trim($defaults['html'])
            && ($text === null || $text === '' || $text === trim($defaults['text']));

        if ($subject === '' || $html === '' || $matchesShipped) {
            if ($row instanceof MailTemplate) {
                $this->entityManager->remove($row);
                $this->entityManager->flush();
            }

            return;
        }

        if (!$row instanceof MailTemplate) {
            $row = new MailTemplate($key, $locale);
            $this->entityManager->persist($row);
        }

        $row->setSubject($subject)
            ->setBodyHtml($html)
            ->setBodyText($text)
            ->setEnabled($enabled);

        $this->entityManager->flush();
    }

    /**
     * Drops the operator's copy so the shipped wording takes over again.
     * A locale of null resets every language of the template.
     */
    public function reset(string $key, ?string $locale = null): int
    {
        $rows = $locale === null
            ? array_values($this->templates->findByKeyIndexedByLocale($key))
            : array_filter([$this->templates->findOneFor($key, $locale)]);

        foreach ($rows as $row) {
            $this->entityManager->remove($row);
        }

        if ($rows !== []) {
            $this->entityManager->flush();
        }

        return \count($rows);
    }

    private function assertKnown(string $key, string $locale): MailTemplateDefinition
    {
        $definition = $this->registry->get($key);

        if ($definition === null) {
            throw new \InvalidArgumentException(sprintf('Unknown mail template "%s".', $key));
        }

        if (!$this->localeProvider->isSupported($locale)) {
            throw new \InvalidArgumentException(sprintf('Locale "%s" is not active.', $locale));
        }

        return $definition;
    }
}
