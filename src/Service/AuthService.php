<?php
declare(strict_types=1);
namespace App\Service;

use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Token\AccessToken;
use RuntimeException;

final class AuthService
{
    // Scopes available for on-demand section fetches
    public const SECTION_SCOPES = [
        'skills' => 'esi-skills.read_skills.v1',
        'wallet' => 'esi-wallet.read_character_wallet.v1',
        'assets' => 'esi-assets.read_assets.v1',
    ];

    private GenericProvider $provider;

    public function __construct(array $scopes = [])
    {
        $this->provider = new GenericProvider([
            'clientId'                => $_ENV['EVE_CLIENT_ID'],
            'clientSecret'            => $_ENV['EVE_CLIENT_SECRET'],
            'redirectUri'             => $_ENV['EVE_CALLBACK_URL'],
            'urlAuthorize'            => 'https://login.eveonline.com/v2/oauth/authorize',
            'urlAccessToken'          => 'https://login.eveonline.com/v2/oauth/token',
            'urlResourceOwnerDetails' => 'https://login.eveonline.com/v2/oauth/token',
            'scopes'                  => implode(' ', $scopes),
            'scopeSeparator'          => ' ',
        ]);
    }

    /**
     * Login URL with no scopes — just identifies the character.
     */
    public function getLoginUrl(): string
    {
        $url = $this->provider->getAuthorizationUrl();
        $_SESSION['oauth2_state'] = $this->provider->getState();
        return $url;
    }

    /**
     * Section fetch URL — requests a single scope with a signed state
     * carrying the section name so the callback knows what to fetch.
     */
    public function getSectionUrl(string $section): string
    {
        if (!isset(self::SECTION_SCOPES[$section])) {
            throw new RuntimeException("Unknown section: {$section}");
        }

        $payload = json_encode([
            'section' => $section,
            'nonce'   => bin2hex(random_bytes(8)),
        ]);
        $state = base64_encode($payload) . '.' . hash_hmac('sha256', $payload, $_ENV['APP_SECRET']);

        $_SESSION['oauth2_state'] = $state;

        return $this->provider->getAuthorizationUrl([
            'scope' => self::SECTION_SCOPES[$section],
            'state' => $state,
        ]);
    }

    /**
     * Handle login callback — returns character id and name from JWT.
     */
    public function handleLoginCallback(string $code, string $returnedState): array
    {
        if (empty($_SESSION['oauth2_state']) || $returnedState !== $_SESSION['oauth2_state']) {
            throw new RuntimeException('OAuth2 state mismatch.');
        }
        unset($_SESSION['oauth2_state']);

        $token   = $this->provider->getAccessToken('authorization_code', ['code' => $code]);
        $claims  = $this->decodeJwt($token->getToken());

        $characterId = (int) explode(':', $claims['sub'])[2];
        $name        = $claims['name'];

        return [
            'id'   => $characterId,
            'name' => $name,
        ];
    }

    /**
     * Handle section callback — verifies state signature, verifies character
     * matches session, returns [section, access_token].
     */
    public function handleSectionCallback(string $code, string $returnedState): array
    {
        if (empty($_SESSION['oauth2_state']) || $returnedState !== $_SESSION['oauth2_state']) {
            throw new RuntimeException('OAuth2 state mismatch.');
        }
        unset($_SESSION['oauth2_state']);

        // Verify HMAC signature on state
        [$encodedPayload, $sig] = explode('.', $returnedState, 2);
        $payload = base64_decode($encodedPayload);

        $expected = hash_hmac('sha256', $payload, $_ENV['APP_SECRET']);
        if (!hash_equals($expected, $sig)) {
            throw new RuntimeException('State signature invalid.');
        }

        $data    = json_decode($payload, true);
        $section = $data['section'] ?? null;

        if (!isset(self::SECTION_SCOPES[$section])) {
            throw new RuntimeException("Unknown section in state: {$section}");
        }

        // Exchange code for token
        $token  = $this->provider->getAccessToken('authorization_code', ['code' => $code]);
        $claims = $this->decodeJwt($token->getToken());

        // Verify the character matches who is logged in
        $characterId = (int) explode(':', $claims['sub'])[2];
        if ($characterId !== (int) ($_SESSION['pilot_id'] ?? 0)) {
            throw new RuntimeException('Character mismatch — token is for a different pilot.');
        }

        return [
            'section'      => $section,
            'access_token' => $token->getToken(),
            'character_id' => $characterId,
        ];
    }

    /**
     * Decode a JWT payload without verifying signature.
     * Signature verification is handled by EVE SSO itself during token exchange.
     */
    public function decodeJwt(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new RuntimeException('Invalid JWT format.');
        }
        return json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
    }
}
