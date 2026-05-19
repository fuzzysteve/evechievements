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
            $method === 'POST' && $path === '/dashboard/visibility'
                => (new ProfileController($this->twig, $this->pilots, $this->trophies, $this->logger))->updateVisibility(),
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
}
