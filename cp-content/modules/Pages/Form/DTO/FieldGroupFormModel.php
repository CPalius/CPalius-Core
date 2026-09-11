<?php

declare(strict_types=1);

namespace Modules\Pages\Form\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final class FieldGroupFormModel
{
    #[Assert\NotBlank(message: 'pages.validation.group_title_required')]
    #[Assert\Length(max: 255, maxMessage: 'pages.validation.group_title_max')]
    public string $title = '';

    #[Assert\Length(max: 255, maxMessage: 'pages.validation.group_slug_max')]
    #[Assert\Regex(
        pattern: '/^(?:[a-z0-9]+(?:-[a-z0-9]+)*)?$/',
        message: 'pages.validation.group_slug_format',
        match: true,
    )]
    public ?string $slug = null;

    public string $fieldsJson = '[]';
}
