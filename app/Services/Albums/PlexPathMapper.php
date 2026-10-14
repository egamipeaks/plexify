<?php

namespace App\Services\Albums;

class PlexPathMapper
{
    public function __construct(
        private readonly string $remotePrefix,
        private readonly string $localPrefix,
    ) {}

    public function isConfigured(): bool
    {
        return $this->remotePrefix !== '' && $this->localPrefix !== '';
    }

    public function localRoot(): string
    {
        return rtrim($this->localPrefix, '/');
    }

    public function toLocal(string $remotePath): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        if (! str_starts_with(mb_strtolower($remotePath), mb_strtolower($this->remotePrefix))) {
            return null;
        }

        $relative = str_replace('\\', '/', substr($remotePath, strlen($this->remotePrefix)));

        return $this->localRoot().'/'.ltrim($relative, '/');
    }

    public function toRemote(string $localPath): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $root = $this->localRoot().'/';

        if (! str_starts_with($localPath, $root)) {
            return null;
        }

        $relative = str_replace('/', '\\', substr($localPath, strlen($root)));

        return rtrim($this->remotePrefix, '\\').'\\'.$relative;
    }
}
