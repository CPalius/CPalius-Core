<?php

declare(strict_types=1);

namespace App\Core\Api\Entity;

use App\Core\Api\Repository\ApiIdempotencyRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * 24h replay cache for mutating machine requests. Scoped by API key + Idempotency-Key.
 */
#[ORM\Entity(repositoryClass: ApiIdempotencyRepository::class)]
#[ORM\Table(name: 'cp_api_idempotency')]
#[ORM\UniqueConstraint(name: 'uniq_api_idempotency_scope', columns: ['scope_hash'])]
#[ORM\Index(columns: ['expires_at'], name: 'idx_api_idempotency_expires')]
class ApiIdempotency
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'scope_hash', type: 'string', length: 64)]
    private string $scopeHash;

    #[ORM\Column(name: 'request_hash', type: 'string', length: 64)]
    private string $requestHash;

    #[ORM\Column(name: 'status_code', type: 'integer')]
    private int $statusCode;

    #[ORM\Column(name: 'response_body', type: 'text')]
    private string $responseBody;

    #[ORM\Column(name: 'expires_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;

    public function __construct(
        string $scopeHash,
        string $requestHash,
        int $statusCode,
        string $responseBody,
        \DateTimeImmutable $expiresAt,
    ) {
        $this->scopeHash = $scopeHash;
        $this->requestHash = $requestHash;
        $this->statusCode = $statusCode;
        $this->responseBody = $responseBody;
        $this->expiresAt = $expiresAt;
    }

    public function getScopeHash(): string
    {
        return $this->scopeHash;
    }

    public function getRequestHash(): string
    {
        return $this->requestHash;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getResponseBody(): string
    {
        return $this->responseBody;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }
}
