<?php

declare(strict_types=1);

namespace Modules\Blog\Widget;

use App\Core\Content\RichTextSanitizer;
use App\Core\Widget\WidgetField;
use App\Core\Widget\WidgetHtml;
use App\Core\Widget\WidgetRenderContext;
use App\Entity\Node;
use App\Entity\User;
use Modules\Blog\Twig\BlogTwigExtension;

/**
 * About the author. "Automatic" shows the author of the post being read (and nothing on other pages unless
 * a fallback is filled in); "custom" always shows the name, text and picture typed here, for a site-wide
 * "about me" box.
 */
final class AuthorCardWidget extends AbstractBlogWidget
{
    public function __construct(
        private readonly BlogTwigExtension $blog,
        private readonly RichTextSanitizer $richText,
    ) {
    }

    protected function name(): string
    {
        return 'author_card';
    }

    public function icon(): string
    {
        return 'heroicons:user-circle';
    }

    public function fields(): array
    {
        return [
            WidgetField::select('mode', 'blog.widget.field.author_mode', [
                'auto' => 'blog.widget.author_mode.auto',
                'custom' => 'blog.widget.author_mode.custom',
            ], 'auto', 'blog.widget.field.author_mode_help'),
            WidgetField::text('name', 'blog.widget.field.author_name')->translatable(),
            WidgetField::textarea('bio', 'blog.widget.field.author_bio')->translatable(),
            WidgetField::url('avatar', 'blog.widget.field.author_avatar'),
            WidgetField::url('link', 'blog.widget.field.author_link')->translatable(),
        ];
    }

    public function render(array $settings, WidgetRenderContext $context): string
    {
        $author = ($settings['mode'] ?? 'auto') === 'auto' && $context->subject instanceof Node ? $context->subject->getAuthor() : null;

        if ($author instanceof User) {
            $name = $this->blog->resolvePublicDisplayName($author);
            $avatar = (string) $this->blog->resolveUserAvatarUrl($author);
            $link = (string) $this->blog->resolveAuthorProfileUrl($author);
            // The member's own bio is rich text; it goes through the same sanitiser as on the post page.
            $bioHtml = $this->richText->sanitize($author->getBio());
        } else {
            $name = trim((string) ($settings['name'] ?? ''));
            $avatar = (string) ($settings['avatar'] ?? '');
            $link = (string) ($settings['link'] ?? '');
            $bio = trim((string) ($settings['bio'] ?? ''));
            $bioHtml = $bio !== '' ? '<p>'.nl2br(WidgetHtml::esc($bio), false).'</p>' : '';
        }

        if ($name === '') {
            return '';
        }

        $picture = WidgetHtml::safeUrl($avatar) !== ''
            ? '<img src="'.WidgetHtml::esc(WidgetHtml::safeUrl($avatar)).'" alt="'.WidgetHtml::esc($name).'" loading="lazy" width="72" height="72" style="width:72px;height:72px;object-fit:cover;border-radius:50%">'
            : '';

        return '<div class="slw-sidebar-profile">'
            .($picture !== '' ? '<div class="slw-sidebar-profile__avatar">'.$picture.'</div>' : '')
            .'<strong class="slw-sidebar-profile__name">'.WidgetHtml::link($link, $name).'</strong>'
            .($bioHtml !== '' ? '<div class="slw-sidebar-profile__bio">'.$bioHtml.'</div>' : '')
            .'</div>';
    }
}
