<?php

use App\Models\Folder;
use App\Models\FolderPlaylist;
use App\Services\Plex\Dto\Playlist;
use App\Services\Plex\Dto\Track;
use App\Services\Plex\Exceptions\PlexUnreachableException;
use App\Services\Plex\PlexClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function playlist(string $id, string $title, int $count = 1): Playlist
{
    return new Playlist(id: $id, title: $title, trackCount: $count, durationMs: 0, thumb: null, playlistType: 'audio');
}

function mockSidebarPlex(array $playlists = [], ?Closure $extra = null): void
{
    test()->mock(PlexClient::class, function ($mock) use ($playlists, $extra) {
        $mock->shouldReceive('playlists')->andReturn(collect($playlists));
        $mock->shouldReceive('thumbUrl')->andReturnUsing(fn ($t) => $t ? "https://thumb{$t}" : null);
        $mock->shouldReceive('ping')->andReturn(['name' => 'HOME', 'reachable' => true, 'connection' => 'direct', 'machineIdentifier' => 'abc']);

        if ($extra) {
            $extra($mock);
        }
    });
}

it('lists the Plex playlists as links into the detail view', function () {
    mockSidebarPlex([playlist('4242', 'Late Night', 12), playlist('4243', 'Bangers', 30)]);

    Livewire::test('sidebar')
        ->assertSee('Late Night')
        ->assertSee('Bangers')
        ->assertSeeHtml('href="'.route('playlist', '4242').'"')
        ->assertSeeHtml('href="'.route('playlist', '4243').'"')
        ->assertDontSee('No playlists yet');
});

it('falls back to the empty placeholder when Plex is unreachable and there are no folders', function () {
    test()->mock(PlexClient::class, function ($mock) {
        $mock->shouldReceive('playlists')->andThrow(new PlexUnreachableException('refused'));
        $mock->shouldReceive('thumbUrl')->andReturnNull();
        $mock->shouldReceive('ping')->andReturn(['name' => 'HOME', 'reachable' => false, 'connection' => 'down', 'machineIdentifier' => 'abc']);
    });

    Livewire::test('sidebar')
        ->assertSee('No playlists yet')
        ->assertSee('Your Playlists');
});

it('creates a folder and enters rename mode', function () {
    mockSidebarPlex();

    Livewire::test('sidebar')
        ->call('createFolder')
        ->assertSet('renamingFolderId', fn ($id) => $id === Folder::first()->id);

    expect(Folder::count())->toBe(1)
        ->and(Folder::first()->name)->toBe('New Folder');
});

it('renames a folder and ignores a blank name', function () {
    mockSidebarPlex();
    $folder = Folder::factory()->create(['name' => 'Old']);

    Livewire::test('sidebar')
        ->set('renamingFolderId', $folder->id)
        ->call('renameFolder', $folder->id, '  Moods  ')
        ->assertSet('renamingFolderId', null);
    expect($folder->fresh()->name)->toBe('Moods');

    Livewire::test('sidebar')->call('renameFolder', $folder->id, '   ');
    expect($folder->fresh()->name)->toBe('Moods');
});

it('deletes a folder and its pivot rows', function () {
    mockSidebarPlex();
    $folder = Folder::factory()->create();
    $folder->folderPlaylists()->create(['plex_playlist_id' => 'p1', 'position' => 0]);

    Livewire::test('sidebar')->call('deleteFolder', $folder->id);

    expect(Folder::count())->toBe(0)
        ->and(FolderPlaylist::count())->toBe(0);
});

it('toggles a folder open and closed', function () {
    mockSidebarPlex();
    $folder = Folder::factory()->create(['expanded' => true]);

    Livewire::test('sidebar')->call('toggleFolder', $folder->id);
    expect($folder->fresh()->expanded)->toBeFalse();

    Livewire::test('sidebar')->call('toggleFolder', $folder->id);
    expect($folder->fresh()->expanded)->toBeTrue();
});

it('moves a playlist into a folder, replacing any prior placement, and back to root', function () {
    mockSidebarPlex([playlist('p1', 'One')]);
    $a = Folder::factory()->create();
    $b = Folder::factory()->create();

    $component = Livewire::test('sidebar');

    $component->call('movePlaylistToFolder', 'p1', $a->id);
    expect(FolderPlaylist::where('plex_playlist_id', 'p1')->count())->toBe(1)
        ->and(FolderPlaylist::where('plex_playlist_id', 'p1')->first()->folder_id)->toBe($a->id);

    $component->call('movePlaylistToFolder', 'p1', $b->id);
    expect(FolderPlaylist::where('plex_playlist_id', 'p1')->count())->toBe(1)
        ->and(FolderPlaylist::where('plex_playlist_id', 'p1')->first()->folder_id)->toBe($b->id);

    $component->call('movePlaylistToFolder', 'p1', null);
    expect(FolderPlaylist::where('plex_playlist_id', 'p1')->count())->toBe(0);
});

it('adds a track to a playlist via PlexClient and reports success/failure', function () {
    mockSidebarPlex([playlist('4242', 'Mix')], function ($mock) {
        $mock->shouldReceive('addTrackToPlaylist')->once()->with('4242', '99')->andReturnNull();
    });

    Livewire::test('sidebar')->call('addTrackToPlaylist', '4242', '99')->assertReturned(true);
});

it('returns false from addTrackToPlaylist when Plex rejects it', function () {
    mockSidebarPlex([playlist('4242', 'Mix')], function ($mock) {
        $mock->shouldReceive('addTrackToPlaylist')->andThrow(new PlexUnreachableException('refused'));
    });

    Livewire::test('sidebar')->call('addTrackToPlaylist', '4242', '99')->assertReturned(false);
});

it('adds an album to a playlist via PlexClient', function () {
    mockSidebarPlex([playlist('4242', 'Mix')], function ($mock) {
        $mock->shouldReceive('addAlbumToPlaylist')->once()->with('4242', '1001')->andReturnNull();
    });

    Livewire::test('sidebar')->call('addAlbumToPlaylist', '4242', '1001')->assertReturned(true);
});

it('creates a playlist from a dragged track and enters rename mode on the new id', function () {
    mockSidebarPlex([], function ($mock) {
        $mock->shouldReceive('createPlaylist')->once()->with('New Playlist', '99')->andReturn('5005');
    });

    Livewire::test('sidebar')
        ->call('createPlaylistFromTrack', '99')
        ->assertReturned(true)
        ->assertSet('renamingPlaylistId', '5005');
});

it('renames a playlist through Plex and ignores a blank name', function () {
    mockSidebarPlex([playlist('4242', 'Mix')], function ($mock) {
        $mock->shouldReceive('renamePlaylist')->once()->with('4242', 'Chill')->andReturnNull();
    });

    Livewire::test('sidebar')
        ->set('renamingPlaylistId', '4242')
        ->call('renamePlaylist', '4242', '  Chill  ')
        ->assertSet('renamingPlaylistId', null);
});

it('deletes a playlist through Plex and clears any folder placement', function () {
    mockSidebarPlex([], function ($mock) {
        $mock->shouldReceive('deletePlaylist')->once()->with('4242')->andReturnNull();
    });
    $folder = Folder::factory()->create();
    $folder->folderPlaylists()->create(['plex_playlist_id' => '4242', 'position' => 0]);

    Livewire::test('sidebar')->call('deletePlaylist', '4242');

    expect(FolderPlaylist::where('plex_playlist_id', '4242')->count())->toBe(0);
});

it('plays the first track of a playlist', function () {
    mockSidebarPlex([playlist('4242', 'Mix')], function ($mock) {
        $mock->shouldReceive('playlistTracks')->once()->with('4242')->andReturn(collect([
            new Track(id: '1', title: 'Song', artist: 'Band', album: 'Rec', trackNumber: 1, durationMs: 1000, partId: 7, container: 'flac', thumb: '/t'),
        ]));
        $mock->shouldReceive('streamUrl')->andReturn('https://stream/1.flac');
    });

    Livewire::test('sidebar')
        ->call('playPlaylist', '4242')
        ->assertDispatched('play-track', url: 'https://stream/1.flac', title: 'Song', artist: 'Band');
});
