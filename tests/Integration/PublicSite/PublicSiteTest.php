<?php

declare(strict_types=1);

namespace EduCloud\Tests\Integration\PublicSite;

use EduCloud\Tests\Support\ApiActors;
use EduCloud\Tests\Support\AuthHelpers;
use EduCloud\Tests\Support\LabHelpers;
use EduCloud\Tests\TestCase;

/** Public, indexable pages and SEO (M11a, plan §20). */
final class PublicSiteTest extends TestCase
{
    use AuthHelpers;
    use ApiActors;
    use LabHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetDatabase();
        $this->importLabs();
    }

    public function testLabCatalogAndLabPagesAreIndexableAndConfidential(): void
    {
        $catalog = $this->request('GET', '/labs');
        self::assertSame(200, $catalog->status);
        self::assertArrayNotHasKey('X-Robots-Tag', $catalog->headers);
        self::assertStringContainsString('<meta name="robots" content="index, follow">', $catalog->body);
        self::assertStringContainsString('/labs/construye-un-data-lake', $catalog->body);

        $lab = $this->request('GET', '/labs/fundamentos-de-sql');
        self::assertSame(200, $lab->status);
        self::assertStringContainsString('<link rel="canonical" href="http://localhost/educloudlab/labs/fundamentos-de-sql">', $lab->body);
        self::assertSame(1, preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $lab->body, $m));
        $jsonLd = json_decode($m[1], true);
        self::assertSame(
            ['Course', 'LAB-003', true, 'PT60M'],
            [$jsonLd['@type'], $jsonLd['courseCode'], $jsonLd['isAccessibleForFree'], $jsonLd['timeRequired']]
        );
        foreach (['expected_sql', "category = 'Libros'", 'Necesitas dos condiciones', 'bronze.order_items i'] as $secret) {
            self::assertStringNotContainsString($secret, $lab->body, "public lab page leaks $secret");
        }
        foreach (['/labs/no-existe', '/labs/../etc', '/labs/A%20B'] as $path) {
            $r = $this->request('GET', $path);
            self::assertSame(404, $r->status, $path);
            self::assertSame('noindex, nofollow', $r->headers['X-Robots-Tag'] ?? null);
        }
    }

    public function testOnlyPublishedPublicCoursesArePublic(): void
    {
        $org = $this->createOrgTenant('Universidad');
        $this->createVerifiedUser('prof@test.example');
        $this->addMember($org['id'], 'prof@test.example', 'instructor');
        $csrf = $this->sessionIn('prof@test.example', $org['public_id']);
        $send = function (string $m, string $p, ?array $b = null) use (&$csrf) {
            return $this->request($m, $p, $b, ['X-CSRF-Token' => $csrf]);
        };
        $course = $send('POST', '/api/v1/courses', ['code' => 'PUB-1', 'title' => 'Datos abiertos', 'description' => 'Curso público'])
            ->decoded()['data'];
        $send('POST', '/api/v1/courses/' . $course['id'] . '/labs', ['lab_code' => 'LAB-004']);
        $slug = (string) $this->app()->db()->scalar('SELECT slug FROM courses');

        self::assertSame(404, $this->request('GET', "/courses/$slug")->status, 'draft');
        $send('PATCH', '/api/v1/courses/' . $course['id'], ['status' => 'published']);
        self::assertSame(404, $this->request('GET', "/courses/$slug")->status, 'private');
        $public = $send('PATCH', '/api/v1/courses/' . $course['id'], ['visibility' => 'public']);
        self::assertSame(200, $public->status, $public->body);
        self::assertStringEndsWith("/courses/$slug", (string) $public->decoded()['data']['public_url']);

        $this->cookieJar = [];
        $page = $this->request('GET', "/courses/$slug");
        self::assertSame(200, $page->status);
        self::assertStringContainsString('Datos abiertos', $page->body);
        self::assertStringContainsString('/labs/construye-un-data-lake', $page->body);
        self::assertStringNotContainsString('prof@test.example', $page->body);
        self::assertStringContainsString("/courses/$slug", $this->request('GET', '/courses')->body);

        $sitemap = $this->request('GET', '/sitemap.xml');
        self::assertSame('application/xml; charset=UTF-8', $sitemap->headers['Content-Type']);
        $xml = simplexml_load_string($sitemap->body);
        self::assertNotFalse($xml);
        $all = [];
        foreach ($xml->url as $url) {
            $all[] = (string) $url->loc;
        }
        self::assertContains('http://localhost/educloudlab/labs/bronze-silver-gold', $all);
        self::assertContains("http://localhost/educloudlab/courses/$slug", $all);
        self::assertCount(4 + 8, $all, 'home, /labs, 8 labs, /courses, 1 course');

        $csrf = $this->sessionIn('prof@test.example', $org['public_id']);
        self::assertSame(200, $send('PATCH', '/api/v1/courses/' . $course['id'], ['status' => 'archived'])->status);
        $this->cookieJar = [];
        self::assertSame(404, $this->request('GET', "/courses/$slug")->status, 'archived');
        self::assertStringNotContainsString("/courses/$slug", $this->request('GET', '/sitemap.xml')->body);
    }

    public function testRobotsAndAppPagesStayOutOfSearchEngines(): void
    {
        $robots = $this->request('GET', '/robots.txt')->body;
        self::assertStringContainsString('Disallow: ' . url('/app/'), $robots);
        self::assertStringContainsString('Sitemap: http://localhost/educloudlab/sitemap.xml', $robots);
        $this->createVerifiedUser('ana@test.example');
        $this->login('ana@test.example');
        self::assertSame('noindex, nofollow', $this->request('GET', '/app/labs')->headers['X-Robots-Tag'] ?? null);
    }
}
