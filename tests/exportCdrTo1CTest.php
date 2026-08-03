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
            $this->testOptionsAcceptSpaceSeparatedValues();
            $this->testCdrGeneralIsUsedWhenCdrTableAlsoExists();
            $this->testAnsweredCallKeepsOnlyAnsweredLegs();
            $this->testMissedIncomingCallKeepsOneLegTo2003();
            $this->testFailurePreservesExistingOutput();
            $this->testManualImportProcessingSourceContract();
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
        $pdo->exec('CREATE TABLE cdr_general (id INTEGER PRIMARY KEY, start TEXT)');
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
            'CREATE TABLE cdr_general (' .
            'id INTEGER PRIMARY KEY, start TEXT, answer TEXT, endtime TEXT, ' .
            'src_num TEXT, dst_num TEXT, linkedid TEXT, UNIQUEID TEXT, ' .
            'did TEXT, disposition TEXT, duration INTEGER, billsec INTEGER, recordingfile TEXT)'
        );
        $insert = $pdo->prepare(
            'INSERT INTO cdr_general (start, answer, endtime, src_num, dst_num, linkedid, UNIQUEID, did, disposition, duration, billsec, recordingfile) ' .
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

    private function testOptionsAcceptSpaceSeparatedValues(): void
    {
        $database = $this->tmpDir . '/space-separated.db';
        $output = $this->tmpDir . '/space-separated.xml';
        $pdo = $this->createCompatibleDatabase($database);
        $pdo->exec(
            "INSERT INTO cdr_general (start, linkedid, UNIQUEID, src_num, dst_num) " .
            "VALUES ('2026-07-17 10:00:00', 'space-separated-call', 'leg', '100', '200')"
        );
        $pdo = null;

        $result = $this->runCli([
            '--database', $database,
            '--from', '2026-07-17 00:00:00',
            '--output', $output,
        ]);
        $this->assertSame(0, $result['code'], 'space-separated long options export');
        $this->assertTrue(is_file($output), 'space-separated output path is used');
        $this->assertContains('space-separated-call', (string) file_get_contents($output), 'space-separated values reach exporter');
    }

    private function testCdrGeneralIsUsedWhenCdrTableAlsoExists(): void
    {
        $database = $this->tmpDir . '/both-cdr-tables.db';
        $output = $this->tmpDir . '/both-cdr-tables.xml';
        $pdo = $this->createCompatibleDatabase($database, 'cdr_general');
        $this->createCompatibleTable($pdo, 'cdr');
        $pdo->exec(
            "INSERT INTO cdr_general (start, linkedid, UNIQUEID, src_num, dst_num) " .
            "VALUES ('2026-07-17 10:00:00', 'from-cdr-general', 'general-leg', '100', '200')"
        );
        $pdo->exec(
            "INSERT INTO cdr (start, linkedid, UNIQUEID, src_num, dst_num) " .
            "VALUES ('2026-07-17 10:00:00', 'from-wrong-cdr', 'cdr-leg', '300', '400')"
        );
        $pdo = null;

        $result = $this->runCli([
            '--database', $database,
            '--from', '2026-07-17 00:00:00',
            '--output', $output,
        ]);
        $this->assertSame(0, $result['code'], 'database with cdr_general exports');
        $xml = (string) file_get_contents($output);
        $this->assertContains('from-cdr-general', $xml, 'cdr_general data is exported');
        $this->assertTrue(strpos($xml, 'from-wrong-cdr') === false, 'cdr table data is ignored');
    }

    private function testAnsweredCallKeepsOnlyAnsweredLegs(): void
    {
        $database = $this->tmpDir . '/grouped.db';
        $output = $this->tmpDir . '/grouped.xml';
        $pdo = $this->createCompatibleDatabase($database);
        $insert = $pdo->prepare(
            'INSERT INTO cdr_general (start, answer, endtime, src_num, dst_num, linkedid, UNIQUEID, did, disposition, duration, billsec, recordingfile) ' .
            'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $rows = [
            ['2026-07-16 20:55:10', '', '', 'old', 'old', 'old-call', 'old-leg', '', 'NO ANSWER', 1, 0, ''],
            ['2026-07-16 20:55:11.486', '2026-07-16 20:55:12.125', '2026-07-16 20:56:11.999', '100 & office', '7999<1>', 'group-1', 'leg-1', 'line&1', 'ANSWERED', 60, 59, '/records/a&b.mp3'],
            ['2026-07-16 20:56:12.100', '', '2026-07-16 20:57:12.100', '100', '200', 'group-1', 'leg-2', 'line&1', 'NO ANSWER', 60, 0, ''],
            ['2026-07-16 21:00:00', '', '', '300', '400', '', 'fallback-unique', '', 'BUSY', 5, 0, ''],
            ['2026-07-16 21:01:00', '', '', '500', '600', '', '', '', 'BUSY', 5, 0, ''],
        ];
        foreach ($rows as $row) {
            $insert->execute($row);
        }
        $pdo = null;

        $result = $this->runCli([
            '--database=' . $database,
            '--from=2026-07-16 20:55:11',
            '--output=' . $output,
            '--filter-for-1c',
        ]);
        $this->assertSame(0, $result['code'], 'grouped fixture exports');
        $this->assertContains('строк 2', $result['stdout'], 'summary counts retained legs');
        $this->assertContains('звонков 2', $result['stdout'], 'summary counts grouped calls');
        $this->assertContains('пропущено 1', $result['stdout'], 'summary counts missing identifiers');

        $document = new DOMDocument();
        $this->assertTrue($document->load($output), 'generated output is valid XML');
        $xpath = new DOMXPath($document);
        $this->assertSame(2, $xpath->query('/history/history_record')->length, 'one history record per call id');
        $this->assertSame(1, $xpath->query('/history/history_record[@entire_id="group-1"]/details')->length, 'answered group contains only answered legs');
        $this->assertSame(0, $xpath->query('/history/history_record[@entire_id="old-call"]')->length, 'row before boundary is excluded');
        $this->assertSame(1, $xpath->query('/history/history_record[@entire_id="fallback-unique"]')->length, 'UNIQUEID is fallback group id');

        $firstDetail = $xpath->query('/history/history_record[@entire_id="group-1"]/details[1]')->item(0);
        $this->assertTrue($firstDetail instanceof DOMElement, 'first detail exists');
        $this->assertSame('leg-1', $firstDetail->getAttribute('call_id'), 'call_id preserves leg UNIQUEID');
        $this->assertSame('ANSWER', $firstDetail->getAttribute('status'), 'answered maps to ANSWER');
        $this->assertContains('2026-07-16T20:55:11', $firstDetail->getAttribute('started'), 'start is RFC3339');
        $this->assertContains('2026-07-16T20:55:12', $firstDetail->getAttribute('answered'), 'fractional answer is RFC3339');
        $this->assertContains('2026-07-16T20:56:11', $firstDetail->getAttribute('finished'), 'fractional finish is RFC3339');
        $this->assertSame('/records/a&b.mp3', $firstDetail->getAttribute('record_file'), 'recording XML attribute round-trips');

        $from = $xpath->query('/history/history_record[@entire_id="group-1"]/from[1]')->item(0);
        $to = $xpath->query('/history/history_record[@entire_id="group-1"]/to[1]')->item(0);
        $this->assertTrue($from instanceof DOMElement && $to instanceof DOMElement, 'participants exist');
        $this->assertSame('100 & office', $from->getAttribute('number'), 'ampersand round-trips');
        $this->assertSame('7999<1>', $to->getAttribute('number'), 'angle brackets round-trip');
    }

    private function testMissedIncomingCallKeepsOneLegTo2003(): void
    {
        $database = $this->tmpDir . '/missed-incoming.db';
        $output = $this->tmpDir . '/missed-incoming.xml';
        $pdo = $this->createCompatibleDatabase($database);
        $insert = $pdo->prepare(
            'INSERT INTO cdr_general (start, answer, endtime, src_num, dst_num, linkedid, UNIQUEID, did, disposition, duration, billsec, recordingfile) ' .
            'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $rows = [
            ['2026-07-17 10:00:00.100', '', '2026-07-17 10:00:05.100', '79990000000', '2003', 'missed-1', 'missed-main', 'line-1', 'NO ANSWER', 5, 0, ''],
            ['2026-07-17 10:00:01.100', '', '2026-07-17 10:00:05.100', '79990000000', '2010', 'missed-1', 'missed-employee-1', 'line-1', 'NO ANSWER', 4, 0, ''],
            ['2026-07-17 10:00:02.100', '', '2026-07-17 10:00:05.100', '79990000000', '2003', 'missed-1', 'missed-main-duplicate', 'line-1', 'NO ANSWER', 3, 0, ''],
            ['2026-07-17 10:00:03.100', '', '2026-07-17 10:00:05.100', '79990000000', '2020', 'missed-1', 'missed-employee-2', 'line-1', 'NO ANSWER', 2, 0, ''],
        ];
        foreach ($rows as $row) {
            $insert->execute($row);
        }
        $pdo = null;

        $result = $this->runCli([
            '--database', $database,
            '--from', '2026-07-17 00:00:00',
            '--output', $output,
            '--filter-for-1c',
        ]);
        $this->assertSame(0, $result['code'], 'missed incoming fixture exports');
        $document = new DOMDocument();
        $this->assertTrue($document->load($output), 'missed incoming output is valid XML');
        $xpath = new DOMXPath($document);
        $this->assertSame(1, $xpath->query('/history/history_record[@entire_id="missed-1"]/details')->length, 'missed incoming keeps one leg');
        $this->assertSame(1, $xpath->query('/history/history_record[@entire_id="missed-1"]/to[@number="2003"]')->length, 'retained missed leg targets 2003');
        $detail = $xpath->query('/history/history_record[@entire_id="missed-1"]/details')->item(0);
        $this->assertTrue($detail instanceof DOMElement, 'retained missed detail exists');
        $this->assertSame('missed-main', $detail->getAttribute('call_id'), 'first leg to 2003 is retained');
        $this->assertContains('2026-07-17T10:00:05', $detail->getAttribute('finished'), 'missed finish with milliseconds is populated');
    }

    private function testFailurePreservesExistingOutput(): void
    {
        $database = $this->tmpDir . '/broken-after-output.db';
        $output = $this->tmpDir . '/existing.xml';
        file_put_contents($output, 'existing-content');
        $pdo = new PDO('sqlite:' . $database);
        $pdo->exec('CREATE TABLE cdr_general (id INTEGER PRIMARY KEY, start TEXT)');
        $pdo = null;

        $result = $this->runCli([
            '--database=' . $database,
            '--output=' . $output,
        ]);
        $this->assertNotSame(0, $result['code'], 'broken schema fails with existing output');
        $this->assertSame('existing-content', (string) file_get_contents($output), 'failed export preserves existing output');
    }

    private function testManualImportProcessingSourceContract(): void
    {
        $root = dirname(__DIR__);
        $source = $root . '/onec/ЗагрузкаИсторииЗвонковMtsPBX_v2';
        $formElements = json_decode((string) file_get_contents($source . '/Form/ОсновнаяФорма/Form.elem.json'), true);
        $this->assertTrue(is_array($formElements), 'v2 form element model is readable');
        $commands = array_column($formElements['commands'] ?? [], 'name');
        $this->assertTrue(in_array('ЗагрузитьИзXML', $commands, true), 'manual XML command exists');

        $rawForm = (string) file_get_contents($source . '/Form/ОсновнаяФорма/Form.json');
        $this->assertContains('ЗагрузитьИзXML', $rawForm, 'manual XML button exists in command panel');

        $formModule = (string) file_get_contents($source . '/Form/ОсновнаяФорма/Form.obj.bsl');
        $objectModule = (string) file_get_contents($source . '/ExternalDataProcessor.obj.bsl');
        $this->assertContains('Процедура ЗагрузитьИзXML(', $formModule, 'client XML command handler exists');
        $this->assertContains('НачатьПомещениеФайла', $formModule, 'client uploads selected file');
        $this->assertContains('ПолучитьИзВременногоХранилища', $formModule, 'server reads temporary storage');
        $this->assertContains('ОтменитьТранзакцию()', $objectModule, 'manual import rolls transaction back on failure');
        $this->assertContains('ОбработатьЗаписиИстории(', $objectModule, 'manual and network paths use common parser');
        $this->assertContains('ПроверитьОбработкуПропущенныхЗвонков(', $objectModule, 'manual import runs missed call processing');
        $this->assertContains(
            'ИмпортироватьИсториюЗвонков(ЗаписиИсторииXML, Ложь)',
            $objectModule,
            'manual XML import explicitly disables named lock'
        );
        $this->assertContains(
            'Если ИспользоватьИменованнуюБлокировку Тогда',
            $objectModule,
            'shared processing guards named lock by import mode'
        );
        $this->assertContains(
            'ПТ20_ОбщегоНазначения.УстановитьИменованнуюБлокировку',
            $objectModule,
            'standard synchronization retains named lock'
        );
        $this->assertContains(
            "Если ИспользоватьИменованнуюБлокировку Тогда\n\t\t\t\tСтрокаДетализации.queue",
            $objectModule,
            'manual XML compatibility mode skips unavailable queue field'
        );
        $this->assertContains(
            "Если ИспользоватьИменованнуюБлокировку Тогда\n\t\t\t// Добавим в обработку для речевой аналитики.",
            $objectModule,
            'manual XML compatibility mode skips unavailable speech recognition queue'
        );
        $this->assertSame(
            'f70db8c5592ee2de7e8ecc8e2ea98ce154019aad230785a2a3b0472754f04fea',
            hash_file('sha256', $root . '/ЗагрузкаИсторииЗвонковMtsPBX_v1.epf'),
            'original v1 EPF remains unchanged'
        );
    }

    private function createCompatibleDatabase(string $path, string $table = 'cdr_general'): PDO
    {
        $pdo = new PDO('sqlite:' . $path);
        $this->createCompatibleTable($pdo, $table);
        return $pdo;
    }

    private function createCompatibleTable(PDO $pdo, string $table): void
    {
        if (!in_array($table, ['cdr', 'cdr_general'], true)) {
            throw new InvalidArgumentException('Unsupported fixture table');
        }
        $pdo->exec(
            'CREATE TABLE ' . $table . ' (' .
            'id INTEGER PRIMARY KEY, start TEXT, answer TEXT, endtime TEXT, ' .
            'src_num TEXT, dst_num TEXT, linkedid TEXT, UNIQUEID TEXT, ' .
            'did TEXT, disposition TEXT, duration INTEGER, billsec INTEGER, recordingfile TEXT)'
        );
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
