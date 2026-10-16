/*
    Read counter per post, for the "Most read" widget. One row per post; the front end pings it once per
    visitor per post (see PostViewController). Not a Doctrine entity: it is only ever incremented and
    ranked, so plain SQL (Modules\Blog\Service\PostViewCounter) is all it needs.

    Comments are block comments: ModuleInstallContext splits on ";" and discards "--" statements.
*/
CREATE TABLE IF NOT EXISTS cp_blog_post_views (
    node_id INT NOT NULL,
    views INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL,
    INDEX idx_blog_post_views_rank (views),
    PRIMARY KEY (node_id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;
