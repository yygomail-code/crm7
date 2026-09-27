<?php

declare(strict_types=1);

namespace App\Core;

use App\Http\HttpException;

final class RateLimiter
{
    public static function hit(string $bucket, string $key, int $limit, int $windowSeconds): void
    {
        $pdo = Database::pdo();
        $hash = hash('sha256', $key);

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM rate_limits
             WHERE bucket = ? AND key_hash = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)'
        );
        $stmt->execute([$bucket, $hash, max(1, $windowSeconds)]);

        if ((int) $stmt->fetchColumn() >= $limit) {
            throw new HttpException(429, 'rate_limited', 'Слишком много действий подряд — попробуйте позже');
        }

        $pdo->prepare('INSERT INTO rate_limits (bucket, key_hash) VALUES (?, ?)')->execute([$bucket, $hash]);

        if (random_int(1, 50) === 1) {
            $pdo->exec('DELETE FROM rate_limits WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)');
        }
    }
}
