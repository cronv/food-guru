<?php

namespace App\Support;

use Illuminate\Database\QueryException;

class DatabaseError
{
    /**
     * Нарушение именно уникального индекса.
     *
     * Проверять SQLSTATE 23000 нельзя: это «нарушение целостности» вообще —
     * туда же попадают внешние ключи и NOT NULL. С такой проверкой упавший
     * внешний ключ превратился бы для человека в «вы уже голосовали».
     *
     * Поэтому смотрим на код драйвера: MySQL отдаёт 1062, SQLite — 19
     * с текстом про UNIQUE, PostgreSQL — SQLSTATE 23505.
     */
    public static function isDuplicateKey(QueryException $e): bool
    {
        $driverCode = (string) ($e->errorInfo[1] ?? '');
        $sqlState = (string) ($e->errorInfo[0] ?? '');

        if ($sqlState === '23505') {
            return true;
        }

        if ($driverCode === '1062') {
            return true;
        }

        return $driverCode === '19'
            && str_contains(strtoupper((string) ($e->errorInfo[2] ?? '')), 'UNIQUE');
    }
}
