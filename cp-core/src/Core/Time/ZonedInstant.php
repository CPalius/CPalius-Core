<?php

declare(strict_types=1);

namespace App\Core\Time;

/**
 * A moment together with the place it was set in: a tour that leaves Antalya at 09:00 local time.
 *
 * The rule for anything that has to be right across zones: keep the INSTANT in UTC, and keep the
 * IANA zone name ("Europe/Istanbul") next to it in its own column. Show it in the zone it belongs to.
 * Storing only "09:00" loses which 09:00 it was; storing only a UTC time loses what the guide's clock said.
 *
 * This class does not touch PHP's default timezone or how existing datetime columns are read, so adopting it
 * changes nothing for existing data: a module opts in for the fields that need it.
 */
final class ZonedInstant
{
    /** @var array<string, true>|null */
    private static ?array $identifiers = null;

    private function __construct(
        public readonly \DateTimeImmutable $utc,
        public readonly string $timezone,
    ) {
    }

    /**
     * @param string $local    "2026-11-02 09:00", "2026-11-02 09:00:30" or the same with a "T"
     * @param string $timezone IANA identifier; abbreviations such as "EST" are refused because they are not a place
     *
     * @throws \InvalidArgumentException for an unknown zone, an unreadable time, or a local time that does not exist
     *                                   (the hour skipped when clocks go forward)
     */
    public static function fromLocal(string $local, string $timezone): self
    {
        $zone = self::zone($timezone);
        $normalised = str_replace('T', ' ', trim($local));

        foreach (['Y-m-d H:i:s', 'Y-m-d H:i'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat('!'.$format, $normalised, $zone);

            // createFromFormat() silently rolls a non-existent local time forward; reading it back exposes that.
            if ($parsed !== false && $parsed->format($format) === $normalised) {
                return new self($parsed->setTimezone(new \DateTimeZone('UTC')), $timezone);
            }
        }

        throw new \InvalidArgumentException(sprintf('"%s" is not a time that exists in %s.', $local, $timezone));
    }

    public static function fromUtc(\DateTimeInterface $instant, string $timezone): self
    {
        self::zone($timezone);

        return new self(\DateTimeImmutable::createFromInterface($instant)->setTimezone(new \DateTimeZone('UTC')), $timezone);
    }

    /** The moment on the clock of the place it belongs to. */
    public function local(): \DateTimeImmutable
    {
        return $this->utc->setTimezone(new \DateTimeZone($this->timezone));
    }

    /** Local wall-clock text, for showing to people. */
    public function format(string $format = 'Y-m-d H:i'): string
    {
        return $this->local()->format($format);
    }

    /** The UTC text to store in a datetime column ("Y-m-d H:i:s"). */
    public function utcString(): string
    {
        return $this->utc->format('Y-m-d H:i:s');
    }

    public function isSameInstant(self $other): bool
    {
        return $this->utc->getTimestamp() === $other->utc->getTimestamp();
    }

    private static function zone(string $timezone): \DateTimeZone
    {
        self::$identifiers ??= array_fill_keys(\DateTimeZone::listIdentifiers(), true);

        if (!isset(self::$identifiers[$timezone])) {
            throw new \InvalidArgumentException(sprintf('"%s" is not an IANA time zone (use e.g. "Europe/Istanbul").', $timezone));
        }

        return new \DateTimeZone($timezone);
    }
}
