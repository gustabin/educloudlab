<?php

declare(strict_types=1);

namespace EduCloud\Tests\Integration\Admin;

use EduCloud\Core\App;
use EduCloud\Modules\Observability\Heartbeat;
use EduCloud\Tests\Support\ApiActors;
use EduCloud\Tests\Support\AuthHelpers;
use EduCloud\Tests\TestCase;

/** GET /metrics (M12): Prometheus text, protected by its own token, disabled by default. */
final class PrometheusMetricsTest extends TestCase
{
    use AuthHelpers;
    use ApiActors;

    private const TOKEN = 'test-metrics-token-0123456789abcdef0123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetDatabase();
    }

    private function withToken(string $token): void
    {
        $this->app = App::create($this->app()->config->with('observability.metrics_token', $token), testDatabase: true);
    }

    /** @param array<string, string> $headers */
    private function scrape(array $headers = []): \EduCloud\Core\Response
    {
        return $this->request('GET', '/metrics', null, $headers);
    }

    public function testDisabledByDefaultAndWithShortTokens(): void
    {
        $this->withToken('');
        self::assertSame(404, $this->scrape()->status);
        $this->withToken('too-short');
        self::assertSame(404, $this->scrape(['Authorization' => 'Bearer too-short'])->status, 'weak tokens never enable it');
    }

    public function testRequiresTheBearerToken(): void
    {
        $this->withToken(self::TOKEN);
        self::assertSame(401, $this->scrape()->status);
        self::assertSame(401, $this->scrape(['Authorization' => 'Bearer ' . self::TOKEN . 'x'])->status);
        $user = $this->actor('ana@test.example');
        self::assertSame(401, $this->as($user, 'GET', '/metrics')->status, 'a user access token is not the metrics token');
    }

    public function testExposesGaugesWithSafeLabelsOnly(): void
    {
        $this->withToken(self::TOKEN);
        $ana = $this->actor('ana@test.example');
        $ws = (string) $this->createWorkspace($ana)['id'];
        $this->as($ana, 'GET', "/api/v1/workspaces/$ws");
        (new Heartbeat($this->app(), 'dispatcher'))->tick(['processed' => 1], true);

        $r = $this->scrape(['Authorization' => 'Bearer ' . self::TOKEN]);
        self::assertSame(200, $r->status);
        self::assertStringStartsWith('text/plain; version=0.0.4', $r->headers['Content-Type'] ?? '');
        self::assertStringContainsString('educloud_info{version="', $r->body);
        self::assertStringContainsString('educloud_component_up{component="dispatcher"} 1', $r->body);
        self::assertStringContainsString('educloud_component_up{component="database"} 1', $r->body);
        self::assertMatchesRegularExpression('/^educloud_http_requests_5m\{route="workspaces\.show",method="GET"\} 1$/m', $r->body);
        self::assertStringContainsString('educloud_jobs{status="queued"}', $r->body);
        self::assertStringNotContainsString($ws, $r->body, 'no ids in labels');
        self::assertStringNotContainsString('ana@test.example', $r->body);
        self::assertStringNotContainsString($this->storagePath, $r->body);
        $sample = '/^educloud_[a-z0-9_]+(\{[a-z_]+="[^"\n]*"(,[a-z_]+="[^"\n]*")*\})? -?[0-9.]+$/';
        foreach (explode("\n", trim($r->body)) as $line) {
            self::assertMatchesRegularExpression(str_starts_with($line, '#') ? '/^# (HELP|TYPE) educloud_[a-z0-9_]+ .+$/' : $sample, $line);
        }
    }
}
