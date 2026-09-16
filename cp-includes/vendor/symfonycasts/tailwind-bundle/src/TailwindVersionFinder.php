<?php

/*
 * This file is part of the SymfonyCasts TailwindBundle package.
 * Copyright (c) SymfonyCasts <https://symfonycasts.com/>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfonycasts\TailwindBundle;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Finds the latest Tailwind CSS version by major version.
 *
 * @author Kevin Bond <kevinbond@gmail.com>
 *
 * @internal
 */
final class TailwindVersionFinder
{
    private HttpClientInterface $httpClient;

    public function __construct(?HttpClientInterface $httpClient = null)
    {
        $this->httpClient = $httpClient ?? HttpClient::create();
    }

    /**
     * Finds the latest release sharing the major version of the given
     * version string (e.g. "4", "v4.2.0", "3.3" all resolve their major).
     */
    public function latestVersionFor(string $version): string
    {
        if (!preg_match('/^v?(\d+)/', $version, $matches)) {
            throw new \InvalidArgumentException(\sprintf('Cannot parse major version from "%s".', $version));
        }

        $majorVersion = (int) $matches[1];

        foreach ($this->tags() as $tag) {
            if (str_starts_with($tag, "v$majorVersion.")) {
                return $tag;
            }
        }

        throw new \RuntimeException(\sprintf('Could not find a Tailwind CSS %d.x release.', $majorVersion));
    }

    /**
     * @return string[]
     */
    private function tags(int $page = 1): iterable
    {
        $releases = $this->httpClient
            ->request('GET', 'https://api.github.com/repos/tailwindlabs/tailwindcss/releases', [
                'query' => ['page' => $page],
            ])
            ->toArray()
        ;

        if (!$releases) {
            return;
        }

        foreach ($releases as $release) {
            yield $release['tag_name'];
        }

        yield from $this->tags(++$page);
    }
}
