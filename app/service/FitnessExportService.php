<?php
declare(strict_types=1);

namespace app\service;

/**
 * 全民健身模块记录导出
 *
 * 统一输出带 UTF-8 BOM 的 CSV，Excel 可直接打开且不乱码。
 */
class FitnessExportService
{
    /**
     * @param array<int, string> $headers
     * @param array<int, array<int, mixed>> $rows
     */
    public static function buildCsv(array $headers, array $rows): string
    {
        $handle = fopen('php://temp', 'rb+');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, $headers);
        foreach ($rows as $row) {
            fputcsv($handle, array_map(static function ($value) {
                return is_scalar($value) ? (string) $value : '';
            }, $row));
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /** @return array{filename:string,mime:string,encoding:string,content:string,count:int} */
    public static function payload(string $filename, array $headers, array $rows): array
    {
        return [
            'filename' => $filename,
            'mime'     => 'text/csv',
            'encoding' => 'utf-8',
            'content'  => self::buildCsv($headers, $rows),
            'count'    => count($rows),
        ];
    }
}
