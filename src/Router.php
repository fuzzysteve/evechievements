<?php
declare(strict_types=1);
namespace App;

use App\Controller\{AuthController, BrowseController, HomeController, ProfileController};
use App\Model\PilotRepository;
use App\Service\{AuthService, EsiService, DataFetchService, TrophyService};
use App\Config\TwigFactory;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Twig\Environment;

final class Router
{
    private Environment     $twig;
    private Logger          $logger;
    private PilotRepository $pilots;
    private TrophyService   $trophies;

    public function __construct()
    {
        $this->twig     = TwigFactory::create();
        $this->logger   = $this->buildLogger();
        $this->pilots   = new PilotRepository();
        $this->trophies = new TrophyService($this->logger);
    }

    public function dispatch(string $method, string $path): void
    {
        $path = strtok($path, '?');

        match(true) {
            $method === 'GET'  && ($path === '/' || $path === '/index2.php')
                => (new HomeController($this->twig, $this->trophies, $this->pilots))->index(),

            $method === 'GET'  && $path === '/auth/login'
                => $this->authCtrl()->login(),
            $method === 'GET'  && $path === '/auth/callback'
                => $this->authCtrl()->callback(),
            $method === 'GET'  && $path === '/auth/logout'
                => $this->authCtrl()->logout(),

            $method === 'GET'  && str_starts_with($path, '/auth/fetch/')
                => $this->authCtrl()->fetchSection(substr($path, 12)),


            $method === 'GET'  && $path === '/browse'
                => (new BrowseController($this->twig, $this->pilots))->index(),

            $method === 'GET'  && $path === '/dashboard'
                => (new ProfileController($this->twig, $this->pilots, $this->trophies))->dashboard(),

            $method === 'GET'  && str_starts_with($path, '/pilot/')
                => (new ProfileController($this->twig, $this->pilots, $this->trophies))
			->show(urldecode(substr($path, 7))),
            $method === 'POST' && $path === '/dashboard/fetch-title'
                => (new ProfileController($this->twig, $this->pilots, $this->trophies))->fetchTitle(),

            $method === 'POST' && $path === '/dashboard/titles'
                => (new ProfileController($this->twig, $this->pilots, $this->trophies))->saveTitleSelection(),

            $method === 'POST' && $path === '/dashboard/display'
                => (new ProfileController($this->twig, $this->pilots, $this->trophies))->saveDisplaySelections(),

            $method === 'POST' && $path === '/dashboard/visibility'
	        => (new ProfileController($this->twig, $this->pilots, $this->trophies, $this->logger))->updateVisibility(),

            $method === 'GET' && str_starts_with($path, '/debug/mastery/')
                => $this->debugMastery((int) substr($path, 15)),



            default => $this->notFound($path),
        };
    }

    private function notFound(string $path): void
    {
        http_response_code(404);
        echo $this->twig->render('pages/404.twig', ['path' => $path]);
    }

    private function buildLogger(): Logger
    {
        $log = new Logger('evechievements');
        $log->pushHandler(new StreamHandler(
            ROOT . '/logs/app.log', Logger::DEBUG
        ));
        return $log;
    }
    private function authCtrl(): AuthController
    {
        return new AuthController(
            new AuthService(),
            new DataFetchService(new EsiService($this->logger), $this->logger),
            $this->pilots
        );
    }

    private function debugMastery(int $typeId): void
    {
    if (empty($_SESSION['pilot_id'])) { echo 'Not logged in'; return; }

    $db          = \App\Config\Database::connect();
    $skillLevels = $_SESSION['fetched']['skills']
        ? array_column($_SESSION['fetched']['skills']['skills'], 'active_skill_level', 'skill_id')
        : [];

    // What masteries are required for this ship?
    $stmt = $db->prepare(
        'SELECT "masteryLevel", "certID" FROM evesde."certMasteries" WHERE "typeID" = ? ORDER BY "masteryLevel"'
    );
    $stmt->execute([$typeId]);
    $masteryReqs = $stmt->fetchAll();

    // For each cert, what skills are required and what does the pilot have?
    echo "<pre>";
    echo "Ship typeID: {$typeId}\n\n";

    foreach ($masteryReqs as $req) {
        $certId       = $req['certID'];
        $masteryLevel = $req['masteryLevel'];

        $skillReqs = $db->prepare(
            'SELECT "certLevelInt", "skillID", "skillLevel" FROM evesde."certSkills" WHERE "certID" = ? ORDER BY "certLevelInt", "skillID"'
        );
        $skillReqs->execute([$certId]);

        echo "Mastery {$masteryLevel} → certID {$certId}:\n";
        foreach ($skillReqs->fetchAll() as $sr) {
            $have    = $skillLevels[$sr['skillID']] ?? 0;
            $need    = $sr['skillLevel'];
            $ok      = $have >= $need ? '✓' : '✗';
            echo "  {$ok} certLevel {$sr['certLevelInt']} skillID {$sr['skillID']} need:{$need} have:{$have}\n";
        }
        echo "\n";
    }
    echo "</pre>";
    }




}
