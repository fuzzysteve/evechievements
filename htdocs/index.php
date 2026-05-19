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
session_name($sessionName);
session_set_cookie_params([
    'lifetime' => $sessionLifetime,
    'path'     => '/',
    'secure'   => ($_ENV['APP_ENV'] ?? '') === 'production',
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
