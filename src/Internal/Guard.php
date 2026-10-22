<?php
declare(strict_types=1);
namespace Lfcheng92\CrmPolicy\Internal;

/** @internal */
final class Guard
{
    public static function id(string $value): void
    {
        if (preg_match('//u', $value) !== 1 || $value === '' || trim($value) !== $value || strlen($value) > 128 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new \InvalidArgumentException('Identifiers must be 1..128 bytes in UTF-8 without surrounding whitespace or control characters.');
        }
    }
}
