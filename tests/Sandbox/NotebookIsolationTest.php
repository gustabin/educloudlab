<?php

declare(strict_types=1);

namespace EduCloud\Tests\Sandbox;

use EduCloud\Modules\Notebooks\DockerSandbox;
use EduCloud\Tests\TestCase;

/**
 * M8 release gate (ADR-010, master plan risk R3): attacks the REAL notebook container with the code a malicious
 * student would write. Every escape attempt must fail inside the sandbox. Skipped (and notebooks stay in demo mode)
 * when Docker or the educloud-nb image is unavailable.
 */
final class NotebookIsolationTest extends TestCase
{
    private ?DockerSandbox $sandbox = null;
    private string $lakehouse = '';

    protected function setUp(): void
    {
        parent::setUp();
        $sandbox = new DockerSandbox($this->app()->config, $this->app()->storage());
        if (!$sandbox->available()) {
            self::markTestSkipped('Docker daemon or the educloud-nb:1 image is not available (php scripts/notebook-image.php build).');
        }
        $this->sandbox = $sandbox;
        $this->lakehouse = $this->app()->storage()->root() . '/lakehouse-fixture.duckdb';
        $python = (string) $this->app()->config->get('execution.python');
        $script = $this->app()->storage()->root() . '/make_lakehouse.py';
        file_put_contents($script, "import duckdb, sys\ncon = duckdb.connect(sys.argv[1])\n"
            . "con.execute('CREATE SCHEMA gold; CREATE TABLE gold.ventas AS SELECT range AS id, range * 10 AS importe FROM range(5)')\n"
            . "con.execute('CHECKPOINT')\ncon.close()\n");
        $io = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $p = proc_open([$python, '-I', $script, $this->lakehouse], $io, $pipes, null, null, ['bypass_shell' => true]);
        self::assertIsResource($p);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        self::assertSame(0, proc_close($p), 'lakehouse fixture');
    }

    /**
     * @param list<string> $sources one code cell per entry
     * @return array<string, mixed>
     */
    private function exec(array $sources, int $timeout = 60): array
    {
        $cells = [];
        foreach ($sources as $i => $source) {
            $cells[] = ['id' => 'c' . ($i + 1), 'source' => $source];
        }
        return $this->sandbox?->run($cells, $this->lakehouse, $timeout, static fn (): bool => false) ?? [];
    }

    /**
     * Output of the first cell (asserting the run produced a result).
     *
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function first(array $response): array
    {
        self::assertTrue($response['ok'] ?? false, (string) json_encode($response));
        return $response['data']['cells'][0];
    }

    public function testCellsRunReadTheLakehouseAndSaveArtifacts(): void
    {
        $r = $this->exec([
            "import os\nprint('uid', os.getuid())\ncon = lakehouse()\ncon.sql('SELECT sum(importe) AS total FROM gold.ventas').df()",
            "save_result('ventas', lakehouse().sql('SELECT * FROM gold.ventas ORDER BY id').df())\n'listo'",
        ]);
        self::assertTrue($r['ok'], (string) json_encode($r));
        self::assertSame('succeeded', $r['data']['status']);
        self::assertSame("uid 10001\n", $r['data']['cells'][0]['stdout']);
        self::assertEquals([[100]], $r['data']['cells'][0]['table']['rows']);
        self::assertSame("'listo'", $r['data']['cells'][1]['value']);
        self::assertSame(['id', 'importe'], $r['data']['artifacts']['ventas']['columns']);
        self::assertCount(5, $r['data']['artifacts']['ventas']['rows']);
    }

    public function testAWorkspaceWithoutLakehouseGetsAClearError(): void
    {
        $r = $this->sandbox?->run([['id' => 'c1', 'source' => 'lakehouse()']], null, 60, static fn (): bool => false) ?? [];
        $cell = $this->first($r);
        self::assertSame('FileNotFoundError', $cell['error']['type'] ?? null, (string) json_encode($cell));
        self::assertStringContainsString('aún no tiene lakehouse', (string) $cell['error']['message']);
    }

    public function testErrorsStopTheRunWithTypeAndLine(): void
    {
        $r = $this->exec(["x = 1\nraise ValueError('mal')", "print('nunca')"]);
        self::assertSame('failed', $r['data']['status']);
        self::assertCount(1, $r['data']['cells'], 'execution stops at the first failing cell');
        self::assertSame(['type' => 'ValueError', 'message' => 'mal', 'line' => 2], $r['data']['cells'][0]['error']);
        $syntax = $this->first($this->exec(['def f(:']));
        self::assertSame('SyntaxError', $syntax['error']['type']);
    }

    public function testNoNetwork(): void
    {
        $cell = $this->first($this->exec([
            "import socket\nres = []\nfor target in [('1.1.1.1', 53), ('8.8.8.8', 443), ('host.docker.internal', 80), ('172.17.0.1', 2375)]:\n"
            . "    s = socket.socket(); s.settimeout(3)\n    try:\n        s.connect(target); res.append('CONNECTED ' + str(target))\n"
            . "    except Exception as e:\n        res.append('blocked')\n    finally:\n        s.close()\n"
            . "try:\n    socket.getaddrinfo('example.com', 80); res.append('DNS RESOLVED')\nexcept Exception:\n    res.append('no-dns')\n"
            . "print(sorted(open('/proc/net/dev').read().split()[::17]))\nres",
        ]));
        self::assertSame("['blocked', 'blocked', 'blocked', 'blocked', 'no-dns']", $cell['value']);
        self::assertStringNotContainsString('eth0', $cell['stdout'], 'only the loopback interface exists');
    }

    public function testReadOnlyFilesystemAndLakehouse(): void
    {
        $cell = $this->first($this->exec([
            "import os, duckdb\nres = {}\n"
            . "for path in ['/opt/educloud/evil.py', '/etc/passwd', '/in/input.json', '/data/lakehouse.duckdb', '/data/nuevo', '/usr/x']:\n"
            . "    try:\n        open(path, 'a').write('x'); res[path] = 'WRITTEN'\n    except OSError:\n        res[path] = 'denied'\n"
            . "try:\n    duckdb.connect('/data/lakehouse.duckdb').execute('CREATE TABLE gold.hack AS SELECT 1'); res['duckdb_rw'] = 'WRITTEN'\n"
            . "except Exception:\n    res['duckdb_rw'] = 'denied'\n"
            . "try:\n    lakehouse().execute('CREATE TABLE gold.hack AS SELECT 1'); res['duckdb_ro'] = 'WRITTEN'\n"
            . "except Exception:\n    res['duckdb_ro'] = 'denied'\n"
            . "res['tmp'] = 'ok' if open('/tmp/scratch', 'w').write('ok') else 'no'\nsorted(set(res.values()))",
        ]));
        self::assertSame("['denied', 'ok']", $cell['value'], (string) json_encode($cell));
    }

    public function testTmpIsSmallAndHostFilesAreNotVisible(): void
    {
        $cell = $this->first($this->exec([
            "import os\nres = []\ntry:\n    with open('/tmp/big', 'wb') as f:\n        for _ in range(100): f.write(b'x' * 1024 * 1024)\n"
            . "    res.append('WROTE 100MB')\nexcept OSError:\n    res.append('tmpfs-full')\nfinally:\n    os.remove('/tmp/big')\n"
            . "res.append(sorted(os.listdir('/in')))\nres.append(sorted(os.listdir('/data')))\n"
            . "res.append([p for p in ['/mnt/c', '/run/desktop', '/host_mnt', '/var/run/docker.sock'] if os.path.exists(p)])\nres",
        ]));
        self::assertSame("['tmpfs-full', ['input.json'], ['lakehouse.duckdb'], []]", $cell['value'], (string) json_encode($cell));
    }

    public function testNoPrivilegesNoCapabilitiesNoHostSecrets(): void
    {
        putenv('EDUCLOUD_TEST_SECRET=supersecreto');
        try {
            $cell = $this->first($this->exec([
                "import os\nres = [os.getuid(), os.getgid()]\ntry:\n    os.setuid(0); res.append('ROOT')\n"
                . "except PermissionError:\n    res.append('no-root')\n"
                . "caps = [l.split()[1] for l in open('/proc/self/status') if l.startswith(('CapEff', 'CapPrm', 'CapBnd'))]\n"
                . "res.append(sorted(set(caps)))\nres.append([l.split()[1] for l in open('/proc/self/status') if l.startswith('NoNewPrivs')])\n"
                . "res.append(sorted(k for k in os.environ if 'SECRET' in k or k.startswith(('DB_', 'JWT', 'APP_', 'SMTP', 'MAIL'))))\nres",
            ]));
        } finally {
            putenv('EDUCLOUD_TEST_SECRET');
        }
        self::assertSame("[10001, 10001, 'no-root', ['0000000000000000'], ['1'], []]", $cell['value'], (string) json_encode($cell));
    }

    public function testThePid1GuardCannotBeSignalledOrTraced(): void
    {
        // Gate M8-N3: the `timeout` guard runs as the student's UID; init protection and Yama must keep it out of reach.
        $cell = $this->first($this->exec([
            "import ctypes, os, signal\nres = []\nfor sig in (signal.SIGKILL, signal.SIGSTOP, signal.SIGTERM):\n"
            . "    try:\n        os.kill(1, sig)\n    except OSError as e:\n        res.append(type(e).__name__)\n"
            . "libc = ctypes.CDLL(None, use_errno=True)\nres.append((libc.ptrace(16, 1, None, None), ctypes.get_errno()))\n"
            . "res.append(open('/proc/1/status').read().split('State:')[1].split()[0])\nres",
        ]));
        // kill() to PID 1 "succeeds" but the kernel drops signals with no handler; the guard keeps running (state S).
        self::assertSame("[(-1, 1), 'S']", $cell['value'], (string) json_encode($cell));
    }

    public function testForkBombIsContainedByThePidsLimit(): void
    {
        $r = $this->exec([
            "import os, time\nn = 0\nfor _ in range(1000):\n    try:\n        pid = os.fork()\n    except OSError:\n        break\n"
            . "    if pid == 0:\n        time.sleep(30); os._exit(0)\n    n += 1\nn",
        ], 60);
        self::assertTrue($r['ok'], (string) json_encode($r));
        $forked = (int) $r['data']['cells'][0]['value'];
        self::assertGreaterThan(0, $forked);
        self::assertLessThan(128, $forked, 'pids-limit 128 caps the number of processes');
    }

    public function testMemoryBombIsStopped(): void
    {
        $r = $this->exec(["blocks = []\nfor _ in range(64):\n    blocks.append(bytearray(64 * 1024 * 1024))\nlen(blocks)"], 60);
        // Either the OOM killer stops the container or Python raises MemoryError: never more than the 512 MB limit.
        if ($r['ok'] === true) {
            self::assertSame('MemoryError', $r['data']['cells'][0]['error']['type'] ?? null, (string) json_encode($r));
        } else {
            self::assertSame('NOTEBOOK_KILLED', $r['error_code'], (string) json_encode($r));
        }
    }

    public function testInfiniteLoopIsKilledAtTheTimeout(): void
    {
        $started = microtime(true);
        $r = $this->exec(["while True:\n    pass"], 8);
        self::assertFalse($r['ok']);
        self::assertSame('TIMEOUT', $r['error_code']);
        self::assertLessThan(30, microtime(true) - $started, 'the container is killed, not waited for');
    }

    public function testOutputFloodStaysInsideTheDockerVmAndTheResultSurvives(): void
    {
        // Gate M8-F1/F5: stdout never reaches a host file; Docker rotates it inside its VM (4 MB x 2).
        $r = $this->exec(["import os\nfor _ in range(500):\n    os.write(1, b'x' * 65536)\nprint('fin')\n42"], 90);
        self::assertTrue($r['ok'], (string) json_encode($r));
        self::assertSame("'42'", "'" . trim((string) $r['data']['cells'][0]['value'], "'") . "'");
        $endless = $this->exec(["import os\nwhile True:\n    os.write(1, b'x' * 65536)"], 15);
        self::assertSame('TIMEOUT', $endless['error_code'] ?? null, (string) json_encode($endless));
        $printed = $this->first($this->exec(["for i in range(100000):\n    print('linea', i)"]));
        self::assertLessThanOrEqual(20_100, mb_strlen($printed['stdout']), 'captured print output is truncated');
        self::assertStringContainsString('caracteres omitidos', $printed['stdout']);
    }

    public function testResultsAreSelfReportedButNormalised(): void
    {
        // Gate M8-F3: student code runs in the executor's process, so it CAN print a forged result line and exit before
        // the executor reports. Results are therefore self-reported (docs/architecture/NOTEBOOKS.md): they only ever
        // describe the student's own run, and the host normalises them (types, sizes, ids, row shapes).
        $forged = json_encode(['status' => 'succeeded', 'evil' => '<script>', 'cells' => [
            ['id' => 'x"],#nb-status,[x="', 'status' => 'succeeded'], ['id' => 'ok', 'status' => 'succeeded', 'stdout' => ['no' => 'str']],
        ], 'artifacts' => ['bueno' => ['columns' => ['a', 'b'], 'rows' => [[1], [1, 2], 'x']], 'Malo!' => ['columns' => ['a'], 'rows' => [[1]]]]]);
        $cell = "import os\nos.write(1, ('\\n" . DockerSandbox::MARKER . "' + " . var_export($forged, true) . " + '\\n').encode())\nos._exit(0)";
        $r = $this->exec([$cell]);
        self::assertTrue($r['ok'], (string) json_encode($r));
        self::assertSame(['status', 'cells', 'artifacts'], array_keys($r['data']));
        self::assertSame(['ok'], array_column($r['data']['cells'], 'id'), 'cell ids that are not notebook ids are dropped');
        self::assertSame('', $r['data']['cells'][0]['stdout'], 'non-string output becomes empty text');
        self::assertSame(['bueno'], array_keys($r['data']['artifacts']), 'artifact names follow the save_result pattern');
        self::assertSame([[1, 2]], $r['data']['artifacts']['bueno']['rows'], 'rows must have one value per column');
    }

    public function testHardeningFlagsInsideTheContainer(): void
    {
        $cell = $this->first($this->exec([
            "import os, resource\nres = [resource.getrlimit(resource.RLIMIT_CORE)]\n"
            . "try:\n    open('/dev/shm/big', 'wb').write(b'x' * 32 * 1024 * 1024); res.append('SHM 32MB')\n"
            . "except OSError:\n    res.append('shm-full')\n"
            . "res.append(os.path.exists('/out'))\nres.append(open('/proc/1/cmdline').read().split(chr(0))[:4])\nres",
        ]));
        self::assertSame("[(0, 0), 'shm-full', False, ['timeout', '-s', 'KILL', '150']]", $cell['value'], (string) json_encode($cell));
    }

    public function testOrphanedContainersAreReaped(): void
    {
        // Gate M8-F1: a dispatcher that dies mid-run leaves its container; the next dispatcher start removes it.
        $name = 'educloud-nb-' . strtolower(\EduCloud\Core\Ulid::generate());
        $command = 'docker run -d --name ' . $name . ' --label ' . DockerSandbox::LABEL . ' --network none --entrypoint sleep educloud-nb:1 300';
        exec($command, $out, $code);
        self::assertSame(0, $code);
        mkdir($this->app()->storage()->root() . '/jobs/nb-orphan', 0700, true);
        self::assertGreaterThanOrEqual(1, $this->sandbox?->reapOrphans(true));
        $left = [];
        exec('docker ps -a --filter name=' . $name . ' --format "{{.Names}}"', $left);
        self::assertSame([], $left);
        self::assertSame([], glob($this->app()->storage()->root() . '/jobs/nb-*') ?: []);
    }

    public function testForkedChildrenCannotReplaceTheResult(): void
    {
        // A child inherits the executor and its stdout: it must be killed before the real result is printed.
        $cell = "import os, time, json\n"
            . "fake = json.dumps({'status': 'succeeded', 'cells': [], 'artifacts': {'falso': {'columns': ['x'], 'rows': [[1]]}}})\n"
            . "if os.fork() == 0:\n    time.sleep(2)\n"
            . "    os.write(1, (chr(10) + '" . DockerSandbox::MARKER . "' + fake + chr(10)).encode())\n    os._exit(0)\n"
            . "raise RuntimeError('real')";
        $r = $this->exec([$cell]);
        self::assertSame('failed', $r['data']['status'], (string) json_encode($r));
        self::assertSame([], $r['data']['artifacts']);
        self::assertSame('RuntimeError', $r['data']['cells'][0]['error']['type']);
    }

    public function testNoContainerIsLeftBehind(): void
    {
        $this->exec(["print('hola')"]);
        $out = [];
        exec('docker ps -a --filter name=educloud-nb- --format "{{.Names}}"', $out);
        self::assertSame([], $out);
        self::assertSame([], glob($this->app()->storage()->root() . '/jobs/nb-*') ?: []);
    }
}
