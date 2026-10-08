<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\HttpException;

/**
 * Обработка изображений номенклатуры: загрузка, EXIF-поворот, ресайз без увеличения.
 * Поддерживаются jpeg/png/webp (проверено через GD).
 */
final class ImageProcessor
{
    /**
     * Загружает изображение из файла и применяет EXIF-поворот.
     *
     * @return array{0: \GdImage, 1: int, 2: int}
     */
    public static function load(string $path, string $mime): array
    {
        $image = self::create($path, $mime);

        if ($image === false) {
            throw new HttpException(422, 'bad_image', 'Не удалось прочитать изображение');
        }

        $image = self::orient($image, $path, $mime);

        return [$image, imagesx($image), imagesy($image)];
    }

    /**
     * Новое изображение, вписанное в квадрат $maxSide по длинной стороне.
     * Не увеличивает: если исходник меньше — размер сохраняется.
     */
    public static function fit(\GdImage $source, int $maxSide): \GdImage
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $long = max($width, $height);

        if ($maxSide > 0 && $long > $maxSide) {
            $scale = $maxSide / $long;
            $targetWidth = max(1, (int) round($width * $scale));
            $targetHeight = max(1, (int) round($height * $scale));
        } else {
            $targetWidth = $width;
            $targetHeight = $height;
        }

        $target = imagecreatetruecolor($targetWidth, $targetHeight);

        if ($target === false) {
            throw new HttpException(500, 'image_error', 'Не удалось обработать изображение');
        }

        self::preserveAlpha($target);
        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        return $target;
    }

    public static function save(\GdImage $image, string $absolutePath, string $mime): void
    {
        $ok = match (self::format($mime)) {
            'png' => imagepng($image, $absolutePath, 6),
            'webp' => imagewebp($image, $absolutePath, 85),
            default => imagejpeg($image, $absolutePath, 85),
        };

        if ($ok === false) {
            throw new HttpException(500, 'storage_error', 'Не удалось сохранить изображение');
        }
    }

    public static function extension(string $mime): string
    {
        return match (self::format($mime)) {
            'png' => 'png',
            'webp' => 'webp',
            default => 'jpg',
        };
    }

    private static function format(string $mime): string
    {
        return match (true) {
            str_contains($mime, 'png') => 'png',
            str_contains($mime, 'webp') => 'webp',
            default => 'jpeg',
        };
    }

    private static function create(string $path, string $mime): \GdImage|false
    {
        return match (self::format($mime)) {
            'png' => @imagecreatefrompng($path),
            'webp' => @imagecreatefromwebp($path),
            default => @imagecreatefromjpeg($path),
        };
    }

    private static function orient(\GdImage $image, string $path, string $mime): \GdImage
    {
        if (self::format($mime) !== 'jpeg' || !function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);
        $orientation = (int) ($exif['Orientation'] ?? 1);

        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => false,
        };

        if ($rotated === false) {
            return $image;
        }

        imagedestroy($image);

        return $rotated;
    }

    private static function preserveAlpha(\GdImage $image): void
    {
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);

        if ($transparent !== false) {
            imagefilledrectangle(
                $image,
                0,
                0,
                imagesx($image),
                imagesy($image),
                $transparent
            );
        }
    }
}
