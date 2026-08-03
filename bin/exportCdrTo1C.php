<?php

declare(strict_types=1);

const DEFAULT_CDR_DATABASE = '/storage/usbdisk1/mikopbx/astlogs/asterisk/cdr.db';
const DEFAULT_FROM_DATE = '2026-07-16 20:55:11';

function printUsage(): void
{
    fwrite(STDOUT, <<<TEXT
Выгрузка истории звонков MikoPBX в XML для 1С.

Использование:
  php bin/exportCdrTo1C.php --output=FILE [--from="YYYY-MM-DD HH:MM:SS"] [--database=FILE]

Параметры:
  --output=FILE    Итоговый XML-файл (обязательно).
  --from=DATE      Начальная дата включительно (по умолчанию: 2026-07-16 20:55:11).
  --database=FILE  SQLite CDR (по умолчанию: /storage/usbdisk1/mikopbx/astlogs/asterisk/cdr.db).
  --help           Показать эту справку.

TEXT
    );
}

/** @return array{database:string,from:string,output:string,help:bool} */
function parseArguments(array $arguments): array
{
    $options = [
        'database' => DEFAULT_CDR_DATABASE,
        'from' => DEFAULT_FROM_DATE,
        'output' => '',
        'help' => false,
    ];

    $arguments = array_values(array_slice($arguments, 1));
    for ($index = 0, $count = count($arguments); $index < $count; $index++) {
        $argument = $arguments[$index];
        if ($argument === '--help' || $argument === '-h') {
            $options['help'] = true;
            continue;
        }
        if (substr($argument, 0, 2) !== '--') {
            throw new InvalidArgumentException('Неизвестный параметр: ' . $argument);
        }

        $option = substr($argument, 2);
        if (strpos($option, '=') !== false) {
            [$name, $value] = explode('=', $option, 2);
        } else {
            $name = $option;
            if (!isset($arguments[$index + 1]) || substr($arguments[$index + 1], 0, 2) === '--') {
                throw new InvalidArgumentException('Не указано значение параметра --' . $name . '.');
            }
            $value = $arguments[++$index];
        }
        if (!array_key_exists($name, $options) || $name === 'help') {
            throw new InvalidArgumentException('Неизвестный параметр: --' . $name);
        }
        $options[$name] = $value;
    }

    return $options;
}

function validateDate(string $value): string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d H:i:s') !== $value) {
        throw new InvalidArgumentException('Дата должна иметь формат YYYY-MM-DD HH:MM:SS: ' . $value);
    }
    return $date->format('Y-m-d H:i:s');
}

/** @return array{name:string,columns:array<string,string>} */
function findCdrTable(PDO $pdo): array
{
    $required = ['start', 'linkedid', 'uniqueid', 'src_num', 'dst_num'];
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'cdr_general'");
    if ($tables === false) {
        throw new RuntimeException('Не удалось прочитать схему SQLite.');
    }

    foreach ($tables->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $columns = [];
        $statement = $pdo->query('PRAGMA table_info("' . str_replace('"', '""', (string) $table) . '")');
        if ($statement === false) {
            continue;
        }
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $column) {
            $columns[strtolower((string) $column['name'])] = (string) $column['name'];
        }
        if (count(array_intersect($required, array_keys($columns))) === count($required)) {
            return ['name' => (string) $table, 'columns' => $columns];
        }
    }

    throw new RuntimeException('В базе не найдена совместимая таблица cdr_general с обязательными полями.');
}

function quoteIdentifier(string $identifier): string
{
    return '"' . str_replace('"', '""', $identifier) . '"';
}

function columnExpression(array $columns, string $name, string $fallback = "''"): string
{
    return isset($columns[$name]) ? quoteIdentifier($columns[$name]) : $fallback;
}

/** @return array{rows:int,calls:int,skipped:int} */
function exportCdr(PDO $pdo, array $table, string $from, string $output): array
{
    $columns = $table['columns'];
    $id = columnExpression($columns, 'id', 'rowid');
    $linkedId = columnExpression($columns, 'linkedid');
    $uniqueId = columnExpression($columns, 'uniqueid');
    $callId = "COALESCE(NULLIF({$linkedId}, ''), {$uniqueId})";
    $fields = [
        'row_id' => $id,
        'call_group_id' => $callId,
        'unique_id' => $uniqueId,
        'start_value' => columnExpression($columns, 'start'),
        'answer_value' => columnExpression($columns, 'answer'),
        'end_value' => columnExpression($columns, 'endtime'),
        'src_num_value' => columnExpression($columns, 'src_num'),
        'dst_num_value' => columnExpression($columns, 'dst_num'),
        'did_value' => columnExpression($columns, 'did'),
        'disposition_value' => columnExpression($columns, 'disposition'),
        'duration_value' => columnExpression($columns, 'duration', '0'),
        'billsec_value' => columnExpression($columns, 'billsec', '0'),
        'recording_value' => columnExpression($columns, 'recordingfile'),
    ];
    $select = [];
    foreach ($fields as $alias => $expression) {
        $select[] = $expression . ' AS ' . quoteIdentifier($alias);
    }
    $sql = 'SELECT ' . implode(', ', $select) .
        ' FROM ' . quoteIdentifier($table['name']) .
        ' WHERE ' . columnExpression($columns, 'start') . ' >= :from' .
        ' ORDER BY ' . $callId . ', ' . columnExpression($columns, 'start') . ', ' . $id;
    $statement = $pdo->prepare($sql);
    $statement->execute(['from' => $from]);

    $outputDir = dirname($output);
    if (!is_dir($outputDir) || !is_writable($outputDir)) {
        throw new RuntimeException('Каталог результата недоступен для записи: ' . $outputDir);
    }
    $temporary = tempnam($outputDir, '.' . basename($output) . '.tmp-');
    if ($temporary === false) {
        throw new RuntimeException('Не удалось создать временный XML-файл.');
    }

    $writer = new XMLWriter();
    $rows = 0;
    $calls = 0;
    $skipped = 0;
    $currentCall = null;
    try {
        if (!$writer->openUri($temporary)) {
            throw new RuntimeException('Не удалось открыть временный XML-файл.');
        }
        $writer->setIndent(true);
        $writer->setIndentString('  ');
        $writer->startDocument('1.0', 'UTF-8');
        $writer->startElement('history');

        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $callGroupId = trim((string) $row['call_group_id']);
            if ($callGroupId === '') {
                $skipped++;
                continue;
            }
            if ($currentCall !== $callGroupId) {
                if ($currentCall !== null) {
                    $writer->endElement();
                }
                $currentCall = $callGroupId;
                $calls++;
                $writer->startElement('history_record');
                $writer->writeAttribute('no', $callGroupId);
                $writer->writeAttribute('entire_id', $callGroupId);
                $writer->writeAttribute('line', (string) $row['did_value']);
                $writer->writeAttribute('line_number', (string) $row['did_value']);
            }

            writeDetail($writer, $row, $callGroupId);
            $rows++;
        }
        if ($currentCall !== null) {
            $writer->endElement();
        }
        $writer->endElement();
        $writer->endDocument();
        $writer->flush();

        if (!rename($temporary, $output)) {
            throw new RuntimeException('Не удалось заменить итоговый XML-файл: ' . $output);
        }
    } catch (Throwable $exception) {
        if (is_file($temporary)) {
            unlink($temporary);
        }
        throw $exception;
    }

    return ['rows' => $rows, 'calls' => $calls, 'skipped' => $skipped];
}

function writeDetail(XMLWriter $writer, array $row, string $callGroupId): void
{
    $writer->startElement('details');
    $writer->writeAttribute('call_id', $callGroupId);
    $writer->writeAttribute('status', strtoupper((string) $row['disposition_value']) === 'ANSWERED' ? 'ANSWER' : 'CANCEL');
    $writer->writeAttribute('call_flow', '');
    $writer->writeAttribute('queue', '');
    $writer->writeAttribute('start', (string) $row['start_value']);
    $writer->writeAttribute('started', toRfc3339((string) $row['start_value']));
    $writer->writeAttribute('answered', toRfc3339((string) $row['answer_value']));
    $writer->writeAttribute('finished', toRfc3339((string) $row['end_value']));
    $writer->writeAttribute('duration', (string) $row['duration_value']);
    $writer->writeAttribute('conversation', (string) $row['billsec_value']);
    $writer->writeAttribute('record_file', (string) $row['recording_value']);
    $writer->writeAttribute('finish_cause', 'Normal Clearing');
    $writer->endElement();
    $writer->startElement('from');
    $writer->writeAttribute('ext', '');
    $writer->writeAttribute('number', (string) $row['src_num_value']);
    $writer->endElement();
    $writer->startElement('to');
    $writer->writeAttribute('ext', '');
    $writer->writeAttribute('number', (string) $row['dst_num_value']);
    $writer->endElement();
}

function toRfc3339(string $value): string
{
    if (trim($value) === '') {
        return '';
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        return '';
    }
    return $date->setTimezone(new DateTimeZone(date_default_timezone_get()))->format(DateTimeInterface::RFC3339);
}

function main(array $arguments): int
{
    try {
        $options = parseArguments($arguments);
        if ($options['help']) {
            printUsage();
            return 0;
        }
        if ($options['output'] === '') {
            throw new InvalidArgumentException('Не указан обязательный параметр --output.');
        }
        $from = validateDate($options['from']);
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            throw new RuntimeException('В PHP недоступен драйвер PDO SQLite.');
        }
        if (!is_file($options['database'])) {
            throw new RuntimeException('База CDR не найдена: ' . $options['database']);
        }
        if (!is_readable($options['database'])) {
            throw new RuntimeException('База CDR недоступна для чтения: ' . $options['database']);
        }

        $pdo = new PDO('sqlite:' . $options['database'], null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA query_only = ON');
        $table = findCdrTable($pdo);
        $stats = exportCdr($pdo, $table, $from, $options['output']);
        fwrite(STDOUT, sprintf(
            "Готово: строк %d, звонков %d, пропущено %d. Файл: %s\n",
            $stats['rows'],
            $stats['calls'],
            $stats['skipped'],
            $options['output']
        ));
        return 0;
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Ошибка: ' . $exception->getMessage() . PHP_EOL);
        return 1;
    }
}

exit(main($argv));
