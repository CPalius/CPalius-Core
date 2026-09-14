<?php

declare(strict_types=1);

namespace App\Core\Storage;

use App\Core\Settings\SettingSecretCodec;
use App\Core\Settings\SettingsRegistry;
use App\Core\Storage\Driver\FtpTarget;
use App\Core\Storage\Driver\S3Target;
use App\Entity\Setting;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Builds remote targets from settings and remembers which ones actually work.
 *
 * The rule that shapes the whole class: a target is only used once a live write
 * probe has passed against exactly the configuration now stored. Credentials
 * that look plausible are not evidence — a bucket policy that allows ListBucket
 * and denies PutObject, an FTP account whose home is read-only, and a typo in
 * an endpoint hostname all survive any check short of writing a file and
 * reading it back.
 *
 * That matters most for backups, where the failure is silent by nature. An
 * operator who configures off-site backups and is never told the upload failed
 * does not have unreliable backups; they have no backups and a false belief,
 * which is strictly worse than knowing they have none.
 *
 * Test state lives in one JSON settings row rather than a table of its own,
 * following ReleaseChecker: no migration means the feature works on an
 * installation that has updated its files but not yet run cp:update.
 */
final class StorageTargetRegistry implements TargetResolverInterface
{
    /** @var list<string> */
    public const TYPES = ['s3', 'r2', 'ftp'];

    public const PURPOSE_MEDIA = 'media';
    public const PURPOSE_BACKUP = 'backup';

    private const STATE_KEY = 'storage.target.test_state';

    /** @var array<string, list<string>> */
    private const FIELDS = [
        's3' => S3Target::FIELDS,
        // R2 is path-style only, so the toggle is not offered and not stored.
        'r2' => ['endpoint', 'region', 'bucket', 'access_key', 'secret_key', 'prefix'],
        'ftp' => FtpTarget::FIELDS,
    ];

    /** @var list<string> Submitted as checkboxes: absent means false, never "leave unchanged". */
    private const BOOLEAN_FIELDS = ['path_style', 'passive', 'ssl'];

    /** @var list<string> Sealed at rest by SettingSecretCodec. */
    private const SECRET_FIELDS = ['secret_key', 'password'];

    /** @var array<string, RemoteTargetInterface|false> per-request memo; false means "could not be built" */
    private array $built = [];

    /** @var array<string, array{ok: bool, at: string, message: string}>|null */
    private ?array $state = null;

    public function __construct(
        private readonly SettingsRegistry $settings,
        private readonly SettingRepository $settingRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly SettingSecretCodec $secretCodec,
    ) {
    }

    public static function isKnownType(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    /**
     * @return list<string>
     */
    public static function fieldsFor(string $type): array
    {
        return self::FIELDS[$type] ?? [];
    }

    /**
     * Stored configuration for one provider. Secrets come back revealed — this
     * is the value that builds a live client, and it must never be handed to a
     * template. AACPStorageController masks them on the way to the screen.
     *
     * @return array<string, mixed>
     */
    public function config(string $type): array
    {
        $config = [];

        foreach (self::fieldsFor($type) as $field) {
            $config[$field] = $this->settings->get('storage.'.$type.'.'.$field);
        }

        // Not a stored field: forcing it here is what keeps the R2 screen from
        // offering a toggle whose wrong setting produces an unreadable
        // SignatureDoesNotMatch instead of an error anyone can act on.
        if ($type === 'r2') {
            $config['path_style'] = true;
        }

        return $config;
    }

    /**
     * A usable client for this provider, or null when it cannot be built at all
     * (missing credentials, missing extension).
     *
     * Says nothing about whether the credentials are correct — that is what the
     * test state is for. Callers that are about to write real data should go
     * through resolveFor() instead.
     */
    public function build(string $type): ?RemoteTargetInterface
    {
        if (array_key_exists($type, $this->built)) {
            return $this->built[$type] === false ? null : $this->built[$type];
        }

        try {
            $config = $this->config($type);

            $target = match ($type) {
                's3', 'r2' => new S3Target($type, $config),
                'ftp' => new FtpTarget($config),
                default => throw new StorageException(sprintf('Unknown storage target "%s".', $type)),
            };
        } catch (\Throwable) {
            $this->built[$type] = false;

            return null;
        }

        $this->built[$type] = $target;

        return $target;
    }

    /**
     * The target a purpose should use right now, or null for "keep it local".
     *
     * Returns null when the selected provider has never passed a probe. That is
     * the fail-closed half of the design: an operator who picks a target and
     * mistypes the bucket gets local-only behaviour and a red badge on the
     * screen, not uploads quietly vanishing into a 403.
     */
    public function resolveFor(string $purpose): ?RemoteTargetInterface
    {
        $type = (string) $this->settings->get('storage.'.$purpose.'.target', 'off');

        if ($type === 'off' || !self::isKnownType($type)) {
            return null;
        }

        if (!$this->isVerified($type)) {
            return null;
        }

        return $this->build($type);
    }

    /** The provider a purpose is set to, whether or not it is usable. */
    public function selectedType(string $purpose): string
    {
        $type = (string) $this->settings->get('storage.'.$purpose.'.target', 'off');

        return self::isKnownType($type) ? $type : 'off';
    }

    public function isVerified(string $type): bool
    {
        return $this->state()[$type]['ok'] ?? false;
    }

    /**
     * @return array<string, array{ok: bool, at: string, message: string}>
     */
    public function testState(): array
    {
        return $this->state();
    }

    /**
     * Writes the submitted fields, then probes with exactly what was written.
     *
     * One action rather than separate Save and Test buttons, for the same
     * reason PerformanceBackendRegistry does it: two buttons let an operator
     * save a change, skip the test, and leave behind a stored state whose
     * "last test passed" badge describes a configuration that no longer exists.
     *
     * @param array<string, string> $submitted
     */
    public function saveConfigAndTest(string $type, array $submitted): StorageTestResult
    {
        if (!self::isKnownType($type)) {
            throw new \InvalidArgumentException(sprintf('Unknown storage target "%s".', $type));
        }

        foreach (self::fieldsFor($type) as $field) {
            if (in_array($field, self::BOOLEAN_FIELDS, true)) {
                $value = isset($submitted[$field]) && $submitted[$field] !== '0' && $submitted[$field] !== '' ? '1' : '0';
            } elseif (!array_key_exists($field, $submitted)) {
                continue;
            } else {
                $value = trim($submitted[$field]);

                // Same contract as the System Settings screen: the form never
                // renders a stored secret back, so an empty field means "leave
                // it alone". Writing the empty string instead would wipe a
                // working credential every time an operator corrected a typo
                // in the bucket name.
                if (in_array($field, self::SECRET_FIELDS, true)) {
                    if ($value === '') {
                        continue;
                    }

                    $value = $this->secretCodec->seal($value);
                }
            }

            $this->write('storage.'.$type.'.'.$field, $value);
        }

        $this->entityManager->flush();
        $this->settings->clearCache();
        $this->built = [];

        return $this->test($type);
    }

    /**
     * Probes the stored configuration and records the verdict.
     */
    public function test(string $type): StorageTestResult
    {
        $target = $this->build($type);

        if ($target === null) {
            return $this->record($type, new StorageTestResult(false, 'aacp.storage.error.not_configured'));
        }

        $started = microtime(true);

        try {
            $target->test();
        } catch (\Throwable $e) {
            return $this->record($type, StorageTestResult::fromThrowable($e));
        }

        return $this->record($type, StorageTestResult::ok(
            $target->describe(),
            round((microtime(true) - $started) * 1000, 1),
        ));
    }

    /**
     * @return array<string, array{ok: bool, at: string, message: string}>
     */
    private function state(): array
    {
        if ($this->state !== null) {
            return $this->state;
        }

        $raw = $this->settingRepository->findOneBy(['settingKey' => self::STATE_KEY])?->getSettingValue();
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        $state = [];

        foreach (is_array($decoded) ? $decoded : [] as $type => $entry) {
            if (!is_string($type) || !is_array($entry)) {
                continue;
            }

            $state[$type] = [
                'ok' => (bool) ($entry['ok'] ?? false),
                'at' => is_string($entry['at'] ?? null) ? $entry['at'] : '',
                'message' => is_string($entry['message'] ?? null) ? $entry['message'] : '',
            ];
        }

        return $this->state = $state;
    }

    private function record(string $type, StorageTestResult $result): StorageTestResult
    {
        $state = $this->state();

        $state[$type] = [
            'ok' => $result->success,
            'at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'message' => $result->messageKey,
        ];

        $this->write(self::STATE_KEY, json_encode($state, JSON_THROW_ON_ERROR));
        $this->entityManager->flush();

        $this->state = $state;

        return $result;
    }

    private function write(string $key, string $value): void
    {
        $setting = $this->settingRepository->findOneBy(['settingKey' => $key]);

        if (!$setting instanceof Setting) {
            $setting = new Setting($key, 'core');
            $this->entityManager->persist($setting);
        }

        $setting->setSettingValue($value);
    }
}
