<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Security\Support;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

/**
 * A throwaway in-memory SQLite connection carrying the hardening-layer tables.
 *
 * The classes under test talk to DBAL directly with hand-written SQL, so the only
 * honest way to test them is against a real connection running that SQL — a
 * mocked Connection would only assert that the test and the code agree on a
 * string. In-memory SQLite keeps that real while staying inside the unit suite's
 * "no external service" budget.
 */
final class SecurityTestDatabase
{
    public static function connect(): Connection
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        foreach (self::schema() as $statement) {
            $connection->executeStatement($statement);
        }

        return $connection;
    }

    /**
     * Gives an unpersisted entity the id Doctrine would have assigned, so the
     * services under test (all of which key on User::getId()) can be exercised
     * without an entity manager.
     */
    public static function userWithId(int $id, string $email = 'ali@example.com'): User
    {
        $user = new User($email);

        $property = new \ReflectionProperty(User::class, 'id');
        $property->setValue($user, $id);

        return $user;
    }

    /**
     * @return list<string>
     */
    private static function schema(): array
    {
        return [
            'CREATE TABLE cp_banned_ips (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ip_address VARCHAR(64) NOT NULL,
                banned_by INTEGER NULL,
                created_at VARCHAR(19) NOT NULL,
                expires_at VARCHAR(19) NULL,
                reason VARCHAR(255) NULL,
                source VARCHAR(16) NOT NULL DEFAULT "manual",
                is_range INTEGER NOT NULL DEFAULT 0,
                hit_count INTEGER NOT NULL DEFAULT 0,
                last_hit_at VARCHAR(19) NULL
            )',
            'CREATE TABLE cp_user_sessions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                session_hash VARCHAR(64) NOT NULL,
                ip_address VARCHAR(45) NULL,
                user_agent VARCHAR(255) NULL,
                fingerprint VARCHAR(64) NULL,
                created_at VARCHAR(19) NOT NULL,
                last_seen_at VARCHAR(19) NOT NULL,
                revoked_at VARCHAR(19) NULL
            )',
            'CREATE TABLE cp_password_history (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                created_at VARCHAR(19) NOT NULL
            )',
            'CREATE TABLE cp_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email VARCHAR(180) NOT NULL,
                username VARCHAR(180) NULL,
                roles TEXT NOT NULL DEFAULT "[]"
            )',
        ];
    }
}
