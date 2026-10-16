<?php

declare(strict_types=1);

namespace App\Core\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Fills a scratch database with realistic volume, so "how does it behave with 100 000 users and
 * 100 000 reservations" is answered by measuring rather than by guessing.
 *
 * Safety first, because this inserts hundreds of thousands of rows: it refuses to run unless the
 * database name contains "perf". Create one (CREATE DATABASE `cpalius-cmf_perf`), point DATABASE_URL at
 * it, create the schema (doctrine:schema:create), then seed. Your real database is never touched
 * because the command will not run against it.
 *
 * Rows are produced inside MySQL/MariaDB with a recursive CTE, one INSERT ... SELECT per table, so
 * 100 000 rows take seconds, not minutes. Every seeded row is marked (e-mails end in @perf.invalid,
 * topics carry a [perf] prefix) so a later --purge removes exactly what was added.
 */
#[AsCommand(name: 'cp:perf:seed', description: 'Fill a scratch ("perf") database with users, forum posts, blog posts and hall reservations.')]
final class PerfSeedCommand extends Command
{
    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('users', null, InputOption::VALUE_REQUIRED, 'Members to create', '100000')
            ->addOption('topics', null, InputOption::VALUE_REQUIRED, 'Forum topics', '20000')
            ->addOption('posts', null, InputOption::VALUE_REQUIRED, 'Forum posts', '200000')
            ->addOption('nodes', null, InputOption::VALUE_REQUIRED, 'Blog posts', '5000')
            ->addOption('reservations', null, InputOption::VALUE_REQUIRED, 'Hall reservations (needs the WeddingHall tables)', '100000')
            ->addOption('purge', null, InputOption::VALUE_NONE, 'Remove previously seeded rows instead of adding');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $db = (string) $this->connection->fetchOne('SELECT DATABASE()');
        if (!str_contains(strtolower($db), 'perf')) {
            $io->error(\sprintf('Refusing to run against "%s": the database name must contain "perf". Create a scratch database and point DATABASE_URL at it.', $db));

            return Command::FAILURE;
        }

        $this->connection->executeStatement('SET SESSION cte_max_recursion_depth = 2000000');
        $this->connection->executeStatement('SET SESSION foreign_key_checks = 0');

        if ($input->getOption('purge')) {
            $this->purge();
            $io->success('Seeded rows removed.');

            return Command::SUCCESS;
        }

        $users = max(0, (int) $input->getOption('users'));
        $topics = max(0, (int) $input->getOption('topics'));
        $posts = max(0, (int) $input->getOption('posts'));
        $nodes = max(0, (int) $input->getOption('nodes'));
        $reservations = max(0, (int) $input->getOption('reservations'));

        $t = microtime(true);
        $step = function (string $label, callable $do) use ($io, &$t): void {
            $do();
            $io->writeln(\sprintf('  %-28s %5.1fs', $label, microtime(true) - $t));
            $t = microtime(true);
        };

        if ($users > 0) {
            $step($users.' users', fn () => $this->users($users));
        }
        if ($topics > 0) {
            $step('forum sections + '.$topics.' topics', fn () => $this->topics($topics, $users));
        }
        if ($posts > 0 && $topics > 0) {
            $step($posts.' forum posts', fn () => $this->posts($posts, $topics, $users));
        }
        if ($nodes > 0) {
            $step($nodes.' blog posts', fn () => $this->nodes($nodes));
        }
        if ($reservations > 0) {
            if ($this->tableExists('cp_hall_reservations')) {
                $step($reservations.' hall reservations', fn () => $this->reservations($reservations));
            } else {
                $io->note('cp_hall_reservations does not exist; run the WeddingHall module SQL first to seed reservations.');
            }
        }

        foreach (['cp_users', 'cp_forum_topics', 'cp_forum_posts', 'cp_nodes', 'cp_hall_reservations'] as $table) {
            if ($this->tableExists($table)) {
                $this->connection->fetchAllAssociative('ANALYZE TABLE '.$table);
            }
        }
        $io->success('Seeded. Now: cp:perf:probe, or point a load tool at the site.');

        return Command::SUCCESS;
    }

    private function users(int $n): void
    {
        // One bcrypt-looking constant: nobody signs in with these, and hashing 100 000 passwords would take hours.
        $hash = '$2y$04$abcdefghijklmnopqrstuuYyZ9v6vK2Qq3Qf0m1o0n9p8r7s6t5u4v3w2x1y0';
        $this->connection->executeStatement(
            'INSERT INTO cp_users (email, username, password, status, roles, data, created_at)
             WITH RECURSIVE s(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM s WHERE n < :n)
             SELECT CONCAT(\'u\', n, \'@perf.invalid\'), CONCAT(\'perfuser\', n), :hash, \'active\', \'["member"]\', \'{}\',
                    DATE_SUB(NOW(), INTERVAL FLOOR(RAND() * 900) DAY) FROM s',
            ['n' => $n, 'hash' => $hash],
        );
    }

    private function topics(int $n, int $users): void
    {
        $this->connection->executeStatement(
            "INSERT INTO cp_forum_sections (code, locale, slug, title, sort_order, is_container, allow_topics, section_type, node_type, is_locked, default_topic_sort, topic_count, post_count, view_count, created_at, updated_at)
             WITH RECURSIVE s(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM s WHERE n < 12)
             SELECT CONCAT('perf-', n), 'tr', CONCAT('perf-bolum-', n), CONCAT('[perf] Bolum ', n), n, 0, 1, 'subcategory', 'forum', 0, 'latest', 0, 0, 0, NOW(), NOW() FROM s",
        );
        $first = (int) $this->connection->fetchOne("SELECT MIN(id) FROM cp_forum_sections WHERE code LIKE 'perf-%'");
        $this->connection->executeStatement(
            "INSERT INTO cp_forum_topics (section_id, locale, title, first_poster_name, mode, state, locked, sticky, discussion_state, view_count, post_count, created_at, updated_at)
             WITH RECURSIVE s(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM s WHERE n < :n)
             SELECT :first + MOD(n, 12), 'tr', CONCAT('[perf] Konu ', n), CONCAT('perfuser', 1 + MOD(n * 7919, :users)),
                    0, 0, 0, 0, 'visible', FLOOR(RAND() * 500), 10, DATE_SUB(NOW(), INTERVAL FLOOR(RAND() * 700) DAY), NOW() FROM s",
            ['n' => $n, 'first' => $first, 'users' => max(1, $users)],
        );
    }

    private function posts(int $n, int $topics, int $users): void
    {
        $firstTopic = (int) $this->connection->fetchOne("SELECT MIN(id) FROM cp_forum_topics WHERE title LIKE '[perf]%'");
        $this->connection->executeStatement(
            "INSERT INTO cp_forum_posts (topic_id, section_id, poster_name, body, attach_count, edit_count, discussion_state, created_at)
             WITH RECURSIVE s(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM s WHERE n < :n)
             SELECT t.id, t.section_id, CONCAT('perfuser', 1 + MOD(s.n * 104729, :users)),
                    CONCAT('<p>perf post ', s.n, ' ', REPEAT('lorem ipsum dolor sit amet ', 12), '</p>'),
                    0, 0, 'visible', DATE_SUB(NOW(), INTERVAL FLOOR(RAND() * 700) DAY)
             FROM s JOIN cp_forum_topics t ON t.id = :first + MOD(s.n, :topics)",
            ['n' => $n, 'first' => $firstTopic, 'topics' => max(1, $topics), 'users' => max(1, $users)],
        );
    }

    private function nodes(int $n): void
    {
        $this->connection->executeStatement(
            "INSERT INTO cp_nodes (type, slug, title, locale, status, data, created_at, updated_at)
             WITH RECURSIVE s(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM s WHERE n < :n)
             SELECT 'post', CONCAT('perf-yazi-', n), CONCAT('[perf] Yazi ', n), 'tr', 'published',
                    CONCAT('{\"body\":\"', REPEAT('lorem ipsum ', 80), '\"}'),
                    DATE_SUB(NOW(), INTERVAL FLOOR(RAND() * 900) DAY), NOW() FROM s",
            ['n' => $n],
        );
    }

    /** One hall: reservations are spread over ~10 years, four hours long, never overlapping, as the booking rule demands. */
    private function reservations(int $n): void
    {
        $this->connection->executeStatement(
            "INSERT INTO cp_hall_packages (name, hourly_rate, created_at, updated_at) SELECT '[perf] paket', 1000, NOW(), NOW()
             WHERE NOT EXISTS (SELECT 1 FROM cp_hall_packages WHERE name = '[perf] paket')",
        );
        $package = (int) $this->connection->fetchOne("SELECT id FROM cp_hall_packages WHERE name = '[perf] paket'");
        $this->connection->executeStatement(
            "INSERT INTO cp_hall_couples (groom_name, bride_name, phone, username, code_hash, created_at, updated_at)
             WITH RECURSIVE s(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM s WHERE n < :c)
             SELECT 'Damat', 'Gelin', CONCAT('05', LPAD(n, 9, '0')), CONCAT('perfcouple', n), 'x', NOW(), NOW() FROM s",
            ['c' => min($n, 20000)],
        );
        $firstCouple = (int) $this->connection->fetchOne("SELECT MIN(id) FROM cp_hall_couples WHERE username LIKE 'perfcouple%'");
        $couples = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM cp_hall_couples WHERE username LIKE 'perfcouple%'");

        // 6 hours per slot so a 4 hour event plus its buffer never touches the next one: n slots span n * 6 hours.
        $this->connection->executeStatement(
            "INSERT INTO cp_hall_reservations (couple_id, package_id, ref, event_date, starts_at, ends_at, total_hours, total_amount, deposit_amount, status, created_at, updated_at)
             WITH RECURSIVE s(n) AS (SELECT 0 UNION ALL SELECT n + 1 FROM s WHERE n < :n)
             SELECT :firstCouple + MOD(n, :couples), :package, CONCAT('PF', LPAD(HEX(n), 6, '0')),
                    DATE(DATE_ADD('2024-01-01 10:00:00', INTERVAL n * 6 HOUR)),
                    DATE_ADD('2024-01-01 10:00:00', INTERVAL n * 6 HOUR),
                    DATE_ADD('2024-01-01 14:00:00', INTERVAL n * 6 HOUR),
                    4, 4000, 1000, IF(MOD(n, 9) = 0, 'cancelled', 'confirmed'), NOW(), NOW() FROM s",
            ['n' => $n - 1, 'firstCouple' => $firstCouple, 'couples' => max(1, $couples), 'package' => $package],
        );
    }

    private function purge(): void
    {
        $c = $this->connection;
        if ($this->tableExists('cp_hall_reservations')) {
            $c->executeStatement("DELETE FROM cp_hall_reservations WHERE ref LIKE 'PF%'");
            $c->executeStatement("DELETE FROM cp_hall_couples WHERE username LIKE 'perfcouple%'");
            $c->executeStatement("DELETE FROM cp_hall_packages WHERE name = '[perf] paket'");
        }
        $c->executeStatement("DELETE FROM cp_forum_posts WHERE topic_id IN (SELECT id FROM cp_forum_topics WHERE title LIKE '[perf]%')");
        $c->executeStatement("DELETE FROM cp_forum_topics WHERE title LIKE '[perf]%'");
        $c->executeStatement("DELETE FROM cp_forum_sections WHERE code LIKE 'perf-%'");
        $c->executeStatement("DELETE FROM cp_nodes WHERE slug LIKE 'perf-yazi-%'");
        $c->executeStatement("DELETE FROM cp_users WHERE email LIKE '%@perf.invalid'");
    }

    private function tableExists(string $table): bool
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table]) > 0;
    }
}
