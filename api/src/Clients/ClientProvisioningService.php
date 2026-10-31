<?php

declare(strict_types=1);

namespace App\Clients;

use App\Http\HttpException;
use App\Repositories\ClientManagerRepository;
use App\Repositories\UserHistoryRepository;
use App\Repositories\UserRepository;
use App\Support\Validator;
use PDOException;

final class ClientProvisioningService
{
    public function __construct(
        private readonly UserRepository $users = new UserRepository(),
        private readonly UserHistoryRepository $history = new UserHistoryRepository(),
        private readonly ClientManagerRepository $managers = new ClientManagerRepository()
    ) {
    }

    /**
     * Создаёт активного клиента (LEVEL=5) по данным менеджера и привязывает его к автору.
     * Вызывается внутри транзакции создания заявки.
     */
    public function create(array $input, array $actor): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $inn = preg_replace('/\s+/', '', (string) ($input['inn'] ?? '')) ?? '';
        $phone = trim((string) ($input['phone'] ?? ''));
        $email = mb_strtolower(trim((string) ($input['email'] ?? '')));

        if (mb_strlen($name) < 2) {
            throw new HttpException(422, 'validation_error', 'Укажите название клиента (минимум 2 символа)');
        }

        if (mb_strlen($name) > 255) {
            throw new HttpException(422, 'validation_error', 'Название клиента слишком длинное');
        }

        if ($inn === '') {
            throw new HttpException(422, 'validation_error', 'Укажите ИНН клиента');
        }

        if (preg_match('/^(\d{10}|\d{12})$/', $inn) !== 1) {
            throw new HttpException(422, 'validation_error', 'ИНН должен содержать 10 или 12 цифр');
        }

        $phoneDigits = preg_replace('/\D+/', '', $phone) ?? '';

        if (strlen($phoneDigits) < 10 || strlen($phoneDigits) > 15) {
            throw new HttpException(422, 'validation_error', 'Телефон должен содержать от 10 до 15 цифр');
        }

        if ($email !== '' && !Validator::email($email)) {
            throw new HttpException(422, 'validation_error', 'Неверный формат e-mail');
        }

        $existing = $this->users->findActiveClientByInn($inn);

        if ($existing !== null) {
            throw new HttpException(
                422,
                'client_exists',
                'Клиент с таким ИНН уже есть в системе: ' . (string) ($existing['FULL_NAME'] ?? '')
            );
        }

        if ($this->users->findAnyByPhone($phone) !== null) {
            throw new HttpException(422, 'phone_taken', 'Клиент с таким телефоном уже есть в системе');
        }

        if ($email !== ''
            && ($this->users->findAnyByEmail($email) !== null || $this->users->findAnyByLogin($email) !== null)
        ) {
            throw new HttpException(422, 'email_taken', 'Клиент с таким e-mail уже есть в системе');
        }

        $login = $this->uniqueLogin($email !== '' ? $email : $this->phoneLogin($phoneDigits));
        $password = bin2hex(random_bytes(5)) . 'Aa1';

        try {
            $clientId = $this->users->createActiveClient([
                'login' => $login,
                'password' => $password,
                'name' => $name,
                'inn' => $inn,
                'phone' => $phone,
                'email' => $email,
            ]);
        } catch (PDOException $exception) {
            if (($exception->errorInfo[0] ?? '') === '23000') {
                throw new HttpException(
                    422,
                    'client_exists',
                    'Клиент с такими контактами уже есть в системе'
                );
            }

            throw $exception;
        }

        $actorId = (int) ($actor['ID'] ?? 0);

        $this->history->add(
            $clientId,
            'created',
            $actorId > 0 ? $actorId : null,
            'Создан менеджером при оформлении заявки'
        );

        if ($actorId > 0) {
            $this->managers->assign($clientId, $actorId, $actorId);
        }

        return [
            'id' => $clientId,
            'name' => $name,
            'login' => $login,
            'email' => $email,
            'phone' => $phone,
            'password' => $password,
        ];
    }

    private function phoneLogin(string $digits): string
    {
        return 'client-' . ($digits !== '' ? $digits : bin2hex(random_bytes(3)));
    }

    private function uniqueLogin(string $base): string
    {
        $login = mb_substr($base, 0, 200);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            if ($this->users->findAnyByLogin($login) === null) {
                return $login;
            }

            $login = mb_substr($base, 0, 190) . '-' . bin2hex(random_bytes(3));
        }

        return 'client-' . bin2hex(random_bytes(8));
    }
}
