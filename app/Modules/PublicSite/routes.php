<?php

declare(strict_types=1);

use EduCloud\Core\App;
use EduCloud\Core\Request;
use EduCloud\Core\Response;
use EduCloud\Core\Router;

return static function (Router $router, App $app): void {
    $router->get(
        '/',
        static fn (Request $r, App $app): Response => Response::html($app->view()->render('PublicSite::home', [
            'pageTitle' => t('home.title'),
            'metaDescription' => t('home.meta_description'),
            'canonical' => $app->config->get('app.url') . '/',
            'indexable' => true,
        ])),
        ['public' => true, 'name' => 'public.home']
    );

    $router->get(
        '/robots.txt',
        static fn (Request $r, App $app): Response => new Response(
            200,
            "User-agent: *\nDisallow: " . url('/api/') . "\n\nSitemap: " . $app->config->get('app.url') . "/sitemap.xml\n",
            ['Content-Type' => 'text/plain; charset=UTF-8']
        ),
        ['public' => true, 'name' => 'public.robots']
    );
};
