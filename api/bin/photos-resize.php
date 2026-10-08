<?php

declare(strict_types=1);

/**
 * CRM7 — пересчёт вариантов фото номенклатуры (превью/карточка) для существующих фото.
 *
 * Обрабатывает строки без card_path/preview_path или с width = 0: читает текущий
 * максимум (storage_path), генерирует производные по размерам из настроек.
 *
 * Использование:
 *   php api/bin/photos-resize.php [--limit=500]
 *
 * ВАЖНО: перед запуском на k/prod — бэкап БД и storage (файлы не удаляются,
 * только добавляются производные и обновляются столбцы).
 */

use App\Core\Config;
use App\Repositories\SettingsRepository;
use App\Repositories\StockPhotoRepository;
use App\Support\ImageProcessor;
use App\Support\Storage;

require __DIR__ . '/../src/autoload.php';

Config::load(__DIR__ . '/../config/.env');

$limit = 500;

foreach ($argv as $arg) {
    if (preg_match('/^--limit=(\d+)$/', (string) $arg, $m) === 1) {
        $limit = max(1, (int) $m[1]);
    }
}

$photos = new StockPhotoRepository();
$sizes = (new SettingsRepository())->photoSizes();

echo "Размеры: preview={$sizes['preview']}, card={$sizes['card']}, max={$sizes['max']}\n";

$done = 0;
$failed = 0;
$skip = [];
$guard = 0;

while (true) {
    $pending = array_values(array_filter(
        $photos->pendingVariants($limit),
        static fn (array $photo): bool => !isset($skip[$photo['id']])
    ));

    if ($pending === []) {
        break;
    }

    if (++$guard > 10000) {
        echo "Стоп: слишком много итераций\n";
        break;
    }

    foreach ($pending as $photo) {
        $source = Storage::absolute($photo['storage_path']);

        if (!is_file($source)) {
            echo "пропуск #{$photo['id']}: нет файла {$photo['storage_path']}\n";
            $skip[$photo['id']] = true;
            $failed++;
            continue;
        }

        try {
            $mime = $photo['mime'] !== ''
                ? $photo['mime']
                : (string) (new finfo(FILEINFO_MIME_TYPE))->file($source);
            $extension = ImageProcessor::extension($mime);

            [$image, $width, $height] = ImageProcessor::load($source, $mime);

            $cardImage = ImageProcessor::fit($image, $sizes['card']);
            $cardPath = Storage::allocate('item-photos', $extension);
            ImageProcessor::save($cardImage, Storage::absolute($cardPath), $mime);

            $previewImage = ImageProcessor::fit($image, $sizes['preview']);
            $previewPath = Storage::allocate('item-photos', $extension);
            ImageProcessor::save($previewImage, Storage::absolute($previewPath), $mime);

            imagedestroy($image);
            imagedestroy($cardImage);
            imagedestroy($previewImage);

            $photos->updateVariants(
                $photo['id'],
                $cardPath,
                $previewPath,
                $width,
                $height,
                (int) (@filesize($source) ?: 0)
            );

            $done++;
        } catch (Throwable $exception) {
            echo "ошибка #{$photo['id']}: {$exception->getMessage()}\n";
            $skip[$photo['id']] = true;
            $failed++;
        }
    }
}

echo "Готово: обработано {$done}, ошибок {$failed}\n";
