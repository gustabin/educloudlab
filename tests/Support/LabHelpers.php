<?php

declare(strict_types=1);

namespace EduCloud\Tests\Support;

use EduCloud\Modules\Labs\LabImporter;

/** Lab Engine helpers (requires ApiActors, AuthHelpers, TestCase). */
trait LabHelpers
{
    protected function importLabs(): void
    {
        foreach ((new LabImporter($this->app()))->import() as $row) {
            self::assertSame('imported', $row['status'], $row['lab'] . ': ' . implode('; ', $row['errors']));
        }
    }

    /** @return array<string, mixed> the attempt */
    protected function startLab(string $token, string $code, int $expectedStatus = 201): array
    {
        $r = $this->as($token, 'POST', '/api/v1/lab-attempts', ['lab_code' => $code]);
        self::assertSame($expectedStatus, $r->status, $r->body);
        return $r->decoded()['data'];
    }

    /** @return array<string, mixed> */
    protected function attempt(string $token, string $id): array
    {
        $r = $this->as($token, 'GET', "/api/v1/lab-attempts/$id");
        self::assertSame(200, $r->status, $r->body);
        return $r->decoded()['data'];
    }

    /** Submits, runs the queue and returns the graded attempt. */
    protected function submitAndGrade(string $token, string $id): array
    {
        $r = $this->as($token, 'POST', "/api/v1/lab-attempts/$id/submit", []);
        self::assertSame(202, $r->status, $r->body);
        self::assertSame('validating', $r->decoded()['data']['status']);
        $this->runJobs();
        $attempt = $this->attempt($token, $id);
        self::assertNotSame('validating', $attempt['status']);
        self::assertNull($attempt['error'], json_encode($attempt['error']) ?: '');
        return $attempt;
    }

    /** @return array<string, mixed> resource id by name in a workspace */
    protected function resourceByName(string $token, string $workspaceId, string $name): array
    {
        foreach ($this->as($token, 'GET', "/api/v1/workspaces/$workspaceId/resources")->decoded()['data'] as $resource) {
            if ($resource['name'] === $name) {
                return $resource;
            }
        }
        self::fail("No resource $name");
    }

    /** @return array<string, mixed> dataset by layer + name in a workspace */
    protected function datasetByName(string $token, string $workspaceId, string $layer, string $name): array
    {
        foreach ($this->as($token, 'GET', "/api/v1/workspaces/$workspaceId/datasets")->decoded()['data'] as $dataset) {
            if ($dataset['layer'] === $layer && $dataset['name'] === $name) {
                return $dataset;
            }
        }
        self::fail("No dataset $layer.$name");
    }
}
