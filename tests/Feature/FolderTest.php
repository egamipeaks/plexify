<?php

use App\Models\Folder;
use App\Models\FolderPlaylist;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns folder playlists ordered by position', function () {
    $folder = Folder::factory()->create();
    $folder->folderPlaylists()->create(['plex_playlist_id' => 'b', 'position' => 2]);
    $folder->folderPlaylists()->create(['plex_playlist_id' => 'a', 'position' => 1]);

    expect($folder->folderPlaylists->pluck('plex_playlist_id')->all())->toBe(['a', 'b']);
});

it('moves its folder playlists to root when the folder is deleted', function () {
    $folder = Folder::factory()->create();
    FolderPlaylist::create(['folder_id' => $folder->id, 'plex_playlist_id' => 'p1', 'position' => 0]);
    FolderPlaylist::create(['folder_id' => $folder->id, 'plex_playlist_id' => 'p2', 'position' => 1]);

    $folder->delete();

    expect(FolderPlaylist::count())->toBe(2)
        ->and(FolderPlaylist::pluck('folder_id')->all())->toBe([null, null]);
});

it('defaults a new folder to expanded with position zero', function () {
    $folder = Folder::create(['name' => 'Moods'])->fresh();

    expect($folder->expanded)->toBeTrue()
        ->and($folder->position)->toBe(0);
});

it('enforces one folder per playlist via a unique index', function () {
    $a = Folder::factory()->create();
    $b = Folder::factory()->create();
    $a->folderPlaylists()->create(['plex_playlist_id' => 'p1', 'position' => 0]);

    expect(fn () => $b->folderPlaylists()->create(['plex_playlist_id' => 'p1', 'position' => 0]))
        ->toThrow(QueryException::class);
});
