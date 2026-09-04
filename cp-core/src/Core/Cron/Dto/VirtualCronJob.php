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
     */
    public function __construct(
        private readonly string $jobName,
        private readonly string $description,
        private readonly string $cronExpression,
        private readonly string $sourceType,
        private readonly string $sourceDetail,
        private readonly ?\DateTimeImmutable $lastRunAt = null,
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
        );
    }

    public function isActive(): bool
    {
        // Code jobs are always on; disable by removing the attribute/file (Law 3.1).
        return true;
    }

    public function isCodeBased(): bool
    {
        return true;
    }
}
