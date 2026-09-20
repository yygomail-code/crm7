<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Http\Response;

final class HealthController
{
    public function index(): Response
    {
        $database = 'ok';

        try {
            Database::pdo()->query('SELECT 1');
        } catch (\Throwable) {
            $database = 'error';
        }

        return Response::ok([
            'status' => $database === 'ok' ? 'ok' : 'degraded',
            'database' => $database,
            'time' => date('c'),
            'php' => PHP_VERSION,
        ]);
    }
}
