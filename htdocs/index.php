<?php
declare(strict_types=1);

// ── Bootstrap ──────────────────────────────────────────────────────────────
define('ROOT', dirname(__DIR__));

require ROOT . '/vendor/autoload.php';

// Load .env
$dotenv = Dotenv\Dotenv::createImmutable(ROOT);
$dotenv->safeLoad();

// Session
$sessionName     = $_ENV['SESSION_NAME']     ?? 'evechievements';
$sessionLifetime = (int) ($_ENV['SESSION_LIFETIME'] ?? 86400);

// Sessions live in var/sessions with PHP's own garbage collection at the configured lifetime.
// The system default path is swept by Ubuntu's phpsessionclean timer at php.ini's
// gc_maxlifetime (24 minutes), which logged pilots out long before the cookie expired.
ini_set('session.save_path',      ROOT . '/var/sessions');
ini_set('session.gc_maxlifetime', (string) $sessionLifetime);
ini_set('session.gc_probability', '1');
ini_set('session.gc_divisor',     '100');
ini_set('session.use_strict_mode', '1');   // never adopt a session ID the server didn't issue

session_name($sessionName);
session_set_cookie_params([
    'lifetime' => $sessionLifetime,
    'path'     => '/',
    'secure'   => str_starts_with($_ENV['APP_URL'] ?? '', 'https://'),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

// ── Dispatch ───────────────────────────────────────────────────────────────
$router = new App\Router();
$router->dispatch(
    $_SERVER['REQUEST_METHOD'],
    $_SERVER['REQUEST_URI']
);
