<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controller\Admin\AACPDisplayController;
use App\Core\Config\Provider\EntityDisplayConfigProvider;
use App\Core\Display\Entity\EntityDisplay;
use App\Core\Display\EntityDisplayRegistry;
use App\Core\Field\Api\FieldValueSerializer;
use App\Core\Field\Display\FieldRenderer;
use App\Core\Field\Entity\FieldDefinition;
use App\Core\Field\FieldDefinitionRegistry;
use App\Entity\Node;
use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * T2.2 — view modes resolved from one configuration.
 *
 * Drupal's Manage Display is theme-only: JSON:API ignores it, so "teaser" has
 * to be defined twice and the two definitions drift. The claim being tested is
 * that here a single EntityDisplay row drives both the rendered output and the
 * serialised output, so hiding a field in "teaser" hides it in both.
 */
#[CoversClass(EntityDisplayRegistry::class)]
#[CoversClass(EntityDisplay::class)]
final class EntityDisplayTest extends IntegrationTestCase
{
    public function testViewModeHidesAFieldConsistentlyInRenderAndApi(): void
    {
        $container = $this->container();
        $em = $this->em();

        $this->defineFields();

        // Hide internal_notes in "teaser" only. Everything unconfigured keeps
        // the field's own default, so zero configuration means zero change.
        $hidden = new EntityDisplay('page', 'teaser', 'internal_notes');
        $hidden->setVisible(false);
        $em->persist($hidden);
        $em->flush();

        /** @var EntityDisplayRegistry $displays */
        $displays = $container->get(EntityDisplayRegistry::class);
        $displays->invalidate();

        $node = new Node('Handbook', 'handbook', 'page', 'en');
        $node->setData(['summary' => 'Public summary', 'internal_notes' => 'Editors only']);
        $em->persist($node);
        $em->flush();

        /** @var FieldRenderer $renderer */
        $renderer = $container->get(FieldRenderer::class);
        /** @var FieldValueSerializer $serializer */
        $serializer = $container->get(FieldValueSerializer::class);

        $defaultHtml = $renderer->renderEntity($node, 'default');
        $teaserHtml = $renderer->renderEntity($node, 'teaser');
        $defaultApi = $serializer->serialize($node, 'default');
        $teaserApi = $serializer->serialize($node, 'teaser');

        // Default: present on both surfaces.
        self::assertStringContainsString('Editors only', $defaultHtml);
        self::assertArrayHasKey('internal_notes', $defaultApi);

        // Teaser: absent from both, from the same single row.
        self::assertStringNotContainsString('Editors only', $teaserHtml);
        self::assertArrayNotHasKey('internal_notes', $teaserApi);

        // The other field is untouched, so this is a targeted hide and not an
        // empty view mode.
        self::assertStringContainsString('Public summary', $teaserHtml);
        self::assertArrayHasKey('summary', $teaserApi);
    }

    public function testAacpControllerSavesTheMatrix(): void
    {
        $container = $this->container();
        $this->defineFields();
        $this->pushRequest();
        $this->authenticateAs('admin');

        /** @var CsrfTokenManagerInterface $csrf */
        $csrf = $container->get('security.csrf.token_manager');

        // The screen posts one matrix for every view mode at once; a field with
        // no "visible" key in the payload is the unchecked checkbox.
        $request = new Request(request: [
            '_token' => $csrf->getToken('aacp_display')->getValue(),
            'display' => [
                'teaser' => [
                    'summary' => ['visible' => '1', 'weight' => '5', 'label' => EntityDisplay::LABEL_HIDDEN],
                    'internal_notes' => ['weight' => '9'],
                ],
            ],
        ]);
        $request->setMethod('POST');

        /** @var AACPDisplayController $controller */
        $controller = $container->get(AACPDisplayController::class);
        $controller->save('page', $request);

        /** @var EntityDisplayRegistry $displays */
        $displays = $container->get(EntityDisplayRegistry::class);
        $displays->invalidate();

        $visible = $this->visibleNames($displays, 'teaser');
        self::assertContains('summary', $visible);
        self::assertNotContains('internal_notes', $visible, 'an absent checkbox means hidden, not unchanged');
    }

    public function testConfigProviderRoundTripIsAuthoritative(): void
    {
        $container = $this->container();
        $em = $this->em();
        $this->defineFields();

        $row = new EntityDisplay('page', 'teaser', 'internal_notes');
        $row->setVisible(false)->setWeight(7);
        $em->persist($row);
        $em->flush();

        /** @var EntityDisplayConfigProvider $provider */
        $provider = $container->get(EntityDisplayConfigProvider::class);

        self::assertContains('display.page', $provider->documents());
        self::assertTrue($provider->ownsDocument('display.page'));

        $exported = $provider->exportDocument('display.page');
        self::assertSame([], $provider->diffDocument('display.page', $exported), 'an export re-imports with no diff');

        // Authoritative, unlike the taxonomy provider: a row dropped from the
        // document is removed on import, so the field falls back to its own
        // default. Display config carries no content, so nothing is lost.
        $provider->importDocument('display.page', []);
        $em->flush();

        /** @var EntityDisplayRegistry $displays */
        $displays = $container->get(EntityDisplayRegistry::class);
        $displays->invalidate();

        self::assertContains(
            'internal_notes',
            $this->visibleNames($displays, 'teaser'),
            'with the override gone the field returns to its default visibility',
        );
    }

    /**
     * visibleFields() yields ResolvedDisplayField objects; the assertions
     * here are about which fields survive, not how they render.
     *
     * @return list<string>
     */
    private function visibleNames(EntityDisplayRegistry $displays, string $viewMode): array
    {
        return array_map(
            static fn ($row): string => $row->definition->getName(),
            $displays->visibleFields('page', $viewMode),
        );
    }

    private function defineFields(): void
    {
        $em = $this->em();

        foreach ([['summary', 'Summary'], ['internal_notes', 'Internal notes']] as [$name, $label]) {
            $em->persist(new FieldDefinition('page', $name, 'text', $label));
        }

        $em->flush();

        /** @var FieldDefinitionRegistry $fields */
        $fields = $this->container()->get(FieldDefinitionRegistry::class);
        $fields->invalidate();
    }
}
