<?php

declare(strict_types=1);

namespace App\Export;

use App\Core\Config;
use App\Http\HttpException;
use RuntimeException;
use Throwable;

final class TableExport
{
    public const FORMATS = ['csv', 'txt', 'xls', 'xlsx', 'pdf'];

    private const MIME = [
        'csv' => 'text/csv; charset=UTF-8',
        'txt' => 'text/plain; charset=UTF-8',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pdf' => 'application/pdf',
    ];

    public static function normalizeFormat(string $format): string
    {
        $format = strtolower(trim($format));

        return in_array($format, self::FORMATS, true) ? $format : 'csv';
    }

    /**
     * @param array<int, array{title?: string, headers: array<int, string>, rows: array<int, array<int, string>>}> $sections
     * @param array<int, string> $meta
     * @return array{content: string, file_name: string, mime: string}
     */
    public static function build(string $baseName, string $format, array $sections, array $meta = []): array
    {
        $format = self::normalizeFormat($format);

        $content = match ($format) {
            'txt' => self::buildTxt($sections, $meta),
            'xls' => self::buildSpreadsheet($sections, $meta, 'Xls'),
            'xlsx' => self::buildSpreadsheet($sections, $meta, 'Xlsx'),
            'pdf' => self::buildPdf($baseName, $sections, $meta),
            default => self::buildCsv($sections, $meta),
        };

        return [
            'content' => $content,
            'file_name' => $baseName . '.' . $format,
            'mime' => self::MIME[$format],
        ];
    }

    private static function buildCsv(array $sections, array $meta): string
    {
        $lines = [];

        foreach ($meta as $line) {
            $lines[] = self::csvCell((string) $line);
        }

        if ($meta !== []) {
            $lines[] = '';
        }

        foreach ($sections as $section) {
            if (!empty($section['title'])) {
                $lines[] = self::csvCell((string) $section['title']);
            }

            $lines[] = implode(';', array_map([self::class, 'csvCell'], $section['headers']));

            foreach ($section['rows'] as $row) {
                $lines[] = implode(';', array_map([self::class, 'csvCell'], array_map('strval', $row)));
            }

            $lines[] = '';
        }

        return "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n";
    }

    private static function buildTxt(array $sections, array $meta): string
    {
        $lines = [];

        foreach ($meta as $line) {
            $lines[] = (string) $line;
        }

        foreach ($sections as $section) {
            if ($lines !== []) {
                $lines[] = '';
            }

            if (!empty($section['title'])) {
                $lines[] = (string) $section['title'];
            }

            $headers = array_map('strval', $section['headers']);
            $widths = self::textWidths($headers, $section['rows']);

            $lines[] = self::padRow($headers, $widths);
            $lines[] = implode('  ', array_map(static fn (int $width): string => str_repeat('-', $width), $widths));

            foreach ($section['rows'] as $row) {
                $lines[] = self::padRow(array_map('strval', $row), $widths);
            }
        }

        return implode("\r\n", $lines) . "\r\n";
    }

    private static function textWidths(array $headers, array $rows): array
    {
        $widths = array_fill(0, count($headers), 0);

        foreach (array_merge([$headers], array_slice($rows, 0, 500)) as $row) {
            foreach ($headers as $i => $_) {
                $widths[$i] = max($widths[$i], mb_strwidth((string) ($row[$i] ?? ''), 'UTF-8'));
            }
        }

        return array_map(static fn (int $width): int => max(3, min(60, $width)), $widths);
    }

    private static function padRow(array $row, array $widths): string
    {
        $cells = [];

        foreach ($widths as $i => $width) {
            $value = (string) ($row[$i] ?? '');

            if (mb_strwidth($value, 'UTF-8') > $width) {
                $value = mb_strimwidth($value, 0, max(1, $width - 1), '…', 'UTF-8');
            }

            $cells[] = $value . str_repeat(' ', max(0, $width - mb_strwidth($value, 'UTF-8')));
        }

        return rtrim(implode('  ', $cells));
    }

    private static function buildSpreadsheet(array $sections, array $meta, string $writerType): string
    {
        $vendor = (string) Config::get('LEGACY_VENDOR', dirname(__DIR__, 3) . '/site-old/back/vendor/autoload.php');

        if (!is_file($vendor)) {
            throw new HttpException(422, 'excel_unavailable', 'Excel недоступен: библиотека не найдена, выберите CSV, TXT или PDF');
        }

        require_once $vendor;

        if (!class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet')) {
            throw new HttpException(422, 'excel_unavailable', 'Excel недоступен: библиотека не найдена, выберите CSV, TXT или PDF');
        }

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $row = 1;
        $lastColumn = 'A';

        if ($meta !== []) {
            foreach ($meta as $line) {
                $sheet->setCellValue('A' . $row, (string) $line);
                $sheet->getStyle('A' . $row)->getFont()->setSize(9)->setColor(
                    new \PhpOffice\PhpSpreadsheet\Style\Color('FF6B7280')
                );
                $row++;
            }

            $row++;
        }

        foreach ($sections as $section) {
            $headers = array_map('strval', $section['headers']);
            $lastColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(max(1, count($headers)));

            if (!empty($section['title'])) {
                $sheet->setCellValue('A' . $row, (string) $section['title']);
                $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(12);
                $row++;
            }

            $sheet->fromArray($headers, null, 'A' . $row);
            $sheet->getStyle('A' . $row . ':' . $lastColumn . $row)->getFont()->setBold(true);
            $row++;

            foreach ($section['rows'] as $data) {
                $sheet->fromArray(array_map('strval', $data), null, 'A' . $row);
                $row++;
            }

            $row++;
        }

        foreach (range('A', $lastColumn) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $writerClass = 'PhpOffice\\PhpSpreadsheet\\Writer\\' . $writerType;

        ob_start();
        (new $writerClass($spreadsheet))->save('php://output');
        $content = (string) ob_get_clean();

        $spreadsheet->disconnectWorksheets();

        return $content;
    }

    private static function buildPdf(string $baseName, array $sections, array $meta): string
    {
        try {
            return PdfWriter::render($baseName, $sections, $meta);
        } catch (RuntimeException $exception) {
            throw new HttpException(422, 'pdf_unavailable', 'PDF недоступен: ' . $exception->getMessage());
        } catch (Throwable $exception) {
            throw new HttpException(500, 'pdf_failed', 'Не удалось сформировать PDF: ' . $exception->getMessage());
        }
    }

    private static function csvCell(string $value): string
    {
        $value = str_replace(['"', "\r", "\n"], ['""', ' ', ' '], $value);

        return str_contains($value, ';') ? '"' . $value . '"' : $value;
    }
}
