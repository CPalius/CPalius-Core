<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Core\Field\Entity\FieldDefinition;
use App\Core\Field\FieldDefinitionRegistry;
use App\Core\Field\FieldValuePersister;
use App\Entity\User;
use App\Form\DTO\UserFormModel;
use App\Form\UserType;
use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Form\FormFactoryInterface;

/**
 * T1.1's payoff: the Field API stopped being about nodes.
 *
 * A field defined for the "user" bundle must appear on the user form and
 * persist into User::$data, with no user-specific field code anywhere. If this
 * only ever worked for Node, "entity-agnostic" would be a claim rather than a
 * fact — and the second consumer is exactly where such claims break.
 */
#[CoversClass(UserType::class)]
final class UserFieldableFormTest extends IntegrationTestCase
{
    public function testUserBundleFieldAppearsOnFormAndPersists(): void
    {
        $container = $this->container();
        $em = $this->em();

        // Before any definition exists the form has no "fields" child at all:
        // zero configuration must mean zero change to the screen.
        /** @var FormFactoryInterface $forms */
        $forms = $container->get(FormFactoryInterface::class);
        self::assertFalse(
            $forms->create(UserType::class, new UserFormModel())->has('fields'),
            'no definitions, no extra form section',
        );

        $definition = new FieldDefinition('user', 'job_title', 'text', 'Job title');
        $em->persist($definition);
        $em->flush();

        /** @var FieldDefinitionRegistry $registry */
        $registry = $container->get(FieldDefinitionRegistry::class);
        $registry->invalidate();

        $user = new User('after@example.test');
        $user->setPassword('x')->setStatus(User::STATUS_ACTIVE);

        // The form edits a DTO, not the entity; the field section is bolted
        // on as an unmapped child, so it is independent of that choice.
        $form = $forms->create(UserType::class, UserFormModel::fromUser($user), ['field_locale' => 'und']);
        self::assertTrue($form->has('fields'), 'the field section appears once a definition exists');
        self::assertTrue($form->get('fields')->has('job_title'));

        // Persisting goes through the same entity-agnostic writer the node form
        // uses; User carries the value in its hybrid $data column.
        /** @var FieldValuePersister $persister */
        $persister = $container->get(FieldValuePersister::class);
        $violations = $persister->persist($user, ['job_title' => 'Archivist'], 'und');

        self::assertSame([], $violations);

        $em->persist($user);
        $em->flush();
        $em->clear();

        $reloaded = $em->getRepository(User::class)->findOneBy(['email' => 'after@example.test']);
        self::assertInstanceOf(User::class, $reloaded);
        self::assertSame('Archivist', $reloaded->getFieldableData()['job_title'] ?? null);
        self::assertSame('user', $reloaded->fieldableEntityTypeId());
        self::assertSame('user', $reloaded->fieldableBundle());
    }
}
