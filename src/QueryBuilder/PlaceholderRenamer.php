<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\QueryBuilder;

use function is_int;
use function is_string;
use function ltrim;
use function preg_replace_callback;

/**
 * Переименовывает именованные плейсхолдеры вложенного запроса.
 *
 * Каждый билдер нумерует свои параметры самостоятельно (:in_1, :where_1, ...), поэтому при встраивании
 * подзапроса (UNION, FROM/JOIN/SELECT/WHERE-подзапрос) имена могут совпасть с параметрами внешнего запроса.
 * Перед встраиванием все именованные параметры подзапроса получают уникальный префикс.
 *
 * Строковые литералы ('...'), экранированные идентификаторы ("..." и `...`) и приведения типов (::type)
 * не изменяются.
 *
 * @internal
 */
final class PlaceholderRenamer
{
    private const string PATTERN = '/\'(?:[^\']|\'\')*\'|"(?:[^"]|"")*"|`(?:[^`]|``)*`|::|(?<![A-Za-z0-9_]):([A-Za-z_][A-Za-z0-9_]*)/';

    /**
     * @param array<string|int, mixed> $params
     * @return array{sql: string, params: array<string|int, mixed>}
     */
    public static function prefix(string $sql, array $params, string $prefix): array
    {
        /** @var array<string, string> $map */
        $map = [];
        foreach ($params as $key => $_value) {
            if (is_int($key)) {
                continue;
            }

            $name = ltrim($key, ':');
            if ($name !== '') {
                $map[$name] = $prefix . $name;
            }
        }

        if ($map === []) {
            return ['sql' => $sql, 'params' => $params];
        }

        $renamedSql = preg_replace_callback(
            self::PATTERN,
            static function (array $match) use ($map): string {
                $name = $match[1] ?? '';
                if ($name === '' || !isset($map[$name])) {
                    return $match[0];
                }

                return ':' . $map[$name];
            },
            $sql,
        );

        if (!is_string($renamedSql)) {
            return ['sql' => $sql, 'params' => $params];
        }

        $renamedParams = [];
        foreach ($params as $key => $value) {
            if (is_int($key)) {
                $renamedParams[$key] = $value;
                continue;
            }

            $name = ltrim($key, ':');
            if ($name === '') {
                continue;
            }

            $renamedParams[$map[$name]] = $value;
        }

        return [
            'sql'    => $renamedSql,
            'params' => $renamedParams,
        ];
    }
}
