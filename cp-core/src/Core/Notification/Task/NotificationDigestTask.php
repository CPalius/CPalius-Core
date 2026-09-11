<?php

declare(strict_types=1);

namespace App\Core\Notification\Task;

use App\Core\Cron\Attribute\CpCronJob;
use App\Core\Mail\CpMailerService;
use App\Core\Notification\Entity\NotificationDigestItem;
use App\Core\Notification\NotificationPreferenceResolver;
use App\Core\Notification\Repository\NotificationDigestItemRepository;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Sends closed digest buckets as one email per user.
 */
final class NotificationDigestTask
{
    public function __construct(
        private readonly NotificationDigestItemRepository $digestRepository,
        private readonly NotificationPreferenceResolver $preferences,
        private readonly CpMailerService $mailer,
        private readonly EntityManagerInterface $entityManager,
        private readonly Environment $twig,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[CpCronJob(
        schedule: '15 8 * * *',
        name: 'notification.digest.send',
        description: 'Send closed daily/weekly notification digest mails',
    )]
    public function execute(): string
    {
        if (!$this->mailer->canSend()) {
            return 'skipped=mail_disabled';
        }

        $items = $this->digestRepository->findPendingForClosedBuckets(
            $this->preferences->closedBuckets(),
        );
        if ($items === []) {
            return 'sent=0';
        }

        /** @var array<int, list<NotificationDigestItem>> $byUser */
        $byUser = [];
        foreach ($items as $item) {
            $uid = (int) $item->getUser()->getId();
            $byUser[$uid][] = $item;
        }

        $sent = 0;
        foreach ($byUser as $group) {
            $user = $group[0]->getUser();
            if ($this->sendDigest($user, $group)) {
                foreach ($group as $item) {
                    $item->markSent();
                }
                $this->entityManager->flush();
                ++$sent;
            }
        }

        return sprintf('sent=%d users=%d items=%d', $sent, \count($byUser), \count($items));
    }

    /**
     * @param list<NotificationDigestItem> $items
     */
    private function sendDigest(User $user, array $items): bool
    {
        $email = trim($user->getEmail());
        if ($email === '' || !filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $html = $this->twig->render('notification/email/digest.html.twig', [
            'user' => $user,
            'items' => $items,
        ]);

        $subject = $this->translator->trans('notification.mail.subject.digest', [
            'count' => \count($items),
        ]);

        try {
            $this->mailer->sendNow($email, $subject, $html);
        } catch (\Throwable) {
            return false;
        }

        return true;
    }
}
