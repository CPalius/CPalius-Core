<?php

declare(strict_types=1);

namespace App\Core\Mail\Entity;

use App\Core\Mail\Repository\MailTemplateRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One operator-edited mail body, in one language.
 *
 * Why a table and not the translation catalogue it overrides: the catalogue
 * ships WITH the release. `cp-content/translations/*.yaml` is a package file,
 * so the next update overwrites it — an operator who rewrote the welcome mail
 * there would silently lose the rewrite on the first patch. Rows here survive
 * updates the same way settings do.
 *
 * Why a table and not a settings blob: `cp_settings.setting_value` is TEXT, and
 * one HTML body per template per locale outgrows 64 KiB long before a site with
 * five languages runs out of mails to send. Splitting it across key-per-field
 * settings rows would be the postmeta trap the manifesto rules out (Law 6.3).
 *
 * A missing row is not an error — MailTemplateRenderer falls back to the
 * catalogue — so an installation that has never opened the screen behaves
 * exactly as it did before this table existed.
 */
#[ORM\Entity(repositoryClass: MailTemplateRepository::class)]
#[ORM\Table(name: 'cp_mail_templates')]
#[ORM\UniqueConstraint(name: 'uniq_mail_template_key_locale', columns: ['template_key', 'locale'])]
#[ORM\Index(columns: ['template_key'], name: 'idx_mail_template_key')]
class MailTemplate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /**
     * Registry key (e.g. "account.verify"), not a translation key. The two are
     * related by MailTemplateDefinition, which is the only place that mapping
     * lives.
     */
    #[ORM\Column(name: 'template_key', type: 'string', length: 100)]
    private string $templateKey;

    #[ORM\Column(type: 'string', length: 5)]
    private string $locale;

    #[ORM\Column(type: 'string', length: 255)]
    private string $subject = '';

    #[ORM\Column(name: 'body_html', type: 'text')]
    private string $bodyHtml = '';

    /**
     * Plain-text alternative. Null means "derive it from the HTML at send time"
     * rather than "send an empty text part".
     */
    #[ORM\Column(name: 'body_text', type: 'text', nullable: true)]
    private ?string $bodyText = null;

    /**
     * An operator turning one language off falls back to the default locale
     * rather than sending a half-translated mail.
     */
    #[ORM\Column(type: 'boolean')]
    private bool $enabled = true;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $templateKey, string $locale)
    {
        $this->templateKey = $templateKey;
        $this->locale = $locale;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTemplateKey(): string
    {
        return $this->templateKey;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function setSubject(string $subject): static
    {
        $this->subject = mb_substr(trim($subject), 0, 255);

        return $this->touch();
    }

    public function getBodyHtml(): string
    {
        return $this->bodyHtml;
    }

    public function setBodyHtml(string $bodyHtml): static
    {
        $this->bodyHtml = trim($bodyHtml);

        return $this->touch();
    }

    public function getBodyText(): ?string
    {
        return $this->bodyText;
    }

    public function setBodyText(?string $bodyText): static
    {
        $trimmed = $bodyText === null ? null : trim($bodyText);
        $this->bodyText = $trimmed === '' ? null : $trimmed;

        return $this->touch();
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): static
    {
        $this->enabled = $enabled;

        return $this->touch();
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * True when the row carries nothing worth sending. Such a row is treated as
     * absent so that clearing every field on the screen is a "reset to the
     * shipped default" rather than a way to mail someone a blank page.
     */
    public function isBlank(): bool
    {
        return $this->subject === '' && $this->bodyHtml === '';
    }

    private function touch(): static
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
