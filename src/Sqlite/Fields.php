<?php
declare(strict_types=1);
namespace Lfcheng\CrmPolicy\Sqlite;

/** @internal Strict decoding: malformed stored data throws; it never supplies a grant or count. */
final class Fields
{
    /** @return array<string, mixed> */
    public static function object(mixed $value): array
    {
        if (!is_array($value)) { throw new \UnexpectedValueException('Expected object.'); }
        foreach (array_keys($value) as $key) {
            if (!is_string($key)) { throw new \UnexpectedValueException('Expected object keys.'); }
        }
        return $value;
    }
    /** @return list<mixed> */
    public static function list(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) { throw new \UnexpectedValueException('Expected list.'); }
        return $value;
    }
    public static function string(mixed $value): string
    {
        if (!is_string($value)) { throw new \UnexpectedValueException('Expected string.'); }
        return $value;
    }
    public static function nullableString(mixed $value): ?string { return $value === null ? null : self::string($value); }
    public static function int(mixed $value): int
    {
        if (!is_int($value)) { throw new \UnexpectedValueException('Expected integer.'); }
        return $value;
    }
    public static function instant(mixed $value): \DateTimeImmutable
    {
        $text = self::string($value);
        $instant = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.uP', $text, new \DateTimeZone('UTC'));
        if ($instant === false || $instant->format('Y-m-d\TH:i:s.uP') !== $text) {
            throw new \UnexpectedValueException('Expected an absolute timestamp with microseconds and explicit offset.');
        }
        return $instant;
    }
    public static function bool(mixed $value): bool
    {
        if (!is_bool($value)) { throw new \UnexpectedValueException('Expected boolean.'); }
        return $value;
    }
}
