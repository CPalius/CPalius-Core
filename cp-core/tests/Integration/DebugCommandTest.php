<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Core\Command\DebugCommand;
use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * T5.2b — cp:debug over the real registries.
 *
 * The value of this command is that it answers "did my thing register?" without
 * triggering it, so the test that matters is that each topic reads its registry
 * through the API that registry actually exposes. Two of the six were written
 * against a guessed shape and only failed when run — cron hands back a mix of
 * Doctrine entities and DTOs rather than arrays, and resources expose readonly
 * properties rather than array keys.
 */
#[CoversClass(DebugCommand::class)]
final class DebugCommandTest extends IntegrationTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function topics(): iterable
    {
        yield 'capabilities' => ['capabilities', 'Capability'];
        yield 'hooks' => ['hooks', 'Hook point'];
        yield 'cron' => ['cron', 'Schedule'];
        yield 'entity types' => ['entity-types', 'Id'];
        yield 'fields' => ['fields', 'Kind'];
        yield 'resources' => ['resources', 'Resource'];
    }

    #[DataProvider('topics')]
    public function testEveryTopicReadsItsRegistry(string $topic, string $expectedHeader): void
    {
        $tester = $this->tester();

        $tester->execute(['topic' => $topic]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString($expectedHeader, $tester->getDisplay());
    }

    public function testCapabilitiesReportWhichRolesHoldThem(): void
    {
        $tester = $this->tester();
        $tester->execute(['topic' => 'capabilities']);

        $display = $tester->getDisplay();

        // The question this topic exists to answer is "why can the editor not
        // do X", so the holder column has to carry real role ids.
        self::assertStringContainsString('taxonomy.manage', $display);
        self::assertStringContainsString('admin', $display);
    }

    public function testEntityTypesReportTheirDiscoveredFlags(): void
    {
        $tester = $this->tester();
        $tester->execute(['topic' => 'entity-types']);

        $display = $tester->getDisplay();

        // Discovered from #[CpEntityType] at compile time, not declared here.
        self::assertStringContainsString('taxonomy_term', $display);
        self::assertStringContainsString('fieldable', $display);
    }

    public function testFilterNarrowsTheOutput(): void
    {
        $tester = $this->tester();

        $tester->execute(['topic' => 'capabilities', '--filter' => 'taxonomy']);
        $filtered = $tester->getDisplay();

        self::assertStringContainsString('taxonomy.manage', $filtered);
        self::assertStringNotContainsString('system.backup.manage', $filtered, 'unrelated rows are dropped');
    }

    public function testJsonOutputIsMachineReadable(): void
    {
        $tester = $this->tester();
        $tester->execute(['topic' => 'entity-types', '--json' => true]);

        $decoded = json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertSame('entity-types', $decoded['topic']);
        self::assertGreaterThan(0, $decoded['count']);
        self::assertArrayHasKey('Id', $decoded['rows'][0], 'rows are keyed by column name');
    }

    public function testAnUnknownTopicIsRefusedRatherThanReportedEmpty(): void
    {
        $tester = $this->tester();

        // Returning an empty table for a typo would read as "nothing
        // registered", which is the opposite of the truth.
        self::assertSame(Command::INVALID, $tester->execute(['topic' => 'hooksss']));
        self::assertStringContainsString('Unknown topic', $tester->getDisplay());
    }

    private function tester(): CommandTester
    {
        /** @var DebugCommand $command */
        $command = $this->container()->get(DebugCommand::class);

        return new CommandTester($command);
    }
}
