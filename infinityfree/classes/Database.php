<?php
declare(strict_types=1);

namespace App\Classes;

use PDO;
use PDOException;

final class Database
{
    /** @var array<string, mixed> */
    private array $settings;
    private ?PDO $connection = null;

    /** @param array<string, mixed> $settings */
    public function __construct(array $settings)
    {
        $this->settings = $settings;
    }

    public function pdo(): PDO
    {
        if ($this->connection instanceof PDO) {
            return $this->connection;
        }

        $charset = (string) ($this->settings['charset'] ?? 'utf8mb4');
        $host = (string) $this->settings['host'];
        $port = (int) ($this->settings['port'] ?? 3306);
        $name = (string) $this->settings['name'];
        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";

        try {
            $this->connection = new PDO($dsn, (string) $this->settings['username'], (string) $this->settings['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset}",
            ]);
        } catch (PDOException $exception) {
            throw new PDOException('تعذر الاتصال بقاعدة البيانات. تحقق من إعدادات الاتصال.', 0, $exception);
        }

        return $this->connection;
    }

    /** @template T @param callable(PDO):T $callback @return T */
    public function transaction(callable $callback): mixed
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();

        try {
            $result = $callback($pdo);
            $pdo->commit();
            return $result;
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }
}
