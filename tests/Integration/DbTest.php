<?php

declare(strict_types=1);

namespace EduCloud\Tests\Integration;

use EduCloud\Core\Ulid;
use EduCloud\Tests\TestCase;
use RuntimeException;

/** Runs against educloud_test only. Each test cleans up the rows it creates. */
final class DbTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->app()->db()->execute("DELETE FROM tenants WHERE slug LIKE 'dbtest-%'");
        parent::tearDown();
    }

    private function insertTenant(string $slug, string $name = 'T'): int
    {
        return $this->app()->db()->insert(
            'INSERT INTO tenants (public_id, type, name, slug) VALUES (?, ?, ?, ?)',
            [Ulid::generate(), 'personal', $name, $slug]
        );
    }

    public function testConnectsToTestDatabaseOnly(): void
    {
        self::assertSame('educloud_test', $this->app()->db()->scalar('SELECT DATABASE()'));
    }

    public function testPreparedStatementsStoreInjectionPayloadsLiterally(): void
    {
        $payload = "x'); DROP TABLE tenants; --";
        $id = $this->insertTenant('dbtest-inj', $payload);
        $row = $this->app()->db()->selectOne('SELECT name FROM tenants WHERE id = ?', [$id]);
        self::assertSame($payload, $row['name'] ?? null);
        $tables = $this->app()->db()->scalar(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'tenants'"
        );
        self::assertSame(1, (int) $tables);
    }

    public function testParameterTypes(): void
    {
        $db = $this->app()->db();
        $row = $db->selectOne('SELECT ? AS i, ? AS s, ? AS n, ? AS b, ? AS f', [42, 'texto ñ', null, true, 1.5]);
        self::assertSame(42, $row['i']);
        self::assertSame('texto ñ', $row['s']);
        self::assertNull($row['n']);
        self::assertSame(1, $row['b']);
        self::assertEqualsWithDelta(1.5, (float) $row['f'], 0.0001);
    }

    public function testTransactionRollsBackOnException(): void
    {
        $db = $this->app()->db();
        try {
            $db->transaction(function () {
                $this->insertTenant('dbtest-rollback');
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
        }
        self::assertSame(0, (int) $db->scalar("SELECT COUNT(*) FROM tenants WHERE slug = 'dbtest-rollback'"));
    }

    public function testNestedTransactionsJoinTheOuterOne(): void
    {
        $db = $this->app()->db();
        try {
            $db->transaction(function ($db) {
                $this->insertTenant('dbtest-outer');
                $db->transaction(fn () => $this->insertTenant('dbtest-inner'));
                throw new RuntimeException('outer fails');
            });
        } catch (RuntimeException) {
        }
        self::assertSame(0, (int) $db->scalar("SELECT COUNT(*) FROM tenants WHERE slug IN ('dbtest-outer', 'dbtest-inner')"));
    }

    public function testSessionUsesUtcAndStrictMode(): void
    {
        $db = $this->app()->db();
        self::assertSame('+00:00', $db->scalar('SELECT @@session.time_zone'));
        self::assertStringContainsString('STRICT_ALL_TABLES', (string) $db->scalar('SELECT @@session.sql_mode'));
    }
}
