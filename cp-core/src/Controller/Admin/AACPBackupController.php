<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Backup\BackupException;
use App\Core\Backup\BackupFilename;
use App\Core\Backup\BackupService;
use App\Core\Backup\BackupShipper;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * AACP backup desk: create, list, download, and delete archives. Restore is not in-request.
 */
final class AACPBackupController extends AbstractController
{
    private const CSRF_TOKEN_ID = 'aacp_backup';

    public function __construct(
        private readonly BackupService $backupService,
        private readonly BackupShipper $shipper,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/aacp/backup', name: 'aacp_backup', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.menu.backup', icon: 'heroicons:archive-box', panel: 'aacp', priority: 81, capability: 'system.backup.manage', parent: 'aacp_hub_maintenance')]
    #[IsGranted('system.backup.manage')]
    public function index(): Response
    {
        $archives = $this->backupService->list();
        $ledger = $this->shipper->ledger();

        // Archives that exist only off-site: either keep_local is off, or the
        // local copy was deleted. Listed separately because there is nothing to
        // download and nothing to restore from without fetching them by hand —
        // and hiding them would make a site with off-site-only backups look
        // like a site with no backups at all.
        $localNames = array_map(static fn ($a): string => $a->filename, $archives);
        $remoteOnly = array_diff_key($ledger, array_flip($localNames));

        return $this->render('aacp/backup/index.html.twig', [
            'archives' => $archives,
            'csrf_token' => $this->csrfTokenManager->getToken(self::CSRF_TOKEN_ID)->getValue(),
            'storage_dir' => $this->backupService->backupDirectory(),
            'remote_ledger' => $ledger,
            'remote_only' => $remoteOnly,
            'remote_target' => $this->shipper->targetLabel(),
            'remote_selected' => $this->shipper->selectedType(),
            'remote_active' => $this->shipper->isConfigured(),
        ]);
    }

    #[Route('/aacp/backup/create', name: 'aacp_backup_create', methods: ['POST'])]
    #[IsGranted('system.backup.manage')]
    public function create(Request $request): RedirectResponse
    {
        $this->assertValidCsrfToken($request);

        $type = trim((string) $request->request->get('type'));
        if (!\in_array($type, BackupFilename::TYPES, true)) {
            $this->addFlash('error', $this->translator->trans('aacp.backup.invalid_type'));

            return new RedirectResponse('/aacp/backup');
        }

        try {
            $archive = $this->backupService->create($type);
            $this->addFlash('success', $this->translator->trans('aacp.backup.created', [
                'filename' => $archive->filename,
            ]));
        } catch (BackupException $e) {
            $this->addFlash('error', $this->translator->trans('aacp.backup.create_failed', [
                'error' => $e->getMessage(),
            ]));

            return new RedirectResponse('/aacp/backup');
        }

        // Reported separately from the creation above, because they are separate
        // outcomes: an archive that was written correctly and then failed to
        // upload is a partial success, and folding the two into one message
        // would tell the operator either that their backup does not exist (it
        // does) or that it is off-site (it is not).
        $shipping = $this->backupService->shipAndPrune($archive);

        if ($shipping['error'] !== null) {
            $this->addFlash('error', $this->translator->trans('aacp.backup.ship_failed', [
                'error' => $shipping['error'],
            ]));
        } elseif ($shipping['shipped']) {
            $this->addFlash('success', $this->translator->trans('aacp.backup.shipped', [
                'target' => (string) $shipping['target'],
            ]));

            if ($shipping['local_removed']) {
                $this->addFlash('success', $this->translator->trans('aacp.backup.local_removed'));
            }

            if ($shipping['pruned'] > 0) {
                $this->addFlash('success', $this->translator->trans('aacp.backup.remote_pruned', [
                    'count' => $shipping['pruned'],
                ]));
            }
        }

        return new RedirectResponse('/aacp/backup');
    }

    /**
     * Deletes the off-site copy only.
     *
     * A separate action from the local delete on purpose: the two copies exist
     * so that losing one is survivable, and an operator clearing disk space
     * must not silently reach across the network and remove the copy that was
     * the entire point of configuring a destination.
     */
    #[Route('/aacp/backup/delete-remote', name: 'aacp_backup_delete_remote', methods: ['POST'])]
    #[IsGranted('system.backup.manage')]
    public function deleteRemote(Request $request): RedirectResponse
    {
        $this->assertValidCsrfToken($request);

        $filename = (string) $request->request->get('filename');

        if (!BackupFilename::isValid($filename)) {
            $this->addFlash('error', $this->translator->trans('aacp.backup.invalid_file'));

            return new RedirectResponse('/aacp/backup');
        }

        $this->shipper->forget($filename);
        $this->addFlash('success', $this->translator->trans('aacp.backup.remote_deleted', [
            'filename' => $filename,
        ]));

        return new RedirectResponse('/aacp/backup');
    }

    #[Route('/aacp/backup/archive/{stem}', name: 'aacp_backup_download', methods: ['GET'], requirements: ['stem' => 'cpalius-(db|files|full)-\d{8}-\d{6}'])]
    #[IsGranted('system.backup.manage')]
    public function download(string $stem): BinaryFileResponse
    {
        try {
            $filename = BackupFilename::fromStem($stem);
            $path = $this->backupService->absolutePath($filename);
        } catch (BackupException) {
            throw new BadRequestHttpException($this->translator->trans('aacp.backup.invalid_file'));
        }

        if (!is_file($path)) {
            throw new NotFoundHttpException($this->translator->trans('aacp.backup.missing_file'));
        }

        $response = new BinaryFileResponse($path);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename);
        $response->headers->set(
            'Content-Type',
            str_ends_with($filename, '.zip') ? 'application/zip' : 'application/gzip',
        );

        return $response;
    }

    #[Route('/aacp/backup/delete', name: 'aacp_backup_delete', methods: ['POST'])]
    #[IsGranted('system.backup.manage')]
    public function delete(Request $request): RedirectResponse
    {
        $this->assertValidCsrfToken($request);

        $filename = (string) $request->request->get('filename');

        try {
            $this->backupService->delete($filename);
            $this->addFlash('success', $this->translator->trans('aacp.backup.deleted', [
                'filename' => $filename,
            ]));
        } catch (BackupException $e) {
            $this->addFlash('error', $this->translator->trans('aacp.backup.delete_failed', [
                'error' => $e->getMessage(),
            ]));
        }

        return new RedirectResponse('/aacp/backup');
    }

    private function assertValidCsrfToken(Request $request): void
    {
        $submittedToken = (string) $request->request->get('_token');
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, $submittedToken))) {
            throw new BadRequestHttpException($this->translator->trans('aacp.backup.invalid_csrf'));
        }
    }
}
