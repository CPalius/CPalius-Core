<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controller\Admin\AACPFieldController;
use App\Core\Field\Entity\FieldDefinition;
use App\Core\Field\FieldDefinitionRegistry;
use App\Core\Field\Repository\FieldDefinitionRepository;
use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * The field administration screen, through the real container.
 *
 * Two things are worth holding still here. A field's type is immutable once
 * rows exist — changing it after the fact would reinterpret stored values —
 * and the registry must be invalidated on every write, or the screen shows the
 * new field while the rest of the application still cannot see it.
 */
#[CoversClass(AACPFieldController::class)]
final class AacpFieldControllerTest extends IntegrationTestCase
{
    public function testIndexAndBundleAndNewFormsRender(): void
    {
        $container = $this->container();
        $em = $this->em();
        $this->pushRequest();
        $this->authenticateAs('admin');

        $em->persist(new FieldDefinition('page', 'subtitle', 'text', 'Subtitle'));
        $em->flush();

        /** @var FieldDefinitionRegistry $registry */
        $registry = $container->get(FieldDefinitionRegistry::class);
        $registry->invalidate();

        /** @var AACPFieldController $controller */
        $controller = $container->get(AACPFieldController::class);

        $index = $controller->index();
        self::assertSame(200, $index->getStatusCode());
        self::assertStringContainsString('page', (string) $index->getContent(), 'a bundle with fields is listed');

        $bundle = $controller->bundle('page');
        self::assertSame(200, $bundle->getStatusCode());
        self::assertStringContainsString('subtitle', (string) $bundle->getContent());

        // The creation form renders for a bundle that has no fields yet —
        // otherwise a new bundle could never get its first field.
        $new = $controller->new('empty_bundle', new Request());
        self::assertSame(200, $new->getStatusCode());

        $edit = $controller->edit('page', 'subtitle', new Request());
        self::assertSame(200, $edit->getStatusCode());
        self::assertStringContainsString('subtitle', (string) $edit->getContent());
    }

    public function testCreateEditDeleteRoundTrip(): void
    {
        $container = $this->container();
        $this->pushRequest();
        $this->authenticateAs('admin');

        /** @var CsrfTokenManagerInterface $csrf */
        $csrf = $container->get('security.csrf.token_manager');
        $token = $csrf->getToken('aacp_fields')->getValue();

        /** @var AACPFieldController $controller */
        $controller = $container->get(AACPFieldController::class);
        /** @var FieldDefinitionRepository $repository */
        $repository = $container->get(FieldDefinitionRepository::class);

        $controller->save($this->post($token, [
            'bundle' => 'page',
            'name' => 'lead',
            'type' => 'text',
            'label' => 'Lead paragraph',
            'weight' => '3',
            'queryable' => '1',
        ]));

        $created = $repository->findOneByBundleAndName('page', 'lead');
        self::assertInstanceOf(FieldDefinition::class, $created);
        self::assertSame('Lead paragraph', $created->getLabel());
        self::assertSame('text', $created->getType());
        self::assertTrue($created->isQueryable());
        self::assertSame(3, $created->getWeight());

        // Editing the same field: the label changes, the type does not. An
        // unchecked box is an absent key, which must read as false rather than
        // as "leave it alone".
        $controller->save($this->post($token, [
            'bundle' => 'page',
            'name' => 'lead',
            'type' => 'integer',
            'label' => 'Lead',
            'weight' => '4',
        ]));

        $edited = $repository->findOneByBundleAndName('page', 'lead');
        self::assertInstanceOf(FieldDefinition::class, $edited);
        self::assertSame('Lead', $edited->getLabel());
        self::assertSame('text', $edited->getType(), 'the type is immutable once the field exists');
        self::assertFalse($edited->isQueryable(), 'an absent checkbox clears the flag');

        // A bad name is rejected without creating anything.
        $controller->save($this->post($token, [
            'bundle' => 'page',
            'name' => 'Not A Name',
            'type' => 'text',
        ]));
        self::assertNull($repository->findOneByBundleAndName('page', 'Not A Name'));

        $controller->delete('page', 'lead', $this->post($token, []));
        self::assertNull($repository->findOneByBundleAndName('page', 'lead'), 'delete removes the definition');
    }

    /**
     * @param array<string, string> $fields
     */
    private function post(string $token, array $fields): Request
    {
        $request = new Request(request: ['_token' => $token] + $fields);
        $request->setMethod('POST');

        return $request;
    }
}
