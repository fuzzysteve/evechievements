<?php
declare(strict_types=1);
namespace App\Controller;

use App\Service\AuthService;
use App\Service\TrophyService;
use App\Model\PilotRepository;

final class AuthController
{
    public function __construct(
        private readonly AuthService      $auth,
    ) {}

    /** Redirects user to EVE SSO */
    public function login(): void
    {
        $url = $this->auth->getLoginUrl();
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

        try {
            $pilot  = $this->auth->handleCallback($code, $state);

            // CharacterID is nested in the JWT sub claim: "CHARACTER:EVE:<id>"
            $characterId = $pilot['id'];
            $name        = $pilot['name'];

            if ($characterId === 0) {
                $this->redirect('/?error=invalid_token');
                return;
            }

            // Store pilot ID in session
            $_SESSION['pilot_id']   = $characterId;
            $_SESSION['pilot_name'] = $name;

            // Trigger background sync (in production, dispatch to a queue)

            $this->redirect('/dashboard');
        } catch (\Throwable $e) {
            error_log('Auth callback error: ' . $e->getMessage());
            $this->redirect('/?error=auth_failed');
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
