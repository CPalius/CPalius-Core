<?php

declare(strict_types=1);

namespace App\Core\Settings\Definitions;

use App\Core\Annotation\CpSetting;

/**
 * Account registration flow settings. Studio → Module settings → account group.
 */
#[CpSetting(
    key: 'account.registration_enabled',
    label: 'Kayıt Açık',
    type: 'checkbox',
    default: true,
    module: 'account',
    group: 'account.registration',
)]
#[CpSetting(
    key: 'account.require_email_verification',
    label: 'E-posta Doğrulaması Zorunlu',
    type: 'checkbox',
    default: false,
    module: 'account',
    group: 'account.registration',
)]
#[CpSetting(
    key: 'account.require_admin_approval',
    label: 'Yönetici Onayı Zorunlu',
    type: 'checkbox',
    default: false,
    module: 'account',
    group: 'account.registration',
)]
#[CpSetting(
    key: 'account.username_required',
    label: 'Kullanıcı Adı Zorunlu',
    type: 'checkbox',
    default: true,
    module: 'account',
    group: 'account.registration',
)]
#[CpSetting(
    key: 'account.show_first_name',
    label: 'Ad Alanı',
    type: 'select',
    default: '2',
    variants: ['0' => 'Gizli', '1' => 'Opsiyonel', '2' => 'Zorunlu'],
    module: 'account',
    group: 'account.registration',
)]
#[CpSetting(
    key: 'account.show_last_name',
    label: 'Soyad Alanı',
    type: 'select',
    default: '2',
    variants: ['0' => 'Gizli', '1' => 'Opsiyonel', '2' => 'Zorunlu'],
    module: 'account',
    group: 'account.registration',
)]
#[CpSetting(
    key: 'account.require_terms',
    label: 'Kullanım Koşulları Onayı Zorunlu',
    type: 'checkbox',
    default: true,
    module: 'account',
    group: 'account.registration',
)]
final class AccountRegistrationSettings
{
}
