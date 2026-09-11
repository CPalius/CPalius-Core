<?php

declare(strict_types=1);

namespace App\Core\Token\Provider;

use App\Core\Token\TokenValueProviderInterface;
use App\Entity\Node;

final class NodeTokenProvider implements TokenValueProviderInterface
{
    public function supports(string $type): bool
    {
        return $type === 'node';
    }

    public function resolve(string $name, mixed $subject, ?string $arg): ?string
    {
        if (!$subject instanceof Node) {
            return null;
        }

        return match ($name) {
            'title' => $subject->getTitle(),
            'slug' => $subject->getSlug(),
            'type' => $subject->getType(),
            'locale' => $subject->getLocale(),
            'status' => $subject->getStatus(),
            'created' => $this->date($subject->getCreatedAt(), $arg),
            'updated' => $this->date($subject->getUpdatedAt(), $arg),
            'published' => $subject->getPublishedAt() !== null ? $this->date($subject->getPublishedAt(), $arg) : '',
            'author' => $subject->getAuthor()?->getPublicDisplayName() ?? '',
            'category' => $subject->getCategory()?->getName() ?? '',
            default => $this->fieldValue($subject, $name),
        };
    }

    private function date(\DateTimeInterface $date, ?string $format): string
    {
        return $date->format($format !== null && $format !== '' ? $format : 'Y-m-d');
    }

    /**
     * Arbitrary Field API / JSON data passthrough — lets a pattern reference a
     * custom field by its raw machine name (e.g. [node:excerpt]) without a
     * per-field-type token plugin, unlike Drupal's Token module.
     */
    private function fieldValue(Node $node, string $name): ?string
    {
        $value = $node->getDataValue($name);

        return match (true) {
            \is_string($value) => $value,
            \is_int($value), \is_float($value) => (string) $value,
            \is_bool($value) => $value ? '1' : '0',
            default => null,
        };
    }
}
