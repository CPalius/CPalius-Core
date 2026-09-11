<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Backup\BackupException;
use App\Core\Backup\BackupFilename;
use App\Core\Backup\BackupService;
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
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/aacp/backup', name: 'aacp_backup', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.menu.backup', icon: 'heroicons:archive-box', panel: 'aacp', priority: 22, capability: 'system.backup.manage', parent: 'aacp_tools')]
    #[IsGranted('system.backup.manage')]
    public function index(): Response
    {
        return $this->render('aacp/backup/index.html.twig', [
            'archives' => $this->backupService->list(),
            'csrf_token' => $this->csrfTokenManager->getToken(self::CSRF_TOKEN_ID)->getValue(),
            'storage_dir' => $this->backupService->backupDirectory(),
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
        }

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
