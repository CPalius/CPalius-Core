<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Node;
use App\Tests\Support\IntegrationTestCase;
use Modules\Blog\Controller\Admin\PostAdminController;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * The writer's side panel on the public post page calls /admin/posts/{id}/quick.
 */
#[CoversNothing]
final class BlogQuickEditTest extends IntegrationTestCase
{
    private function call(PostAdminController $c, int $id, array $body, bool $validToken = true)
    {
        /** @var CsrfTokenManagerInterface $csrf */
        $csrf = $this->container()->get('security.csrf.token_manager');
        $request = new Request(content: json_encode($body));
        $request->setMethod('POST');
        $request->headers->set('X-CSRF-Token', $validToken ? $csrf->getToken('admin_post_form')->getValue() : 'bad');

        return $c->quick($id, $request);
    }

    public function testQuickActionsChangeTheirOwnFieldAndRefuseBadInput(): void
    {
        $this->container();
        $this->pushRequest();
        $this->authenticateAs('admin');

        $node = new Node('Hello', 'hello', 'post', 'tr');
        $this->em()->persist($node);
        $this->em()->flush();
        $id = (int) $node->getId();

        /** @var PostAdminController $c */
        $c = $this->container()->get(PostAdminController::class);

        self::assertSame(403, $this->call($c, $id, ['action' => 'comments', 'value' => false], false)->getStatusCode());

        self::assertSame(200, $this->call($c, $id, ['action' => 'comments', 'value' => false])->getStatusCode());
        self::assertSame(0, $node->getDataValue('comments_enabled'));

        $this->call($c, $id, ['action' => 'featured', 'value' => true]);
        self::assertSame(1, $node->getDataValue('is_featured'));

        // A future date turns "published" into "scheduled" instead of leaking early.
        $future = (new \DateTimeImmutable('+3 days'))->format('Y-m-d\TH:i');
        $this->call($c, $id, ['action' => 'schedule', 'status' => 'published', 'published_at' => $future]);
        self::assertSame(Node::STATUS_SCHEDULED, $node->getStatus());

        $this->call($c, $id, ['action' => 'schedule', 'status' => 'published', 'published_at' => '']);
        self::assertSame(Node::STATUS_PUBLISHED, $node->getStatus());

        $this->call($c, $id, ['action' => 'schedule', 'status' => 'draft']);
        self::assertSame(Node::STATUS_DRAFT, $node->getStatus());

        self::assertSame(400, $this->call($c, $id, ['action' => 'schedule', 'status' => 'nonsense'])->getStatusCode());
        self::assertSame(400, $this->call($c, $id, ['action' => 'schedule', 'status' => 'published', 'published_at' => 'not a date'])->getStatusCode());
        self::assertSame(400, $this->call($c, $id, ['action' => 'cover', 'asset_id' => 99999])->getStatusCode());
        self::assertSame(400, $this->call($c, $id, ['action' => 'whatever'])->getStatusCode());

        $this->call($c, $id, ['action' => 'cover', 'asset_id' => null]);
        self::assertNull($node->getDataValue('featured_image_asset_id'));
    }
}
