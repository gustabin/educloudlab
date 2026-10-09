<?php

declare(strict_types=1);

namespace EduCloud\Modules\PublicSite;

use EduCloud\Core\App;
use EduCloud\Core\Exceptions\NotFoundException;
use EduCloud\Core\Format;
use EduCloud\Core\Request;
use EduCloud\Core\Response;

/**
 * Public, indexable pages (SEO, plan §20): lab catalog and lab pages, public courses, sitemap.xml.
 * Lab pages show the description, objectives and task titles only - never instructions details, hints or checks.
 */
final class PublicSiteController
{
    private PublicRepository $repo;

    public function __construct(private readonly App $app)
    {
        $this->repo = new PublicRepository($app->db());
    }

    /** Public home: what the platform teaches, with numbers and the learning path taken from the published catalog. */
    public function home(Request $request): Response
    {
        $labs = array_map([self::class, 'presentLab'], $this->repo->labs());
        $url = $this->absolute('/');
        return Response::html($this->app->view()->render('PublicSite::home', [
            'labs' => $labs,
            'stats' => [
                'labs' => count($labs),
                'exercises' => array_sum(array_map(static fn (array $l): int => count($l['tasks']), $labs)),
                'hours' => (int) round(array_sum(array_column($labs, 'estimated_minutes')) / 60),
            ],
            'pageTitle' => t('home.title'),
            'metaDescription' => t('home.meta_description'),
            'canonical' => $url,
            'indexable' => true,
            'jsonLd' => [
                '@context' => 'https://schema.org',
                '@type' => 'WebSite',
                'name' => 'EduCloud Lab',
                'url' => $url,
                'description' => t('home.meta_description'),
                'inLanguage' => 'es',
                'license' => 'https://www.apache.org/licenses/LICENSE-2.0',
            ],
        ]));
    }

    public function labs(Request $request): Response
    {
        $labs = array_map([self::class, 'presentLab'], $this->repo->labs());
        return $this->page('PublicSite::labs', t('public.labs.title'), t('public.labs.meta'), '/labs', ['labs' => $labs]);
    }

    public function lab(Request $request): Response
    {
        $row = $this->repo->lab(self::slug($request));
        if ($row === null) {
            throw new NotFoundException('El laboratorio no existe.');
        }
        $lab = self::presentLab($row);
        $canonical = $this->absolute('/labs/' . $lab['slug']);
        // Truthful structured data (schema.org Course): free, online, self-paced, Spanish.
        $jsonLd = [
            '@context' => 'https://schema.org',
            '@type' => 'Course',
            'name' => $lab['title'],
            'description' => $lab['summary'],
            'courseCode' => $lab['code'],
            'inLanguage' => 'es',
            'educationalLevel' => t('labs.difficulty.' . $lab['difficulty']),
            'timeRequired' => 'PT' . $lab['estimated_minutes'] . 'M',
            'isAccessibleForFree' => true,
            'teaches' => $lab['objectives'],
            'url' => $canonical,
            'provider' => ['@type' => 'Organization', 'name' => 'EduCloud Lab', 'url' => $this->absolute('/')],
            'hasCourseInstance' => [
                '@type' => 'CourseInstance',
                'courseMode' => 'online',
                'courseWorkload' => 'PT' . $lab['estimated_minutes'] . 'M',
            ],
        ];
        return $this->page('PublicSite::lab', $lab['title'] . ' · ' . t('public.labs.title'), $lab['summary'], '/labs/' . $lab['slug'], [
            'lab' => $lab,
            'jsonLd' => $jsonLd,
        ]);
    }

    public function courses(Request $request): Response
    {
        $courses = array_map(static fn (array $c): array => [
            'slug' => (string) $c['slug'],
            'code' => (string) $c['code'],
            'title' => (string) $c['title'],
            'description' => $c['description'] === null ? null : (string) $c['description'],
            'organization' => (string) $c['organization'],
            'lab_count' => (int) $c['lab_count'],
        ], $this->repo->courses());
        return $this->page('PublicSite::courses', t('public.courses.title'), t('public.courses.meta'), '/courses', ['courses' => $courses]);
    }

    public function course(Request $request): Response
    {
        $row = $this->repo->course(self::slug($request));
        if ($row === null) {
            throw new NotFoundException('El curso no existe.');
        }
        $labs = $this->repo->courseLabs((int) $row['tenant_id'], (int) $row['id']);
        $description = $row['description'] === null
            ? t('public.course.meta', ['org' => (string) $row['organization']])
            : (string) $row['description'];
        return $this->page('PublicSite::course', (string) $row['title'], mb_substr($description, 0, 160), '/courses/' . $row['slug'], [
            'course' => [
                'code' => (string) $row['code'],
                'title' => (string) $row['title'],
                'description' => $row['description'] === null ? null : (string) $row['description'],
                'organization' => (string) $row['organization'],
            ],
            'labs' => $labs,
        ]);
    }

    public function sitemap(Request $request): Response
    {
        $urls = [['loc' => $this->absolute('/'), 'lastmod' => null], ['loc' => $this->absolute('/labs'), 'lastmod' => null]];
        foreach ($this->repo->labs() as $lab) {
            $urls[] = ['loc' => $this->absolute('/labs/' . $lab['slug']), 'lastmod' => self::day($lab['updated_at'])];
        }
        $courses = $this->repo->courses();
        if ($courses !== []) {
            $urls[] = ['loc' => $this->absolute('/courses'), 'lastmod' => null];
        }
        foreach ($courses as $course) {
            $urls[] = ['loc' => $this->absolute('/courses/' . $course['slug']), 'lastmod' => self::day($course['updated_at'])];
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as $u) {
            $xml .= '  <url><loc>' . htmlspecialchars($u['loc'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc>'
                . ($u['lastmod'] !== null ? '<lastmod>' . $u['lastmod'] . '</lastmod>' : '') . "</url>\n";
        }
        $xml .= "</urlset>\n";
        return new Response(200, $xml, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function presentLab(array $row): array
    {
        $definition = Format::jsonColumn($row['definition']);
        return [
            'code' => (string) $row['code'],
            'slug' => (string) $row['slug'],
            'title' => (string) $row['title'],
            'summary' => (string) $row['summary'],
            'difficulty' => (string) $row['difficulty'],
            'estimated_minutes' => (int) $row['estimated_minutes'],
            'max_score' => (float) $row['max_score'],
            'objectives' => array_values(array_map('strval', $definition['objectives'] ?? [])),
            'prerequisites' => array_values(array_map('strval', $definition['prerequisites'] ?? [])),
            'tasks' => array_map(
                static fn (array $t): array => ['title' => (string) $t['title'], 'points' => (int) $t['points']],
                $definition['tasks'] ?? []
            ),
        ];
    }

    private static function day(mixed $datetime): string
    {
        return substr((string) Format::isoUtc((string) $datetime), 0, 10);
    }

    private static function slug(Request $request): string
    {
        $slug = $request->param('slug');
        if (preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/D', $slug) !== 1 || strlen($slug) > 120) {
            throw new NotFoundException();
        }
        return $slug;
    }

    private function absolute(string $path): string
    {
        return rtrim((string) $this->app->config->get('app.url'), '/') . ($path === '/' ? '/' : $path);
    }

    /** @param array<string, mixed> $data */
    private function page(string $template, string $title, string $description, string $path, array $data): Response
    {
        return Response::html($this->app->view()->render($template, $data + [
            'pageTitle' => $title . ' · EduCloud Lab',
            'metaDescription' => mb_substr($description, 0, 160),
            'canonical' => $this->absolute($path),
            'indexable' => true,
        ]));
    }
}
