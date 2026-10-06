<?php

declare(strict_types=1);

namespace EduCloud\Core;

use mysqli;
use mysqli_stmt;
use Throwable;

/**
 * Thin MySQLi wrapper. Every query that carries values MUST go through these prepared helpers.
 * Never interpolate external input into $sql; dynamic identifiers must come from allowlists.
 */
final class Db
{
    private ?mysqli $conn = null;
    private int $transactionDepth = 0;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $name,
        private readonly string $user,
        private readonly string $pass,
    ) {
    }

    public static function fromConfig(Config $config, bool $test = false): self
    {
        $prefix = $test ? 'database.test.' : 'database.';
        return new self(
            (string) $config->get('database.host'),
            (int) $config->get('database.port'),
            (string) $config->get($prefix . 'name'),
            (string) $config->get($prefix . 'user'),
            (string) $config->get($prefix . 'pass'),
        );
    }

    public function connection(): mysqli
    {
        if ($this->conn === null) {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            $conn = new mysqli($this->host, $this->user, $this->pass, $this->name, $this->port);
            $conn->set_charset('utf8mb4');
            $conn->query(
                "SET time_zone = '+00:00', SESSION sql_mode = "
                . "'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'"
            );
            $this->conn = $conn;
        }
        return $this->conn;
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $params = []): array
    {
        $stmt = $this->run($sql, $params);
        $result = $stmt->get_result();
        $rows = $result === false ? [] : $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    /**
     * @param list<mixed> $params
     * @return array<string, mixed>|null
     */
    public function selectOne(string $sql, array $params = []): ?array
    {
        $rows = $this->select($sql, $params);
        return $rows[0] ?? null;
    }

    /** @param list<mixed> $params */
    public function scalar(string $sql, array $params = []): mixed
    {
        $row = $this->selectOne($sql, $params);
        return $row === null ? null : reset($row);
    }

    /**
     * INSERT/UPDATE/DELETE. Returns affected rows.
     *
     * @param list<mixed> $params
     */
    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->run($sql, $params);
        $affected = $stmt->affected_rows;
        $stmt->close();
        return (int) $affected;
    }

    /**
     * INSERT returning the auto-increment id.
     *
     * @param list<mixed> $params
     */
    public function insert(string $sql, array $params = []): int
    {
        $stmt = $this->run($sql, $params);
        $id = $stmt->insert_id;
        $stmt->close();
        return (int) $id;
    }

    /**
     * Runs $fn inside a transaction (nested calls join the outer transaction).
     *
     * @template T
     * @param callable(self): T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        $conn = $this->connection();
        if ($this->transactionDepth === 0) {
            $conn->begin_transaction();
        }
        $this->transactionDepth++;
        try {
            $result = $fn($this);
            $this->transactionDepth--;
            if ($this->transactionDepth === 0) {
                $conn->commit();
            }
            return $result;
        } catch (Throwable $e) {
            $this->transactionDepth--;
            if ($this->transactionDepth === 0) {
                $conn->rollback();
            }
            throw $e;
        }
    }

    public function close(): void
    {
        $this->conn?->close();
        $this->conn = null;
    }

    /** @param list<mixed> $params */
    private function run(string $sql, array $params): mysqli_stmt
    {
        $stmt = $this->connection()->prepare($sql);
        if ($params !== []) {
            $types = '';
            $values = [];
            foreach ($params as $param) {
                if (is_bool($param)) {
                    $types .= 'i';
                    $values[] = (int) $param;
                } elseif (is_int($param)) {
                    $types .= 'i';
                    $values[] = $param;
                } elseif (is_float($param)) {
                    $types .= 'd';
                    $values[] = $param;
                } elseif ($param === null || is_string($param)) {
                    $types .= 's';
                    $values[] = $param;
                } else {
                    throw new \InvalidArgumentException('Unsupported SQL parameter type: ' . get_debug_type($param));
                }
            }
            $stmt->bind_param($types, ...$values);
        }
        $stmt->execute();
        return $stmt;
    }
}
