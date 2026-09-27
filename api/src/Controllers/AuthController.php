<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthService;
use App\Http\Request;
use App\Http\Response;

final class AuthController
{
    public function __construct(private readonly AuthService $auth = new AuthService())
    {
    }

    public function login(Request $request): Response
    {
        $data = $this->auth->login(
            $request->str('login'),
            (string) $request->input('password', ''),
            $request->ip,
            $request->userAgent()
        );

        return Response::ok($data);
    }

    public function register(Request $request): Response
    {
        $data = $this->auth->register($request->bodyAll(), $request->ip);

        return Response::ok($data, 201);
    }

    public function logout(Request $request): Response
    {
        [, $tokenRow] = $this->auth->authenticate($request->bearerToken());
        $this->auth->logout($tokenRow);

        return Response::ok(['revoked' => true]);
    }

    public function logoutOthers(Request $request): Response
    {
        [$user, $tokenRow] = $this->auth->authenticate($request->bearerToken());
        $revoked = $this->auth->logoutOthers($user, $tokenRow);

        return Response::ok(['revoked' => $revoked]);
    }

    public function logoutAll(Request $request): Response
    {
        [$user] = $this->auth->authenticate($request->bearerToken());
        $revoked = $this->auth->logoutAll($user);

        return Response::ok(['revoked' => $revoked]);
    }

    public function me(Request $request): Response
    {
        [$user] = $this->auth->authenticate($request->bearerToken());

        return Response::ok($this->auth->me($user));
    }

    public function changePassword(Request $request): Response
    {
        [$user, $tokenRow] = $this->auth->authenticate($request->bearerToken());

        $this->auth->changePassword(
            $user,
            $tokenRow,
            (string) $request->input('current_password', ''),
            (string) $request->input('new_password', '')
        );

        return Response::ok(['changed' => true]);
    }

    public function requestReset(Request $request): Response
    {
        $this->auth->requestPasswordReset($request->str('email'), $request->ip);

        return Response::ok(['sent' => true]);
    }

    public function resetPassword(Request $request): Response
    {
        $this->auth->resetPassword(
            $request->str('token'),
            (string) $request->input('new_password', '')
        );

        return Response::ok(['reset' => true]);
    }
}
