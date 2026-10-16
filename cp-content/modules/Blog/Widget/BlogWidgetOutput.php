<?php

declare(strict_types=1);

namespace Modules\Blog\Widget;

use App\Core\Widget\WidgetHtml;
use App\Entity\Node;
use Modules\Blog\Service\BlogPostPresentationService;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The markup the blog's post-list widgets share: a list of post links, optionally with the date and a
 * small picture. Everything printed is escaped here, so a widget type only chooses which posts to show.
 */
final class BlogWidgetOutput
{
    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        private readonly BlogPostPresentationService $presentation,
    ) {
    }

    public function postUrl(Node $post): string
    {
        return $this->urls->generate('blog_show', ['_locale' => $post->getLocale(), 'slug' => $post->getSlug()]);
    }

    /**
     * @param list<Node> $posts
     */
    public function postList(array $posts, string $locale, bool $showDate, bool $showThumb): string
    {
        $items = [];
        foreach ($posts as $post) {
            $link = '<a href="'.WidgetHtml::esc($this->postUrl($post)).'">'.WidgetHtml::esc($post->getTitle()).'</a>';
            $date = $showDate && $post->getPublishedAt() instanceof \DateTimeInterface
                ? '<span class="text-fs-xs" style="display:block;opacity:.7">'.WidgetHtml::esc($this->date($post->getPublishedAt(), $locale)).'</span>'
                : '';

            $thumb = '';
            if ($showThumb) {
                $image = $this->presentation->resolveFeaturedImageUrl($post);
                if (\is_string($image) && $image !== '') {
                    $thumb = '<img src="'.WidgetHtml::esc($image).'" alt="" loading="lazy" width="56" height="56" style="width:56px;height:56px;object-fit:cover;border-radius:6px;flex:none">';
                }
            }

            $items[] = $thumb !== ''
                ? '<span style="display:flex;gap:.6rem;align-items:center">'.$thumb.'<span>'.$link.$date.'</span></span>'
                : $link.$date;
        }

        return WidgetHtml::ul($items);
    }

    public function date(\DateTimeInterface $date, string $locale): string
    {
        if (class_exists(\IntlDateFormatter::class)) {
            $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE);
            $text = $formatter->format($date);
            if (\is_string($text)) {
                return $text;
            }
        }

        return $date->format('d.m.Y');
    }
}
