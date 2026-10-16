<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Core\Trash\Entity\TrashItem;
use App\Core\Trash\TrashManager;
use App\Entity\Setting;
use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class TrashTest extends IntegrationTestCase
{
    private function manager(): TrashManager
    {
        /** @var TrashManager $manager */
        $manager = $this->container()->get(TrashManager::class);

        return $manager;
    }

    private function deleteSetting(string $key): int
    {
        $setting = (new Setting($key))->setSettingValue('v');
        $this->em()->persist($setting);
        $this->em()->flush();
        $id = (int) $setting->getId();

        $this->em()->remove($setting);
        $this->em()->flush();

        return $id;
    }

    public function testADeletedRowIsCapturedAndRestoredWithItsOriginalId(): void
    {
        $id = $this->deleteSetting('trash.demo');

        $items = $this->em()->getRepository(TrashItem::class)->findAll();
        self::assertCount(1, $items);
        self::assertSame('trash.demo', $items[0]->getRowData()['setting_key']);
        self::assertSame((string) $id, $items[0]->getResourceId());
        self::assertNull($this->em()->find(Setting::class, $id));

        $this->manager()->restore($items[0]);

        $this->em()->clear();
        $restored = $this->em()->find(Setting::class, $id);
        self::assertNotNull($restored);
        self::assertSame('trash.demo', $restored->getSettingKey());
        self::assertSame([], $this->em()->getRepository(TrashItem::class)->findAll());
    }

    public function testRestoreFailsLoudlyWhenTheKeyHasBeenTaken(): void
    {
        $id = $this->deleteSetting('trash.taken');

        $clash = new Setting('trash.taken');
        $this->em()->persist($clash);
        $this->em()->flush();

        $item = $this->em()->getRepository(TrashItem::class)->findAll()[0];

        $this->expectException(\RuntimeException::class);
        $this->manager()->restore($item);
    }

    public function testTurningATypeOffStopsCapture(): void
    {
        $this->manager()->saveRules(true, 30, ['Setting' => ['enabled' => false, 'days' => null]]);

        $this->deleteSetting('trash.off');

        self::assertSame([], $this->em()->getRepository(TrashItem::class)->findAll());
    }

    public function testTheMasterSwitchStopsCaptureForEveryType(): void
    {
        $this->manager()->saveRules(false, 30, []);

        $this->deleteSetting('trash.master_off');

        self::assertSame([], $this->em()->getRepository(TrashItem::class)->findAll());
    }

    public function testRetentionSetsTheExpiryAndExpiredItemsArePurged(): void
    {
        $this->manager()->saveRules(true, 10, ['Setting' => ['enabled' => true, 'days' => 5]]);
        $this->deleteSetting('trash.expiry');

        $item = $this->em()->getRepository(TrashItem::class)->findAll()[0];
        // Expiry is computed a few milliseconds before deletedAt is stamped, so round.
        $days = (int) round(($item->getExpiresAt()->getTimestamp() - $item->getDeletedAt()->getTimestamp()) / 86400);
        self::assertSame(5, $days);

        self::assertSame(0, $this->manager()->purgeExpired());

        $this->em()->getConnection()->executeStatement('UPDATE cp_trash_items SET expires_at = ?', ['2000-01-01 00:00:00']);
        self::assertSame(1, $this->manager()->purgeExpired());
    }

    public function testZeroDaysMeansTheItemNeverExpires(): void
    {
        $this->manager()->saveRules(true, 0, []);
        $this->deleteSetting('trash.forever');

        $item = $this->em()->getRepository(TrashItem::class)->findAll()[0];
        self::assertNull($item->getExpiresAt());
    }
}
