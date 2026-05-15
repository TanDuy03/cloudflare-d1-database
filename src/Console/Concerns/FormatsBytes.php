<?php

declare(strict_types=1);

namespace Ntanduy\CFD1\Console\Concerns;

trait FormatsBytes
{
    protected function formatBytes(int $bytes): string
    {
        if ($bytes >= 1_000_000_000) {
            return round($bytes / 1_000_000_000, 2).' GB';
        }
        if ($bytes >= 1_000_000) {
            return round($bytes / 1_000_000, 2).' MB';
        }
        if ($bytes >= 1_000) {
            return round($bytes / 1_000, 2).' kB';
        }

        return $bytes.' B';
    }
}
