<?php

declare(strict_types=1);

namespace App\Core\Install;

use App\Core\Taxonomy\Entity\Term;
use App\Core\Taxonomy\Entity\Vocabulary;
use App\Entity\Node;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Blog\PostSubType;
use Modules\Forum\Entity\ForumPost;
use Modules\Forum\Entity\ForumSection;
use Modules\Forum\Entity\ForumTopic;
use Modules\Forum\ForumSectionType;

/**
 * One blog category and post, one forum division, one board and one message.
 */
final class InstallSampleContent
{
    public function seed(EntityManagerInterface $em, User $author, string $locale, string $siteName): void
    {
        $this->seedBlog($em, $author, $locale, $siteName);
        $this->seedForum($em, $author, $locale, $siteName);
        $em->flush();
    }

    private function seedBlog(EntityManagerInterface $em, User $author, string $locale, string $siteName): void
    {
        $vocabulary = $em->getRepository(Vocabulary::class)->findOneBy(['machineName' => 'blog_category']);
        if (!$vocabulary instanceof Vocabulary) {
            return;
        }

        $english = $locale === 'en';
        $category = new Term($vocabulary, $english ? 'General' : 'Genel', 'genel', $locale);
        $em->persist($category);

        $title = $english ? 'Welcome' : 'Merhaba';
        $excerpt = $english
            ? $siteName.' is ready. Replace this post with your own.'
            : $siteName.' kuruldu. Bu yazıyı kendi içeriğinizle değiştirin.';
        $node = new Node($title, 'merhaba', 'post', $locale);
        $node->setAuthor($author);
        $node->setCategory($category);
        $node->addCategory($category);
        $node->setData([
            'excerpt' => $excerpt,
            'body' => '<p>'.htmlspecialchars($excerpt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</p>',
            'post_sub_type' => PostSubType::ARTICLE,
        ]);
        $node->publish();
        $em->persist($node);
    }

    private function seedForum(EntityManagerInterface $em, User $author, string $locale, string $siteName): void
    {
        $english = $locale === 'en';
        $name = $author->getUsername() ?: $author->getFirstName();
        $now = new \DateTimeImmutable();

        $division = new ForumSection('genel', 'genel', $locale, $english ? 'General' : 'Genel');
        $division->setSectionType(ForumSectionType::Division);
        $division->setDescription($english ? 'The first forum section.' : 'İlk forum bölümü.');
        $em->persist($division);
        $em->flush();
        $division->setParentPath('/'.$division->getId().'/');

        $board = new ForumSection('genel-konular', 'genel-konular', $locale, $english ? 'General topics' : 'Genel konular');
        $board->setSectionType(ForumSectionType::Subcategory);
        $board->setParent($division);
        $board->setDescription($english ? 'A single sample board.' : 'Tek örnek kategori.');
        $em->persist($board);
        $em->flush();
        $board->setParentPath($division->getParentPath().$board->getId().'/');

        $title = $english ? 'Welcome' : 'Merhaba';
        $body = $english
            ? '<p>'.$siteName.' is ready. This is the only sample topic.</p>'
            : '<p>'.htmlspecialchars($siteName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').' kuruldu. Bu, tek örnek konudur.</p>';
        $topic = new ForumTopic($board, $title, $name);
        $topic->setSlug('merhaba');
        $topic->setFirstPoster($author);
        $topic->setLastPoster($author);
        $topic->setLastPosterName($name);
        $topic->setPostCount(1);
        $topic->setPreview($english ? $siteName.' is ready.' : $siteName.' kuruldu.');
        $em->persist($topic);
        $em->flush();

        $post = new ForumPost($topic, $board, $name, $body);
        $post->setAuthor($author);
        $em->persist($post);
        $em->flush();

        $topic->setLastPostId($post->getId());
        $topic->setLastPostDate($now);
        foreach ([$board, $division] as $section) {
            $section->setTopicCount(1);
            $section->setPostCount(1);
            $section->setLastTopicId($topic->getId());
            $section->setLastTopicTitle($title);
            $section->setLastPostId($post->getId());
            $section->setLastPostAt($now);
            $section->setLastPoster($author);
            $section->setLastPosterName($name);
        }
    }
}
