<?php

declare(strict_types=1);

namespace Modules\Ai\Settings;

use App\Core\Annotation\CpSetting;

/**
 * AI translator keys. The API key never ships in the seeder or the repository.
 */
#[CpSetting(key: 'ai.enabled', label: 'studio.ai.field.enabled', type: 'checkbox', default: '1', module: 'ai', group: 'ai_provider')]
#[CpSetting(key: 'ai.provider', label: 'studio.ai.field.provider', type: 'select', default: 'gemini', variants: [
    'gemini' => 'studio.ai.provider.gemini',
    'groq' => 'studio.ai.provider.groq',
], module: 'ai', group: 'ai_provider')]
#[CpSetting(key: 'ai.model', label: 'studio.ai.field.model', type: 'select', default: 'gemini-3.5-flash-lite', variants: [
    'gemini-3.5-flash-lite' => 'studio.ai.model.gemini_35_flash_lite',
    'gemini-flash-lite-latest' => 'studio.ai.model.gemini_flash_lite_latest',
    'gemini-3.5-flash' => 'studio.ai.model.gemini_35_flash',
    'llama-3.1-8b-instant' => 'studio.ai.model.llama_31_8b',
    'llama-3.3-70b-versatile' => 'studio.ai.model.llama_33_70b',
], module: 'ai', group: 'ai_provider')]
#[CpSetting(key: 'ai.api_key', label: 'studio.ai.field.api_key', type: 'password', default: '', module: 'ai', group: 'ai_provider')]
#[CpSetting(key: 'ai.timeout', label: 'studio.ai.field.timeout', type: 'integer', default: '25', module: 'ai', group: 'ai_provider')]
#[CpSetting(key: 'ai.allow_edit_translate', label: 'studio.ai.field.allow_edit_translate', type: 'checkbox', default: '0', module: 'ai', group: 'ai_provider')]
final class AiModuleSettings
{
}
