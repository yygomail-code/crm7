<?php

declare(strict_types=1);

use App\Core\Config;
use App\Mail\MailService;
use App\Reports\ReportsService;
use App\Repositories\StocksRepository;
use App\Clients\ClientManagerService;
use App\Substitutions\SubstitutionService;

require __DIR__ . '/../src/autoload.php';

Config::load(__DIR__ . '/../config/.env');
date_default_timezone_set((string) Config::get('APP_TIMEZONE', 'Europe/Moscow'));

$mail = new MailService();
$queue = $mail->processQueue(50);

$reports = new ReportsService();
$schedules = $reports->processDueSchedules();

$substitutions = new SubstitutionService();
$endedSubstitutions = $substitutions->processEnded();

$clientManagers = new ClientManagerService();
$clientTransfers = $clientManagers->processDue();

$stuckJobs = (new StocksRepository())->failStuckJobs();

echo date('Y-m-d H:i:s')
    . ' email queue: ' . json_encode($queue, JSON_UNESCAPED_UNICODE)
    . ' report schedules: ' . json_encode($schedules, JSON_UNESCAPED_UNICODE)
    . ' substitutions: ' . json_encode($endedSubstitutions, JSON_UNESCAPED_UNICODE)
    . ' client transfers: ' . json_encode($clientTransfers, JSON_UNESCAPED_UNICODE)
    . ' stuck import jobs: ' . $stuckJobs
    . PHP_EOL;
