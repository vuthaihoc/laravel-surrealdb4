<?php

namespace LaravelSurrealDb\Query;

use DateTimeInterface;

/**
 * SurrealDB 4.0 infers the type of a parameter from what it is compared
 * with, except inside a nested query (`exists (select ...)`, `in (select
 * ...)`, a subquery column, a union branch) and in a HAVING clause: there a
 * parameter is text, which matches only text columns. Placeholders in those
 * places get a type from their PHP binding: integers, floats and booleans
 * are cast, dates (DateTimeInterface) are cast to timestamp, and date
 * strings (`2026-01-02`, `2026-01-02 03:04:05`) are inlined as literals,
 * which SurrealDB compares with date, timestamp and text columns alike.
 * Other strings stay text. Top-level placeholders stay untyped, so a number
 * can still be written to a text column.
 */
class ParameterTypes
{
    /** Keywords that end a HAVING clause. */
    protected const HAVING_END = ['order', 'limit', 'offset', 'union', 'window', 'for', 'intersect', 'except'];

    /** A date or timestamp string: digits and separators only, safe to inline. */
    protected const DATE = '/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2}(?:\.\d{1,9})?)?)?(?:Z|[+-]\d{2}(?::?\d{2})?)?$/';

    /**
     * @param  array<array-key, mixed>  $bindings  bindings before Connection::prepareBindings()
     * @return array{0: string, 1: array<array-key, mixed>} the SQL and its bindings (without the inlined ones)
     */
    public static function cast(string $sql, array $bindings): array
    {
        $values = array_values($bindings);

        if (! preg_match('/\(\s*(select|with)\b|\bhaving\b/i', $sql) || ! array_filter($values, self::needsType(...))) {
            return [$sql, $bindings];
        }

        $kept = $values;
        $inlined = false;

        $result = '';
        $length = strlen($sql);
        // One frame per open parenthesis: inside a nested query, inside a HAVING clause.
        $frames = [['nested' => false, 'having' => false]];
        $index = 0;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            // Quoted strings and identifiers are copied as they are.
            if ($char === "'" || $char === '"') {
                $end = $i + 1;

                while ($end < $length) {
                    if ($sql[$end] === $char) {
                        if ($end + 1 < $length && $sql[$end + 1] === $char) {
                            $end += 2;

                            continue;
                        }

                        break;
                    }

                    $end++;
                }

                $result .= substr($sql, $i, $end - $i + 1);
                $i = $end;

                continue;
            }

            if ($char === '-' && ($sql[$i + 1] ?? '') === '-') {
                $end = strpos($sql, "\n", $i);
                $end = $end === false ? $length : $end;
                $result .= substr($sql, $i, $end - $i);
                $i = $end - 1;

                continue;
            }

            if ($char === '/' && ($sql[$i + 1] ?? '') === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $end = $end === false ? $length : $end + 2;
                $result .= substr($sql, $i, $end - $i);
                $i = $end - 1;

                continue;
            }

            $frame = $frames[count($frames) - 1];

            if (ctype_alpha($char) && ($i === 0 || ! preg_match('/[\w$]/', $sql[$i - 1]))) {
                $word = preg_match('/\G\w+/', $sql, $match, 0, $i) ? $match[0] : $char;
                $keyword = strtolower($word);

                if ($keyword === 'having') {
                    $frames[count($frames) - 1]['having'] = true;
                } elseif (in_array($keyword, self::HAVING_END, true)) {
                    $frames[count($frames) - 1]['having'] = false;
                }

                $result .= $word;
                $i += strlen($word) - 1;

                continue;
            }

            if ($char === '(') {
                $select = (bool) preg_match('/\G[\s(]*(select|with)\b/i', $sql, $m, 0, $i + 1);
                $frames[] = ['nested' => $frame['nested'] || $select, 'having' => $frame['having'] && ! $select];
            } elseif ($char === ')') {
                if (count($frames) > 1) {
                    array_pop($frames);
                }
            } elseif ($char === '?') {
                // `??` is PDO's escaped question mark, not a placeholder.
                if (($sql[$i + 1] ?? '') === '?') {
                    $result .= '??';
                    $i++;

                    continue;
                }

                $value = $values[$index] ?? null;

                if (($frame['nested'] || $frame['having']) && is_string($value) && preg_match(self::DATE, $value)) {
                    $result .= "'".$value."'";
                    unset($kept[$index++]);
                    $inlined = true;

                    continue;
                }

                $index++;

                if ($frame['nested'] || $frame['having']) {
                    $result .= match (true) {
                        is_bool($value) => '(?::bigint <> 0)',
                        is_int($value) => '?::bigint',
                        is_float($value) => '?::float8',
                        $value instanceof DateTimeInterface => '?::timestamp',
                        default => '?',
                    };

                    continue;
                }
            }

            $result .= $char;
        }

        return [$result, $inlined ? array_values($kept) : $bindings];
    }

    protected static function needsType(mixed $value): bool
    {
        return is_int($value) || is_float($value) || is_bool($value) || $value instanceof DateTimeInterface
            || (is_string($value) && preg_match(self::DATE, $value) === 1);
    }
}
