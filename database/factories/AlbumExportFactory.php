<?php

namespace Database\Factories;

use App\Enums\AlbumExportStatus;
use App\Models\AlbumExport;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AlbumExport> */
class AlbumExportFactory extends Factory
{
    public function definition(): array
    {
        return [
            'plex_playlist_id' => (string) fake()->unique()->numberBetween(1000, 9999),
            'title' => fake()->words(2, true),
            'status' => AlbumExportStatus::Queued,
            'progress' => 0,
            'total' => 3,
        ];
    }

    public function running(): static
    {
        return $this->state(['status' => AlbumExportStatus::Running, 'progress' => 1]);
    }

    public function done(string $folderPath): static
    {
        return $this->state(['status' => AlbumExportStatus::Done, 'progress' => 3, 'folder_path' => $folderPath]);
    }

    public function failed(string $error): static
    {
        return $this->state(['status' => AlbumExportStatus::Failed, 'error' => $error]);
    }
}
