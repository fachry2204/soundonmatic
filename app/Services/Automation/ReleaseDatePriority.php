<?php

declare(strict_types=1);

namespace App\Services\Automation;

use Carbon\CarbonImmutable;
use Throwable;

final class ReleaseDatePriority
{
    public static function key(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') return '9999-12-31';
        try { return CarbonImmutable::parse($value)->format('Y-m-d'); }
        catch (Throwable) { return '9999-12-31'; }
    }
}
