<?php

declare(strict_types=1);

namespace Modules\Importer\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Migrate\ConfigurableMigrationInterface;
use App\Core\Migrate\MigrationInterface;
use App\Core\Migrate\MigrationOption;
use App\Core\Migrate\MigrationRunner;
use Modules\Importer\SourceSystem;
use Modules\Importer\SourceSystemCatalog;
use Modules\Importer\Storage\ImportFileStore;
use Modules\Importer\Storage\ImportSourceStore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The import screen.
 *
 * THE PAGE OPENS WITH NOTHING HAVING HAPPENED
 * Same rule as the AACP updates screen: arriving at a URL must not change
 * data. Submitting the form performs a DRY RUN and shows what would happen;
 * writing needs a second, deliberate click on a CSRF-protected form. An
 * importer is the least reversible thing in the product and the one most
 * likely to be tried out by someone who has not read anything.
 *
 * WHY THERE IS A ROW LIMIT AND A COPYABLE COMMAND
 * A browser request is the wrong place to move a 300 MB export: PHP's time
 * limit will end it halfway, and the operator is left guessing what landed.
 * The map makes a half-finished run safe to resume, but guessing is still a
 * bad experience. So the screen is honest about its shape — it is for trying
 * an import, checking the report, and importing small to medium sites — and it
 * shows the exact command line for the rest, rather than pretending a web
 * request can do it.
 */
#[Route('/admin/import', name: 'admin_import_')]
#[IsGranted('importer.run')]
final class ImportController extends AbstractController
{
    private const CSRF_TOKEN_ID = 'importer_run';

    /** Rows per migration in one web request, unless the operator asks for all. */
    private const DEFAULT_LIMIT = 5000;

    private const MAX_LIMIT = 100000;

    public function __construct(
        private readonly SourceSystemCatalog $catalog,
        private readonly MigrationRunner $runner,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly ImportFileStore $files,
        private readonly ImportSourceStore $sources,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/{system}/upload', name: 'upload', methods: ['POST'], requirements: ['system' => '[a-z0-9_]+'])]
    public function upload(Request $request, string $system): Response
    {
        $source = $this->requireSystem($system);
        $this->assertCsrf($request);

        $file = $request->files->get('export');

        if (!$file instanceof UploadedFile) {
            $this->addFlash('error', $this->translator->trans('importer.upload.none'));

            return $this->redirectToRoute('admin_import_system', ['system' => $source->id]);
        }

        try {
            $stored = $this->files->store($file);
            $this->addFlash('success', $this->translator->trans($stored->isDirectory() ? 'importer.upload.unpacked' : 'importer.upload.stored', ['name' => $stored->originalName]));
        } catch (\Throwable $e) {
            // Wrong type, too large, a zip that climbs out of itself: all
            // things the operator can fix, so they are told rather than logged.
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_import_system', ['system' => $source->id]);
    }

    #[Route('/{system}/upload/{id}/delete', name: 'upload_delete', methods: ['POST'], requirements: ['system' => '[a-z0-9_]+', 'id' => '[0-9a-f]{32}'])]
    public function deleteUpload(Request $request, string $system, string $id): Response
    {
        $source = $this->requireSystem($system);
        $this->assertCsrf($request);

        // Deleting is offered because an export is the most sensitive file the
        // installation will hold; keeping it after the import is finished is a
        // liability, not a convenience.
        $this->files->delete($id);
        $this->addFlash('success', $this->translator->trans('importer.upload.deleted'));

        return $this->redirectToRoute('admin_import_system', ['system' => $source->id]);
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[CpAdminMenu(label: 'importer.menu', icon: 'heroicons:arrow-down-on-square-stack', panel: 'studio', priority: 82, capability: 'importer.run')]
    public function index(): Response
    {
        $systems = $this->catalog->all();

        // One query for every migration on the page. Asking per migration is
        // the N+1 that took this screen down once eighteen of them existed.
        $all = [];
        foreach ($systems as $system) {
            foreach ($this->catalog->migrationsFor($system) as $migration) {
                $all[] = $migration;
            }
        }

        $counts = $this->runner->importedCounts($all);
        $imported = [];

        foreach ($systems as $system) {
            $imported[$system->id] = array_sum(array_map(
                static fn (MigrationInterface $m): int => $counts[$m->id()] ?? 0,
                $this->catalog->migrationsFor($system),
            ));
        }

        return $this->render('@ImporterModule/admin/index.html.twig', [
            'systems' => $systems,
            'imported' => $imported,
        ]);
    }

    #[Route('/{system}', name: 'system', methods: ['GET'], requirements: ['system' => '[a-z0-9_]+'])]
    public function system(string $system): Response
    {
        $source = $this->requireSystem($system);

        return $this->renderSystem($source, $this->publicValues($source), null, false);
    }

    #[Route('/{system}/source/forget', name: 'source_forget', methods: ['POST'], requirements: ['system' => '[a-z0-9_]+'])]
    public function forgetSource(Request $request, string $system): Response
    {
        $source = $this->requireSystem($system);
        $this->assertCsrf($request);

        $this->sources->forget($source->id);
        $this->addFlash('success', $this->translator->trans('importer.source.forgotten'));

        return $this->redirectToRoute('admin_import_system', ['system' => $source->id]);
    }

    #[Route('/{system}/run', name: 'run', methods: ['POST'], requirements: ['system' => '[a-z0-9_]+'])]
    public function run(Request $request, string $system): Response
    {
        $source = $this->requireSystem($system);
        $this->assertCsrf($request);

        if (!$source->available) {
            throw new BadRequestHttpException(sprintf('No migrations are registered for "%s".', $source->id));
        }

        $values = $this->submittedOptions($request, $source);
        $sourceKind = trim((string) $request->request->get('sourceKind'));
        $runValues = $this->applySourceKind($sourceKind, $values);
        $limit = $this->submittedLimit($request);
        $mode = (string) $request->request->get('mode');
        // Applying is opt-in on every submit, so a page left open cannot be
        // re-posted into a write by a stray refresh.
        $apply = $mode === 'apply';
        $rollback = $mode === 'rollback';
        $reports = [];
        $error = null;

        if ($limit === null || ($limit !== null && $limit > 2000)) {
            @set_time_limit(0);
        }

        try {
            if ($rollback) {
                foreach (array_reverse($this->catalog->migrationsFor($source)) as $migration) {
                    $reports[] = $this->runner->rollback($migration, false);
                }
            } else {
                $selected = $this->submittedMigrations($request, $source);

                foreach ($selected as $migration) {
                    $reports[] = $this->runner->run($this->configure($migration, $runValues), !$apply, $limit);
                }

                if ($sourceKind !== '') {
                    $values['_sourceKind'] = $sourceKind;
                }

                $values['_steps'] = implode(',', array_map(
                    static fn (MigrationInterface $migration): string => $migration->id(),
                    $selected,
                ));

                $this->sources->save($source->id, $values);
            }
        } catch (\InvalidArgumentException|\LogicException|\RuntimeException $e) {
            // A missing file, an unreadable uploads directory, a required
            // option left empty: operator input, shown as a message rather
            // than a stack trace.
            $error = $e->getMessage();
            $reports = [];
        }

        return $this->renderSystem($source, $this->publicValues($source, $values), $reports, $apply || $rollback, $error, $limit, $sourceKind);
    }

    /**
     * @param array<string, string> $values
     */
    private function configure(MigrationInterface $migration, array $values): MigrationInterface
    {
        if (!$migration instanceof ConfigurableMigrationInterface) {
            return $migration;
        }

        $declared = array_map(static fn (MigrationOption $o): string => $o->name, $migration->options());

        // Each migration is handed only what it declares, exactly as the
        // command spreads -o values across a run.
        return $migration->withOptions(array_intersect_key($values, array_flip($declared)));
    }

    /**
     * @return array<string, string>
     */
    private function submittedOptions(Request $request, SourceSystem $system): array
    {
        /** @var array<string, mixed> $submitted */
        $submitted = $request->request->all('options');
        $values = [];

        $saved = $this->sources->load($system->id);

        foreach ($this->catalog->optionsFor($system) as $option) {
            $value = $submitted[$option->name] ?? '';
            $value = \is_string($value) ? trim($value) : '';

            if ($value === '' && isset($saved[$option->name])) {
                $value = $saved[$option->name];
            }

            $values[$option->name] = $value;
        }

        return $values;
    }

    /**
     * @param array<string, string> $overlay
     *
     * @return array<string, string>
     */
    private function publicValues(SourceSystem $system, array $overlay = []): array
    {
        $values = $this->sources->load($system->id) + $overlay;
        $public = [];

        foreach ($this->catalog->optionsFor($system) as $option) {
            $value = $overlay[$option->name] ?? $values[$option->name] ?? '';

            if ($option->isSecret()) {
                continue;
            }

            $public[$option->name] = $value;
        }

        return $public;
    }

    private function submittedLimit(Request $request): ?int
    {
        if ($request->request->getBoolean('unlimited')) {
            return null;
        }

        $limit = $request->request->getInt('limit', self::DEFAULT_LIMIT);

        return max(1, min($limit, self::MAX_LIMIT));
    }

    /**
     * Hidden fields from the other source kind still sit in the form (and in
     * the remembered store). Blanking them for this run is what stops a saved
     * MySQL name from turning a WXR import into a database import.
     *
     * @param array<string, string> $values
     *
     * @return array<string, string>
     */
    private function applySourceKind(string $kind, array $values): array
    {
        if ($kind === 'xml') {
            foreach (['sqlDump', 'dbHost', 'dbName', 'dbUser', 'dbPassword'] as $name) {
                $values[$name] = '';
            }

            $file = $values['file'] ?? '';

            if ($file !== '' && $this->looksLikeSqlPath($file)) {
                $values['file'] = '';
            }
        } elseif ($kind === 'sql') {
            $file = $values['file'] ?? '';

            if ($file !== '' && !$this->looksLikeSqlPath($file)) {
                $values['file'] = '';
            }
        } elseif ($kind === 'dump') {
            foreach (['dbHost', 'dbName', 'dbUser', 'dbPassword'] as $name) {
                $values[$name] = '';
            }
        } elseif ($kind === 'remote') {
            $values['sqlDump'] = '';
        }

        unset($values['_sourceKind']);

        return $values;
    }

    private function looksLikeSqlPath(string $path): bool
    {
        $lower = strtolower($path);

        return str_ends_with($lower, '.sql') || str_ends_with($lower, '.sql.gz');
    }

    /**
     * The steps the operator ticked, in dependency order. Unchecked steps are
     * skipped entirely — that is how "only import users" works. Dependencies
     * are not forced: if they already imported users last week, today's post
     * run can resolve them from the map.
     *
     * @return list<MigrationInterface>
     */
    private function submittedMigrations(Request $request, SourceSystem $source): array
    {
        $all = $this->catalog->migrationsFor($source);

        if (!$request->request->getBoolean('steps_present')) {
            return $all;
        }

        $allowed = [];
        foreach ($all as $migration) {
            $allowed[$migration->id()] = $migration;
        }

        $picked = [];
        foreach ($request->request->all('steps') as $id) {
            if (\is_string($id) && isset($allowed[$id])) {
                $picked[$id] = true;
            }
        }

        if ($picked === []) {
            throw new \InvalidArgumentException($this->translator->trans('importer.steps.none'));
        }

        $selected = [];
        foreach ($all as $migration) {
            if (isset($picked[$migration->id()])) {
                $selected[] = $migration;
            }
        }

        return $selected;
    }

    /**
     * @param array<string, string> $saved
     *
     * @return list<string>
     */
    private function rememberedSteps(array $saved): array
    {
        $raw = trim($saved['_steps'] ?? '');

        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(explode(',', $raw), static fn (string $id): bool => $id !== ''));
    }

    /**
     * @param list<\App\Core\Migrate\MigrationReport>|null $reports
     */
    private function renderSystem(
        SourceSystem $source,
        array $values,
        ?array $reports,
        bool $applied,
        ?string $error = null,
        ?int $limit = self::DEFAULT_LIMIT,
        string $sourceKind = '',
    ): Response {
        $saved = $this->sources->load($source->id);

        return $this->render('@ImporterModule/admin/system.html.twig', [
            'system' => $source,
            'migrations' => $this->describeMigrations($source),
            'options' => $this->catalog->optionsFor($source),
            'values' => $values,
            'saved' => $saved,
            'sourceKind' => $sourceKind !== '' ? $sourceKind : ($saved['_sourceKind'] ?? ''),
            'selectedSteps' => $this->rememberedSteps($saved),
            'reports' => $reports,
            'applied' => $applied,
            'error' => $error,
            'defaultLimit' => self::DEFAULT_LIMIT,
            'limit' => $limit,
            'unlimited' => $limit === null,
            'maxLimit' => self::MAX_LIMIT,
            'command' => $reports !== null ? $this->commandLine($source, $saved + $values) : null,
            'uploads' => $this->files->all(),
            'csrf_token' => $this->csrfTokenManager->getToken(self::CSRF_TOKEN_ID)->getValue(),
        ]);
    }

    /**
     * @return list<array{id: string, label: string, dependsOn: list<string>, imported: int}>
     */
    private function describeMigrations(SourceSystem $system): array
    {
        $migrations = $this->catalog->migrationsFor($system);
        $counts = $this->runner->importedCounts($migrations);
        $rows = [];

        foreach ($migrations as $migration) {
            $rows[] = [
                'id' => $migration->id(),
                'label' => $migration->label(),
                'dependsOn' => $migration->dependsOn(),
                'imported' => $counts[$migration->id()] ?? 0,
            ];
        }

        return $rows;
    }

    /**
     * The equivalent command, for the imports a web request has no business
     * attempting. Shown filled in with what the operator typed.
     *
     * @param array<string, string> $values
     */
    private function commandLine(SourceSystem $system, array $values): string
    {
        $command = 'php cp-core/bin/console cp:migrate run';
        $migrations = $this->catalog->migrationsFor($system);
        $picked = $this->rememberedSteps($values);

        if (\count($picked) === 1) {
            $command .= ' '.$picked[0];
        } elseif (\count($migrations) === 1) {
            $command .= ' '.$migrations[0]->id();
        }

        $secrets = [];
        foreach ($this->catalog->optionsFor($system) as $option) {
            if ($option->isSecret()) {
                $secrets[$option->name] = true;
            }
        }

        foreach ($values as $name => $value) {
            if ($value !== '' && !str_starts_with($name, '_') && !isset($secrets[$name])) {
                $command .= sprintf(' -o %s=%s', $name, escapeshellarg($value));
            }
        }

        return $command.' --apply';
    }

    private function requireSystem(string $id): SourceSystem
    {
        try {
            return $this->catalog->get($id);
        } catch (\InvalidArgumentException $e) {
            throw new NotFoundHttpException($e->getMessage(), $e);
        }
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, (string) $request->request->get('_token'))) {
            throw new BadRequestHttpException('Invalid CSRF token.');
        }
    }
}
