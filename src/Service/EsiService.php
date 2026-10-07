<?php
declare(strict_types=1);
namespace App\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class EsiService
{
    private Client $http;

    public const SCOPES = [
        'esi-skills.read_skills.v1',
        'esi-skills.read_skillqueue.v1',
        'esi-killmails.read_killmails.v1',
        'esi-wallet.read_character_wallet.v1',
        'esi-corporations.read_corporation_membership.v1',
        'esi-location.read_location.v1',
        'esi-location.read_ship_type.v1',
        'esi-characters.read_corporation_roles.v1',
        'esi-assets.read_assets.v1',
    ];

    public function __construct(private readonly LoggerInterface $logger)
    {
        $this->http = new Client([
            'base_uri' => ($_ENV['ESI_BASE_URL'] ?? 'https://esi.evetech.net') . '/',
            'timeout'  => 15.0,
            'headers'  => [
                'Accept'               => 'application/json',
                // CCP asks for contact details in the User-Agent; ESI_CONTACT adds e.g. an email
                'User-Agent'           => trim('EVEchievements/1.0 (+' . ($_ENV['APP_URL'] ?? 'https://evechievements.online')
                                            . (!empty($_ENV['ESI_CONTACT']) ? '; ' . $_ENV['ESI_CONTACT'] : '') . ')'),
                'X-Tenant'             => 'tranquility',
                'X-Compatibility-Date' => '2026-06-09',
            ],
        ]);
    }

    // Public
    public function getCharacter(int $id): array   { return $this->get("characters/{$id}/"); }
    public function getPortrait(int $id): array     { return $this->get("characters/{$id}/portrait/"); }
    public function getCorporation(int $id): array  { return $this->get("corporations/{$id}/"); }
    public function getAlliance(int $id): array      { return $this->get("alliances/{$id}/"); }
    public function getCorpHistory(int $id): array  { return $this->get("characters/{$id}/corporationhistory/"); }
    public function getKillmail(int $id, string $h): array { return $this->get("killmails/{$id}/{$h}/"); }
    public function postUniverseNames(array $ids): array   { return $this->post('universe/names/', $ids); }

    // Authenticated
    public function getSkills(int $id, string $t): array       { return $this->get("characters/{$id}/skills/", $t); }
    public function getLocation(int $id, string $t): array { return $this->get("characters/{$id}/location/", $t); }

    public function getWalletBalance(int $id, string $t): float
    {
        $options = [
            'headers' => ['Authorization' => "Bearer {$t}"],
        ];
        $response = $this->http->get("characters/{$id}/wallet/", $options);
        return (float) (string) $response->getBody();
    }

    /**
     * All types' average/adjusted prices, keyed by type_id. Public and identical for everyone,
     * so it's cached on disk for an hour (ESI itself refreshes it about hourly).
     */
    public function getMarketPrices(): array
    {
        $cache = ROOT . '/var/cache/esi-market-prices.json';
        if (is_file($cache) && filemtime($cache) > time() - 3600) {
            $prices = json_decode((string) file_get_contents($cache), true);
            if (is_array($prices)) {
                return $prices;
            }
        }

        $prices = array_column($this->get('markets/prices/'), null, 'type_id');
        @file_put_contents($cache, json_encode($prices), LOCK_EX); // best effort: a failed write only skips caching
        return $prices;
    }

    /** Every page of a character's assets, following ESI's X-Pages header. */
    public function getAssets(int $id, string $t): array
    {
        [$all, $pages] = $this->getWithPages("characters/{$id}/assets/", $t, ['page' => 1]);
        for ($page = 2; $page <= $pages; $page++) {
            [$results] = $this->getWithPages("characters/{$id}/assets/", $t, ['page' => $page]);
            $all = array_merge($all, $results);
        }
        return $all;
    }

    private function get(string $path, ?string $token = null, array $query = []): array
    {
        return $this->getWithPages($path, $token, $query)[0];
    }

    /** @return array [decoded body, X-Pages (1 when absent)] */
    private function getWithPages(string $path, ?string $token = null, array $query = []): array
    {
        $options = [];
        if (!empty($query)) {
            $options['query'] = $query;
        }
        if ($token !== null) {
            $options['headers']['Authorization'] = "Bearer {$token}";
        }
        try {
            $response = $this->http->get($path, $options);
            return [
                json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR),
                max(1, (int) ($response->getHeaderLine('X-Pages') ?: 1)),
            ];
        } catch (GuzzleException $e) {
            $this->logger->error('ESI GET failed', ['path' => $path, 'error' => $e->getMessage()]);
            throw new RuntimeException("ESI request failed: {$e->getMessage()}", 0, $e);
        }
    }

    private function post(string $path, array $body): array
    {
        try {
            $response = $this->http->post($path, [
                'json' => $body,
            ]);
            return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (GuzzleException $e) {
            $this->logger->error('ESI POST failed', ['path' => $path, 'error' => $e->getMessage()]);
            throw new RuntimeException("ESI request failed: {$e->getMessage()}", 0, $e);
        }
    }
}
