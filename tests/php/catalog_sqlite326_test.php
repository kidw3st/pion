<?php
/**
 * На сервере SQLite 3.26 (AlmaLinux 8), а локально и в CI — свежий. Запрос с
 * конструкцией новее 3.26 прошёл бы здесь все проверки и упал бы только на
 * сервере. Эта проверка ищет такие конструкции в коде /pay/ — без
 * комментариев: в них о новых конструкциях писать можно.
 */

declare(strict_types=1);

require_once __DIR__ . '/catalog_fixture.php';

/** Код файла без комментариев. */
function t_code_without_comments(string $path): string
{
    $code = '';
    foreach (token_get_all((string)file_get_contents($path)) as $token) {
        if (is_array($token)) {
            if ($token[0] !== T_COMMENT && $token[0] !== T_DOC_COMMENT) {
                $code .= $token[1];
            }
        } else {
            $code .= $token;
        }
    }
    return $code;
}

t_case('SQL не новее SQLite 3.26', function (): void {
    $newer = [
        'VACUUM INTO (3.27)' => '/\bVACUUM\s+INTO\b/i',
        'RETURNING (3.35)' => '/\bRETURNING\b/i',
        'DROP COLUMN (3.35)' => '/\bDROP\s+COLUMN\b/i',
        'MATERIALIZED (3.35)' => '/\bMATERIALIZED\b/i',
        'IIF (3.32)' => '/\bIIF\s*\(/i',
        'NULLS FIRST/LAST (3.30)' => '/\bNULLS\s+(FIRST|LAST)\b/i',
        'FILTER (WHERE …) (3.30)' => '/\bFILTER\s*\(\s*WHERE\b/i',
        'GENERATED ALWAYS (3.31)' => '/\bGENERATED\s+ALWAYS\b/i',
        'UNIXEPOCH (3.38)' => '/\bUNIXEPOCH\s*\(/i',
        'JSON ->> (3.38)' => '/->>/',
        'STRICT-таблицы (3.37)' => '/\)\s*STRICT\b/i',
    ];
    $root = dirname(__DIR__, 2) . '/server-pay';
    $found = [];
    $checked = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile() || !str_ends_with($file->getFilename(), '.php')) {
            continue;
        }
        $checked++;
        $code = t_code_without_comments($file->getPathname());
        foreach ($newer as $what => $pattern) {
            if (preg_match($pattern, $code)) {
                $found[] = substr(str_replace('\\', '/', $file->getPathname()), strlen($root) + 1) . ': ' . $what;
            }
        }
    }
    t_true($checked > 10, 'файлы /pay/ найдены');
    t_equal($found, [], 'в коде /pay/ нет SQL новее SQLite 3.26');
});
