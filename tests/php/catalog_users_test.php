<?php
/**
 * Учётные записи админки: заводит разработчик, хранится только хэш пароля.
 */

declare(strict_types=1);

require_once __DIR__ . '/catalog_fixture.php';
require_once __DIR__ . '/../../server-pay/catalog/users.php';

t_case('учётная запись', function (): void {
    $db = t_catalog_db();
    $password = catalog_user_add($db, 'anna', 'Анна', t_now());
    t_true((bool)preg_match('/^[a-hjkmnp-zA-HJ-NP-Z2-9]{12}$/', $password), 'пароль — 12 знаков без похожих (0/O, 1/l/I)');
    $u = $db->query("SELECT * FROM users WHERE login = 'anna'")->fetch();
    t_equal([$u['name'], $u['must_change']], ['Анна', 1], 'имя сохранено; при первом входе админка попросит сменить пароль');
    t_true(password_verify($password, $u['password_hash']), 'хранится хэш, пароль к нему подходит');
    t_throws(fn () => catalog_user_add($db, 'anna', 'Другая Анна', t_now()), CatalogError::class, 'логин занят');
    t_throws(fn () => catalog_user_add($db, 'Анна', 'Анна', t_now()), CatalogError::class, 'логин — только латиницей');

    $new = catalog_user_reset($db, 'anna', t_now('+1 day'));
    $u = $db->query("SELECT * FROM users WHERE login = 'anna'")->fetch();
    t_true(password_verify($new, $u['password_hash']) && !password_verify($password, $u['password_hash']), 'новый пароль подходит, старый — нет');
    t_equal($u['must_change'], 1, 'после сброса тоже попросит сменить');
    t_throws(fn () => catalog_user_reset($db, 'olga', t_now()), CatalogError::class, 'сброс несуществующей записи');

    $log = implode(' ', $db->query("SELECT COALESCE(old_value, '') || ' ' || COALESCE(new_value, '') FROM audit")->fetchAll(PDO::FETCH_COLUMN));
    t_true(!str_contains($log, $password) && !str_contains($log, $new), 'пароли в журнал не попадают');
    t_equal(catalog_users($db), [['login' => 'anna', 'name' => 'Анна', 'must_change' => 1]], 'список учётных записей');
});

t_case('user-cli.php', function (): void {
    $scripts = t_catalog_scripts();
    $home = t_tmpdir();
    [$code, $out] = t_catalog_cli("$scripts/catalog/user-cli.php", $home, ['add', 'olga', 'Olga']);
    t_equal($code, 0, 'учётная запись создана');
    t_true((bool)preg_match('/[a-hjkmnp-zA-HJ-NP-Z2-9]{12}/', $out), 'пароль напечатан');
    [$code, $out] = t_catalog_cli("$scripts/catalog/user-cli.php", $home, ['list']);
    t_true($code === 0 && str_contains($out, 'olga'), 'список показывает логин');
    [$code] = t_catalog_cli("$scripts/catalog/user-cli.php", $home, ['add', 'olga', 'Olga']);
    t_equal($code, 1, 'повторное создание — ошибка');
});
