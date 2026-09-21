<?php

declare(strict_types=1);

namespace App\Core\Settings\Definitions;

use App\Core\Annotation\CpSetting;

/**
 * Which typeface each role uses.
 *
 * Stored as settings rather than as a file next to the fonts so they travel
 * with the rest of the configuration — a config export that carried the font
 * files but not the choice of which one is the body face would restore a site
 * that looks wrong.
 *
 * The variants are empty here and filled at runtime by FontFamilyVariantProvider:
 * the list is whatever is installed, which cannot be known when the container
 * is compiled. An empty value means "leave the theme default alone", which is
 * why every one of these ships empty.
 */
#[CpSetting(
    key: 'theme.font_body',
    label: 'aacp.fonts.role.body',
    type: 'select',
    default: '',
    group: 'theme.fonts',
)]
#[CpSetting(
    key: 'theme.font_heading',
    label: 'aacp.fonts.role.heading',
    type: 'select',
    default: '',
    group: 'theme.fonts',
)]
#[CpSetting(
    key: 'theme.font_mono',
    label: 'aacp.fonts.role.mono',
    type: 'select',
    default: '',
    group: 'theme.fonts',
)]
#[CpSetting(
    key: 'theme.font_serif',
    label: 'aacp.fonts.role.serif',
    type: 'select',
    default: '',
    group: 'theme.fonts',
)]
#[CpSetting(
    key: 'theme.font_h1',
    label: 'aacp.fonts.role.h1',
    type: 'select',
    default: '',
    group: 'theme.fonts',
)]
#[CpSetting(
    key: 'theme.font_h2',
    label: 'aacp.fonts.role.h2',
    type: 'select',
    default: '',
    group: 'theme.fonts',
)]
#[CpSetting(
    key: 'theme.font_h3',
    label: 'aacp.fonts.role.h3',
    type: 'select',
    default: '',
    group: 'theme.fonts',
)]
final class FontSettings
{
}
