<?php

declare(strict_types=1);

require_once __DIR__ . '/../../server-pay/feed-img-lib.php';

/** Фото каталога в WebP, как его кладёт сборка или админка. */
function t_feed_webp(string $path, int $width, int $height): void
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
    $img = imagecreatetruecolor($width, $height);
    imagefill($img, 0, 0, imagecolorallocate($img, 200, 30, 60));
    imagewebp($img, $path, 80);
    imagedestroy($img);
}

$web = t_tmpdir();
t_feed_webp("$web/images/catalog/bukety/buket-x-123.webp", 300, 400);

// --- Первое обращение: JPG-копия делается из WebP ---------------------------
$jpg = feed_img_serve($web, 'bukety', 'buket-x-123');
t_equal($jpg, "$web/feed/img/bukety/buket-x-123.jpg", 'путь к JPG-копии');
$info = $jpg !== null ? getimagesize($jpg) : false;
t_equal(
    [$info[0] ?? 0, $info[1] ?? 0, $info['mime'] ?? ''],
    [300, 400, 'image/jpeg'],
    'JPG того же размера, что и фото',
);
t_equal(glob("$web/feed/img/bukety/*.part") ?: [], [], 'временного файла не осталось');

// --- Дальше отдаётся готовый файл, без повторной перекодировки -------------
if ($jpg !== null) {
    touch($jpg, 1_000_000_000);
    clearstatcache();
    t_equal(feed_img_serve($web, 'bukety', 'buket-x-123'), $jpg, 'второй раз — тот же файл');
    clearstatcache();
    t_equal(filemtime($jpg), 1_000_000_000, 'готовая копия не переделывается');
}

// --- Нет фото или чужой путь — null, ничего не создаётся ---------------------
t_equal(feed_img_serve($web, 'bukety', 'net-takogo'), null, 'нет такого фото — null');
foreach ([['..', 'x'], ['bukety', '../../pay/config'], ['Bukety', 'x'], ['bukety', 'x.webp'], ['', 'x']] as [$section, $file]) {
    t_equal(feed_img_serve($web, $section, $file), null, "чужой путь «{$section}/{$file}» — null");
}
t_equal(is_dir("$web/feed/img/net-takogo"), false, 'для несуществующего фото папки не создаются');
