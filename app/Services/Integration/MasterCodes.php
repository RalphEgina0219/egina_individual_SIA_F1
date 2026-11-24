<?php

namespace App\Services\Integration;

/**
 * Mga format ng code ng grupo (B4).
 */
class MasterCodes
{
    public const SKU = '/^SKU-\d{4,}$/';
    public const BRANCH = '/^BR-\d{3,}$/';
    public const WAREHOUSE = '/^WH-\d{3,}$/';
    public const EMPLOYEE = '/^EMP-\d{4,}$/';
    public const PERIOD = '/^\d{4}-(0[1-9]|1[0-2])$/';

    public const ISSUE_TYPES = ['Defective', 'Expired', 'Wrong Item', 'Damaged Packaging', 'Other'];

    public static function isSku(?string $value): bool
    {
        return $value !== null && preg_match(self::SKU, $value) === 1;
    }

    public static function isBranch(?string $value): bool
    {
        return $value !== null && preg_match(self::BRANCH, $value) === 1;
    }

    public static function isEmployee(?string $value): bool
    {
        return $value !== null && preg_match(self::EMPLOYEE, $value) === 1;
    }
}
