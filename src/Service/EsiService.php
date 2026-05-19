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
            'base_uri' => ($_ENV['ESI_BASE_URL'] ?? 'https://esi.evetech.net/latest') . '/',
            'timeout'  => 15.0,
            'headers'  => [
                'Accept'     => 'application/json',
                'User-Agent' => 'EVEchievements/1.0',
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
            'query'   => ['datasource' => 'tranquility'],
            'headers' => ['Authorization' => "Bearer {$t}"],
        ];
        $response = $this->http->get("characters/{$id}/wallet/", $options);
        return (float) (string) $response->getBody();
    }

    public function getMarketPrices(): array
    {
        // Returns all type adjusted/average prices — no auth needed
        // Result is keyed by type_id for easy lookup
        $prices = $this->get('markets/prices/');
        return array_column($prices, null, 'type_id');
    }

    public function getAssets(int $id, string $t): array
    {
        $all  = [];
        $page = 1;

        do {
            $results = $this->get("characters/{$id}/assets/", $t, ['page' => $page]);
            $all     = array_merge($all, $results);
            $page++;
        } while (count($results) === 1000);

        return $all;
    }

    private function get(string $path, ?string $token = null, array $query = []): array
    {
        $options = ['query' => array_merge(['datasource' => 'tranquility'], $query)];
        if ($token !== null) {
            $options['headers']['Authorization'] = "Bearer {$token}";
        }
        try {
            $response = $this->http->get($path, $options);
            return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (GuzzleException $e) {
            $this->logger->error('ESI GET failed', ['path' => $path, 'error' => $e->getMessage()]);
            throw new RuntimeException("ESI request failed: {$e->getMessage()}", 0, $e);
        }
    }

    private function post(string $path, array $body): array
    {
        try {
            $response = $this->http->post($path, [
                'query' => ['datasource' => 'tranquility'],
                'json'  => $body,
            ]);
            return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (GuzzleException $e) {
            $this->logger->error('ESI POST failed', ['path' => $path, 'error' => $e->getMessage()]);
            throw new RuntimeException("ESI request failed: {$e->getMessage()}", 0, $e);
        }
    }
}
