<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

/**
 * Sorts an anonymous visitor into member / guest / spider / bot.
 *
 * "Who is online: 84" is a number nobody can act on, because most of it is
 * usually automated traffic. Splitting it tells an operator the difference
 * between a busy evening and a crawl.
 *
 * Spider and bot are kept apart on purpose. A spider is a search engine
 * indexing the board, which is traffic a forum wants; a bot is everything else
 * automated — scrapers, monitors, AI crawlers, command-line tools — which is
 * traffic an operator may well want to reduce. Lumping them together would hide
 * exactly the distinction worth seeing.
 */
final class ForumVisitorKind
{
    public const MEMBER = 'member';
    public const GUEST = 'guest';
    public const SPIDER = 'spider';
    public const BOT = 'bot';

    /** Display order, from most to least interesting to an operator. */
    public const ALL = [self::MEMBER, self::GUEST, self::SPIDER, self::BOT];

    /**
     * Search engines that index the board. Substring match on a lowercased UA.
     *
     * @var list<string>
     */
    private const SPIDERS = [
        'googlebot', 'google-inspectiontool', 'storebot-google',
        'bingbot', 'bingpreview', 'msnbot',
        'yandexbot', 'yandeximages',
        'duckduckbot', 'duckduckgo',
        'baiduspider', 'sogou', 'exabot', 'seznambot', 'naver',
        'applebot', 'petalbot', 'qwantify',
        'slurp',
    ];

    /**
     * Everything else automated. Checked after the spider list, so a crawler
     * whose name happens to contain "bot" is not miscounted as a scraper.
     *
     * @var list<string>
     */
    private const BOTS = [
        'bot', 'crawler', 'spider', 'scraper',
        'curl', 'wget', 'python-requests', 'python-urllib', 'go-http-client',
        'java/', 'okhttp', 'axios', 'node-fetch', 'guzzle', 'libwww-perl',
        'headlesschrome', 'phantomjs', 'puppeteer', 'playwright',
        'ahrefs', 'semrush', 'mj12', 'dotbot', 'dataforseo', 'blexbot', 'serpstat',
        'gptbot', 'claudebot', 'anthropic-ai', 'ccbot', 'perplexitybot', 'bytespider',
        'facebookexternalhit', 'twitterbot', 'telegrambot', 'whatsapp', 'discordbot', 'slackbot',
        'uptimerobot', 'pingdom', 'statuscake', 'newrelic', 'site24x7',
    ];

    /**
     * @param bool   $authenticated whether a signed-in member made the request
     * @param string $userAgent     raw User-Agent header; empty counts as a bot
     *
     * @return self::MEMBER|self::GUEST|self::SPIDER|self::BOT
     */
    public static function classify(bool $authenticated, string $userAgent): string
    {
        if ($authenticated) {
            // A signed-in session is a person, whatever their browser calls
            // itself — nothing automated gets through the login form.
            return self::MEMBER;
        }

        $ua = mb_strtolower(trim($userAgent));

        if ($ua === '') {
            // Every real browser sends one. A request without it is a script.
            return self::BOT;
        }

        foreach (self::SPIDERS as $needle) {
            if (str_contains($ua, $needle)) {
                return self::SPIDER;
            }
        }

        foreach (self::BOTS as $needle) {
            if (str_contains($ua, $needle)) {
                return self::BOT;
            }
        }

        return self::GUEST;
    }

    public static function isKnown(string $kind): bool
    {
        return \in_array($kind, self::ALL, true);
    }
}
