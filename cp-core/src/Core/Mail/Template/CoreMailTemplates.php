<?php

declare(strict_types=1);

namespace App\Core\Mail\Template;

/**
 * The mails core itself sends: the account lifecycle plus the two notification
 * envelopes.
 *
 * Each entry names the translation keys it shipped with. Those stay the
 * fallback forever — deleting every row on the AACP screen returns the site to
 * exactly the text this release was tested with, which is the property that
 * makes the screen safe to experiment on.
 */
final class CoreMailTemplates implements MailTemplateProviderInterface
{
    public const ACCOUNT_VERIFY = 'account.verify';
    public const ACCOUNT_WELCOME = 'account.welcome';
    public const ACCOUNT_PENDING_APPROVAL = 'account.pending_approval';
    public const ACCOUNT_APPROVED = 'account.approved';
    public const IDENTITY_SUBMITTED = 'account.identity_change.submitted';
    public const IDENTITY_APPROVED = 'account.identity_change.approved';
    public const IDENTITY_REJECTED = 'account.identity_change.rejected';
    public const ACCOUNT_TWO_FACTOR_CODE = 'account.two_factor_code';
    public const NOTIFICATION_INSTANT = 'notification.instant';
    public const NOTIFICATION_DIGEST = 'notification.digest';

    public function mailTemplates(): array
    {
        return [
            new MailTemplateDefinition(
                key: self::ACCOUNT_VERIFY,
                labelKey: 'aacp.mail_templates.tpl.account_verify.label',
                descriptionKey: 'aacp.mail_templates.tpl.account_verify.description',
                subjectKey: 'account.verify.email_subject',
                htmlKey: 'account.verify.email_body_html',
                textKey: 'account.verify.email_body_text',
                parameters: ['url'],
            ),
            new MailTemplateDefinition(
                key: self::ACCOUNT_WELCOME,
                labelKey: 'aacp.mail_templates.tpl.account_welcome.label',
                descriptionKey: 'aacp.mail_templates.tpl.account_welcome.description',
                subjectKey: 'account.mail.welcome.subject',
                htmlKey: 'account.mail.welcome.body_html',
                textKey: 'account.mail.welcome.body_text',
                parameters: ['login_url'],
            ),
            new MailTemplateDefinition(
                key: self::ACCOUNT_PENDING_APPROVAL,
                labelKey: 'aacp.mail_templates.tpl.account_pending.label',
                descriptionKey: 'aacp.mail_templates.tpl.account_pending.description',
                subjectKey: 'account.mail.pending_approval.subject',
                htmlKey: 'account.mail.pending_approval.body_html',
                textKey: 'account.mail.pending_approval.body_text',
            ),
            new MailTemplateDefinition(
                key: self::ACCOUNT_APPROVED,
                labelKey: 'aacp.mail_templates.tpl.account_approved.label',
                descriptionKey: 'aacp.mail_templates.tpl.account_approved.description',
                subjectKey: 'account.mail.approved.subject',
                htmlKey: 'account.mail.approved.body_html',
                textKey: 'account.mail.approved.body_text',
                parameters: ['login_url'],
            ),
            new MailTemplateDefinition(
                key: self::IDENTITY_SUBMITTED,
                labelKey: 'aacp.mail_templates.tpl.identity_submitted.label',
                descriptionKey: 'aacp.mail_templates.tpl.identity_submitted.description',
                subjectKey: 'account.mail.identity_submitted.subject',
                htmlKey: 'account.mail.identity_submitted.body_html',
                textKey: 'account.mail.identity_submitted.body_text',
                parameters: ['new_email', 'new_username'],
            ),
            new MailTemplateDefinition(
                key: self::IDENTITY_APPROVED,
                labelKey: 'aacp.mail_templates.tpl.identity_approved.label',
                descriptionKey: 'aacp.mail_templates.tpl.identity_approved.description',
                subjectKey: 'account.mail.identity_approved.subject',
                htmlKey: 'account.mail.identity_approved.body_html',
                textKey: 'account.mail.identity_approved.body_text',
                parameters: ['new_email', 'new_username', 'login_url'],
            ),
            new MailTemplateDefinition(
                key: self::IDENTITY_REJECTED,
                labelKey: 'aacp.mail_templates.tpl.identity_rejected.label',
                descriptionKey: 'aacp.mail_templates.tpl.identity_rejected.description',
                subjectKey: 'account.mail.identity_rejected.subject',
                htmlKey: 'account.mail.identity_rejected.body_html',
                textKey: 'account.mail.identity_rejected.body_text',
                parameters: ['reason'],
            ),
            new MailTemplateDefinition(
                key: self::ACCOUNT_TWO_FACTOR_CODE,
                labelKey: 'aacp.mail_templates.tpl.two_factor_code.label',
                descriptionKey: 'aacp.mail_templates.tpl.two_factor_code.description',
                subjectKey: 'account.two_factor.mail.subject',
                htmlKey: 'account.two_factor.mail.body_html',
                textKey: 'account.two_factor.mail.body_text',
                parameters: ['code', 'minutes', 'ip'],
            ),
            new MailTemplateDefinition(
                key: self::NOTIFICATION_INSTANT,
                labelKey: 'aacp.mail_templates.tpl.notification_instant.label',
                descriptionKey: 'aacp.mail_templates.tpl.notification_instant.description',
                subjectKey: 'notification.mail.envelope.instant_subject',
                htmlKey: 'notification.mail.envelope.instant_body_html',
                parameters: ['content', 'inbox_url', 'subject'],
            ),
            new MailTemplateDefinition(
                key: self::NOTIFICATION_DIGEST,
                labelKey: 'aacp.mail_templates.tpl.notification_digest.label',
                descriptionKey: 'aacp.mail_templates.tpl.notification_digest.description',
                subjectKey: 'notification.mail.subject.digest',
                htmlKey: 'notification.mail.envelope.digest_body_html',
                parameters: ['content', 'inbox_url', 'count'],
            ),
        ];
    }
}
