<?php

declare(strict_types=1);

namespace Modules\Importer\Source;

/**
 * The old board's files: XenForo data/ (+ internal_data/), MyBB uploads/.
 *
 * The operator points at whichever folder they have — the public data tree,
 * the uploads tree, or the board root that contains them. We never fetch
 * from the live old site: that is slower, incomplete, and an SSRF sink.
 */
final class ForumDataFolder
{
    /** @var list<string> */
    private readonly array $roots;

    private readonly string $dataDir;

    private readonly ?string $internalDataDir;

    private readonly ?string $uploadsDir;

    private function __construct(string $dataDir, ?string $internalDataDir, ?string $uploadsDir, array $roots)
    {
        $this->dataDir = $dataDir;
        $this->internalDataDir = $internalDataDir;
        $this->uploadsDir = $uploadsDir;
        $this->roots = $roots;
    }

    public static function fromOption(string $path): ?self
    {
        $path = trim($path);

        if ($path === '') {
            return null;
        }

        $real = realpath($path);

        if ($real === false || !is_dir($real)) {
            throw new \RuntimeException(sprintf('The files folder "%s" does not exist. Upload the old data/ or uploads/ as a zip, or point at the path on this server.', $path));
        }

        $real = str_replace('\\', '/', $real);
        $dataDir = $real;
        $internal = null;
        $uploads = null;

        if (is_dir($real.'/data') && (is_dir($real.'/data/avatars') || is_dir($real.'/data/attachments') || is_dir($real.'/data/smilies'))) {
            $dataDir = $real.'/data';
        } elseif (is_dir($real.'/avatars') || is_dir($real.'/attachments') || is_dir($real.'/smilies')) {
            $dataDir = $real;
        }

        if (is_dir($real.'/internal_data')) {
            $internal = $real.'/internal_data';
        } elseif (is_dir(\dirname($dataDir).'/internal_data')) {
            $internal = \dirname($dataDir).'/internal_data';
        }

        if (is_dir($real.'/uploads')) {
            $uploads = $real.'/uploads';
        } elseif (is_dir($real.'/avatars') && !is_dir($real.'/data')) {
            $uploads = $real;
        }

        $roots = array_values(array_unique(array_filter([
            $real,
            $dataDir,
            $internal,
            $uploads,
        ])));

        return new self($dataDir, $internal, $uploads, $roots);
    }

    public function xenforoAvatar(int $userId): ?string
    {
        if ($userId < 1) {
            return null;
        }

        $group = (int) floor($userId / 1000);

        foreach (['h', 'l', 'm', 's'] as $size) {
            foreach (['jpg', 'jpeg', 'png', 'gif', 'webp'] as $ext) {
                $found = $this->file($this->dataDir.'/avatars/'.$size.'/'.$group.'/'.$userId.'.'.$ext)
                    ?? $this->file($this->dataDir.'/avatars/'.$size.'/'.$userId.'.'.$ext);

                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    public function xenforoAttachment(int $dataId, string $hash): ?string
    {
        if ($dataId < 1 || $hash === '') {
            return null;
        }

        $group = (int) floor($dataId / 1000);
        $stem = $dataId.'-'.$hash;

        if ($this->internalDataDir !== null) {
            $full = $this->file($this->internalDataDir.'/attachments/'.$group.'/'.$stem.'.data');

            if ($full !== null) {
                return $full;
            }
        }

        foreach (['jpg', 'jpeg', 'png', 'gif', 'webp'] as $ext) {
            $thumb = $this->file($this->dataDir.'/attachments/'.$group.'/'.$stem.'.'.$ext);

            if ($thumb !== null) {
                return $thumb;
            }
        }

        return null;
    }

    public function xenforoRelative(string $relative): ?string
    {
        $relative = str_replace('\\', '/', ltrim($relative, '/'));

        if (str_starts_with($relative, 'data/')) {
            $relative = substr($relative, 5);
        }

        return $this->file($this->dataDir.'/'.$relative);
    }

    public function mybbAvatar(string $avatarField): ?string
    {
        $avatar = trim($avatarField);

        if ($avatar === '' || str_contains(strtolower($avatar), 'gravatar') || preg_match('#^https?://#i', $avatar) === 1) {
            return null;
        }

        $avatar = preg_replace('#^\./#', '', $avatar) ?? $avatar;
        $avatar = str_replace('\\', '/', ltrim($avatar, '/'));

        if (str_starts_with($avatar, 'uploads/')) {
            $avatar = substr($avatar, 8);
        }

        if ($this->uploadsDir !== null) {
            $found = $this->file($this->uploadsDir.'/'.$avatar);

            if ($found !== null) {
                return $found;
            }
        }

        return $this->file($this->dataDir.'/'.$avatar);
    }

    public function mybbAttachment(string $attachname): ?string
    {
        $name = str_replace('\\', '/', ltrim(trim($attachname), '/'));

        if ($name === '') {
            return null;
        }

        if ($this->uploadsDir !== null) {
            $found = $this->file($this->uploadsDir.'/'.$name);

            if ($found !== null) {
                return $found;
            }
        }

        return $this->file($this->dataDir.'/'.$name);
    }

    private function file(string $absolute): ?string
    {
        $absolute = str_replace('\\', '/', $absolute);
        $real = realpath($absolute);

        if ($real === false || !is_file($real) || !is_readable($real)) {
            return null;
        }

        $real = str_replace('\\', '/', $real);

        foreach ($this->roots as $root) {
            if ($real === $root || str_starts_with($real, $root.'/')) {
                return $real;
            }
        }

        throw new \RuntimeException(sprintf('File "%s" resolves outside the uploaded data folder and was refused.', $absolute));
    }
}
