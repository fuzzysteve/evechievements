<?php
declare(strict_types=1);
namespace App\Service;

/**
 * Per-session CSRF token. Every POST form carries it (Twig: {{ csrf_field() }}) and the Router
 * rejects POSTs without a matching one. Defence in depth on top of the SameSite=Lax cookie.
 */
final class Csrf
{
    private const KEY = '_csrf';

    public static function token(): string
    {
        return $_SESSION[self::KEY] ??= bin2hex(random_bytes(32));
    }

    public static function field(): string
    {
        return '<input type="hidden" name="' . self::KEY . '" value="' . self::token() . '">';
    }

    public static function isValid(mixed $submitted): bool
    {
        return is_string($submitted) && isset($_SESSION[self::KEY]) && hash_equals($_SESSION[self::KEY], $submitted);
    }

    public static function submitted(): mixed
    {
        return $_POST[self::KEY] ?? null;
    }
}
