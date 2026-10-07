<?php
declare(strict_types=1);
namespace App;

use App\Controller\{AuthController, BrowseController, HomeController, ProfileController, SearchController, ShipController, SkillController};
use App\Model\PilotRepository;
use App\Service\{AuthService, Csrf, EsiService, DataFetchService, ShipInfoService, ShipTreeService, SkillService};
use App\Config\TwigFactory;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Twig\Environment;

final class Router
{
    private Environment     $twig;
    private Logger          $logger;
    private PilotRepository $pilots;

    public function __construct()
    {
        $this->twig     = TwigFactory::create();
        $this->logger   = $this->buildLogger();
        $this->pilots   = new PilotRepository();
    }

    public function dispatch(string $method, string $path): void
    {
        $path = strtok($path, '?');

        // Every POST must carry the session's CSRF token ({{ csrf_field() }} in the form)
        if ($method === 'POST' && !Csrf::isValid(Csrf::submitted())) {
            http_response_code(403);
            echo $this->twig->render('pages/403.twig', ['reason' => 'csrf']);
            return;
        }

        match(true) {
            $method === 'GET'  && ($path === '/' || $path === '/index2.php')
                => (new HomeController($this->twig))->index(),

            $method === 'GET'  && $path === '/auth/login'
                => $this->authCtrl()->login(),
            $method === 'GET'  && $path === '/auth/callback'
                => $this->authCtrl()->callback(),
            $method === 'POST' && $path === '/auth/logout'
                => $this->authCtrl()->logout(),

            $method === 'GET'  && str_starts_with($path, '/auth/fetch/')
                => $this->authCtrl()->fetchSection(substr($path, 12)),


            $method === 'GET'  && $path === '/search'
                => $this->searchCtrl()->page(),
            $method === 'GET'  && $path === '/search.json'
                => $this->searchCtrl()->suggest(),

            $method === 'GET'  && $path === '/browse'
                => (new BrowseController($this->twig, $this->pilots))->index(),

            $method === 'GET'  && $path === '/dashboard'
                => $this->profileCtrl()->dashboard(),

            $method === 'GET'  && preg_match('#^/skill/(\d+)\.json$#', $path, $m) === 1
                => (new SkillController(new SkillService()))->json((int) $m[1]),

            $method === 'GET'  && $path === '/ships'
                => (new ShipController($this->twig, new ShipTreeService(), new ShipInfoService(), new SkillService()))->trees(),

            $method === 'GET'  && preg_match('#^/ship/(\d+)$#', $path, $m) === 1
                => (new ShipController($this->twig, new ShipTreeService(), new ShipInfoService(), new SkillService()))->show((int) $m[1]),

            // Old pilot-specific mastery pages now live at the generic /ship/{typeID}
            $method === 'GET'  && preg_match('#^/pilot/\d+/ships/(\d+)$#', $path, $m) === 1
                => $this->redirect('/ship/' . $m[1] . (isset($_GET['level']) ? '?level=' . (int) $_GET['level'] : ''), 301),

            $method === 'GET'  && preg_match('#^/pilot/(\d+)/ships$#', $path, $m) === 1
                => $this->profileCtrl()->shipTree((int) $m[1]),

            $method === 'GET'  && preg_match('#^/pilot/(\d+)$#', $path, $m) === 1
                => $this->profileCtrl()->show((int) $m[1]),
            $method === 'POST' && $path === '/dashboard/fetch-title'
                => $this->profileCtrl()->fetchTitle(),

            $method === 'POST' && $path === '/dashboard/titles'
                => $this->profileCtrl()->saveTitleSelection(),

            $method === 'POST' && $path === '/dashboard/display'
                => $this->profileCtrl()->saveDisplaySelections(),

            $method === 'POST' && $path === '/dashboard/visibility'
	        => $this->profileCtrl()->updateVisibility(),




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
    private function redirect(string $url, int $code): void
    {
        header('Location: ' . $url, true, $code);
    }

    private function searchCtrl(): SearchController
    {
        return new SearchController($this->twig, $this->pilots, new ShipTreeService());
    }

    private function profileCtrl(): ProfileController
    {
        return new ProfileController($this->twig, $this->pilots, new ShipTreeService());
    }

    private function authCtrl(): AuthController
    {
        return new AuthController(
            new AuthService(),
            new DataFetchService(new EsiService($this->logger), $this->logger),
            $this->pilots
        );
    }
}
