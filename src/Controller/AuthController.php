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

    /** Redirects user to EVE SSO with no scopes — identification only */
    public function login(): void
    {
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
            $this->redirect('/?error=no_code');
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
                $this->redirect('/?error=invalid_token');
                return;
            }

            // Create pilot if they don't exist yet
            $pilot = $this->pilots->findById($character['id']);
            if ($pilot === null) {
                $pilot = $this->pilots->createById($character['id'], new \App\Service\EsiService(
                    new \Monolog\Logger('esi')
                ));
            }

            $_SESSION['pilot_id']   = $character['id'];
            $_SESSION['pilot_name'] = $character['name'];

            $this->redirect('/dashboard');
        } catch (\Throwable $e) {
            error_log('Login callback error: ' . $e->getMessage());
            $this->redirect('/?error=auth_failed');
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
            $this->redirect('/dashboard');
        } catch (\Throwable $e) {
            error_log('Section callback error: ' . $e->getMessage());
            $this->redirect('/dashboard?error=fetch_failed');
        }
    }

    public function logout(): void
    {
        session_destroy();
        $this->redirect('/');
    }

    private function redirect(string $url): void
    {
        header('Location: ' . $url);
        exit;
    }
}
