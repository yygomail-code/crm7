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

    /**
     * Как build(), но для XLS/XLSX каждый раздел пишется на отдельный лист.
     * Форматы без листов (CSV/TXT/PDF) собираются как обычно.
     *
     * @param array<int, array{title?: string, headers: array<int, string>, rows: array<int, array<int, string>>}> $sections
     * @param array<int, string> $meta
     * @return array{content: string, file_name: string, mime: string}
     */
    public static function buildPerSheet(string $baseName, string $format, array $sections, array $meta = []): array
    {
        $format = self::normalizeFormat($format);

        if (!in_array($format, ['xls', 'xlsx'], true) || count($sections) <= 1) {
            return self::build($baseName, $format, $sections, $meta);
        }

        $content = self::buildSpreadsheetPerSheet($sections, $meta, $format === 'xls' ? 'Xls' : 'Xlsx');

        return [
            'content' => $content,
            'file_name' => $baseName . '.' . $format,
            'mime' => self::MIME[$format],
        ];
    }

    private static function buildSpreadsheetPerSheet(array $sections, array $meta, string $writerType): string
    {
        $spreadsheet = self::newSpreadsheet();
        $spreadsheet->removeSheetByIndex(0);

        $used = [];

        foreach ($sections as $index => $section) {
            $title = self::sheetTitle((string) ($section['title'] ?? ''), $index, $used);
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($title);

            $row = 1;

            if ($index === 0 && $meta !== []) {
                foreach ($meta as $line) {
                    $sheet->setCellValue('A' . $row, (string) $line);
                    $sheet->getStyle('A' . $row)->getFont()->setSize(9)->setColor(
                        new \PhpOffice\PhpSpreadsheet\Style\Color('FF6B7280')
                    );
                    $row++;
                }

                $row++;
            }

            $headers = array_map('strval', $section['headers']);
            $lastColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(max(1, count($headers)));

            self::writeSpreadsheetRow($sheet, $headers, $row);
            $sheet->getStyle('A' . $row . ':' . $lastColumn . $row)->getFont()->setBold(true);
            $row++;

            foreach ($section['rows'] as $data) {
                self::writeSpreadsheetRow($sheet, array_map('strval', $data), $row);
                $row++;
            }

            foreach (range('A', $lastColumn) as $column) {
                $sheet->getColumnDimension($column)->setAutoSize(true);
            }
        }

        if ($spreadsheet->getSheetCount() === 0) {
            $spreadsheet->createSheet();
        }

        $spreadsheet->setActiveSheetIndex(0);

        $writerClass = 'PhpOffice\\PhpSpreadsheet\\Writer\\' . $writerType;

        ob_start();
        (new $writerClass($spreadsheet))->save('php://output');
        $content = (string) ob_get_clean();

        $spreadsheet->disconnectWorksheets();

        return $content;
    }

    private static function sheetTitle(string $title, int $index, array &$used): string
    {
        $title = trim(preg_replace('/[\\\\\\/\\*\\?\\:\\[\\]]/u', ' ', $title) ?? '');

        if ($title === '') {
            $title = 'Лист ' . ($index + 1);
        }

        $title = mb_substr($title, 0, 31, 'UTF-8');

        if (isset($used[$title])) {
            $used[$title]++;
            $suffix = ' (' . $used[$title] . ')';
            $title = mb_substr($title, 0, 31 - mb_strlen($suffix, 'UTF-8'), 'UTF-8') . $suffix;
        } else {
            $used[$title] = 1;
        }

        return $title;
    }

    private static function newSpreadsheet(): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        $vendor = (string) Config::get('LEGACY_VENDOR', dirname(__DIR__, 3) . '/site-old/back/vendor/autoload.php');

        if (!is_file($vendor)) {
            throw new HttpException(422, 'excel_unavailable', 'Excel недоступен: библиотека не найдена, выберите CSV, TXT или PDF');
        }

        require_once $vendor;

        if (!class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet')) {
            throw new HttpException(422, 'excel_unavailable', 'Excel недоступен: библиотека не найдена, выберите CSV, TXT или PDF');
        }

        return new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    }

    private static function buildCsv(array $sections, array $meta): string    {
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

            self::writeSpreadsheetRow($sheet, $headers, $row);
            $sheet->getStyle('A' . $row . ':' . $lastColumn . $row)->getFont()->setBold(true);
            $row++;

            foreach ($section['rows'] as $data) {
                self::writeSpreadsheetRow($sheet, array_map('strval', $data), $row);
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

    private static function writeSpreadsheetRow(
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
        array $values,
        int $row
    ): void {
        $column = 1;

        foreach ($values as $value) {
            $text = (string) $value;
            $coordinate = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($column) . $row;

            if (self::looksLikeFormula($text)) {
                $sheet->setCellValueExplicit(
                    $coordinate,
                    $text,
                    \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
                );
            } else {
                $sheet->setCellValue($coordinate, $text);
            }

            $column++;
        }
    }

    private static function looksLikeFormula(string $value): bool
    {
        if ($value === '' || !in_array($value[0], ['=', '+', '-', '@'], true)) {
            return false;
        }

        return preg_match('/^[+-]?\d+(?:[.,]\d+)?$/', $value) !== 1;
    }

    private static function csvCell(string $value): string
    {
        $value = str_replace(['"', "\r", "\n"], ['""', ' ', ' '], $value);

        if (self::looksLikeFormula($value)) {
            $value = "'" . $value;
        }

        return str_contains($value, ';') ? '"' . $value . '"' : $value;
    }
}
