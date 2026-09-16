<?php

declare(strict_types=1);

namespace Modules\Messages\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use Modules\Messages\Repository\MessageParticipantRepository;
use Modules\Messages\Repository\MessageReportRepository;
use Modules\Messages\Repository\MessageRepository;
use Modules\Messages\Repository\MessageRestrictionRepository;
use Modules\Messages\Repository\MessageThreadRepository;

final class MessagesStatsService
{
    public function __construct(
        private readonly MessageThreadRepository $threads,
        private readonly MessageRepository $messages,
        private readonly MessageReportRepository $reports,
        private readonly MessageRestrictionRepository $restrictions,
        private readonly MessageParticipantRepository $participants,
        private readonly UserRepository $users,
        private readonly MessagesQuota $quota,
    ) {
    }

    /**
     * @return array{
     *     threads: int,
     *     closed: int,
     *     messages: int,
     *     today: int,
     *     openReports: int,
     *     restrictions: int
     * }
     */
    public function overview(): array
    {
        $today = (new \DateTimeImmutable('today'));

        return [
            'threads' => $this->threads->countAll(),
            'closed' => $this->threads->countClosed(),
            'messages' => $this->messages->countAll(),
            'today' => $this->messages->countSince($today),
            'openReports' => $this->reports->countOpen(),
            'restrictions' => $this->restrictions->countActive(),
        ];
    }

    /**
     * @return list<array{user: User, sent: int}>
     */
    public function topSenders(int $limit = 8): array
    {
        $rows = $this->messages->topSenders(new \DateTimeImmutable('-7 days'), $limit);
        $out = [];

        foreach ($rows as $row) {
            $user = $this->users->find($row['authorId']);
            if (!$user instanceof User) {
                continue;
            }

            $out[] = ['user' => $user, 'sent' => $row['sent']];
        }

        return $out;
    }

    public function unreadFor(User $user): int
    {
        return $this->participants->unreadTotal($user);
    }

    public function quotaFor(User $user): MessagesQuotaSnapshot
    {
        return $this->quota->snapshot($user);
    }
}
