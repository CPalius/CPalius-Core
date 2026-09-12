<?php

declare(strict_types=1);

namespace Modules\Importer\Source\Wordpress;

/**
 * Turns an attachment URL from the old site into a file in the operator's copy
 * of wp-content/uploads.
 *
 * WHY LOCAL FILES ARE THE DEFAULT RATHER THAN DOWNLOADING
 * Anyone migrating a WordPress site already has the uploads folder — it is in
 * the backup they took before switching. Downloading thousands of files back
 * off the live old site is slower, fails halfway, and asks this server to make
 * outbound requests to a URL that came out of a file somebody handed us, which
 * is the definition of an SSRF sink. Reading from a directory has none of those
 * properties.
 *
 * A WordPress upload URL ends in the uploads-relative path, conventionally
 * YYYY/MM/name.ext. Everything before "wp-content/uploads/" belongs to the old
 * site and is discarded.
 */
final class UploadsResolver
{
    private readonly string $root;

    public function __construct(string $uploadsDirectory)
    {
        $real = realpath($uploadsDirectory);

        if ($real === false || !is_dir($real)) {
            throw new \RuntimeException(sprintf('Uploads directory "%s" does not exist. Point -o uploads=... at your copy of wp-content/uploads.', $uploadsDirectory));
        }

        $this->root = str_replace('\\', '/', $real);
    }

    public function root(): string
    {
        return $this->root;
    }

    /**
     * The local file for an attachment URL, or null when it is not there.
     */
    public function resolve(string $url): ?string
    {
        $relative = $this->relativePath($url);

        if ($relative === null) {
            return null;
        }

        $candidate = $this->root.'/'.$relative;
        $real = realpath($candidate);

        // realpath() resolves any ".." the URL smuggled in, so comparing the
        // resolved path against the root is what actually contains the read.
        // Without it, an export naming ../../../../etc/passwd as an attachment
        // would have this reading whatever the web user can read.
        if ($real === false) {
            return null;
        }

        $real = str_replace('\\', '/', $real);

        if (!str_starts_with($real, $this->root.'/')) {
            throw new \RuntimeException(sprintf('Attachment path "%s" resolves outside the uploads directory and was refused.', $url));
        }

        return is_file($real) && is_readable($real) ? $real : null;
    }

    /**
     * The uploads-relative part of an attachment URL, e.g. "2024/03/photo.jpg".
     *
     * Shared with the markup rewriter through UrlPath so the two cannot drift:
     * if finding the file and recognising it in a post body disagreed, images
     * would import and then never be linked to.
     */
    public function relativePath(string $url): ?string
    {
        return (new UrlPath())->relative($url);
    }
}
