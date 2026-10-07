<?php
/**
 * Учётные записи админки — их заводит разработчик в Shell-клиенте ISPmanager:
 *
 *   php user-cli.php add <логин> "<Имя>"   новая запись, пароль печатается один раз
 *   php user-cli.php reset <логин>          новый пароль, печатается один раз
 *   php user-cli.php list                   кто есть
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/users.php';

[$command, $login, $name] = array_pad(array_slice($argv, 1), 3, '');
try {
    $db = catalog_db_open(catalog_db_path());
    switch ($command) {
        case 'add':
            $password = catalog_user_add($db, $login, $name, new DateTimeImmutable());
            echo "Учётная запись $login создана. Пароль (показывается один раз): $password", PHP_EOL;
            break;
        case 'reset':
            $password = catalog_user_reset($db, $login, new DateTimeImmutable());
            echo "Новый пароль для $login (показывается один раз): $password", PHP_EOL;
            break;
        case 'list':
            foreach (catalog_users($db) as $user) {
                echo $user['login'], ' — ', $user['name'], $user['must_change'] ? ' (начальный пароль ещё не сменён)' : '', PHP_EOL;
            }
            break;
        default:
            fwrite(STDERR, 'Использование: php user-cli.php add <логин> "<Имя>" | reset <логин> | list' . PHP_EOL);
            exit(2);
    }
} catch (CatalogError $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
