<?php

declare(strict_types=1);

use EduCloud\Core\App;
use EduCloud\Core\Request;
use EduCloud\Core\Response;
use EduCloud\Core\Router;
use EduCloud\Modules\PublicSite\PublicSiteController;

return static function (Router $router, App $app): void {
    $c = static fn (string $m) => static fn (Request $r, App $app) => (new PublicSiteController($app))->$m($r);
    $public = static fn (string $name): array => ['public' => true, 'name' => $name];
    $router->get('/', $c('home'), $public('public.home'));
    $router->get('/labs', $c('labs'), $public('public.labs'));
    $router->get('/labs/{slug}', $c('lab'), $public('public.lab'));
    $router->get('/courses', $c('courses'), $public('public.courses'));
    $router->get('/courses/{slug}', $c('course'), $public('public.course'));
    $router->get('/sitemap.xml', $c('sitemap'), $public('public.sitemap'));

    $router->get(
        '/robots.txt',
        static fn (Request $r, App $app): Response => new Response(
            200,
            "User-agent: *\nDisallow: " . url('/api/') . "\nDisallow: " . url('/app/') . "\n\n"
                . 'Sitemap: ' . $app->config->get('app.url') . "/sitemap.xml\n",
            ['Content-Type' => 'text/plain; charset=UTF-8']
        ),
        ['public' => true, 'name' => 'public.robots']
    );
};
