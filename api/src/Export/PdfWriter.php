<?php

declare(strict_types=1);

namespace App\Export;

use App\Core\Config;
use RuntimeException;

final class PdfWriter
{
    private const PORTRAIT_WIDTH = 595.28;
    private const PORTRAIT_HEIGHT = 841.89;
    private const LANDSCAPE_WIDTH = 841.89;
    private const LANDSCAPE_HEIGHT = 595.28;
    private const MARGIN = 36.0;
    private const BOTTOM = 52.0;
    private const BASE_FONT_SIZE = 8.5;
    private const FONT_SIZES = [8.5, 8.0, 7.5, 7.0, 6.5];
    private const MAX_COLUMN = 240.0;
    private const MAX_MIN_COLUMN = 150.0;

    private float $pageWidth = self::PORTRAIT_WIDTH;
    private float $pageHeight = self::PORTRAIT_HEIGHT;

    private string $fontData = '';
    private string $fontName = 'Embedded';
    private int $unitsPerEm = 1000;
    private int $numHMetrics = 0;
    private int $hmtxOffset = 0;
    private int $cmapOffset = 0;
    private int $cmapFormat = 4;
    private float $ascent = 905.0;
    private float $descent = -212.0;
    private array $bbox = [0, -212, 1000, 905];
    private float $capHeight = 716.0;
    private array $usedGids = [];

    private function __construct(string $fontPath)
    {
        $this->loadFont($fontPath);
    }

    public static function render(string $documentTitle, array $sections, array $meta = []): string
    {
        return (new self(self::fontPath()))->build($documentTitle, $sections, $meta);
    }

    private static function fontPath(): string
    {
        $candidates = array_filter([
            (string) Config::get('PDF_FONT_PATH', ''),
            'C:\\Windows\\Fonts\\arial.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
            '/usr/share/fonts/dejavu/DejaVuSans.ttf',
        ]);

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        throw new RuntimeException('Не найден TTF-шрифт для PDF; укажите PDF_FONT_PATH');
    }

    private function build(string $documentTitle, array $sections, array $meta): string
    {
        $sections = array_values(array_filter(
            $sections,
            static fn (array $section): bool => !empty($section['headers'])
        ));

        $padX = 4.0;
        $padY = 3.0;
        $titleSize = 13.0;
        $metaSize = 8.5;

        [$this->pageWidth, $this->pageHeight, $fontSize] = $this->chooseLayout($sections, $padX);
        $lineHeight = round($fontSize * 1.35, 2);
        $available = $this->pageWidth - 2 * self::MARGIN;

        $pages = [];
        $ops = [];
        $y = $this->pageHeight - self::MARGIN;

        $newPage = function () use (&$pages, &$ops, &$y): void {
            $pages[] = $ops;
            $ops = [];
            $y = $this->pageHeight - self::MARGIN;
        };

        $ops[] = $this->text($documentTitle, self::MARGIN, $y - $titleSize, $titleSize);
        $y -= $titleSize + 6;

        foreach ($meta as $line) {
            $ops[] = $this->text((string) $line, self::MARGIN, $y - $metaSize, $metaSize, [0.35, 0.38, 0.42]);
            $y -= $metaSize + 3;
        }

        $y -= 8;

        foreach ($sections as $section) {
            $headers = array_values($section['headers']);
            $rows = array_values($section['rows'] ?? []);
            $widths = $this->columnWidths($headers, $rows, $fontSize, $padX, $available);
            $tableWidth = array_sum($widths);
            $headerHeight = $lineHeight + 2 * $padY;
            $sectionTitle = trim((string) ($section['title'] ?? ''));

            if ($y - ($sectionTitle !== '' ? $lineHeight + 6 : 0) - $headerHeight - $lineHeight < self::BOTTOM) {
                $newPage();
            }

            if ($sectionTitle !== '') {
                $ops[] = $this->text($sectionTitle, self::MARGIN, $y - $fontSize - 1, $fontSize + 1.5);
                $y -= $lineHeight + 6;
            }

            $drawHeader = function () use (&$ops, &$y, $headers, $widths, $fontSize, $lineHeight, $padX, $padY, $headerHeight, $tableWidth): void {
                $ops[] = $this->rect(self::MARGIN, $y - $headerHeight, $tableWidth, $headerHeight, [0.94, 0.95, 0.97]);
                $x = self::MARGIN;

                foreach ($headers as $i => $header) {
                    $ops[] = $this->text((string) $header, $x + $padX, $y - $padY - $fontSize, $fontSize);
                    $x += $widths[$i];
                }

                $ops[] = $this->line(
                    self::MARGIN,
                    $y - $headerHeight,
                    self::MARGIN + $tableWidth,
                    $y - $headerHeight,
                    [0.72, 0.75, 0.78],
                    0.6
                );

                $y -= $headerHeight;
            };

            $drawHeader();

            foreach ($rows as $row) {
                $cells = [];
                $maxLines = 1;

                foreach ($headers as $i => $_) {
                    $lines = $this->wrap((string) ($row[$i] ?? ''), $widths[$i] - 2 * $padX, $fontSize);
                    $cells[] = $lines;
                    $maxLines = max($maxLines, count($lines));
                }

                $rowHeight = $maxLines * $lineHeight + 2 * $padY;

                if ($y - $rowHeight < self::BOTTOM) {
                    $newPage();
                    $drawHeader();
                }

                $x = self::MARGIN;

                foreach ($cells as $i => $lines) {
                    $baseline = $y - $padY - $fontSize;

                    foreach ($lines as $line) {
                        $ops[] = $this->text($line, $x + $padX, $baseline, $fontSize);
                        $baseline -= $lineHeight;
                    }

                    $x += $widths[$i];
                }

                $y -= $rowHeight;

                $ops[] = $this->line(self::MARGIN, $y, self::MARGIN + $tableWidth, $y, [0.88, 0.9, 0.92], 0.4);
            }

            $y -= 10;
        }

        $pages[] = $ops;
        $total = count($pages);
        $streams = [];

        foreach ($pages as $index => $pageOps) {
            $pageOps[] = $this->text(
                'Стр. ' . ($index + 1) . ' из ' . $total,
                self::MARGIN,
                self::MARGIN - 16,
                8.0,
                [0.5, 0.52, 0.55]
            );

            $streams[] = implode("\n", array_filter($pageOps, static fn (string $op): bool => $op !== ''));
        }

        return $this->assemble($streams);
    }

    /** @return array{0: float, 1: float, 2: float} */
    private function chooseLayout(array $sections, float $padX): array
    {
        $portraitAvailable = self::PORTRAIT_WIDTH - 2 * self::MARGIN;
        $landscapeAvailable = self::LANDSCAPE_WIDTH - 2 * self::MARGIN;
        $needed = $this->maxMinTotal($sections, self::BASE_FONT_SIZE, $padX);

        $landscape = $needed > $portraitAvailable && $landscapeAvailable > $portraitAvailable;
        $width = $landscape ? self::LANDSCAPE_WIDTH : self::PORTRAIT_WIDTH;
        $height = $landscape ? self::LANDSCAPE_HEIGHT : self::PORTRAIT_HEIGHT;
        $available = $width - 2 * self::MARGIN;
        $fontSize = self::FONT_SIZES[count(self::FONT_SIZES) - 1];

        foreach (self::FONT_SIZES as $size) {
            $fontSize = $size;

            if ($this->maxMinTotal($sections, $size, $padX) <= $available) {
                break;
            }
        }

        return [$width, $height, $fontSize];
    }

    private function maxMinTotal(array $sections, float $size, float $padX): float
    {
        $max = 0.0;

        foreach ($sections as $section) {
            $headers = array_values($section['headers']);
            $rows = array_values($section['rows'] ?? []);
            $max = max($max, array_sum($this->minWidths($headers, $rows, $size, $padX)));
        }

        return $max;
    }

    private function minWidths(array $headers, array $rows, float $size, float $padX): array
    {
        $widths = array_fill(0, count($headers), 0.0);

        foreach ($headers as $i => $header) {
            $widths[$i] = $this->longestWordWidth((string) $header, $size) + 2 * $padX;
        }

        foreach (array_slice($rows, 0, 200) as $row) {
            foreach ($headers as $i => $_) {
                $widths[$i] = max($widths[$i], $this->longestWordWidth((string) ($row[$i] ?? ''), $size) + 2 * $padX);
            }
        }

        return array_map(
            static fn (float $width): float => max(28.0, min($width, self::MAX_MIN_COLUMN)),
            $widths
        );
    }

    private function longestWordWidth(string $text, float $size): float
    {
        $longest = 0.0;

        foreach (preg_split('/\s+/u', trim($text)) ?: [] as $word) {
            $longest = max($longest, $this->textWidth($word, $size));
        }

        return $longest;
    }

    private function columnWidths(array $headers, array $rows, float $size, float $padX, float $available): array
    {
        $minimums = $this->minWidths($headers, $rows, $size, $padX);
        $natural = [];

        foreach ($headers as $i => $header) {
            $natural[$i] = min($this->textWidth((string) $header, $size), self::MAX_COLUMN) + 2 * $padX;
        }

        foreach (array_slice($rows, 0, 200) as $row) {
            foreach ($headers as $i => $_) {
                $natural[$i] = max(
                    $natural[$i],
                    min($this->textWidth((string) ($row[$i] ?? ''), $size), self::MAX_COLUMN) + 2 * $padX
                );
            }
        }

        $minTotal = array_sum($minimums);

        if ($minTotal > $available) {
            $factor = $available / $minTotal;

            return array_map(static fn (float $width): float => $width * $factor, $minimums);
        }

        $pool = 0.0;

        foreach ($natural as $i => $width) {
            $pool += max(0.0, $width - $minimums[$i]);
        }

        $extra = $available - $minTotal;

        if ($pool <= 0.0) {
            return $minimums;
        }

        $widths = [];

        foreach ($natural as $i => $width) {
            $widths[$i] = $minimums[$i] + max(0.0, $width - $minimums[$i]) * ($extra / $pool);
        }

        return $widths;
    }

    private function wrap(string $text, float $maxWidth, float $size): array
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if ($text === '') {
            return [''];
        }

        $words = $this->splitLongWords(explode(' ', $text), $maxWidth, $size);
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;

            if ($this->textWidth($candidate, $size) <= $maxWidth) {
                $current = $candidate;
            } else {
                if ($current !== '') {
                    $lines[] = $current;
                }

                $current = $word;
            }
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines !== [] ? $lines : [''];
    }

    private function splitLongWords(array $words, float $maxWidth, float $size): array
    {
        $result = [];

        foreach ($words as $word) {
            if ($this->textWidth($word, $size) <= $maxWidth) {
                $result[] = $word;
                continue;
            }

            $chunk = '';
            $length = mb_strlen($word, 'UTF-8');

            for ($i = 0; $i < $length; $i++) {
                $char = mb_substr($word, $i, 1, 'UTF-8');
                $candidate = $chunk . $char;

                if ($chunk !== '' && $this->textWidth($candidate, $size) > $maxWidth) {
                    $result[] = $chunk;
                    $chunk = $char;
                } else {
                    $chunk = $candidate;
                }
            }

            if ($chunk !== '') {
                $result[] = $chunk;
            }
        }

        return $result;
    }

    private function textWidth(string $text, float $size): float
    {
        $width = 0;
        $length = mb_strlen($text, 'UTF-8');

        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($text, $i, 1, 'UTF-8');
            $code = mb_ord($char, 'UTF-8');
            $width += $this->advance($code !== false ? $this->gidFor($code) : 0);
        }

        return $width * $size / $this->unitsPerEm;
    }

    private function text(string $value, float $x, float $baseline, float $size, array $color = [0, 0, 0]): string
    {
        if ($value === '') {
            return '';
        }

        return sprintf(
            '%.3f %.3f %.3f rg BT /F1 %.2f Tf 1 0 0 1 %.2f %.2f Tm <%s> Tj ET 0 0 0 rg',
            $color[0],
            $color[1],
            $color[2],
            $size,
            $x,
            $baseline,
            $this->encode($value)
        );
    }

    private function rect(float $x, float $y, float $w, float $h, array $color): string
    {
        return sprintf(
            '%.3f %.3f %.3f rg %.2f %.2f %.2f %.2f re f 0 0 0 rg',
            $color[0],
            $color[1],
            $color[2],
            $x,
            $y,
            $w,
            $h
        );
    }

    private function line(float $x1, float $y1, float $x2, float $y2, array $color, float $width): string
    {
        return sprintf(
            '%.3f %.3f %.3f RG %.2f w %.2f %.2f m %.2f %.2f l S 0 0 0 RG',
            $color[0],
            $color[1],
            $color[2],
            $width,
            $x1,
            $y1,
            $x2,
            $y2
        );
    }

    private function encode(string $value): string
    {
        $hex = '';
        $length = mb_strlen($value, 'UTF-8');

        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($value, $i, 1, 'UTF-8');
            $code = mb_ord($char, 'UTF-8');
            $gid = $code !== false ? $this->gidFor($code) : 0;

            if ($gid === 0) {
                $gid = $this->gidFor(63);
            }

            $this->usedGids[$gid] = $code !== false ? $code : 63;
            $hex .= sprintf('%04X', $gid);
        }

        return $hex;
    }

    private function loadFont(string $path): void
    {
        $data = file_get_contents($path);

        if ($data === false || strlen($data) < 12) {
            throw new RuntimeException('Не удалось прочитать шрифт: ' . $path);
        }

        $this->fontData = $data;
        $this->fontName = ucfirst((string) preg_replace('/[^A-Za-z0-9]/', '', (string) pathinfo($path, PATHINFO_FILENAME)));

        if ($this->fontName === '') {
            $this->fontName = 'Embedded';
        }

        $tables = $this->tableDirectory($data);
        $head = $tables['head'] ?? null;
        $hhea = $tables['hhea'] ?? null;
        $hmtx = $tables['hmtx'] ?? null;
        $cmap = $tables['cmap'] ?? null;

        if ($head === null || $hhea === null || $hmtx === null || $cmap === null) {
            throw new RuntimeException('Некорректный TTF-шрифт: ' . $path);
        }

        $this->unitsPerEm = max(1, self::u16($data, $head + 18));
        $scale = 1000 / $this->unitsPerEm;

        $this->bbox = [
            (int) round(self::i16($data, $head + 36) * $scale),
            (int) round(self::i16($data, $head + 38) * $scale),
            (int) round(self::i16($data, $head + 40) * $scale),
            (int) round(self::i16($data, $head + 42) * $scale),
        ];

        $this->ascent = round(self::i16($data, $hhea + 4) * $scale, 1);
        $this->descent = round(self::i16($data, $hhea + 6) * $scale, 1);
        $this->capHeight = round(self::i16($data, $hhea + 4) * $scale * 0.72, 1);
        $this->numHMetrics = max(1, self::u16($data, $hhea + 34));
        $this->hmtxOffset = $hmtx;

        if (isset($tables['OS/2']) && $tables['OS/2'] > 0) {
            $version = self::u16($data, $tables['OS/2']);

            if ($version >= 2 && $tables['OS/2'] + 88 <= strlen($data)) {
                $cap = self::i16($data, $tables['OS/2'] + 88);

                if ($cap > 0) {
                    $this->capHeight = round($cap * $scale, 1);
                }
            }
        }

        $this->parseCmap($data, $cmap);
    }

    private function tableDirectory(string $data): array
    {
        $count = self::u16($data, 4);
        $tables = [];

        for ($i = 0; $i < $count; $i++) {
            $offset = 12 + $i * 16;

            if ($offset + 16 > strlen($data)) {
                break;
            }

            $tag = substr($data, $offset, 4);

            if ($tag === false || trim($tag) === '') {
                continue;
            }

            $tables[$tag] = self::u32($data, $offset + 8);
        }

        return $tables;
    }

    private function parseCmap(string $data, int $cmapOffset): void
    {
        $count = self::u16($data, $cmapOffset + 2);
        $bestScore = -1;

        for ($i = 0; $i < $count; $i++) {
            $record = $cmapOffset + 4 + $i * 8;

            if ($record + 8 > strlen($data)) {
                break;
            }

            $platform = self::u16($data, $record);
            $encoding = self::u16($data, $record + 2);
            $offset = $cmapOffset + self::u32($data, $record + 4);

            if ($offset + 4 > strlen($data)) {
                continue;
            }

            $format = self::u16($data, $offset);
            $score = match (true) {
                $platform === 3 && $encoding === 10 && $format === 12 => 5,
                $platform === 0 && $format === 12 => 4,
                $platform === 3 && $encoding === 1 && $format === 4 => 3,
                $platform === 0 && $format === 4 => 2,
                $platform === 3 && $encoding === 0 && $format === 4 => 1,
                default => -1,
            };

            if ($score > $bestScore) {
                $bestScore = $score;
                $this->cmapOffset = $offset;
                $this->cmapFormat = $format;
            }
        }

        if ($bestScore < 0) {
            $this->cmapOffset = 0;
        }
    }

    private function gidFor(int $code): int
    {
        if ($this->cmapOffset === 0) {
            return 0;
        }

        return $this->cmapFormat === 12
            ? $this->gidFromFormat12($code)
            : $this->gidFromFormat4($code);
    }

    private function gidFromFormat4(int $code): int
    {
        if ($code > 0xFFFF) {
            return 0;
        }

        $data = $this->fontData;
        $offset = $this->cmapOffset;
        $segCount = self::u16($data, $offset + 6) / 2;
        $endCodes = $offset + 14;
        $startCodes = $endCodes + $segCount * 2 + 2;
        $idDeltas = $startCodes + $segCount * 2;
        $idRangeOffsets = $idDeltas + $segCount * 2;
        $segment = -1;

        for ($i = 0; $i < $segCount; $i++) {
            if (self::u16($data, $endCodes + $i * 2) >= $code) {
                $segment = $i;
                break;
            }
        }

        if ($segment < 0) {
            return 0;
        }

        $start = self::u16($data, $startCodes + $segment * 2);

        if ($start > $code) {
            return 0;
        }

        $delta = self::i16($data, $idDeltas + $segment * 2);
        $rangeOffset = self::u16($data, $idRangeOffsets + $segment * 2);

        if ($rangeOffset === 0) {
            return ($code + $delta) & 0xFFFF;
        }

        $address = $idRangeOffsets + $segment * 2 + $rangeOffset + ($code - $start) * 2;

        if ($address + 2 > strlen($data)) {
            return 0;
        }

        $gid = self::u16($data, $address);

        return $gid === 0 ? 0 : ($gid + $delta) & 0xFFFF;
    }

    private function gidFromFormat12(int $code): int
    {
        $data = $this->fontData;
        $offset = $this->cmapOffset;
        $groups = self::u32($data, $offset + 12);
        $low = 0;
        $high = $groups - 1;

        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);
            $record = $offset + 16 + $middle * 12;
            $start = self::u32($data, $record);
            $end = self::u32($data, $record + 4);

            if ($code < $start) {
                $high = $middle - 1;
            } elseif ($code > $end) {
                $low = $middle + 1;
            } else {
                return self::u32($data, $record + 8) + ($code - $start);
            }
        }

        return 0;
    }

    private function advance(int $gid): int
    {
        if ($gid <= 0) {
            return 0;
        }

        $index = min($gid, $this->numHMetrics - 1);
        $address = $this->hmtxOffset + $index * 4;

        if ($address + 2 > strlen($this->fontData)) {
            return 0;
        }

        return self::u16($this->fontData, $address);
    }

    private function width1000(int $gid): int
    {
        return (int) round($this->advance($gid) * 1000 / $this->unitsPerEm);
    }

    private function widthArray(): string
    {
        $parts = [];

        foreach (array_keys($this->usedGids) as $gid) {
            $parts[] = $gid . ' [' . $this->width1000((int) $gid) . ']';
        }

        return implode(' ', $parts);
    }

    private function toUnicode(): string
    {
        $entries = [];

        foreach ($this->usedGids as $gid => $code) {
            $entries[] = sprintf('<%04X> <%04X>', $gid, $code);
        }

        $body = '';

        foreach (array_chunk($entries, 100) as $chunk) {
            $body .= count($chunk) . " beginbfchar\n" . implode("\n", $chunk) . "\nendbfchar\n";
        }

        return "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n"
            . "/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n"
            . "/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n"
            . "1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n"
            . $body
            . "endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend\n";
    }

    private function assemble(array $pageStreams): string
    {
        $pageCount = count($pageStreams);
        $fontBase = 3 + 2 * $pageCount;

        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';

        $kids = [];

        for ($i = 0; $i < $pageCount; $i++) {
            $kids[] = (3 + 2 * $i) . ' 0 R';
        }

        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $pageCount . ' >>';

        for ($i = 0; $i < $pageCount; $i++) {
            $objects[3 + 2 * $i] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2f %.2f] /Resources << /Font << /F1 %d 0 R >> >> /Contents %d 0 R >>',
                $this->pageWidth,
                $this->pageHeight,
                $fontBase,
                4 + 2 * $i
            );

            $objects[4 + 2 * $i] = ['stream' => (string) gzcompress($pageStreams[$i], 9), 'extra' => ''];
        }

        $objects[$fontBase] = sprintf(
            '<< /Type /Font /Subtype /Type0 /BaseFont /%s /Encoding /Identity-H /DescendantFonts [%d 0 R] /ToUnicode %d 0 R >>',
            $this->fontName,
            $fontBase + 1,
            $fontBase + 4
        );

        $objects[$fontBase + 1] = sprintf(
            '<< /Type /Font /Subtype /CIDFontType2 /BaseFont /%s /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /FontDescriptor %d 0 R /CIDToGIDMap /Identity /DW 1000 /W [%s] >>',
            $this->fontName,
            $fontBase + 2,
            $this->widthArray()
        );

        $objects[$fontBase + 2] = sprintf(
            '<< /Type /FontDescriptor /FontName /%s /Flags 32 /FontBBox [%d %d %d %d] /ItalicAngle 0 /Ascent %d /Descent %d /CapHeight %d /StemV 80 /FontFile2 %d 0 R >>',
            $this->fontName,
            $this->bbox[0],
            $this->bbox[1],
            $this->bbox[2],
            $this->bbox[3],
            (int) round($this->ascent),
            (int) round($this->descent),
            (int) round($this->capHeight),
            $fontBase + 3
        );

        $objects[$fontBase + 3] = [
            'stream' => (string) gzcompress($this->fontData, 9),
            'extra' => ' /Length1 ' . strlen($this->fontData),
        ];

        $objects[$fontBase + 4] = ['stream' => (string) gzcompress($this->toUnicode(), 9), 'extra' => ''];

        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];

        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);

            if (is_array($body)) {
                $data = $body['stream'];
                $pdf .= $id . " 0 obj\n<< /Length " . strlen($data) . " /Filter /FlateDecode" . $body['extra'] . " >>\nstream\n"
                    . $data . "\nendstream\nendobj\n";
            } else {
                $pdf .= $id . " 0 obj\n" . $body . "\nendobj\n";
            }
        }

        $maxId = (int) max(array_keys($objects));
        $xrefOffset = strlen($pdf);
        $pdf .= 'xref' . "\n" . '0 ' . ($maxId + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";

        for ($id = 1; $id <= $maxId; $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id] ?? 0);
        }

        $pdf .= 'trailer' . "\n" . '<< /Size ' . ($maxId + 1) . ' /Root 1 0 R >>' . "\n";
        $pdf .= 'startxref' . "\n" . $xrefOffset . "\n%%EOF\n";

        return $pdf;
    }

    private static function u16(string $data, int $offset): int
    {
        if ($offset + 2 > strlen($data)) {
            return 0;
        }

        return unpack('n', substr($data, $offset, 2))[1];
    }

    private static function i16(string $data, int $offset): int
    {
        $value = self::u16($data, $offset);

        return $value >= 0x8000 ? $value - 0x10000 : $value;
    }

    private static function u32(string $data, int $offset): int
    {
        if ($offset + 4 > strlen($data)) {
            return 0;
        }

        return unpack('N', substr($data, $offset, 4))[1];
    }
}
