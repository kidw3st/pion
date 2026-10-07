<?php
/**
 * Учётные записи админки. Заводит их разработчик (user-cli.php): скрипт сам
 * придумывает начальный пароль и показывает его один раз. В базе — только
 * хэш (password_hash); при первом входе админка просит сменить пароль.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** Без похожих знаков (0/O, 1/l/I) — пароль диктуют и переписывают с экрана. */
const CATALOG_PASSWORD_ALPHABET = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
const CATALOG_PASSWORD_LENGTH = 12;

function catalog_new_password(): string
{
    $password = '';
    for ($i = 0; $i < CATALOG_PASSWORD_LENGTH; $i++) {
        $password .= CATALOG_PASSWORD_ALPHABET[random_int(0, strlen(CATALOG_PASSWORD_ALPHABET) - 1)];
    }
    return $password;
}

/** Новая учётная запись; возвращает начальный пароль. */
function catalog_user_add(PDO $db, string $login, string $name, DateTimeImmutable $now): string
{
    return catalog_tx($db, function () use ($db, $login, $name, $now): string {
        if (!preg_match('/^[a-z0-9._-]{2,32}$/', $login)) {
            throw new CatalogError('Логин — строчная латиница, цифры, точка, дефис, подчёркивание; от 2 до 32 знаков.');
        }
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 60) {
            throw new CatalogError('Имя — от 1 до 60 знаков.');
        }
        $q = $db->prepare('SELECT 1 FROM users WHERE login = ?');
        $q->execute([$login]);
        if ($q->fetchColumn() !== false) {
            throw new CatalogError("Логин $login уже занят.");
        }
        $password = catalog_new_password();
        $at = catalog_iso($now);
        $db->prepare('INSERT INTO users (login, name, password_hash, must_change, created_at, updated_at) VALUES (?, ?, ?, 1, ?, ?)')
            ->execute([$login, $name, password_hash($password, PASSWORD_DEFAULT), $at, $at]);
        catalog_audit($db, 'console', $now, 'user', $login, 'created', null, $name);
        return $password;
    });
}

/** Новый пароль вместо забытого; при входе админка попросит его сменить. */
function catalog_user_reset(PDO $db, string $login, DateTimeImmutable $now): string
{
    return catalog_tx($db, function () use ($db, $login, $now): string {
        $password = catalog_new_password();
        $q = $db->prepare('UPDATE users SET password_hash = ?, must_change = 1, updated_at = ? WHERE login = ?');
        $q->execute([password_hash($password, PASSWORD_DEFAULT), catalog_iso($now), $login]);
        if ($q->rowCount() === 0) {
            throw new CatalogError("Учётной записи $login нет.");
        }
        catalog_audit($db, 'console', $now, 'user', $login, 'password', null, 'сброшен');
        return $password;
    });
}

/** @return list<array{login: string, name: string, must_change: int}> */
function catalog_users(PDO $db): array
{
    return $db->query('SELECT login, name, must_change FROM users ORDER BY login')->fetchAll();
}
