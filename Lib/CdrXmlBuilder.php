<?php

namespace Modules\ModuleMtsPbx\Lib;

use DateTime;

/**
 * Формирование XML-ответа GET /pbxcore/mts-pbx/cdr для обработки 1С.
 */
final class CdrXmlBuilder
{
    /**
     * Размер страницы выдачи. Параметр запроса `limit` не используется: обработка 1С шлёт limit=450,
     * но фильтр `id > offset OR start >= сегодня` всегда возвращает и сегодняшние строки с id <= offset,
     * поэтому маленький лимит может целиком заполниться ими и выдача перестанет продвигаться.
     */
    public const PAGE_LIMIT = 5000;

    /**
     * @param array $rows   строки mts_cdr (toArray())
     * @param int   $offset значение offset из запроса
     *
     * @return array{xml: string, maxOffset: int}
     */
    public static function build(array $rows, int $offset): array
    {
        $maxOffset = $offset;
        foreach ($rows as $row) {
            $maxOffset = max($maxOffset, (int)($row['id'] ?? 0));
        }

        usort($rows, static function ($a, $b) {
            return strtotime((string)($a['start'] ?? '')) <=> strtotime((string)($b['start'] ?? ''));
        });

        $xml = '<history>' . PHP_EOL;
        foreach ($rows as $row) {
            $linkedId = (string)($row['linkedid'] ?? '');
            if (strpos($linkedId, MtsCallId::PREFIX) !== 0) {
                continue;
            }
            $xml .= '<history_record' . self::attributes([
                'no'        => $linkedId,
                'entire_id' => $linkedId,
                'line'      => $row['did'] ?? '',
            ]) . '>';
            $xml .= '<details' . self::attributes([
                'call_id'      => $linkedId,
                'status'       => ($row['disposition'] ?? '') === 'ANSWERED' ? 'ANSWER' : 'CANCEL',
                'call_flow'    => '',
                'queue'        => '',
                'start'        => $row['start'] ?? '',
                'started'      => self::isoDate($row['start'] ?? ''),
                'answered'     => self::isoDate($row['answer'] ?? ''),
                'finished'     => self::isoDate($row['endtime'] ?? ''),
                'duration'     => $row['duration'] ?? '',
                'conversation' => $row['billsec'] ?? '',
                'record_file'  => $row['recordingfile'] ?? '',
                'finish_cause' => 'Normal Clearing',
            ]) . ' />';
            $xml .= '<from' . self::attributes(['ext' => '', 'number' => $row['src_num'] ?? '']) . '></from>';
            $xml .= '<to' . self::attributes(['ext' => '', 'number' => $row['dst_num'] ?? '']) . '></to>';
            $xml .= '</history_record>' . PHP_EOL;
        }
        $xml .= '</history>' . PHP_EOL;

        return ['xml' => $xml, 'maxOffset' => $maxOffset];
    }

    private static function attributes(array $attributes): string
    {
        $result = '';
        foreach ($attributes as $name => $value) {
            $result .= ' ' . $name . '="' . htmlspecialchars((string)$value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }
        return $result;
    }

    private static function isoDate($value): string
    {
        try {
            return (new DateTime((string)$value))->format('c');
        } catch (\Throwable $e) {
            return '';
        }
    }
}
