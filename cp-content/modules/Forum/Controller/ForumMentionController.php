<?php

declare(strict_types=1);

namespace Modules\Forum\Controller;

use App\Core\Account\UserAvatarService;
use App\Entity\User;
use App\Repository\UserRepository;
use Modules\Forum\Repository\ForumPostRepository;
use Modules\Forum\Repository\ForumTopicRepository;
use Modules\Forum\Service\ForumSmilieCatalog;
use Modules\Forum\Service\ForumSpoilerGate;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The small lookups the composer and the post body need at runtime.
 *
 * Three endpoints, all read-only and all cheap on purpose — they are called
 * while somebody is typing, or on hover, and a slow answer is the same as no
 * answer.
 *
 * Signed-in only. Not because the data is secret (every name here is on a
 * public profile page) but because an open username-enumeration endpoint is a
 * gift to anyone assembling a login-attack list.
 */
#[Route('/forums/api', name: 'forum_api_')]
#[IsGranted('IS_AUTHENTICATED')]
final class ForumMentionController extends AbstractController
{
    /** Enough to choose from without turning the flyout into a scroll. */
    private const MENTION_LIMIT = 8;

    /** Matches ForumMentionParser's pattern, so what autocompletes is what links. */
    private const USERNAME_PATTERN = '[a-zA-Z0-9_.-]{1,32}';

    /** A card is a teaser; past this the reader should open the post itself. */
    private const PREVIEW_CHARS = 320;

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly ForumPostRepository $postRepository,
        private readonly ForumTopicRepository $topicRepository,
        private readonly UserAvatarService $avatarService,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly ForumSpoilerGate $spoilerGate,
        private readonly ForumSmilieCatalog $smilies,
    ) {
    }

    /**
     * The composer picker. Not secret — the same set is already visible in
     * every public post — but signed-in so a scraper cannot use it as a
     * cheap "is this a forum" probe.
     */
    #[Route('/smilies', name: 'smilies', methods: ['GET'])]
    public function smilies(): JsonResponse
    {
        return $this->json(['smilies' => $this->smilies->forEditor()]);
    }

    /**
     * Username suggestions for the composer's @ autocomplete.
     */
    #[Route('/mentions', name: 'mentions', methods: ['GET'])]
    public function mentions(Request $request): JsonResponse
    {
        $query = trim((string) $request->query->get('q', ''));

        // One character matches most of the member list and is not a choice
        // anybody is making yet, so the flyout stays closed until two.
        if (mb_strlen($query) < 2) {
            return $this->json(['items' => []]);
        }

        $items = [];

        foreach ($this->userRepository->findForMentionAutocomplete($query, self::MENTION_LIMIT) as $user) {
            $items[] = [
                'id' => '@'.$user->getProfileSlug(),
                'name' => $user->getPublicDisplayName(),
                'slug' => $user->getProfileSlug(),
                'avatar' => $this->avatarService->resolveUrl($user),
                'url' => $this->profileUrl($user),
            ];
        }

        return $this->json(['items' => $items]);
    }

    /**
     * The hover card behind an @mention.
     */
    #[Route('/member/{username}/card', name: 'member_card', methods: ['GET'], requirements: ['username' => self::USERNAME_PATTERN])]
    public function memberCard(string $username): JsonResponse
    {
        $user = $this->resolveUser($username);

        if (!$user instanceof User) {
            return $this->json(['ok' => false], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'ok' => true,
            'name' => $user->getPublicDisplayName(),
            'title' => trim((string) $user->getCustomTitle()),
            'avatar' => $this->avatarService->resolveUrl($user),
            'joined' => $user->getCreatedAt()->format('d.m.Y'),
            'postCount' => $this->postRepository->countPublicByAuthor($user),
            'url' => $this->profileUrl($user),
        ]);
    }

    /**
     * The hover card behind a #N post reference.
     *
     * N is the post's position in the topic, which is what the reader sees next
     * to every post — not the database id. Scoped to one topic for the same
     * reason: "#3" means the third post of *this* conversation, and resolving
     * it anywhere else would quietly show a stranger's post.
     */
    #[Route('/topic/{topicId}/post/{number}/preview', name: 'post_preview', methods: ['GET'], requirements: ['topicId' => '\d+', 'number' => '\d+'])]
    public function postPreview(int $topicId, int $number): JsonResponse
    {
        $topic = $this->topicRepository->find($topicId);

        if ($topic === null || $number < 1) {
            return $this->json(['ok' => false], Response::HTTP_NOT_FOUND);
        }

        $posts = $this->postRepository->createTopicPostsQueryBuilder($topic)
            ->setFirstResult($number - 1)
            ->setMaxResults(1)
            ->getQuery()
            ->getResult();

        $post = $posts[0] ?? null;

        if ($post === null) {
            return $this->json(['ok' => false], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'ok' => true,
            'number' => $number,
            'author' => $post->getPosterName(),
            'avatar' => $this->avatarService->resolveUrl($post->getAuthor()),
            'date' => $post->getCreatedAt()->format('d.m.Y H:i'),
            // Plain text, not HTML: a card is a glance, and re-rendering a post
            // body here would drag embeds, lightboxes and scripts into a tooltip.
            'excerpt' => $this->excerpt($this->spoilerGate->isUnlocked($post) ? $post->getBody() : $this->spoilerGate->quotePlain($post->getBody())),
            'anchor' => '#post'.$post->getId(),
        ]);
    }

    private function resolveUser(string $username): ?User
    {
        // getProfileSlug() falls back to the numeric id for a username that is
        // not URL-safe, so the lookup has to accept both forms.
        return ctype_digit($username)
            ? $this->userRepository->find((int) $username)
            : $this->userRepository->findOneByUsername($username);
    }

    private function profileUrl(User $user): string
    {
        return $this->urlGenerator->generate('forum_profile', ['username' => $user->getProfileSlug()]);
    }

    private function excerpt(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($text) > self::PREVIEW_CHARS
            ? mb_substr($text, 0, self::PREVIEW_CHARS).'…'
            : $text;
    }
}
