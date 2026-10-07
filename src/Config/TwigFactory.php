<?php
declare(strict_types=1);
namespace App\Config;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class TwigFactory
{
    public static function create(): Environment
    {
        $loader = new FilesystemLoader(ROOT . '/templates');
        $twig   = new Environment($loader, [
            // Compiled-template cache in production web requests only: CLI runs (as root) would
            // leave cache files the web server user can't overwrite.
            'cache'       => ($_ENV['APP_ENV'] ?? '') === 'production' && PHP_SAPI !== 'cli'
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

        // Hidden CSRF token input for POST forms (checked by the Router)
        $twig->addFunction(new TwigFunction('csrf_field', [\App\Service\Csrf::class, 'field'], ['is_safe' => ['html']]));

        // Cache-busting URL for files under htdocs/ — assets are served with a long max-age
        $twig->addFunction(new TwigFunction('asset', function (string $path): string {
            $mtime = @filemtime(ROOT . '/htdocs' . $path);
            return $mtime ? "{$path}?v={$mtime}" : $path;
        }));

        // Round to 3 significant figures and format EVE-style
        $twig->addFilter(new TwigFilter('sig_figs', function (float $value, int $figs = 3): string {
            if ($value == 0) return '0';
            $magnitude = floor(log10(abs($value)));
            $factor    = pow(10, $figs - 1 - $magnitude);
            $rounded   = round($value * $factor) / $factor;
            return match(true) {
                $rounded >= 1_000_000_000 => number_format($rounded / 1_000_000_000, max(0, $figs - 1 - (int) floor(log10($rounded / 1_000_000_000)))) . 'B',
                $rounded >= 1_000_000     => number_format($rounded / 1_000_000,     max(0, $figs - 1 - (int) floor(log10($rounded / 1_000_000))))     . 'M',
                $rounded >= 1_000         => number_format($rounded / 1_000,         max(0, $figs - 1 - (int) floor(log10($rounded / 1_000))))         . 'K',
                // Below 1,000 keep the significant figures as decimals (4.53, 0.00450), not a whole number
                default                   => number_format($rounded, max(0, $figs - 1 - (int) floor(log10(abs($rounded))))),
            };
        }));

        return $twig;
    }
}
