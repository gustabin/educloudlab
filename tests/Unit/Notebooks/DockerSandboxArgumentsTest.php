<?php

declare(strict_types=1);

namespace EduCloud\Tests\Unit\Notebooks;

use EduCloud\Modules\Notebooks\DockerSandbox;
use PHPUnit\Framework\TestCase;

/** ADR-010: the exact isolation flags of every notebook container (any change must be deliberate). */
final class DockerSandboxArgumentsTest extends TestCase
{
    private const SETTINGS = [
        'image' => 'educloud-nb:1', 'memory' => '512m', 'cpus' => '1', 'pids' => 128, 'nofile' => 256, 'tmpfs_mb' => 64,
        'shm_mb' => 16, 'log_max_mb' => 4,
    ];

    public function testIsolationFlags(): void
    {
        $dir = sys_get_temp_dir() . '/nbargs-' . bin2hex(random_bytes(4));
        mkdir($dir . '/in', 0700, true);
        touch($dir . '/lakehouse.duckdb');
        try {
            $args = DockerSandbox::arguments(self::SETTINGS, 'educloud-nb-x', $dir . '/in', $dir . '/lakehouse.duckdb');
        } finally {
            unlink($dir . '/lakehouse.duckdb');
            rmdir($dir . '/in');
            rmdir($dir);
        }
        $joined = implode(' ', $args);
        $flags = [
            'run --detach --name educloud-nb-x --label educloud.nb=1', '--network none', '--read-only',
            '--tmpfs /tmp:rw,nosuid,nodev,noexec,size=64m', '--shm-size 16m',
            '--memory 512m --memory-swap 512m', '--cpus 1', '--pids-limit 128', '--ulimit nofile=256:256', '--ulimit core=0:0',
            '--cap-drop ALL', '--security-opt no-new-privileges', '--user 10001:10001',
            '--log-driver json-file --log-opt max-size=4m --log-opt max-file=2',
        ];
        foreach ($flags as $flag) {
            self::assertStringContainsString($flag, $joined);
        }
        self::assertSame('educloud-nb:1', end($args), 'the image is the last argument: no command override');
        $mounts = array_values(array_filter($args, static fn (string $a): bool => str_starts_with($a, 'type=bind')));
        self::assertCount(2, $mounts);
        foreach ($mounts as $mount) {
            self::assertStringEndsWith(',readonly', $mount, 'every host mount is read-only');
        }
        self::assertStringContainsString('target=/in,', $mounts[0]);
        self::assertStringContainsString('target=/data/lakehouse.duckdb,', $mounts[1]);
        $forbiddenFlags = ['-e', '--env', '--env-file', '--privileged', '--cap-add', '-v', '--volume', '--device', '--pid', '--ipc', '--userns'];
        foreach ($forbiddenFlags as $forbidden) {
            self::assertNotContains($forbidden, $args, "$forbidden must never be passed");
        }
    }

    public function testRunTimeoutFiresBeforeThePid1GuardAndTheReaper(): void
    {
        // Gate M8-N5: the image guard (Dockerfile, timeout -s KILL 150) and the reaper age are fixed.
        $env = [];
        $config = require dirname(__DIR__, 3) . '/config/execution.php';
        $timeout = $config['timeouts']['notebook_run'];
        self::assertLessThan(140, $timeout, 'a longer run would be killed by the PID 1 guard as NOTEBOOK_KILLED');
        $dockerfile = (string) file_get_contents(dirname(__DIR__, 3) . '/worker/notebook/Dockerfile');
        self::assertStringContainsString('ENTRYPOINT ["timeout", "-s", "KILL", "150",', $dockerfile);
        self::assertGreaterThan(150, DockerSandbox::ORPHAN_AGE_S);
    }

    public function testNoLakehouseMeansNoDataMount(): void
    {
        $dir = sys_get_temp_dir() . '/nbargs-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        try {
            $args = DockerSandbox::arguments(self::SETTINGS, 'n', $dir, null);
        } finally {
            rmdir($dir);
        }
        self::assertCount(1, array_filter($args, static fn (string $a): bool => str_starts_with($a, 'type=bind')));
    }
}
