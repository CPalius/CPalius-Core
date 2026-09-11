<?php

declare(strict_types=1);

namespace App\Core\Notification;

use App\Core\Module\ModuleContributionCatalog;

/**
 * Merges core-seeded notification types with module contributions.yaml entries.
 */
final class NotificationTypeRegistry
{
    /** @var array<string, NotificationTypeDefinition> */
    private array $types;

    public function __construct(
        private readonly ModuleContributionCatalog $contributions,
    ) {
        $this->types = $this->build();
    }

    public function get(string $eventKey): ?NotificationTypeDefinition
    {
        return $this->types[$eventKey] ?? null;
    }

    /**
     * @return array<string, NotificationTypeDefinition>
     */
    public function all(): array
    {
        return $this->types;
    }

    /**
     * @return list<NotificationTypeDefinition>
     */
    public function accountExposed(): array
    {
        return array_values(array_filter(
            $this->types,
            static fn (NotificationTypeDefinition $t): bool => $t->exposeInAccount && $t->preferenceKey !== null,
        ));
    }

    /**
     * @return array<string, NotificationTypeDefinition>
     */
    private function build(): array
    {
        $types = [];
        foreach ($this->coreDefaults() as $def) {
            $types[$def->eventKey] = $def;
        }

        foreach ($this->contributions->notificationTypes() as $key => $row) {
            $types[$key] = new NotificationTypeDefinition(
                eventKey: $key,
                labelKey: $row['label'],
                module: $row['module'],
                defaultChannels: $row['defaultChannels'],
                preferenceKey: $row['preferenceKey'],
                mailTemplate: $row['mailTemplate'],
                allowSelf: $row['allowSelf'],
                exposeInAccount: $row['exposeInAccount'],
            );
        }

        return $types;
    }

    /**
     * @return list<NotificationTypeDefinition>
     */
    private function coreDefaults(): array
    {
        return [
            new NotificationTypeDefinition(
                eventKey: 'content.workflow.transition',
                labelKey: 'notification.type.content_workflow',
                module: 'core',
                defaultChannels: ['in_app', 'mail_instant'],
                preferenceKey: 'notif_content_workflow',
                mailTemplate: 'content_workflow',
                allowSelf: false,
                exposeInAccount: true,
            ),
        ];
    }
}
