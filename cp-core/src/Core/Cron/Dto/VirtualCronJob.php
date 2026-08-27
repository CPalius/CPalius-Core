<?php

declare(strict_types=1);

namespace App\Core\Cron\Dto;

/**
 * Kod tabanlı (Attribute veya Flat-File kulvarı) bir cron görevinin,
 * bellekte (in-memory) üretilen, DB'ye HİÇ yazılmayan taşıyıcısı.
 *
 * App\Entity\CronJob (DB-tabanlı, "[MANUEL]" rozetli görevler) ile KASITLI
 * olarak aynı Doctrine entity'si DEĞİLDİR — bu sınıf salt bir DTO'dur,
 * kalıcılık katmanına hiç dokunmaz. Ancak AACP şablonlarının ve
 * CronManager::getTasks()'ın tek bir birleşik listede işleyebilmesi için
 * DB'deki CronJob ile AYNI OKUMA arayüzünü (getName/getCronExpression/...)
 * sunar — bkz. CronManager::getTasks() dönüş tipi.
 *
 * $sourceType ayrımı AACP panelindeki "[KOD]" rozetinin hangi kulvardan
 * geldiğini (attribute mi flat-file mi) göstermek için kullanılır; çalışma
 * zamanı davranışını ETKİLEMEZ (ikisi de RunVirtualCronJobCommand üzerinden
 * aynı şekilde tetiklenir).
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
     * AACP tablosunun "Ad" kolonuyla uyumlu okuma arayüzü (bkz. CronJob::getName()).
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
        // Kod tabanlı görevler her zaman aktiftir: DB'deki "active" bayrağının
        // kod kulvarında bir karşılığı yoktur — bir görevi devre dışı bırakmak
        // isteyen geliştirici attribute'u/dosyayı kaldırır (Manifesto Law 3.1
        // ruhu: kodun kendisi tek gerçek kaynaktır).
        return true;
    }

    public function isCodeBased(): bool
    {
        return true;
    }
}
