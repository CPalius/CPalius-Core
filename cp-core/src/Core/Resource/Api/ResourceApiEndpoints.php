<?php

declare(strict_types=1);

namespace App\Core\Resource\Api;

use App\Core\Api\ApiKeyService;
use App\Core\Api\Attribute\CpApi;
use App\Core\Resource\ResourceDefinition;
use App\Core\Resource\ResourceRegistry;
use App\Core\Webhook\WebhookEventEmitter;
use App\Core\Workflow\Exception\WorkflowException;
use App\Core\Workflow\WorkflowManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Generic machine surface for #[CpResource] entities. No business schema in core.
 * Capability is interpolated by the gateway ({name}.view|create|edit|delete).
 */
final class ResourceApiEndpoints
{
    private const MAX_LIMIT = 50;
    private const DENY_FIELDS = [
        'password', 'passwordHash', 'secret', 'token', 'hash', 'salt',
        'apiKey', 'rememberMeToken', 'confirmationToken', 'plainPassword',
    ];

    public function __construct(
        private readonly ResourceRegistry $resourceRegistry,
        private readonly EntityManagerInterface $entityManager,
        private readonly ApiKeyService $apiKeyService,
        private readonly WebhookEventEmitter $webhookEventEmitter,
        private readonly WorkflowManager $workflowManager,
    ) {
    }

    #[CpApi(path: '/resources/{name}', methods: ['GET'], public: false, capability: '{name}.view')]
    public function list(Request $request, string $name): JsonResponse
    {
        $definition = $this->requireResource($name, 'view');
        if ($definition instanceof JsonResponse) {
            return $definition;
        }
        $denied = $this->denyCrossTenant($request, $definition);
        if ($denied !== null) {
            return $denied;
        }

        $limit = min(self::MAX_LIMIT, max(1, $request->query->getInt('limit', 20)));
        $metadata = $this->entityManager->getClassMetadata($definition->entityClass);
        $idField = $this->identifierField($metadata);
        $qb = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from($definition->entityClass, 'e')
            ->orderBy('e.'.$idField, 'ASC')
            ->setMaxResults($limit + 1);

        $cursor = trim((string) $request->query->get('cursor', ''));
        if ($cursor !== '' && preg_match('/^[A-Za-z0-9+\/]+=*$/', $cursor) === 1) {
            $decoded = base64_decode($cursor, true);
            if (\is_string($decoded) && $decoded !== '') {
                $qb->andWhere('e.'.$idField.' > :cursor')->setParameter('cursor', $decoded);
            }
        }

        /** @var list<object> $rows */
        $rows = $qb->getQuery()->getResult();
        $hasMore = \count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $next = null;
        if ($hasMore && $rows !== []) {
            $last = $rows[\count($rows) - 1];
            $id = $metadata->getFieldValue($last, $idField);
            $next = base64_encode((string) $id);
        }

        $fields = $this->requestedFields($request);
        $data = [];
        foreach ($rows as $row) {
            $data[] = $this->summarize($metadata, $row, $fields);
        }

        return new JsonResponse(['data' => $data, 'next_cursor' => $next]);
    }

    #[CpApi(path: '/resources/{name}/{id}', methods: ['GET'], public: false, capability: '{name}.view')]
    public function show(Request $request, string $name, string $id): JsonResponse
    {
        $definition = $this->requireResource($name, 'view');
        if ($definition instanceof JsonResponse) {
            return $definition;
        }
        $denied = $this->denyCrossTenant($request, $definition);
        if ($denied !== null) {
            return $denied;
        }

        $entity = $this->findEntity($definition, $id);
        if ($entity === null) {
            return new JsonResponse(['error' => 'Not Found'], Response::HTTP_NOT_FOUND);
        }

        $metadata = $this->entityManager->getClassMetadata($definition->entityClass);

        return new JsonResponse(['data' => $this->summarize($metadata, $entity, $this->requestedFields($request))]);
    }

    #[CpApi(path: '/resources/{name}', methods: ['POST'], public: false, capability: '{name}.create')]
    public function create(Request $request, string $name): JsonResponse
    {
        $definition = $this->requireResource($name, 'create');
        if ($definition instanceof JsonResponse) {
            return $definition;
        }
        $denied = $this->denyCrossTenant($request, $definition);
        if ($denied !== null) {
            return $denied;
        }

        $body = $this->jsonObject($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $class = $definition->entityClass;
        $entity = new $class();
        $metadata = $this->entityManager->getClassMetadata($class);
        $this->assign($metadata, $entity, $body, $definition);

        $this->entityManager->persist($entity);
        $this->entityManager->flush();

        $id = $metadata->getFieldValue($entity, $this->identifierField($metadata));
        $this->webhookEventEmitter->emit(
            'resource.'.$name.'.created',
            ['id' => (string) $id],
            $this->apiKeyService->authenticate($request)?->tenantId,
            $name,
        );

        return new JsonResponse(['data' => $this->summarize($metadata, $entity, [])], Response::HTTP_CREATED);
    }

    #[CpApi(path: '/resources/{name}/{id}', methods: ['PATCH'], public: false, capability: '{name}.edit')]
    public function patch(Request $request, string $name, string $id): JsonResponse
    {
        $definition = $this->requireResource($name, 'edit');
        if ($definition instanceof JsonResponse) {
            return $definition;
        }
        $denied = $this->denyCrossTenant($request, $definition);
        if ($denied !== null) {
            return $denied;
        }

        $entity = $this->findEntity($definition, $id);
        if ($entity === null) {
            return new JsonResponse(['error' => 'Not Found'], Response::HTTP_NOT_FOUND);
        }

        $body = $this->jsonObject($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $metadata = $this->entityManager->getClassMetadata($definition->entityClass);
        $this->assign($metadata, $entity, $body, $definition);
        $this->entityManager->flush();

        $this->webhookEventEmitter->emit(
            'resource.'.$name.'.updated',
            ['id' => $id],
            $this->apiKeyService->authenticate($request)?->tenantId,
            $name,
        );

        return new JsonResponse(['data' => $this->summarize($metadata, $entity, [])]);
    }

    #[CpApi(path: '/resources/{name}/{id}', methods: ['DELETE'], public: false, capability: '{name}.delete')]
    public function delete(Request $request, string $name, string $id): JsonResponse
    {
        $definition = $this->requireResource($name, 'delete');
        if ($definition instanceof JsonResponse) {
            return $definition;
        }
        $denied = $this->denyCrossTenant($request, $definition);
        if ($denied !== null) {
            return $denied;
        }

        $entity = $this->findEntity($definition, $id);
        if ($entity === null) {
            return new JsonResponse(['error' => 'Not Found'], Response::HTTP_NOT_FOUND);
        }

        $this->entityManager->remove($entity);
        $this->entityManager->flush();

        $this->webhookEventEmitter->emit(
            'resource.'.$name.'.deleted',
            ['id' => $id],
            $this->apiKeyService->authenticate($request)?->tenantId,
            $name,
        );

        return new JsonResponse(['ok' => true]);
    }

    #[CpApi(path: '/resources/{name}/{id}/transition', methods: ['POST'], public: false, capability: '{name}.edit')]
    public function transition(Request $request, string $name, string $id): JsonResponse
    {
        $definition = $this->requireResource($name, 'edit');
        if ($definition instanceof JsonResponse) {
            return $definition;
        }
        if ($definition->workflow === null || $definition->workflow === '') {
            return new JsonResponse(['error' => 'Not Found'], Response::HTTP_NOT_FOUND);
        }
        $denied = $this->denyCrossTenant($request, $definition);
        if ($denied !== null) {
            return $denied;
        }

        $entity = $this->findEntity($definition, $id);
        if ($entity === null) {
            return new JsonResponse(['error' => 'Not Found'], Response::HTTP_NOT_FOUND);
        }

        $body = $this->jsonObject($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $transitionName = trim((string) ($body['transition'] ?? ''));
        if ($transitionName === '') {
            return new JsonResponse(['error' => 'Missing "transition"'], Response::HTTP_BAD_REQUEST);
        }
        $comment = isset($body['comment']) && \is_string($body['comment']) ? mb_substr($body['comment'], 0, 500) : null;

        try {
            $result = $this->workflowManager->apply($entity, $definition->workflow, $transitionName, $comment);
        } catch (WorkflowException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->entityManager->flush();

        $this->webhookEventEmitter->emit(
            'resource.'.$name.'.transitioned',
            ['id' => $id, 'from' => $result['from'], 'to' => $result['to'], 'transition' => $transitionName],
            $this->apiKeyService->authenticate($request)?->tenantId,
            $name,
        );

        return new JsonResponse(['data' => ['id' => $id] + $result]);
    }

    private function requireResource(string $name, string $action): ResourceDefinition|JsonResponse
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/', $name) !== 1) {
            return new JsonResponse(['error' => 'Not Found'], Response::HTTP_NOT_FOUND);
        }

        $definition = $this->resourceRegistry->getByName($name);
        if ($definition === null || !\in_array($action, $definition->capabilities, true)) {
            return new JsonResponse(['error' => 'Not Found'], Response::HTTP_NOT_FOUND);
        }

        return $definition;
    }

    private function denyCrossTenant(Request $request, ResourceDefinition $definition): ?JsonResponse
    {
        if (!$definition->multiTenant) {
            return null;
        }

        $key = $this->apiKeyService->authenticate($request);
        if ($key === null || $key->tenantId === null || $key->tenantId === '') {
            return new JsonResponse(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    private function findEntity(ResourceDefinition $definition, string $id): ?object
    {
        if ($id === '' || \strlen($id) > 64) {
            return null;
        }

        return $this->entityManager->find($definition->entityClass, $id);
    }

    /**
     * @return array<string, mixed>|JsonResponse
     */
    private function jsonObject(Request $request): array|JsonResponse
    {
        try {
            $decoded = json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        if (!\is_array($decoded) || array_is_list($decoded)) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        return $decoded;
    }

    /**
     * @return list<string>
     */
    private function requestedFields(Request $request): array
    {
        $raw = trim((string) $request->query->get('fields', ''));
        if ($raw === '') {
            return [];
        }

        $fields = [];
        foreach (explode(',', $raw) as $field) {
            $field = trim($field);
            if ($field !== '' && preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,62}$/', $field) === 1) {
                $fields[] = $field;
            }
        }

        return array_slice($fields, 0, 16);
    }

    /**
     * @param ClassMetadata<object> $metadata
     * @param list<string>          $fields
     *
     * @return array<string, mixed>
     */
    private function summarize(ClassMetadata $metadata, object $entity, array $fields): array
    {
        $allowed = $this->safeFieldNames($metadata);
        if ($fields !== []) {
            $allowed = array_values(array_intersect($allowed, $fields));
        } else {
            $allowed = array_slice($allowed, 0, 12);
        }

        $out = [];
        foreach ($allowed as $field) {
            $value = $metadata->getFieldValue($entity, $field);
            if ($value instanceof \DateTimeInterface) {
                $value = $value->format(\DATE_ATOM);
            }
            if (\is_scalar($value) || $value === null) {
                $out[$field] = $value;
            }
        }

        return $out;
    }

    /**
     * @param ClassMetadata<object> $metadata
     * @param array<string, mixed>  $body
     */
    private function assign(ClassMetadata $metadata, object $entity, array $body, ResourceDefinition $definition): void
    {
        $idField = $this->identifierField($metadata);
        foreach ($body as $field => $value) {
            if (!\is_string($field) || $field === $idField || !$this->isSafeField($field) || !$metadata->hasField($field)) {
                continue;
            }
            if ($definition->multiTenant && $field === 'tenantId') {
                continue;
            }
            if (!\is_scalar($value) && $value !== null) {
                continue;
            }
            $metadata->setFieldValue($entity, $field, $value);
        }
    }

    /**
     * @param ClassMetadata<object> $metadata
     *
     * @return list<string>
     */
    private function safeFieldNames(ClassMetadata $metadata): array
    {
        $names = [];
        foreach ($metadata->getFieldNames() as $field) {
            if ($this->isSafeField($field)) {
                $names[] = $field;
            }
        }

        return $names;
    }

    private function isSafeField(string $field): bool
    {
        return !\in_array($field, self::DENY_FIELDS, true);
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private function identifierField(ClassMetadata $metadata): string
    {
        $ids = $metadata->getIdentifierFieldNames();

        return $ids[0] ?? 'id';
    }
}
