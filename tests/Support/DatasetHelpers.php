<?php

declare(strict_types=1);

namespace EduCloud\Tests\Support;

use EduCloud\Core\Response;
use EduCloud\Modules\Jobs\Dispatcher;

/** Upload + job helpers for dataset tests (requires ApiActors, AuthHelpers, TestCase). */
trait DatasetHelpers
{
    /** Sample CSV (PHP 8.1: traits cannot declare constants). */
    protected static function customersCsv(): string
    {
        return "Customer ID,Full Name,E-mail,Signup Date,amount\n"
            . "1,Ana García,ana@x.com,2026-01-05,10.50\n"
            . "2,Luis,,2026-02-10,7\n"
            . "3,=SUM(A1:A2),evil@x.com,2026-03-01,1\n";
    }

    protected function uploadAs(string $token, string $workspaceId, string $name, string $content, string $clientName = 'customers.csv'): Response
    {
        $jar = $this->cookieJar;
        $this->cookieJar = [];
        try {
            return $this->upload(
                "/api/v1/workspaces/$workspaceId/datasets",
                ['name' => $name],
                ['file' => [$this->fixtureFile($content), $clientName]],
                ['Authorization' => 'Bearer ' . $token]
            );
        } finally {
            $this->cookieJar = $jar;
        }
    }

    protected function runJobs(): int
    {
        return (new Dispatcher($this->app()))->drain();
    }

    /** @return array<string, mixed> */
    protected function dataset(string $token, string $id): array
    {
        $r = $this->as($token, 'GET', "/api/v1/datasets/$id");
        self::assertSame(200, $r->status, $r->body);
        return $r->decoded()['data'];
    }

    protected function requireRunner(): void
    {
        if (!is_file((string) $this->app()->config->get('execution.python'))) {
            self::markTestSkipped('Python runner venv not installed (worker/.venv)');
        }
    }
}
