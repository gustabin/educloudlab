<?php

declare(strict_types=1);

namespace EduCloud\Tests\Integration\Datasets;

use EduCloud\Modules\Jobs\JobRepository;
use EduCloud\Modules\Jobs\RunnerProcess;
use EduCloud\Tests\Support\ApiActors;
use EduCloud\Tests\Support\AuthHelpers;
use EduCloud\Tests\Support\DatasetHelpers;
use EduCloud\Tests\TestCase;

final class JobQueueTest extends TestCase
{
    use AuthHelpers;
    use ApiActors;
    use DatasetHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetDatabase();
    }

    public function testClaimingIsFairAcrossUsers(): void
    {
        $ana = $this->actor('ana@test.example');
        $bob = $this->actor('bob@test.example');
        $wsAna = (string) $this->createWorkspace($ana)['id'];
        $wsBob = (string) $this->createWorkspace($bob)['id'];
        foreach (['a1', 'a2', 'a3'] as $name) {
            self::assertSame(202, $this->uploadAs($ana, $wsAna, $name, "x\n1\n")->status);
        }
        self::assertSame(202, $this->uploadAs($bob, $wsBob, 'b1', "x\n1\n")->status, 'queued after all of ana\'s jobs');

        $repo = new JobRepository($this->app()->db());
        $order = [];
        while (($job = $repo->claimNext('test')) !== null) {
            $order[] = (int) $job['user_id'];
            $repo->finish((int) $job['id'], 'succeeded', null);
        }
        $anaId = $this->userId('ana@test.example');
        $bobId = $this->userId('bob@test.example');
        self::assertSame([$anaId, $bobId, $anaId, $anaId], $order, 'bob does not wait behind ana\'s whole burst');
    }

    public function testActiveJobQuotaIsEnforced(): void
    {
        $ana = $this->actor('ana@test.example');
        $ws = (string) $this->createWorkspace($ana)['id'];
        foreach (['a1', 'a2', 'a3'] as $name) {
            self::assertSame(202, $this->uploadAs($ana, $ws, $name, "x\n1\n")->status);
        }
        $fourth = $this->uploadAs($ana, $ws, 'a4', "x\n1\n");
        self::assertSame(409, $fourth->status);
        self::assertSame('QUOTA_EXCEEDED', $fourth->decoded()['error']['code']);
        self::assertSame(3, (int) $this->app()->db()->scalar('SELECT COUNT(*) FROM jobs'));
    }

    public function testRunnerEnvironmentCarriesNoSecrets(): void
    {
        putenv('DB_PASS=super-secret');
        putenv('JWT_KEYS=k1:abc');
        putenv('SMTP_PASS=x');
        try {
            $env = RunnerProcess::environment();
        } finally {
            putenv('DB_PASS');
            putenv('JWT_KEYS');
            putenv('SMTP_PASS');
        }
        $allowed = ['PYTHONIOENCODING', 'PYTHONDONTWRITEBYTECODE', 'PATH', 'SYSTEMROOT', 'SystemRoot', 'TEMP', 'TMP', 'WINDIR'];
        self::assertSame([], array_diff(array_keys($env), $allowed));
        self::assertStringNotContainsString('super-secret', (string) json_encode($env));
    }
}
