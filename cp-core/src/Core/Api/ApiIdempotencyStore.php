<?php

declare(strict_types=1);

namespace App\Core\Api;

use App\Core\Api\Entity\ApiIdempotency;
use App\Core\Api\Repository\ApiIdempotencyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Idempotency-Key for POST/PUT/PATCH/DELETE. Same key + different body => 409.
 */
final class ApiIdempotencyStore
{
    public const HEADER = 'Idempotency-Key';
    public const TTL_HOURS = 24;
    public const MAX_BODY_STORE = 65536;

    public function __construct(
        private readonly ApiIdempotencyRepository $repository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function isMutating(string $method): bool
    {
        return \in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    /**
     * @return JsonResponse|null Cached response, or 409 when the key was reused with a different body
     */
    public function replay(Request $request, ApiKey $apiKey): ?JsonResponse
    {
        $header = trim((string) $request->headers->get(self::HEADER, ''));
        if ($header === '' || !$this->isMutating($request->getMethod())) {
            return null;
        }

        if (!$this->isSafeKey($header)) {
            return new JsonResponse(['error' => 'Invalid Idempotency-Key'], 400);
        }

        $scope = $this->scopeHash($apiKey, $request, $header);
        $requestHash = $this->requestHash($request);
        $existing = $this->repository->findValid($scope, new \DateTimeImmutable());

        if ($existing === null) {
            return null;
        }

        if (!hash_equals($existing->getRequestHash(), $requestHash)) {
            return new JsonResponse(['error' => 'Idempotency-Key reuse with a different payload'], 409);
        }

        return new JsonResponse(
            json_decode($existing->getResponseBody(), true),
            $existing->getStatusCode(),
        );
    }

    public function remember(Request $request, ApiKey $apiKey, JsonResponse $response): void
    {
        $header = trim((string) $request->headers->get(self::HEADER, ''));
        if ($header === '' || !$this->isMutating($request->getMethod()) || !$this->isSafeKey($header)) {
            return;
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 500) {
            return;
        }

        $body = (string) $response->getContent();
        if (\strlen($body) > self::MAX_BODY_STORE) {
            return;
        }

        $row = new ApiIdempotency(
            $this->scopeHash($apiKey, $request, $header),
            $this->requestHash($request),
            $status,
            $body !== '' ? $body : '{}',
            (new \DateTimeImmutable())->modify('+'.self::TTL_HOURS.' hours'),
        );

        try {
            $this->entityManager->persist($row);
            $this->entityManager->flush();
        } catch (\Throwable) {
            // Unique race: first writer wins; caller already has the live response.
        }
    }

    private function isSafeKey(string $key): bool
    {
        return preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $key) === 1;
    }

    private function scopeHash(ApiKey $apiKey, Request $request, string $header): string
    {
        return hash('sha256', $apiKey->id."\n".$request->getMethod()."\n".$request->getPathInfo()."\n".$header);
    }

    private function requestHash(Request $request): string
    {
        return hash('sha256', $request->getMethod()."\n".$request->getPathInfo()."\n".$request->getContent());
    }
}
