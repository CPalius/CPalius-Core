<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CronJobRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Faz 6: AACP üzerinden tamamen dinamik (DB-tabanlı) yönetilen bir cron
 * işi tanımı. Setting entity'siyle aynı ruhta: bu sınıf salt bir veri
 * taşıyıcısıdır, HİÇBİR zamanlama/çalıştırma mantığı içermez — gerçek
 * "zamanı geldi mi?" hesaplaması App\Core\Cron\CronExpressionEvaluator'da,
 * gerçek çalıştırma App\Core\Command\RunDueCronJobsCommand'de yaşar.
 *
 * commandName BİLİNÇLİ olarak serbest metin DEĞİLDİR: AACP formu bu alanı
 * App\Core\Cron\CronCommandWhitelist'in izin verdiği "cp:*" önekli
 * komutlarla sınırlar (bkz. CronCommandWhitelist docblock'u) — aksi halde
 * bir yönetici (veya ele geçirilmiş bir admin hesabı) "dbal:run-sql" gibi
 * tehlikeli bir komutu cron'a ekleyip rastgele SQL çalıştırabilirdi.
 */
#[ORM\Entity(repositoryClass: CronJobRepository::class)]
#[ORM\Table(name: 'cp_cron_jobs')]
class CronJob
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 191)]
    private string $name;

    #[ORM\Column(name: 'command_name', type: 'string', length: 191)]
    private string $commandName;

    #[ORM\Column(name: 'command_arguments', type: 'string', length: 500, nullable: true)]
    private ?string $commandArguments = null;

    #[ORM\Column(name: 'cron_expression', type: 'string', length: 100)]
    private string $cronExpression;

    #[ORM\Column(type: 'boolean')]
    private bool $active = true;

    #[ORM\Column(name: 'last_run_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastRunAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, CronJobRun> */
    #[ORM\OneToMany(targetEntity: CronJobRun::class, mappedBy: 'cronJob', orphanRemoval: true)]
    #[ORM\OrderBy(['startedAt' => 'DESC'])]
    private Collection $runs;

    public function __construct(string $name, string $commandName, string $cronExpression)
    {
        $this->name = $name;
        $this->commandName = $commandName;
        $this->cronExpression = $cronExpression;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->runs = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        $this->touch();

        return $this;
    }

    public function getCommandName(): string
    {
        return $this->commandName;
    }

    public function setCommandName(string $commandName): static
    {
        $this->commandName = $commandName;
        $this->touch();

        return $this;
    }

    public function getCommandArguments(): ?string
    {
        return $this->commandArguments;
    }

    public function setCommandArguments(?string $commandArguments): static
    {
        $this->commandArguments = $commandArguments;
        $this->touch();

        return $this;
    }

    public function getCronExpression(): string
    {
        return $this->cronExpression;
    }

    public function setCronExpression(string $cronExpression): static
    {
        $this->cronExpression = $cronExpression;
        $this->touch();

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;
        $this->touch();

        return $this;
    }

    public function getLastRunAt(): ?\DateTimeImmutable
    {
        return $this->lastRunAt;
    }

    public function markRunAt(\DateTimeImmutable $runAt): static
    {
        $this->lastRunAt = $runAt;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @return Collection<int, CronJobRun>
     */
    public function getRuns(): Collection
    {
        return $this->runs;
    }

    /**
     * VirtualCronJob (kod tabanlı görev) ile aynı okuma arayüzünü paylaşmak
     * için: AACP şablonları "[MANUEL]" / "[KOD]" rozetini bu bayrakla seçer
     * (bkz. VirtualCronJob::isCodeBased()).
     */
    public function isCodeBased(): bool
    {
        return false;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
