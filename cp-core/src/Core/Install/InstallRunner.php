<?php

declare(strict_types=1);

namespace App\Core\Install;

use App\Core\Version\CpVersion;
use App\Entity\Setting;
use App\Entity\User;
use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Dotenv\Dotenv;

/**
 * Turns a probed empty database into a booted site: `.env`, migrations,
 * the first administrator, and the lock file. The wizard directory is
 * deleted only after the lock exists, so a cleanup failure cannot hide
 * the fact that the site is already installed.
 */
final class InstallRunner
{
    public const TIMEZONES = [
        'Europe/Istanbul',
        'Europe/London',
        'Europe/Berlin',
        'America/New_York',
        'America/Los_Angeles',
        'Asia/Dubai',
        'Asia/Tokyo',
        'Australia/Sydney',
        'UTC',
    ];

    public function __construct(
        private readonly EnvironmentWriter $environmentWriter = new EnvironmentWriter(),
    ) {
    }

    /**
     * @param array{
     *     databaseUrl: string,
     *     siteName: string,
     *     siteUrl: string,
     *     locale: string,
     *     timezone: string,
     *     email: string,
     *     username: string,
     *     password: string,
     *     displayName: string
     * } $config
     *
     * @return array{recoveryToken: string, cronToken: string}
     */
    public function run(string $projectDir, array $config): array
    {
        $this->assertConfig($config);

        $recoveryToken = EnvironmentWriter::secret();
        $cronToken = EnvironmentWriter::secret();
        $appSecret = EnvironmentWriter::secret();

        InstallGate::markInstalling($projectDir);

        $locale = $config['locale'] === 'en' ? 'en' : 'tr';
        $this->environmentWriter->write($projectDir, [
            'APP_ENV' => 'prod',
            'APP_DEBUG' => '0',
            'APP_SECRET' => $appSecret,
            'CP_APP_DEBUG_LEVEL' => 'safe',
            'DEFAULT_URI' => $config['siteUrl'],
            'DATABASE_URL' => $config['databaseUrl'],
            'AACP_RECOVERY_TOKEN' => $recoveryToken,
            'CRON_TOKEN' => $cronToken,
            'CPALIUS_DEFAULT_LOCALE' => $locale,
            'CPALIUS_LOCALES' => 'tr,en',
        ]);

        InstallDefaults::writeActiveModules($projectDir);
        $this->bootEnv($projectDir);

        $kernel = new Kernel('prod', false);
        $application = new Application($kernel);
        $application->setAutoExit(false);
        $application->setCatchExceptions(true);

        try {
            $this->console($application, ['command' => 'doctrine:migrations:migrate', '--no-interaction' => true]);
            try {
                $this->console($application, [
                    'command' => 'cp:user:create-admin',
                    'email' => $config['email'],
                    'username' => $config['username'],
                    'password' => $config['password'],
                ]);
            } catch (\RuntimeException $e) {
                if (!str_contains($e->getMessage(), 'already exists')) {
                    throw $e;
                }
            }
            $this->finishAccount($kernel, $config, $locale);
        } catch (\Throwable $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }

        InstallGate::markInstalled($projectDir, [
            'installed_at' => gmdate('c'),
            'version' => CpVersion::VERSION,
            'locale' => $locale,
        ]);
        InstallGate::clearInstalling($projectDir);

        return [
            'recoveryToken' => $recoveryToken,
            'cronToken' => $cronToken,
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    public function assertConfig(array $config): void
    {
        $email = (string) ($config['email'] ?? '');
        $username = (string) ($config['username'] ?? '');
        $password = (string) ($config['password'] ?? '');
        $siteName = trim((string) ($config['siteName'] ?? ''));
        $siteUrl = (string) ($config['siteUrl'] ?? '');

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Administrator email is not valid.');
        }

        if (preg_match('/^[A-Za-z0-9_.-]{3,180}$/', $username) !== 1) {
            throw new \InvalidArgumentException('Username must be 3–180 characters: letters, numbers, dot, underscore or hyphen.');
        }

        if ($siteName === '' || mb_strlen($siteName) > 180) {
            throw new \InvalidArgumentException('Site name is required.');
        }

        if (filter_var($siteUrl, FILTER_VALIDATE_URL) === false || preg_match('#^https?://#', $siteUrl) !== 1) {
            throw new \InvalidArgumentException('Site URL must start with http:// or https://.');
        }

        $timezone = (string) ($config['timezone'] ?? '');
        if (!\in_array($timezone, self::TIMEZONES, true)) {
            throw new \InvalidArgumentException('Timezone is not valid.');
        }

        $errors = $this->passwordErrors($password, [$email, $username]);
        if ($errors !== []) {
            throw new \InvalidArgumentException(implode(' ', $errors));
        }
    }

    /**
     * Mirrors the default password policy (length 10, three classes) so the
     * wizard can reject a password before it writes `.env`. The command still
     * enforces the real policy after the kernel boots.
     *
     * @param list<string> $identity
     *
     * @return list<string>
     */
    public function passwordErrors(string $password, array $identity): array
    {
        $errors = [];
        if (mb_strlen($password) < 10) {
            $errors[] = 'Password must be at least 10 characters.';
        }

        $classes = 0;
        $classes += preg_match('/\p{Ll}/u', $password) === 1 ? 1 : 0;
        $classes += preg_match('/\p{Lu}/u', $password) === 1 ? 1 : 0;
        $classes += preg_match('/\p{N}/u', $password) === 1 ? 1 : 0;
        $classes += preg_match('/[^\p{L}\p{N}]/u', $password) === 1 ? 1 : 0;
        if ($classes < 3) {
            $errors[] = 'Password must mix at least three of: lowercase, uppercase, digits, symbols.';
        }

        $lower = mb_strtolower($password);
        foreach (['password', 'password123', 'admin123', 'cpalius', 'parola', 'sifre1234', 'qwerty123'] as $denied) {
            if ($lower === $denied) {
                $errors[] = 'That password is too common.';
                break;
            }
        }

        foreach ($identity as $piece) {
            $piece = mb_strtolower(trim($piece));
            if (mb_strlen($piece) >= 4 && str_contains($lower, $piece)) {
                $errors[] = 'Password must not contain the email or username.';
                break;
            }
        }

        return $errors;
    }

    private function bootEnv(string $projectDir): void
    {
        if (!class_exists(Dotenv::class)) {
            require_once $projectDir.'/cp-includes/vendor/autoload.php';
        }

        (new Dotenv())->bootEnv($projectDir.'/.env', 'prod', ['test'], true);
        $_SERVER['APP_ENV'] = 'prod';
        $_SERVER['APP_DEBUG'] = '0';
        $_ENV['APP_ENV'] = 'prod';
        $_ENV['APP_DEBUG'] = '0';
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function console(Application $application, array $parameters): void
    {
        $input = new ArrayInput($parameters);
        $input->setInteractive(false);
        $output = new BufferedOutput();
        $code = $application->run($input, $output);
        if ($code !== 0) {
            $text = trim($output->fetch());
            throw new \RuntimeException($text !== '' ? $text : 'A setup command failed.');
        }
    }

    /**
     * @param array{email: string, username: string, displayName: string, siteName: string, timezone: string, locale: string} $config
     */
    private function finishAccount(Kernel $kernel, array $config, string $locale): void
    {
        $kernel->boot();
        $em = $kernel->getContainer()->get('doctrine.orm.entity_manager');
        if (!$em instanceof \Doctrine\ORM\EntityManagerInterface) {
            throw new \RuntimeException('Database connection is not available after migration.');
        }

        $user = $em->getRepository(User::class)->findOneBy(['email' => $config['email']]);
        if (!$user instanceof User) {
            throw new \RuntimeException('Administrator account was not created.');
        }

        $user->setUsername($config['username']);
        $display = trim($config['displayName']);
        if ($display !== '') {
            $user->setFirstName($display);
        }

        // The wizard never sends mail (MAILER_DSN is null on a fresh host), so the
        // account that was just typed in cannot be blocked on email verification.
        $user->markEmailVerified();

        $this->upsertSetting($em, 'core.site_name', json_encode([
            'tr' => $config['siteName'],
            'en' => $config['siteName'],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $this->upsertSetting($em, 'core.default_locale', $locale);
        $this->upsertSetting($em, 'core.timezone', $config['timezone']);
        $em->flush();

        (new InstallSampleContent())->seed($em, $user, $locale, $config['siteName']);

        $this->ensureLocales($em->getConnection(), $locale);
    }

    private function upsertSetting(\Doctrine\ORM\EntityManagerInterface $em, string $key, string $value): void
    {
        $setting = $em->getRepository(Setting::class)->findOneBy(['settingKey' => $key]);
        if (!$setting instanceof Setting) {
            $setting = new Setting($key, 'core');
            $em->persist($setting);
        }

        $setting->setSettingValue($value);
    }

    private function ensureLocales(\Doctrine\DBAL\Connection $connection, string $default): void
    {
        $catalog = [
            'tr' => ['Türkçe', 'Türkçe'],
            'en' => ['English', 'English'],
        ];
        $sort = 0;
        foreach ($catalog as $code => [$name, $native]) {
            $exists = $connection->fetchOne('SELECT id FROM cp_locales WHERE code = ?', [$code]);
            if ($exists === false) {
                $connection->executeStatement(
                    'INSERT INTO cp_locales (code, name, native_name, is_active, is_default, sort_order) VALUES (?, ?, ?, 1, 0, ?)',
                    [$code, $name, $native, $sort],
                );
            }
            ++$sort;
        }

        $connection->executeStatement(
            'UPDATE cp_locales SET is_default = CASE WHEN code = ? THEN 1 ELSE 0 END',
            [$default],
        );
    }
}
