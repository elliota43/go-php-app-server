<?php

declare(strict_types=1);

namespace BareMetalPHP\Auth;

use App\Models\User;
use BareMetalPHP\Support\Session;

/**
 * Authentication service
 */
class Auth
{
    protected const SESSION_KEY = 'auth_user_id';

    /**
     * Get the currently authenticated user
     */
    public static function user(): ?User
    {
        $userId = Session::get(self::SESSION_KEY);
        
        if (!$userId) {
            return null;
        }

        return User::find((int)$userId);
    }

    /**
     * Check if a user is authenticated
     */
    public static function check(): bool
    {
        return Session::has(self::SESSION_KEY) && self::user() !== null;
    }

    /**
     * Get the authenticated user's ID
     */
    public static function id(): ?int
    {
        return Session::get(self::SESSION_KEY);
    }

    /**
     * Log in a user
     */
    public static function login(User $user): void
    {
        Session::regenerate();
        Session::set(self::SESSION_KEY, (int)$user->getAttribute('id'));
    }

    /**
     * Log out the current user
     */
    public static function logout(): void
    {
        Session::remove(self::SESSION_KEY);
        Session::regenerate();
    }

    /**
     * Attempt to authenticate a user with credentials
     */
    public static function attempt(string $email, string $password): ?User
    {
        $user = User::query()
            ->where('email', '=', strtolower(trim($email)))
            ->first();

        if (!$user) {
            return null;
        }

        $hashedPassword = $user->getAttribute('password');

        if (!$hashedPassword || !password_verify($password, $hashedPassword)) {
            return null;
        }

        self::login($user);

        return $user;
    }
}

