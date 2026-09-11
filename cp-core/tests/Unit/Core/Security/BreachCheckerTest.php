<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Security;

use App\Core\Security\Password\BreachChecker;
use App\Tests\Unit\Core\Security\Support\ExplodingCachePool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(BreachChecker::class)]
final class BreachCheckerTest extends TestCase
{
    /** SHA-1 of the constant below is BA6BAB72E1E25A70B06E796A0B480E7B8EC60BAA. */
    private const PASSWORD = 'Tr0ub4dor&3-zxcvbn';
    private const PREFIX = 'BA6BA';
    private const SUFFIX = 'B72E1E25A70B06E796A0B480E7B8EC60BAA';

    /** @var list<string> */
    private array $requestedUrls = [];

    /** @var list<string> */
    private array $requestBodies = [];

    /**
     * @param list<MockResponse>|MockResponse $responses
     */
    private function client(array|MockResponse $responses): MockHttpClient
    {
        $this->requestedUrls = [];
        $this->requestBodies = [];

        $queue = \is_array($responses) ? $responses : [$responses];
        $index = 0;

        return new MockHttpClient(function (string $method, string $url, array $options) use ($queue, &$index): MockResponse {
            $this->requestedUrls[] = $url;
            $this->requestBodies[] = (string) ($options['body'] ?? '');

            return $queue[min($index++, \count($queue) - 1)];
        });
    }

    private function body(string ...$lines): string
    {
        return implode("\r\n", $lines);
    }

    /**
     * The whole point of k-anonymity: the remote service must learn five hex
     * characters and nothing else. A regression here would send the full hash of
     * every password anybody ever types on the site to a third party.
     */
    public function testOnlyTheFiveCharacterPrefixLeavesTheMachine(): void
    {
        $checker = new BreachChecker(
            $this->client(new MockResponse($this->body(self::SUFFIX.':12345'))),
            new ArrayAdapter(),
        );

        $checker->occurrences(self::PASSWORD);

        self::assertCount(1, $this->requestedUrls);
        self::assertSame('https://api.pwnedpasswords.com/range/'.self::PREFIX, $this->requestedUrls[0]);
        self::assertStringNotContainsString(self::SUFFIX, $this->requestedUrls[0]);
        self::assertStringNotContainsString(self::PASSWORD, $this->requestedUrls[0]);
        self::assertStringNotContainsString(strtoupper(sha1(self::PASSWORD)), $this->requestedUrls[0]);
        self::assertSame([''], $this->requestBodies, 'A GET carries no body.');
    }

    public function testThePaddingHeaderIsSentSoTheResponseSizeLeaksNothing(): void
    {
        $captured = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = $options['headers'] ?? [];

            return new MockResponse($this->body(self::SUFFIX.':1'));
        });

        (new BreachChecker($client, new ArrayAdapter()))->occurrences(self::PASSWORD);

        $flat = implode('|', array_map(static fn (mixed $h): string => \is_array($h) ? implode(',', $h) : (string) $h, $captured));
        self::assertStringContainsString('Add-Padding', $flat);
        self::assertStringContainsString('true', $flat);
    }

    public function testAMatchingSuffixReturnsItsCount(): void
    {
        $checker = new BreachChecker(
            $this->client(new MockResponse($this->body(
                'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA:7',
                self::SUFFIX.':12345',
                'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB:3',
            ))),
            new ArrayAdapter(),
        );

        self::assertSame(12345, $checker->occurrences(self::PASSWORD));
    }

    public function testSuffixMatchingIsCaseInsensitiveOnTheWire(): void
    {
        $checker = new BreachChecker(
            $this->client(new MockResponse($this->body(strtolower(self::SUFFIX).':9'))),
            new ArrayAdapter(),
        );

        self::assertSame(9, $checker->occurrences(self::PASSWORD));
    }

    public function testAPrefixBucketWithoutOurSuffixMeansZero(): void
    {
        $checker = new BreachChecker(
            $this->client(new MockResponse($this->body('AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA:7'))),
            new ArrayAdapter(),
        );

        self::assertSame(0, $checker->occurrences(self::PASSWORD));
    }

    /**
     * Padding entries come back with a count of 0. Treating one as a hit would
     * reject a perfectly good password; treating a real hit as padding would let
     * a breached one through.
     */
    public function testZeroCountPaddingEntriesAreNotTreatedAsHits(): void
    {
        $checker = new BreachChecker(
            $this->client(new MockResponse($this->body(
                self::SUFFIX.':0',
                'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA:0',
            ))),
            new ArrayAdapter(),
        );

        self::assertSame(0, $checker->occurrences(self::PASSWORD));
    }

    public function testMalformedLinesAreSkippedRatherThanFatal(): void
    {
        $checker = new BreachChecker(
            $this->client(new MockResponse($this->body(
                '',
                'no-colon-here',
                '   ',
                self::SUFFIX.':4',
            ))),
            new ArrayAdapter(),
        );

        self::assertSame(4, $checker->occurrences(self::PASSWORD));
    }

    public function testAnEmptyPasswordIsNotLookedUpAtAll(): void
    {
        $checker = new BreachChecker($this->client(new MockResponse('')), new ArrayAdapter());

        self::assertNull($checker->occurrences(''));
        self::assertSame([], $this->requestedUrls);
    }

    // --- unavailable corpus -------------------------------------------------

    public function testANonTwoHundredResponseIsReportedAsUnknown(): void
    {
        $checker = new BreachChecker(
            $this->client(new MockResponse('', ['http_code' => 503])),
            new ArrayAdapter(),
        );

        self::assertNull($checker->occurrences(self::PASSWORD), 'Unknown, not "clean".');
    }

    public function testATransportFailureIsReportedAsUnknown(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            throw new \RuntimeException('DNS failure.');
        });

        self::assertNull((new BreachChecker($client, new ArrayAdapter()))->occurrences(self::PASSWORD));
    }

    // --- caching ------------------------------------------------------------

    public function testAPrefixBucketIsFetchedOnceAndThenServedFromCache(): void
    {
        $cache = new ArrayAdapter();
        $checker = new BreachChecker(
            $this->client(new MockResponse($this->body(self::SUFFIX.':11'))),
            $cache,
        );

        self::assertSame(11, $checker->occurrences(self::PASSWORD));
        self::assertSame(11, $checker->occurrences(self::PASSWORD));
        self::assertSame(11, $checker->occurrences(self::PASSWORD));

        self::assertCount(1, $this->requestedUrls, 'One prefix, one request.');
    }

    public function testTheCacheKeyCarriesOnlyThePrefix(): void
    {
        $cache = new ArrayAdapter();
        $checker = new BreachChecker(
            $this->client(new MockResponse($this->body(self::SUFFIX.':11'))),
            $cache,
        );
        $checker->occurrences(self::PASSWORD);

        $keys = array_keys($cache->getValues());
        self::assertSame(['cp_hibp_'.self::PREFIX], $keys);

        foreach ($keys as $key) {
            self::assertStringNotContainsString(self::SUFFIX, (string) $key);
            self::assertStringNotContainsString(self::PASSWORD, (string) $key);
        }
    }

    public function testAnUnusableCacheDoesNotStopTheLookup(): void
    {
        $checker = new BreachChecker(
            $this->client(new MockResponse($this->body(self::SUFFIX.':2'))),
            new ExplodingCachePool(),
        );

        self::assertSame(2, $checker->occurrences(self::PASSWORD));
    }
}
