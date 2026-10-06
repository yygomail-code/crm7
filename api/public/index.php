<?php

declare(strict_types=1);

use App\Controllers\AdminController;
use App\Controllers\AttachmentController;
use App\Controllers\AuditController;
use App\Controllers\AppSettingsController;
use App\Controllers\ChatController;
use App\Controllers\AuthController;
use App\Controllers\ClientController;
use App\Controllers\HealthController;
use App\Controllers\LegalController;
use App\Controllers\ProfileController;
use App\Controllers\NotificationController;
use App\Controllers\ReportController;
use App\Controllers\MyReportController;
use App\Controllers\ReportScheduleController;
use App\Controllers\RequestController;
use App\Controllers\RequestDraftController;
use App\Controllers\StocksController;
use App\Controllers\SubstitutionController;
use App\Controllers\UserController;
use App\Core\Config;
use App\Core\Logger;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;

require __DIR__ . '/../src/autoload.php';

$root = dirname(__DIR__);

Config::load($root . '/config/.env');

date_default_timezone_set((string) Config::get('APP_TIMEZONE', 'Europe/Moscow'));

$allowedOrigins = array_filter(array_map('trim', explode(',', (string) Config::get('CORS_ORIGIN', ''))));
$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');

if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Allow-Methods: GET, POST, PATCH, PUT, DELETE, OPTIONS');
    header('Access-Control-Max-Age: 600');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$request = Request::fromGlobals();

$basePath = rtrim((string) Config::get('API_BASE_PATH', ''), '/');

if ($basePath !== '' && str_starts_with($request->path, $basePath)) {
    $request = $request->withPath(substr($request->path, strlen($basePath)) ?: '/');
}

$router = new Router();
$auth = new AuthController();
$health = new HealthController();
$appSettings = new AppSettingsController();
$requests = new RequestController();
$requestDrafts = new RequestDraftController();
$clients = new ClientController();
$users = new UserController();
$attachments = new AttachmentController();
$notifications = new NotificationController();
$admin = new AdminController();
$reports = new ReportController();
$myReports = new MyReportController();
$reportSchedules = new ReportScheduleController();
$substitutions = new SubstitutionController();
$chat = new ChatController();
$stocks = new StocksController();
$audit = new AuditController();
$legal = new LegalController();
$profile = new ProfileController();

$router->get('/health', [$health, 'index']);
$router->get('/settings/app', [$appSettings, 'index']);
$router->get('/settings/public', [$appSettings, 'publicSettings']);
$router->get('/price-types', [$appSettings, 'priceTypes']);
$router->get('/item-groups', [$appSettings, 'itemGroups']);
$router->post('/auth/login', [$auth, 'login']);
$router->post('/auth/register', [$auth, 'register']);
$router->get('/legal/{code}', [$legal, 'show']);

$router->patch('/profile', [$profile, 'update']);
$router->post('/profile/avatar', [$profile, 'uploadAvatar']);
$router->delete('/profile/avatar', [$profile, 'deleteAvatar']);
$router->get('/users/{id}/avatar', [$profile, 'avatar']);
$router->get('/users/{id}/profile', [$profile, 'show']);
$router->post('/auth/logout', [$auth, 'logout']);
$router->post('/auth/logout-others', [$auth, 'logoutOthers']);
$router->post('/auth/logout-all', [$auth, 'logoutAll']);
$router->get('/auth/me', [$auth, 'me']);
$router->post('/auth/password/change', [$auth, 'changePassword']);
$router->post('/auth/password/reset-request', [$auth, 'requestReset']);
$router->post('/auth/password/reset', [$auth, 'resetPassword']);

$router->get('/request-statuses', [$requests, 'statuses']);
$router->get('/activity-types', [$requests, 'activityTypes']);
$router->get('/requests/summary', [$requests, 'summary']);
$router->get('/requests/filters', [$requests, 'filters']);
$router->get('/requests', [$requests, 'index']);
$router->post('/requests', [$requests, 'create']);
$router->post('/requests/price-preview', [$requests, 'preview']);
$router->get('/requests/{id}', [$requests, 'show']);
$router->post('/requests/{id}/transition', [$requests, 'transition']);
$router->post('/requests/{id}/items', [$requests, 'items']);
$router->post('/requests/{id}/edit', [$requests, 'edit']);
$router->post('/requests/{id}/meta', [$requests, 'meta']);
$router->get('/request-drafts', [$requestDrafts, 'index']);
$router->post('/request-drafts', [$requestDrafts, 'create']);
$router->get('/request-drafts/{id}', [$requestDrafts, 'show']);
$router->patch('/request-drafts/{id}', [$requestDrafts, 'update']);
$router->delete('/request-drafts/{id}', [$requestDrafts, 'remove']);
$router->post('/requests/{id}/claim', [$requests, 'claim']);
$router->post('/requests/{id}/assign', [$requests, 'assign']);
$router->post('/requests/{id}/comments', [$requests, 'comment']);
$router->post('/requests/{id}/activities', [$requests, 'activity']);

$router->get('/requests/{id}/attachments', [$attachments, 'index']);
$router->post('/requests/{id}/attachments', [$attachments, 'upload']);
$router->get('/attachments/{id}', [$attachments, 'download']);
$router->delete('/attachments/{id}', [$attachments, 'remove']);

$router->get('/notifications/count', [$notifications, 'count']);
$router->get('/notifications', [$notifications, 'index']);
$router->post('/notifications/read', [$notifications, 'read']);
$router->get('/notification-settings', [$notifications, 'settings']);
$router->put('/notification-settings', [$notifications, 'saveSettings']);

$router->get('/admin/settings/email', [$admin, 'emailSettings']);
$router->post('/admin/settings/email', [$admin, 'saveEmailSettings']);
$router->post('/admin/settings/email/test', [$admin, 'testEmail']);
$router->get('/admin/settings/database', [$admin, 'databaseSettings']);
$router->post('/admin/settings/database', [$admin, 'saveDatabaseSettings']);
$router->post('/admin/settings/database/test', [$admin, 'testDatabaseSettings']);
$router->get('/admin/templates', [$admin, 'templates']);
$router->post('/admin/templates/{code}', [$admin, 'saveTemplate']);
$router->get('/admin/queue', [$admin, 'queue']);
$router->get('/admin/settings/system', [$admin, 'systemSettings']);
$router->post('/admin/settings/system', [$admin, 'saveSystemSettings']);
$router->get('/admin/price-types', [$admin, 'priceTypes']);
$router->post('/admin/price-types', [$admin, 'createPriceType']);
$router->patch('/admin/price-types/{id}', [$admin, 'updatePriceType']);
$router->delete('/admin/price-types/{id}', [$admin, 'deletePriceType']);
$router->get('/admin/item-groups', [$admin, 'itemGroups']);
$router->post('/admin/item-groups', [$admin, 'createItemGroup']);
$router->patch('/admin/item-groups/{id}', [$admin, 'updateItemGroup']);
$router->delete('/admin/item-groups/{id}', [$admin, 'deleteItemGroup']);

$router->get('/reports/my/summary', [$myReports, 'summary']);
$router->get('/reports/my/export', [$myReports, 'export']);
$router->get('/reports/summary', [$reports, 'summary']);
$router->get('/reports/sales-leads', [$reports, 'salesLeads']);
$router->get('/reports/sales-leads/export', [$reports, 'salesLeadsExport']);
$router->get('/reports/warehouses', [$reports, 'warehouses']);
$router->get('/reports/warehouses/export', [$reports, 'warehousesExport']);
$router->get('/reports/export', [$reports, 'export']);

$router->get('/admin/report-schedules', [$reportSchedules, 'index']);
$router->post('/admin/report-schedules', [$reportSchedules, 'create']);
$router->post('/admin/report-schedules/{id}', [$reportSchedules, 'update']);
$router->delete('/admin/report-schedules/{id}', [$reportSchedules, 'remove']);
$router->post('/admin/report-schedules/{id}/send', [$reportSchedules, 'send']);

$router->get('/chat/threads', [$chat, 'index']);
$router->post('/chat/threads', [$chat, 'start']);
$router->get('/chat/unread', [$chat, 'unread']);
$router->get('/chat/quick-replies', [$chat, 'quickReplies']);
$router->get('/chat/threads/{id}/messages', [$chat, 'messages']);
$router->post('/chat/threads/{id}/messages', [$chat, 'post']);
$router->post('/chat/threads/{id}/read', [$chat, 'read']);
$router->post('/chat/threads/{id}/attachments', [$chat, 'uploadAttachment']);
$router->get('/chat/attachments/{id}', [$chat, 'downloadAttachment']);

$router->get('/stocks/warehouses', [$stocks, 'warehouses']);
$router->get('/stocks/levels', [$stocks, 'levels']);
$router->get('/stocks/search-counts', [$stocks, 'searchCounts']);
$router->get('/stocks/export', [$stocks, 'export']);
$router->post('/stocks/import', [$stocks, 'import']);
$router->get('/stocks/import/history', [$stocks, 'history']);
$router->post('/stocks/{id}/items', [$stocks, 'createItem']);
$router->patch('/stocks/levels/{itemId}', [$stocks, 'updateItem']);
$router->patch('/stocks/{id}', [$stocks, 'rename']);

$router->get('/admin/legal', [$legal, 'index']);
$router->post('/admin/legal/{code}', [$legal, 'save']);

$router->get('/admin/audit', [$audit, 'index']);
$router->get('/admin/audit/actions', [$audit, 'actions']);

$router->get('/substitutions', [$substitutions, 'index']);
$router->post('/substitutions', [$substitutions, 'create']);
$router->delete('/substitutions/{id}', [$substitutions, 'end']);

$router->get('/clients', [$clients, 'index']);
$router->get('/clients/pending', [$clients, 'pending']);
$router->get('/clients/{id}', [$clients, 'show']);
$router->get('/clients/{id}/interests', [$clients, 'interests']);
$router->post('/clients/{id}/activate', [$clients, 'activate']);
$router->post('/clients/{id}/reject', [$clients, 'reject']);
$router->post('/clients/{id}/block', [$clients, 'block']);
$router->post('/clients/{id}/unblock', [$clients, 'unblock']);
$router->patch('/clients/{id}', [$clients, 'update']);
$router->post('/clients/{id}/claim', [$clients, 'claim']);
$router->post('/clients/{id}/assign', [$clients, 'assign']);
$router->post('/clients/{id}/transfer', [$clients, 'transfer']);
$router->post('/client-transfers/{id}/accept', [$clients, 'acceptTransfer']);
$router->post('/client-transfers/{id}/decline', [$clients, 'declineTransfer']);
$router->post('/client-transfers/{id}/cancel', [$clients, 'cancelTransfer']);
$router->get('/managers', [$users, 'managers']);
$router->get('/users', [$users, 'index']);
$router->post('/users', [$users, 'create']);
$router->patch('/users/{id}', [$users, 'update']);
$router->post('/users/{id}/block', [$users, 'block']);
$router->post('/users/{id}/unblock', [$users, 'unblock']);
$router->post('/users/{id}/reset-password', [$users, 'resetPassword']);
$router->get('/roles', [$users, 'roles']);
$router->post('/roles/{level}', [$users, 'saveRole']);

try {
    $response = $router->dispatch($request);
} catch (HttpException $exception) {
    $response = Response::error($exception->errorCode, $exception->getMessage(), $exception->status);
} catch (Throwable $exception) {
    Logger::error('Unhandled exception', [
        'message' => $exception->getMessage(),
        'file' => $exception->getFile(),
        'line' => $exception->getLine(),
    ]);

    $message = Config::get('APP_ENV', 'prod') === 'dev'
        ? $exception->getMessage()
        : 'Внутренняя ошибка сервера';

    $response = Response::error('server_error', $message, 500);
}

$response->send();

