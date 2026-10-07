<?php
declare(strict_types=1);
namespace App\Controller;

use App\Service\AuthService;
use App\Service\DataFetchService;
use App\Model\PilotRepository;

final class AuthController
{
    public function __construct(
        private readonly AuthService      $auth,
        private readonly DataFetchService $dataFetch,
        private readonly PilotRepository  $pilots
    ) {}

    /**
     * Redirects user to EVE SSO with no scopes — identification only.
     * ?return=/path brings them back there afterwards (same-site paths only); with &load=skills the
     * skills fetch runs straight after login, so e.g. /ships can log in and load skills in one go.
     */
    public function login(): void
    {
        unset($_SESSION['auth_return'], $_SESSION['auth_then']);
        if ($return = $this->safeReturn($_GET['return'] ?? null)) {
            $_SESSION['auth_return'] = $return;
        }
        if (($_GET['load'] ?? '') === 'skills') {
            $_SESSION['auth_then'] = 'skills';
        }
        $url = $this->auth->getLoginUrl();
        header('Location: ' . $url);
        exit;
    }

    /** Redirects user to EVE SSO for a specific section scope */
    public function fetchSection(string $section): void
    {
        if (empty($_SESSION['pilot_id'])) {
            $this->redirect('/');
            return;
        }
        if (!isset(AuthService::SECTION_SCOPES[$section])) {
            $this->redirect('/dashboard');
            return;
        }
        // A return path given here wins; otherwise keep one set by login() for a chained fetch
        if ($return = $this->safeReturn($_GET['return'] ?? null)) {
            $_SESSION['auth_return'] = $return;
        }
        $url = $this->auth->getSectionUrl($section);
        header('Location: ' . $url);
        exit;
    }

    /** Handles the OAuth2 callback from EVE SSO */
    public function callback(): void
    {
        $code  = $_GET['code']  ?? '';
        $state = $_GET['state'] ?? '';

        if ($code === '') {
            // Cancelled at EVE's login page
            $this->fail('no_code', str_contains($state, '.') ? '/dashboard' : '/');
            return;
        }

        // Determine if this is a section fetch or a login
        // Section states contain a '.' (base64payload.hmac)
        $isSection = str_contains($state, '.');

        if ($isSection) {
            $this->handleSectionCallback($code, $state);
        } else {
            $this->handleLoginCallback($code, $state);
        }
    }

    private function handleLoginCallback(string $code, string $state): void
    {
        try {
            $character = $this->auth->handleLoginCallback($code, $state);

            if ($character['id'] === 0) {
                $this->fail('invalid_token', '/');
                return;
            }

            // Create pilot if they don't exist yet
            $pilot = $this->pilots->findById($character['id']);
            if ($pilot === null) {
                $pilot = $this->pilots->createById($character['id'], new \App\Service\EsiService(
                    new \Monolog\Logger('esi')
                ));
            }

            // New session ID on login, so an ID set before login can't be used afterwards
            session_regenerate_id(true);
            $_SESSION['pilot_id']   = $character['id'];
            $_SESSION['pilot_name'] = $character['name'];

            // Chained skills fetch (e.g. from /ships): the return path stays in the session for it
            if (($_SESSION['auth_then'] ?? null) === 'skills') {
                unset($_SESSION['auth_then']);
                $this->redirect('/auth/fetch/skills');
                return;
            }
            $this->redirect($this->takeReturn() ?? '/dashboard');
        } catch (\Throwable $e) {
            error_log('Login callback error: ' . $e->getMessage());
            $this->fail('auth_failed', '/');
        }
    }

    private function handleSectionCallback(string $code, string $state): void
    {
        try {
            $result = $this->auth->handleSectionCallback($code, $state);

            $this->dataFetch->fetch(
                $result['section'],
                $result['character_id'],
                $result['access_token']
            );

            // Token is now discarded — DataFetchService stored the data in session
            $this->redirect($this->takeReturn() ?? '/dashboard');
        } catch (\Throwable $e) {
            error_log('Section callback error: ' . $e->getMessage());
            $this->fail('fetch_failed', '/dashboard');
        }
    }

    public function logout(): void
    {
        session_destroy();
        $this->redirect('/');
    }

    /** Back to the page the flow started from (or $fallback) with ?error=$code. */
    private function fail(string $code, string $fallback): void
    {
        unset($_SESSION['auth_then']);
        $url = $this->takeReturn() ?? $fallback;
        $this->redirect($url . (str_contains($url, '?') ? '&' : '?') . 'error=' . $code);
    }

    /** The stored return path, removed from the session. */
    private function takeReturn(): ?string
    {
        $return = $_SESSION['auth_return'] ?? null;
        unset($_SESSION['auth_return']);
        return $this->safeReturn($return);
    }

    /** A path on this site, or null: no scheme, no host, no "//" or backslash tricks, no control characters. */
    private function safeReturn(mixed $url): ?string
    {
        if (!is_string($url) || $url === '' || strlen($url) > 300) return null;
        if ($url[0] !== '/' || str_starts_with($url, '//') || str_contains($url, '\\')) return null;
        if (preg_match('/[\x00-\x1f\x7f]/', $url)) return null;
        return $url;
    }

    private function redirect(string $url): void
    {
        header('Location: ' . $url);
        exit;
    }
}
