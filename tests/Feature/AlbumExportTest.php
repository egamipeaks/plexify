<?php

use App\Enums\AlbumExportStatus;
use App\Models\AlbumExport;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('casts status to the enum', function () {
    $export = AlbumExport::factory()->create();

    expect($export->fresh()->status)->toBe(AlbumExportStatus::Queued);
});

it('knows which statuses are active', function () {
    expect(AlbumExportStatus::Queued->isActive())->toBeTrue()
        ->and(AlbumExportStatus::Running->isActive())->toBeTrue()
        ->and(AlbumExportStatus::Done->isActive())->toBeFalse()
        ->and(AlbumExportStatus::Failed->isActive())->toBeFalse();
});

it('returns the newest export per playlist keyed by playlist id', function () {
    AlbumExport::factory()->done('/old')->create(['plex_playlist_id' => '1']);
    $newest = AlbumExport::factory()->running()->create(['plex_playlist_id' => '1']);
    $other = AlbumExport::factory()->create(['plex_playlist_id' => '2']);

    $latest = AlbumExport::latestPerPlaylist();

    expect($latest->keys()->sort()->values()->all())->toBe([1, 2])
        ->and($latest['1']->id)->toBe($newest->id)
        ->and($latest['2']->id)->toBe($other->id);
});
