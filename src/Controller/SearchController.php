<?php
declare(strict_types=1);
namespace App\Controller;

use App\Model\PilotRepository;
use App\Service\ShipTreeService;
use Twig\Environment;

/** Site search: public pilots and ships. Private pilots never appear. */
final class SearchController
{
    private const MIN_LENGTH = 2;
    private const MAX_LENGTH = 100;

    public function __construct(
        private readonly Environment     $twig,
        private readonly PilotRepository $pilots,
        private readonly ShipTreeService $shipTree
    ) {}

    /** Full results: /search?q= (works without JS) */
    public function page(): void
    {
        $query = $this->query();
        $ready = mb_strlen($query) >= self::MIN_LENGTH;

        echo $this->twig->render('pages/search.twig', [
            'query'  => $query,
            'ready'  => $ready,
            'pilots' => $ready ? $this->pilots->searchPublic($query, 48) : [],
            'ships'  => $ready ? $this->shipTree->searchShips($query, 48) : [],
        ]);
    }

    /** Suggestions for the nav search box: /search.json?q= */
    public function suggest(): void
    {
        $query  = $this->query();
        $result = ['pilots' => [], 'ships' => []];

        if (mb_strlen($query) >= self::MIN_LENGTH) {
            foreach ($this->pilots->searchPublic($query, 5) as $pilot) {
                $result['pilots'][] = [
                    'name'   => $pilot['name'],
                    'detail' => $pilot['corporation_name'] ?? '',
                    'url'    => '/pilot/' . $pilot['id'],
                    'image'  => "https://images.evetech.net/characters/{$pilot['id']}/portrait?size=32",
                ];
            }
            foreach ($this->shipTree->searchShips($query, 6) as $ship) {
                $result['ships'][] = [
                    'name'   => $ship['name'],
                    'detail' => trim($ship['group_name'] . ' · ' . ($ship['faction_name'] ?? ''), ' ·'),
                    'url'    => '/ship/' . $ship['type_id'],
                    'image'  => "https://images.evetech.net/types/{$ship['type_id']}/icon?size=32",
                ];
            }
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function query(): string
    {
        $query = trim((string) ($_GET['q'] ?? ''));
        return mb_substr($query, 0, self::MAX_LENGTH);
    }
}
