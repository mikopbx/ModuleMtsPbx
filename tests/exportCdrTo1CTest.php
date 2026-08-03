<?php

declare(strict_types=1);

final class ExportCdrTo1CTest
{
    private string $script;
    private string $tmpDir;
    private int $assertions = 0;

    public function __construct()
    {
        $this->script = dirname(__DIR__) . '/bin/exportCdrTo1C.php';
        $this->tmpDir = sys_get_temp_dir() . '/mts-cdr-export-' . bin2hex(random_bytes(6));
        if (!mkdir($this->tmpDir, 0700, true) && !is_dir($this->tmpDir)) {
            throw new RuntimeException('Cannot create test directory');
        }
    }

    public function run(): void
    {
        try {
            $this->testHelpDoesNotOpenDatabase();
            $this->testOutputIsRequired();
            $this->testInvalidDateIsRejected();
            $this->testMissingDatabaseIsRejected();
            $this->testIncompatibleSchemaIsRejected();
            $this->testDefaultDateIsInclusive();
            fwrite(STDOUT, "OK ({$this->assertions} assertions)\n");
        } finally {
            $this->removeTree($this->tmpDir);
        }
    }

    private function testHelpDoesNotOpenDatabase(): void
    {
        $result = $this->runCli(['--help']);
        $this->assertSame(0, $result['code'], '--help exit code');
        $this->assertContains('--output', $result['stdout'], '--help describes output');
    }

    private function testOutputIsRequired(): void
    {
        $result = $this->runCli([]);
        $this->assertNotSame(0, $result['code'], 'missing --output fails');
        $this->assertContains('--output', $result['stderr'], 'missing output error is actionable');
    }

    private function testInvalidDateIsRejected(): void
    {
        $result = $this->runCli([
            '--database=' . $this->tmpDir . '/missing.db',
            '--from=16.07.2026 20:55:11',
            '--output=' . $this->tmpDir . '/history.xml',
        ]);
        $this->assertNotSame(0, $result['code'], 'invalid date fails');
        $this->assertContains('YYYY-MM-DD HH:MM:SS', $result['stderr'], 'date format is explained');
    }

    private function testMissingDatabaseIsRejected(): void
    {
        $database = $this->tmpDir . '/does-not-exist.db';
        $result = $this->runCli([
            '--database=' . $database,
            '--output=' . $this->tmpDir . '/history.xml',
        ]);
        $this->assertNotSame(0, $result['code'], 'missing database fails');
        $this->assertContains('не найдена', $result['stderr'], 'missing database is explained');
        $this->assertTrue(!file_exists($database), 'read-only open does not create database');
    }

    private function testIncompatibleSchemaIsRejected(): void
    {
        $database = $this->tmpDir . '/incompatible.db';
        $pdo = new PDO('sqlite:' . $database);
        $pdo->exec('CREATE TABLE cdr (id INTEGER PRIMARY KEY, start TEXT)');
        $pdo = null;

        $result = $this->runCli([
            '--database=' . $database,
            '--output=' . $this->tmpDir . '/history.xml',
        ]);
        $this->assertNotSame(0, $result['code'], 'incompatible schema fails');
        $this->assertContains('совместим', $result['stderr'], 'schema error is explained');
    }

    private function testDefaultDateIsInclusive(): void
    {
        $database = $this->tmpDir . '/default-date.db';
        $output = $this->tmpDir . '/default-date.xml';
        $pdo = new PDO('sqlite:' . $database);
        $pdo->exec(
            'CREATE TABLE cdr (' .
            'id INTEGER PRIMARY KEY, start TEXT, answer TEXT, endtime TEXT, ' .
            'src_num TEXT, dst_num TEXT, linkedid TEXT, UNIQUEID TEXT, ' .
            'did TEXT, disposition TEXT, duration INTEGER, billsec INTEGER, recordingfile TEXT)'
        );
        $insert = $pdo->prepare(
            'INSERT INTO cdr (start, answer, endtime, src_num, dst_num, linkedid, UNIQUEID, did, disposition, duration, billsec, recordingfile) ' .
            'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([
            '2026-07-16 20:55:11', '2026-07-16 20:55:12', '2026-07-16 20:56:11',
            '100', '79990000000', 'call-at-boundary', 'leg-1', '74950000000',
            'ANSWERED', 60, 59, '/recordings/a.mp3',
        ]);
        $pdo = null;

        $result = $this->runCli([
            '--database=' . $database,
            '--output=' . $output,
        ]);
        $this->assertSame(0, $result['code'], 'valid compatible database exports');
        $this->assertTrue(is_file($output), 'export creates output');
        $this->assertContains('call-at-boundary', (string) file_get_contents($output), 'default boundary is inclusive');
    }

    /**
     * @param string[] $arguments
     * @return array{code:int,stdout:string,stderr:string}
     */
    private function runCli(array $arguments): array
    {
        $command = array_merge([PHP_BINARY, $this->script], $arguments);
        $pipes = [];
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start export CLI');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'code' => proc_close($process),
            'stdout' => $stdout === false ? '' : $stdout,
            'stderr' => $stderr === false ? '' : $stderr,
        ];
    }

    /** @param mixed $expected @param mixed $actual */
    private function assertSame($expected, $actual, string $message): void
    {
        $this->assertions++;
        if ($expected !== $actual) {
            throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    /** @param mixed $unexpected @param mixed $actual */
    private function assertNotSame($unexpected, $actual, string $message): void
    {
        $this->assertions++;
        if ($unexpected === $actual) {
            throw new RuntimeException($message . ': value unexpectedly equals ' . var_export($unexpected, true));
        }
    }

    private function assertContains(string $needle, string $haystack, string $message): void
    {
        $this->assertions++;
        if (strpos($haystack, $needle) === false) {
            throw new RuntimeException($message . ': missing ' . var_export($needle, true) . ' in ' . var_export($haystack, true));
        }
    }

    private function assertTrue(bool $condition, string $message): void
    {
        $this->assertions++;
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = scandir($path);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $child = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($child)) {
                $this->removeTree($child);
            } else {
                unlink($child);
            }
        }
        rmdir($path);
    }
}

(new ExportCdrTo1CTest())->run();
