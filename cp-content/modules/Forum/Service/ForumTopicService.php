<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

use App\Core\Content\RichTextSanitizer;
use App\Entity\ForumPost;
use App\Entity\ForumPostDislike;
use App\Entity\ForumPostLike;
use App\Entity\ForumSection;
use App\Entity\ForumTopic;
use App\Entity\ForumTopicPrefix;
use App\Entity\User;
use App\Repository\ForumPostDislikeRepository;
use App\Repository\ForumPostLikeRepository;
use App\Repository\ForumPostRepository;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Forum\ForumDictionary;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Konu oluşturma, yanıt ve silme işlemleri — Cotonti forums.newtopic.php /
 * forums.posts.php mantığının servis katmanı uyarlaması.
 */
final class ForumTopicService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ForumPostRepository $postRepository,
        private readonly ForumPostLikeRepository $postLikeRepository,
        private readonly ForumPostDislikeRepository $postDislikeRepository,
        private readonly ForumStatsService $statsService,
        private readonly RichTextSanitizer $richTextSanitizer,
    ) {
    }

    public function createTopic(
        ForumSection $section,
        User $author,
        string $title,
        string $description,
        string $body,
        bool $isPrivate,
        Request $request,
        ?ForumTopicPrefix $prefix = null,
    ): ForumTopic {
        $topic = new ForumTopic($section, $title, $author->getFullName());
        $topic->setSlug($this->generateTopicSlug($title));
        $topic->setDescription($description !== '' ? $description : null);
        $topic->setPrefix($prefix);
        $topic->setFirstPoster($author);
        $topic->setMode($isPrivate ? ForumTopic::MODE_PRIVATE : ForumTopic::MODE_NORMAL);
        $topic->setPostCount(1);

        $post = new ForumPost($topic, $section, $author->getFullName(), $this->sanitizeBody($body));
        $post->setAuthor($author);
        $post->setPosterIp($request->getClientIp());

        $topic->setLastPoster($author);
        $topic->setLastPosterName($author->getFullName());
        $topic->setPreview(mb_substr(strip_tags($post->getBody()), 0, 128));

        $this->entityManager->persist($topic);
        $this->entityManager->persist($post);
        $this->entityManager->flush();

        $this->statsService->syncSection($section);

        return $topic;
    }

    public function addReply(ForumTopic $topic, User $author, string $body, Request $request): ForumPost
    {
        $post = new ForumPost($topic, $topic->getSection(), $author->getFullName(), $this->sanitizeBody($body));
        $post->setAuthor($author);
        $post->setPosterIp($request->getClientIp());

        $topic->setLastPoster($author);
        $topic->setLastPosterName($author->getFullName());
        $topic->touch();

        $this->entityManager->persist($post);
        $this->entityManager->flush();

        $this->statsService->syncTopic($topic);

        return $post;
    }

    public function deleteTopic(ForumTopic $topic): void
    {
        $section = $topic->getSection();
        $this->entityManager->remove($topic);
        $this->entityManager->flush();
        $this->statsService->syncSection($section);
    }

    /**
     * Konuyu başka bir bölüme taşır — Cotonti forums.topics.php "move"
     * eyleminin karşılığı. $keepRedirect true ise eski bölümde, gerçek
     * konuya yönlendiren, mesajsız bir "hayalet" konu bırakılır.
     */
    public function moveTopic(ForumTopic $topic, ForumSection $target, bool $keepRedirect): void
    {
        $origin = $topic->getSection();

        if ($keepRedirect) {
            $ghost = new ForumTopic($origin, $topic->getTitle(), $topic->getFirstPosterName());
            $ghost->setSlug($topic->getSlug());
            $ghost->setMovedToTopic($topic);
            $ghost->setState(ForumTopic::STATE_LOCKED);
            $this->entityManager->persist($ghost);
        }

        $topic->setSection($target);
        $topic->touch();

        foreach ($this->postRepository->findByTopic($topic) as $post) {
            $post->setSection($target);
        }

        $this->entityManager->flush();

        $this->statsService->syncSection($origin);
        $this->statsService->syncSection($target);
    }

    public function canEditPost(ForumPost $post, ?User $user, bool $isModerator): bool
    {
        if ($user === null) {
            return false;
        }

        if ($isModerator) {
            return true;
        }

        if ($post->getAuthor()?->getId() !== $user->getId()) {
            return false;
        }

        $timeout = ForumDictionary::DEFAULT_EDIT_TIMEOUT_MINUTES * 60;
        $elapsed = time() - $post->getCreatedAt()->getTimestamp();

        return $elapsed <= $timeout;
    }

    public function updatePost(ForumPost $post, User $editor, string $body, ?string $topicTitle = null): void
    {
        $post->setBody($this->sanitizeBody($body));
        $post->setUpdatedAt(new \DateTimeImmutable());
        $post->setUpdatedByName($editor->getFullName());

        if ($topicTitle !== null) {
            $post->getTopic()->setTitle($topicTitle);
            $post->getTopic()->setSlug($this->generateTopicSlug($topicTitle));
        }

        $this->entityManager->flush();
        $this->statsService->syncTopic($post->getTopic());
    }

    /**
     * Beğeniyi açar/kapatır ve yeni durumu döndürür (true = artık beğenilmiş).
     * Beğeni açılırken aynı kullanıcının beğenmemesi varsa kaldırılır.
     */
    public function toggleLike(ForumPost $post, User $user): bool
    {
        $existing = $this->postLikeRepository->findOneByPostAndUser($post, $user);

        if ($existing !== null) {
            $this->entityManager->remove($existing);
            $this->entityManager->flush();

            return false;
        }

        $dislike = $this->postDislikeRepository->findOneByPostAndUser($post, $user);
        if ($dislike !== null) {
            $this->entityManager->remove($dislike);
        }

        $this->entityManager->persist(new ForumPostLike($post, $user));
        $this->entityManager->flush();

        return true;
    }

    /**
     * Beğenmemeyi açar/kapatır (true = artık beğenilmemiş).
     * Beğenmeme açılırken aynı kullanıcının beğenisi varsa kaldırılır.
     */
    public function toggleDislike(ForumPost $post, User $user): bool
    {
        $existing = $this->postDislikeRepository->findOneByPostAndUser($post, $user);

        if ($existing !== null) {
            $this->entityManager->remove($existing);
            $this->entityManager->flush();

            return false;
        }

        $like = $this->postLikeRepository->findOneByPostAndUser($post, $user);
        if ($like !== null) {
            $this->entityManager->remove($like);
        }

        $this->entityManager->persist(new ForumPostDislike($post, $user));
        $this->entityManager->flush();

        return true;
    }

    public function deletePost(ForumPost $post): void
    {
        $topic = $post->getTopic();
        $postCount = $this->postRepository->countByTopic($topic);

        if ($postCount <= 1) {
            $this->deleteTopic($topic);

            return;
        }

        $this->entityManager->remove($post);
        $this->entityManager->flush();
        $this->statsService->syncTopic($topic);
    }

    /**
     * Yanıt/konu metin alanı düz metin bir <textarea>'dır (zengin metin
     * editörü yok) — kullanıcı satır sonlarını, sanitize edilip |raw
     * basılan HTML çıktısında görebilsin diye önce nl2br uygulanır, sonra
     * RichTextSanitizer XSS'e karşı temizler (bkz. Manifesto Law 5.3).
     */
    private function sanitizeBody(string $body): string
    {
        return $this->richTextSanitizer->sanitize(nl2br(trim($body), false));
    }

    /**
     * Konu URL'i için SEO amaçlı slug — bkz. ForumFrontController rotası
     * '/forum/konu/{topicId}-{slug}'. Slug sadece kozmetiktir, gerçek arama
     * topicId üzerinden yapılır; bu yüzden global tekillik zorunlu değildir.
     */
    private function generateTopicSlug(string $title): string
    {
        $slugger = new AsciiSlugger();
        $slug = mb_strtolower($slugger->slug($title)->toString());

        return $slug !== '' ? mb_substr($slug, 0, 180) : 'konu';
    }
}
