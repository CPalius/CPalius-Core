<?php

declare(strict_types=1);

namespace App\Core\Cron\Dto;

/**
 * In-memory DTO for attribute/flat-file cron jobs (never persisted). Same read API as CronJob.
 * $sourceType is for the AACP "[KOD]" badge only; both tracks run via RunVirtualCronJobCommand.
 */
final class VirtualCronJob
{
    /**
     * @param 'attribute'|'flat-file' $sourceType
     * @param string                  $cronExpression the schedule actually used — the operator's override when there is one
     * @param string                  $declaredExpression the schedule the code ships, kept so AACP can offer "reset to default"
     */
    public function __construct(
        private readonly string $jobName,
        private readonly string $description,
        private readonly string $cronExpression,
        private readonly string $sourceType,
        private readonly string $sourceDetail,
        private readonly ?\DateTimeImmutable $lastRunAt = null,
        private readonly string $declaredExpression = '',
        private readonly bool $active = true,
    ) {
    }

    public function getJobName(): string
    {
        return $this->jobName;
    }

    /**
     * Same name getter as CronJob for the AACP table "Ad" column.
     */
    public function getName(): string
    {
        return $this->jobName;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getCronExpression(): string
    {
        return $this->cronExpression;
    }

    /**
     * @return 'attribute'|'flat-file'
     */
    public function getSourceType(): string
    {
        return $this->sourceType;
    }

    public function getSourceDetail(): string
    {
        return $this->sourceDetail;
    }

    public function getLastRunAt(): ?\DateTimeImmutable
    {
        return $this->lastRunAt;
    }

    public function withLastRunAt(\DateTimeImmutable $lastRunAt): self
    {
        return new self(
            $this->jobName,
            $this->description,
            $this->cronExpression,
            $this->sourceType,
            $this->sourceDetail,
            $lastRunAt,
            $this->declaredExpression,
            $this->active,
        );
    }

    /**
     * The schedule written in the attribute or flat file, before any override.
     */
    public function getDeclaredExpression(): string
    {
        return $this->declaredExpression !== '' ? $this->declaredExpression : $this->cronExpression;
    }

    /**
     * True when an operator has chosen a different schedule than the code ships.
     */
    public function isRescheduled(): bool
    {
        return $this->declaredExpression !== '' && $this->declaredExpression !== $this->cronExpression;
    }

    public function isActive(): bool
    {
        // Was hard-coded true: disabling a code task meant deleting its
        // attribute, which an update puts straight back. The override row
        // carries the operator's answer instead.
        return $this->active;
    }

    public function isCodeBased(): bool
    {
        return true;
    }
}
