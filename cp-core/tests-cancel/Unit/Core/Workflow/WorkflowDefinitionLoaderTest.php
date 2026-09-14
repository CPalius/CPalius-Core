<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Workflow;

use App\Core\Workflow\WorkflowDefinitionLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WorkflowDefinitionLoader::class)]
final class WorkflowDefinitionLoaderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/cp_wf_'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->dir);
    }

    private function write(string $name, string $yaml): void
    {
        file_put_contents($this->dir.'/'.$name.'.yaml', $yaml);
    }

    public function testLoadsAValidWorkflow(): void
    {
        $this->write('editorial', <<<'YAML'
            places: [draft, review, published]
            initial: draft
            transitions:
              submit: { from: [draft], to: review, capability: content.moderate }
              publish: { from: [review], to: published }
            YAML);

        $definitions = (new WorkflowDefinitionLoader($this->dir))->loadAll();

        self::assertArrayHasKey('editorial', $definitions);
        $editorial = $definitions['editorial'];
        self::assertSame(['draft', 'review', 'published'], $editorial->places);
        self::assertSame('draft', $editorial->initialPlace);
        self::assertSame('content.moderate', $editorial->getTransition('submit')?->capability);
        self::assertSame('review', $editorial->getTransition('submit')?->to);
    }

    public function testRejectsBrokenDefinitions(): void
    {
        $this->write('no_places', "initial: x\ntransitions: {a: {from: [x], to: y}}");
        $this->write('bad_initial', "places: [a, b]\ninitial: z\ntransitions: {t: {from: [a], to: b}}");
        $this->write('unknown_target', "places: [a]\ninitial: a\ntransitions: {t: {from: [a], to: zzz}}");
        $this->write('not yaml at all', 'places: [a]');

        $definitions = (new WorkflowDefinitionLoader($this->dir))->loadAll();

        self::assertSame([], array_keys($definitions));
    }

    public function testTransitionReferencingUnknownPlaceIsDropped(): void
    {
        $this->write('mix', <<<'YAML'
            places: [a, b]
            initial: a
            transitions:
              ok: { from: [a], to: b }
              broken: { from: [ghost], to: b }
            YAML);

        $definitions = (new WorkflowDefinitionLoader($this->dir))->loadAll();

        self::assertSame(['ok'], array_keys($definitions['mix']->transitions));
    }
}
