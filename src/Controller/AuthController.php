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
        private readonly PilotRepository  $pilots
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
            $token  = $this->auth->handleCallback($code, $state);
            $claims = $this->auth->verifyToken($token);

            // CharacterID is nested in the JWT sub claim: "CHARACTER:EVE:<id>"
            $sub         = $claims['sub'] ?? '';
            $characterId = (int) (explode(':', $sub)[2] ?? 0);
            $name        = $claims['name'] ?? 'Unknown Capsuleer';

            if ($characterId === 0) {
                $this->redirect('/?error=invalid_token');
                return;
            }

            // Upsert pilot
            $pilot = $this->pilots->upsert([
                'id'            => $characterId,
                'name'          => $name,
                'access_token'  => $token->getToken(),
                'refresh_token' => $token->getRefreshToken(),
                'token_expires' => date('Y-m-d H:i:s', $token->getExpires()),
                'scopes'        => explode(' ', $claims['scp'] ?? ''),
            ]);

            // Store pilot ID in session
            $_SESSION['pilot_id']   = $characterId;
            $_SESSION['pilot_name'] = $name;

            // Trigger background sync (in production, dispatch to a queue)
            try {
                $this->sync->syncAll($characterId);
                $this->trophies->evaluate($characterId);
            } catch (\Throwable $e) {
                // Sync failure is non-fatal — pilot is logged in
                error_log('Sync failed for ' . $characterId . ': ' . $e->getMessage());
            }

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
