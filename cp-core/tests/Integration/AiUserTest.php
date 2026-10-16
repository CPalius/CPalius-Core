<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Node;
use App\Entity\User;
use App\Tests\Support\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Modules\AiUser\Controller\Admin\AiUserAdminController;
use Modules\AiUser\Entity\AiUserJob;
use Modules\AiUser\Entity\AiUserPersona;
use Modules\AiUser\Service\AiUserService;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/** Persona setup in Studio, and the "who answers which new post" decision. No AI call is made. */
#[CoversNothing]
final class AiUserTest extends IntegrationTestCase
{
    private bool $signedIn = false;

    private function save(array $fields): void
    {
        $this->container();
        if (!$this->signedIn) {
            $this->pushRequest();
            $this->authenticateAs('admin');
            $this->signedIn = true;
        }

        /** @var CsrfTokenManagerInterface $csrf */
        $csrf = $this->container()->get('security.csrf.token_manager');
        $request = new Request(request: $fields + ['_token' => $csrf->getToken('admin_ai_user')->getValue()]);
        $request->setMethod('POST');

        $this->container()->get(AiUserAdminController::class)->save($request);
    }

    public function testNewNameCreatesAnAccountAndAPersonaOnlyOnce(): void
    {
        $name = 'ai_tester_'.bin2hex(random_bytes(3));
        $this->save(['user' => $name, 'persona' => 'curious', 'enabled' => '1', 'reply_chance' => '100']);
        $this->save(['user' => $name, 'persona' => 'again']);

        /** @var EntityManagerInterface $em */
        $em = $this->container()->get(EntityManagerInterface::class);
        $user = $em->getRepository(User::class)->findOneBy(['username' => $name]);
        self::assertInstanceOf(User::class, $user);

        $personas = $em->getRepository(AiUserPersona::class)->findBy(['userId' => $user->getId()]);
        self::assertCount(1, $personas);
        self::assertSame('curious', $personas[0]->persona);
        self::assertSame(100, $personas[0]->replyChance);
    }

    public function testDiscoverQueuesANewPostOnceAndIgnoresOwnPosts(): void
    {
        $name = 'ai_tester_'.bin2hex(random_bytes(3));
        $this->save(['user' => $name, 'persona' => 'x', 'enabled' => '1', 'blog_enabled' => '1', 'reply_chance' => '100', 'delay_min' => '0', 'delay_max' => '0']);

        /** @var EntityManagerInterface $em */
        $em = $this->container()->get(EntityManagerInterface::class);
        $persona = $em->getRepository(AiUserPersona::class)->findOneBy(['userId' => $em->getRepository(User::class)->findOneBy(['username' => $name])->getId()]);
        // createdAt is "now"; push it back so the post below counts as newer than the persona.
        $persona->createdAt = new \DateTimeImmutable('-1 hour');

        $post = new Node('AI test '.bin2hex(random_bytes(3)), 'ai-test-'.bin2hex(random_bytes(3)), 'post', 'en');
        $post->publish();
        $em->persist($post);
        $em->flush();

        /** @var AiUserService $service */
        $service = $this->container()->get(AiUserService::class);
        self::assertGreaterThanOrEqual(1, $service->discover());
        self::assertSame(0, $service->discover(), 'a second run must not queue the same post again');

        $job = $em->getRepository(AiUserJob::class)->findOneBy(['personaId' => $persona->id, 'sourceType' => AiUserJob::BLOG, 'sourceId' => $post->getId()]);
        self::assertInstanceOf(AiUserJob::class, $job);
        self::assertSame(AiUserJob::PENDING, $job->status);
    }
}
