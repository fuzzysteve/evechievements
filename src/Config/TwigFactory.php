<?php
declare(strict_types=1);
namespace App\Config;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;

final class TwigFactory
{
    public static function create(): Environment
    {
        $loader = new FilesystemLoader(ROOT . '/templates');
        $twig   = new Environment($loader, [
            'cache'       => $_ENV['APP_ENV'] === 'production'
                                ? ROOT . '/var/cache/twig' : false,
            'debug'       => ($_ENV['APP_DEBUG'] ?? 'false') === 'true',
            'auto_reload' => true,
        ]);

        if (($_ENV['APP_DEBUG'] ?? 'false') === 'true') {
            $twig->addExtension(new \Twig\Extension\DebugExtension());
        }

        $twig->addGlobal('app_url',      $_ENV['APP_URL'] ?? '');
        $twig->addGlobal('current_year', date('Y'));
        $twig->addGlobal('session',      $_SESSION ?? []);

        $twig->addFilter(new TwigFilter('eve_number', function (float|int $v): string {
            return match(true) {
                $v >= 1_000_000_000 => round($v / 1_000_000_000, 1) . 'B',
                $v >= 1_000_000     => round($v / 1_000_000, 1)     . 'M',
                $v >= 1_000         => round($v / 1_000, 1)         . 'K',
                default             => (string) $v,
            };
        }));

        $twig->addFilter(new TwigFilter('rarity_class', fn(string $r): string => "trophy--{$r}"));

        return $twig;
    }
}
