<?php

declare(strict_types=1);

namespace App\Auth;

use App\Audit\AuditService;
use App\Core\Config;
use App\Http\HttpException;
use App\Notifications\NotificationService;
use App\Repositories\AttemptRepository;
use App\Repositories\ResetRepository;
use App\Repositories\TokenRepository;
use App\Repositories\UserHistoryRepository;
use App\Repositories\UserRepository;
use App\Support\Mailer;
use App\Support\Validator;

final class AuthService
{
    private const MAX_LOGIN_ATTEMPTS = 5;

    private const LOGIN_WINDOW_MINUTES = 15;

    private const MAX_RESET_REQUESTS = 3;

    private const RESET_WINDOW_MINUTES = 60;

    private const MAX_REGISTER_ATTEMPTS = 5;

    private const REGISTER_WINDOW_MINUTES = 60;

    public function __construct(
        private readonly UserRepository $users = new UserRepository(),
        private readonly TokenRepository $tokens = new TokenRepository(),
        private readonly AttemptRepository $attempts = new AttemptRepository(),
        private readonly ResetRepository $resets = new ResetRepository(),
        private readonly UserHistoryRepository $history = new UserHistoryRepository(),
        private readonly NotificationService $notifications = new NotificationService(),
        private readonly AuditService $audit = new AuditService()
    ) {
    }

    public function login(string $login, string $password, string $ip, string $userAgent): array
    {
        if ($login === '' || $password === '') {
            throw new HttpException(422, 'validation_error', 'Укажите логин и пароль');
        }

        if ($this->attempts->countRecent('login', $login, $ip, self::LOGIN_WINDOW_MINUTES) >= self::MAX_LOGIN_ATTEMPTS) {
            throw new HttpException(429, 'rate_limited', 'Слишком много попыток входа. Повторите через 15 минут');
        }

        $user = $this->users->findByLogin($login);

        if ($user === null || !password_verify($password, (string) $user['PASSWORD'])) {
            $this->attempts->record('login', $login, $ip, false);

            throw new HttpException(401, 'invalid_credentials', 'Неверный логин или пароль');
        }

        $regState = (string) ($user['reg_state'] ?? 'active');

        if ($regState === 'pending') {
            $this->attempts->record('login', $login, $ip, false);

            throw new HttpException(403, 'pending_approval', 'Заявка на регистрацию ещё не подтверждена менеджером');
        }

        if ($regState === 'rejected') {
            $this->attempts->record('login', $login, $ip, false);

            throw new HttpException(403, 'registration_rejected', 'Заявка на регистрацию отклонена. Обратитесь к менеджеру');
        }

        if (($user['STATUS'] ?? 'N') !== 'Y' || ($user['ACTIVE'] ?? 'N') !== 'Y') {
            throw new HttpException(403, 'blocked', 'Учётная запись отключена');
        }

        $this->attempts->clear('login', $login, $ip);
        $this->users->touchActivity((int) $user['ID']);
        $this->audit->log($user, 'auth.login', 'user', (int) $user['ID'], [], $ip);

        $token = bin2hex(random_bytes(32));

        $expiresAt = $this->tokens->create(
            (int) $user['ID'],
            hash('sha256', $token),
            $ip,
            mb_substr($userAgent, 0, 255),
            Config::int('TOKEN_TTL_DAYS', 30) * 1440
        );

        return [
            'token' => $token,
            'expires_at' => $expiresAt,
            'user' => $this->users->toProfile($user),
            'capabilities' => $this->users->capabilitiesForLevel((int) $user['LEVEL']),
        ];
    }

    public function register(array $input, string $ip): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $company = trim((string) ($input['company'] ?? ''));
        $inn = preg_replace('/\s+/', '', (string) ($input['inn'] ?? '')) ?? '';
        $phone = trim((string) ($input['phone'] ?? ''));
        $email = mb_strtolower(trim((string) ($input['email'] ?? '')));
        $password = (string) ($input['password'] ?? '');
        $passwordConfirm = (string) ($input['password_confirm'] ?? $password);

        if (mb_strlen($name) < 3) {
            throw new HttpException(422, 'validation_error', 'Укажите ФИО');
        }

        if (empty($input['consent'])) {
            throw new HttpException(
                422,
                'consent_required',
                'Необходимо согласие на обработку персональных данных'
            );
        }

        if (!Validator::email($email)) {
            throw new HttpException(422, 'validation_error', 'Укажите корректный e-mail');
        }

        if ($inn !== '' && preg_match('/^(\d{10}|\d{12})$/', $inn) !== 1) {
            throw new HttpException(422, 'validation_error', 'ИНН должен содержать 10 или 12 цифр');
        }

        if ($password !== $passwordConfirm) {
            throw new HttpException(422, 'validation_error', 'Пароли не совпадают');
        }

        $error = Validator::password($password);

        if ($error !== null) {
            throw new HttpException(422, 'weak_password', $error);
        }

        if ($this->attempts->countRecent('register', $email, $ip, self::REGISTER_WINDOW_MINUTES, false) >= self::MAX_REGISTER_ATTEMPTS) {
            throw new HttpException(429, 'rate_limited', 'Слишком много попыток регистрации. Повторите позже');
        }

        if ($this->users->findAnyByEmail($email) !== null || $this->users->findAnyByLogin($email) !== null) {
            throw new HttpException(422, 'email_taken', 'Пользователь с таким e-mail уже зарегистрирован');
        }

        if ($phone !== '' && $this->users->findAnyByPhone($phone) !== null) {
            throw new HttpException(422, 'phone_taken', 'Пользователь с таким телефоном уже зарегистрирован');
        }

        $this->attempts->record('register', $email, $ip, true);

        $userId = $this->users->createPendingClient([
            'login' => $email,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'name' => mb_substr($name, 0, 255),
            'company' => mb_substr($company, 0, 255),
            'inn' => $inn,
            'phone' => mb_substr($phone, 0, 255),
            'email' => mb_substr($email, 0, 255),
        ]);

        $this->users->setConsent($userId);
        $this->history->add($userId, 'registered', null, 'Регистрация с сайта');
        $this->history->add($userId, 'consent', null, 'Согласие на обработку персональных данных');
        $this->audit->log(null, 'auth.register', 'user', $userId, ['email' => $email], $ip);

        $details = array_filter(
            [$company, $inn !== '' ? 'ИНН ' . $inn : '', $phone, $email],
            static fn (string $value): bool => $value !== ''
        );

        foreach ($this->users->listManagers() as $manager) {
            $this->notifications->notify(
                (int) $manager['id'],
                'user.pending',
                'Новая регистрация: ' . $name,
                implode(', ', $details),
                null
            );
        }

        return [
            'pending' => true,
            'message' => 'Заявка на регистрацию отправлена. Менеджер подтвердит её, после этого вы сможете войти.',
        ];
    }

    public function authenticate(?string $token): array
    {
        if ($token === null || $token === '') {
            throw new HttpException(401, 'unauthorized', 'Требуется авторизация');
        }

        $tokenRow = $this->tokens->findActiveByHash(hash('sha256', $token));

        if ($tokenRow === null) {
            throw new HttpException(401, 'unauthorized', 'Сессия не найдена или истекла');
        }

        $user = $this->users->findById((int) $tokenRow['user_id']);

        if ($user === null || ($user['ACTIVE'] ?? 'N') !== 'Y') {
            throw new HttpException(401, 'unauthorized', 'Пользователь недоступен');
        }

        $this->tokens->touch((int) $tokenRow['id']);

        return [$user, $tokenRow];
    }

    public function me(array $user): array
    {
        return [
            'user' => $this->users->toProfile($user),
            'capabilities' => $this->capabilitiesFor($user),
        ];
    }

    public function capabilitiesFor(array $user): array
    {
        return $this->users->capabilitiesForLevel((int) $user['LEVEL']);
    }

    public function logout(array $tokenRow): void
    {
        $this->tokens->revoke((int) $tokenRow['id']);
    }

    public function changePassword(array $user, array $tokenRow, string $current, string $new): void
    {
        if (($user['ACTIVE'] ?? 'N') !== 'Y' || (string) ($user['reg_state'] ?? 'active') !== 'active') {
            throw new HttpException(
                403,
                'not_activated',
                'Профиль ещё не активирован менеджером — смена пароля недоступна'
            );
        }

        if (!password_verify($current, (string) $user['PASSWORD'])) {
            throw new HttpException(422, 'invalid_current_password', 'Текущий пароль указан неверно');
        }

        if ($current === $new) {
            throw new HttpException(422, 'same_password', 'Новый пароль совпадает с текущим');
        }

        $error = Validator::password($new);

        if ($error !== null) {
            throw new HttpException(422, 'weak_password', $error);
        }

        $this->users->updatePassword((int) $user['ID'], password_hash($new, PASSWORD_DEFAULT));
        $this->tokens->revokeAllForUser((int) $user['ID'], (int) $tokenRow['id']);
        $this->history->add((int) $user['ID'], 'password_changed', (int) $user['ID'], 'Смена пароля');
        $this->audit->log($user, 'auth.password_change', 'user', (int) $user['ID']);
    }

    public function requestPasswordReset(string $email, string $ip): void
    {
        if (!Validator::email($email)) {
            throw new HttpException(422, 'validation_error', 'Укажите корректный e-mail');
        }

        if ($this->attempts->countRecent('reset', $email, $ip, self::RESET_WINDOW_MINUTES, false) >= self::MAX_RESET_REQUESTS) {
            return;
        }

        $this->attempts->record('reset', $email, $ip, true);

        $user = $this->users->findAnyByEmail($email);

        if ($user === null) {
            return;
        }

        $state = (string) ($user['reg_state'] ?? 'active');

        if ($state !== 'active' || ($user['ACTIVE'] ?? 'N') !== 'Y') {
            $this->audit->log($user, 'auth.password_reset_blocked', 'user', (int) $user['ID'], [], $ip);

            throw new HttpException(
                403,
                'not_activated',
                'Профиль ещё не активирован менеджером — восстановление пароля недоступно'
            );
        }

        $token = bin2hex(random_bytes(32));

        $this->resets->invalidateForUser((int) $user['ID']);
        $this->resets->create((int) $user['ID'], hash('sha256', $token), 60);

        $appUrl = rtrim((string) Config::get('APP_URL', 'http://localhost:5173'), '/');
        $link = $appUrl . '/#/reset?token=' . $token;

        Mailer::send(
            (string) $user['EMAIL'],
            'Восстановление пароля',
            "Здравствуйте!\n\nСсылка для установки нового пароля (действует 60 минут):\n$link\n\nЕсли вы не запрашивали сброс пароля, просто проигнорируйте это письмо."
        );
    }

    public function resetPassword(string $token, string $new): void
    {
        $error = Validator::password($new);

        if ($error !== null) {
            throw new HttpException(422, 'weak_password', $error);
        }

        $row = $this->resets->findActiveByHash(hash('sha256', $token));

        if ($row === null) {
            throw new HttpException(422, 'invalid_token', 'Ссылка недействительна или истекла');
        }

        $user = $this->users->findById((int) $row['user_id']);

        if ($user === null || ($user['ACTIVE'] ?? 'N') !== 'Y' || (string) ($user['reg_state'] ?? 'active') !== 'active') {
            throw new HttpException(
                403,
                'not_activated',
                'Профиль ещё не активирован менеджером — смена пароля недоступна'
            );
        }

        $this->users->updatePassword((int) $row['user_id'], password_hash($new, PASSWORD_DEFAULT));
        $this->resets->markUsed((int) $row['id']);
        $this->tokens->revokeAllForUser((int) $row['user_id']);
        $this->history->add((int) $row['user_id'], 'password_reset', null, 'Восстановление пароля по e-mail');
        $this->audit->log(null, 'auth.password_reset', 'user', (int) $row['user_id']);
    }
}
