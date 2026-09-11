<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CronJobRunRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Immutable log row for one CronJob run (scheduled or manual via AACP).
 * $triggeredManually distinguishes manual Run Now executions.
 */
#[ORM\Entity(repositoryClass: CronJobRunRepository::class)]
#[ORM\Table(name: 'cp_cron_job_runs')]
#[ORM\Index(name: 'idx_cron_job_run_started_at', columns: ['started_at'])]
class CronJobRun
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CronJob::class, inversedBy: 'runs')]
    #[ORM\JoinColumn(name: 'cron_job_id', nullable: false, onDelete: 'CASCADE')]
    private CronJob $cronJob;

    #[ORM\Column(name: 'started_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(name: 'finished_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(type: 'boolean', nullable: true)]
    private ?bool $success = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $output = null;

    #[ORM\Column(name: 'triggered_manually', type: 'boolean')]
    private bool $triggeredManually;

    public function __construct(CronJob $cronJob, bool $triggeredManually)
    {
        $this->cronJob = $cronJob;
        $this->triggeredManually = $triggeredManually;
        $this->startedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCronJob(): CronJob
    {
        return $this->cronJob;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function isSuccess(): ?bool
    {
        return $this->success;
    }

    public function getOutput(): ?string
    {
        return $this->output;
    }

    public function isTriggeredManually(): bool
    {
        return $this->triggeredManually;
    }

    public function markFinished(bool $success, string $output): static
    {
        $this->finishedAt = new \DateTimeImmutable();
        $this->success = $success;
        // Cap output length so huge command logs cannot bloat the table.
        $this->output = mb_substr($output, 0, 20000);

        return $this;
    }
}
