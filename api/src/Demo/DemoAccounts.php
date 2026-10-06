<?php

declare(strict_types=1);

namespace App\Demo;

final class DemoAccounts
{
    public const PASSWORD = 'demo123456';

    /**
     * @return list<array{login:string,level:int,name:string,role:string,email:string,company:?string}>
     */
    public static function all(): array
    {
        return [
            ['login' => 'demo_root', 'level' => 90, 'name' => 'Демо Сисадмин', 'role' => 'Сисадмин', 'email' => 'demo_root@demo.local', 'company' => null],
            ['login' => 'demo_admin', 'level' => 50, 'name' => 'Демо Администратор', 'role' => 'Администратор', 'email' => 'demo_admin@demo.local', 'company' => null],
            ['login' => 'demo_manager', 'level' => 10, 'name' => 'Демо Менеджер', 'role' => 'Менеджер', 'email' => 'demo_manager@demo.local', 'company' => null],
            ['login' => 'demo_client', 'level' => 5, 'name' => 'Демо Клиент', 'role' => 'Клиент', 'email' => 'demo_client@demo.local', 'company' => 'ООО «Демо Компания»'],
        ];
    }
}
