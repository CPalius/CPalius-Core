<?php

declare(strict_types=1);

namespace Modules\DnsTools\Settings;

use App\Core\Annotation\CpSetting;

/**
 * Operator-facing knobs for public DNS/network probes.
 */
#[CpSetting(key: 'dnstools.enabled', label: 'dnstools.setting.enabled', type: 'checkbox', default: '1', module: 'dnstools', group: 'dnstools_general')]
#[CpSetting(key: 'dnstools.cache_ttl', label: 'dnstools.setting.cache_ttl', type: 'integer', default: '120', module: 'dnstools', group: 'dnstools_performance')]
#[CpSetting(key: 'dnstools.rate_limit_minute', label: 'dnstools.setting.rate_limit_minute', type: 'integer', default: '20', module: 'dnstools', group: 'dnstools_security')]
#[CpSetting(key: 'dnstools.rate_limit_hour', label: 'dnstools.setting.rate_limit_hour', type: 'integer', default: '200', module: 'dnstools', group: 'dnstools_security')]
#[CpSetting(key: 'dnstools.probe_timeout', label: 'dnstools.setting.probe_timeout', type: 'integer', default: '5', module: 'dnstools', group: 'dnstools_security')]
#[CpSetting(key: 'dnstools.max_bulk', label: 'dnstools.setting.max_bulk', type: 'integer', default: '10', module: 'dnstools', group: 'dnstools_security')]
#[CpSetting(key: 'dnstools.allow_network_probes', label: 'dnstools.setting.allow_network_probes', type: 'checkbox', default: '1', module: 'dnstools', group: 'dnstools_security')]
#[CpSetting(key: 'dnstools.allow_port_check', label: 'dnstools.setting.allow_port_check', type: 'checkbox', default: '1', module: 'dnstools', group: 'dnstools_security')]
#[CpSetting(key: 'dnstools.mail_inbox_enabled', label: 'dnstools.setting.mail_inbox_enabled', type: 'checkbox', default: '0', module: 'dnstools', group: 'dnstools_mail')]
#[CpSetting(key: 'dnstools.mail_inbox_domain', label: 'dnstools.setting.mail_inbox_domain', type: 'text', default: '', module: 'dnstools', group: 'dnstools_mail')]
#[CpSetting(key: 'dnstools.mail_inbox_ttl', label: 'dnstools.setting.mail_inbox_ttl', type: 'integer', default: '1200', module: 'dnstools', group: 'dnstools_mail')]
#[CpSetting(key: 'dnstools.mail_inbox_webhook_secret', label: 'dnstools.setting.mail_inbox_webhook_secret', type: 'password', default: '', module: 'dnstools', group: 'dnstools_mail')]
#[CpSetting(key: 'dnstools.mail_imap_host', label: 'dnstools.setting.mail_imap_host', type: 'text', default: '', module: 'dnstools', group: 'dnstools_mail')]
#[CpSetting(key: 'dnstools.mail_imap_port', label: 'dnstools.setting.mail_imap_port', type: 'integer', default: '993', module: 'dnstools', group: 'dnstools_mail')]
#[CpSetting(key: 'dnstools.mail_imap_user', label: 'dnstools.setting.mail_imap_user', type: 'text', default: '', module: 'dnstools', group: 'dnstools_mail')]
#[CpSetting(key: 'dnstools.mail_imap_password', label: 'dnstools.setting.mail_imap_password', type: 'password', default: '', module: 'dnstools', group: 'dnstools_mail')]
#[CpSetting(key: 'dnstools.mail_imap_encryption', label: 'dnstools.setting.mail_imap_encryption', type: 'text', default: 'ssl', module: 'dnstools', group: 'dnstools_mail')]
final class DnsToolsModuleSettings
{
}
