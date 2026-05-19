<?php
declare(strict_types=1);
namespace App\Service;

use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Token\AccessToken;
use RuntimeException;

final class AuthService
{
    private GenericProvider $provider;

    public function __construct()
    {
        $this->provider = new GenericProvider([
            'clientId'                => $_ENV['EVE_CLIENT_ID'],
            'clientSecret'            => $_ENV['EVE_CLIENT_SECRET'],
            'redirectUri'             => $_ENV['EVE_CALLBACK_URL'],
            'urlAuthorize'            => 'https://login.eveonline.com/v2/oauth/authorize',
	    'urlAccessToken'          => 'https://login.eveonline.com/v2/oauth/token',
	    'urlResourceOwnerDetails' => 'https://login.eveonline.com/oauth/verify'
        ]);
    }

    public function getLoginUrl(): string
    {
        $url = $this->provider->getAuthorizationUrl();
        $_SESSION['oauth2_state'] = $this->provider->getState();
        return $url;
    }

    public function handleCallback(string $code, string $returnedState): array
    {
        if (empty($_SESSION['oauth2_state']) || $returnedState !== $_SESSION['oauth2_state']) {
            throw new RuntimeException('OAuth2 state mismatch.');
        }
        unset($_SESSION['oauth2_state']);

        $token = $this->provider->getAccessToken('authorization_code', ['code' => $code]);

        // EVE SSO v2 — decode the JWT payload directly, no verify endpoint needed
        $parts   = explode('.', $token->getToken());
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

        // sub is "CHARACTER:EVE:12345678"
        $characterId = (int) explode(':', $payload['sub'])[2];
        $name        = $payload['name'];

        return [
            'id'            => $characterId,
            'name'          => $name,
            'access_token'  => $token->getToken(),
            'refresh_token' => $token->getRefreshToken(),
            'token_expires' => date('Y-m-d H:i:s', $token->getExpires()),
            'scopes'        => explode(' ', $payload['scp'] ?? ''),
        ];
    }



    public function refreshToken(string $refreshToken): AccessToken
    {
        return $this->provider->getAccessToken('refresh_token', [
            'refresh_token' => $refreshToken,
        ]);
    }

    /**
     * Verifies the token against ESI and returns character claims.
     * Returns array with CharacterID, CharacterName, Scopes, ExpiresOn.
     */
    public function verifyToken(AccessToken $token): array
    {
        $owner = $this->provider->getResourceOwner($token);
        return $owner->toArray();
    }
}
