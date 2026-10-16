<?php

namespace App\Enums;

enum AlbumExportStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';

    public function isActive(): bool
    {
        return $this === self::Queued || $this === self::Running;
    }
}
