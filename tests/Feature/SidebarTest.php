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
        $mock->makePartial();
        $mock->shouldReceive('playlists')->andReturn(collect($playlists));
        $mock->shouldReceive('thumbUrl')->andReturnUsing(fn ($t) => $t ? "https://thumb{$t}" : null);
        $mock->shouldReceive('ping')->andReturn(['name' => 'HOME', 'reachable' => true, 'connection' => 'direct', 'machineIdentifier' => 'abc']);
        $mock->shouldReceive('musicSectionTitle')->andReturn('Music');

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
        $mock->shouldReceive('musicSectionTitle')->andReturn('Music');
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

it('deletes a folder, leaving its playlists at root', function () {
    mockSidebarPlex([playlist('p1', 'One')]);
    $folder = Folder::factory()->create();
    FolderPlaylist::create(['folder_id' => $folder->id, 'plex_playlist_id' => 'p1', 'position' => 0]);

    Livewire::test('sidebar')->call('deleteFolder', $folder->id);

    expect(Folder::count())->toBe(0)
        ->and(FolderPlaylist::count())->toBe(1)
        ->and(FolderPlaylist::first()->folder_id)->toBeNull();
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

it('dispatches a notify error when adding a track to a playlist fails', function () {
    mockSidebarPlex([playlist('4242', 'Mix')], function ($mock) {
        $mock->shouldReceive('addTrackToPlaylist')->andThrow(new PlexUnreachableException('refused'));
    });

    Livewire::test('sidebar')
        ->call('addTrackToPlaylist', 'pl-1', 'tr-1')
        ->assertDispatched('notify', type: 'error');
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

it('enqueues a playlist when played', function () {
    mockSidebarPlex([playlist('4242', 'Mix')], function ($mock) {
        $mock->shouldReceive('playlistTracks')->once()->with('4242')->andReturn(collect([
            new Track(id: '1', title: 'Song', artist: 'Band', album: 'Rec', trackNumber: 1, durationMs: 1000, partId: 7, container: 'flac', thumb: '/t', albumId: 'alb1', artistId: 'art1'),
        ]));
        $mock->shouldReceive('streamUrl')->andReturn('https://stream/1.flac');
    });

    Livewire::test('sidebar')
        ->call('playPlaylist', '4242')
        ->assertDispatched('play-track',
            queue: [[
                'id' => '1',
                'url' => 'https://stream/1.flac',
                'title' => 'Song',
                'artist' => 'Band',
                'artwork' => 'https://thumb/t',
                'albumId' => 'alb1',
                'artistId' => 'art1',
                'userRating' => 0,
            ]],
            index: 0,
            contextType: 'playlist',
            contextId: '4242',
        );
});

it('renders folder contents and an "Other" group', function () {
    mockSidebarPlex([playlist('p1', 'Filed One'), playlist('p2', 'Unfiled Two')]);
    $folder = Folder::factory()->create(['name' => 'Moods']);
    $folder->folderPlaylists()->create(['plex_playlist_id' => 'p1', 'position' => 0]);

    Livewire::test('sidebar')
        ->assertSee('Moods')
        ->assertSee('Filed One')
        ->assertSee('Other')
        ->assertSee('Unfiled Two')
        ->assertSeeHtml('wire:key="folder-'.$folder->id.'"');
});

it('shows the New folder button and the per-folder toggle control', function () {
    mockSidebarPlex();
    $folder = Folder::factory()->create(['name' => 'Drafts']);

    Livewire::test('sidebar')
        ->assertSeeHtml('wire:click="createFolder"')
        ->assertSeeHtml('toggleFolder('.$folder->id.')')
        ->assertSee('Filter playlists'); // the filter input placeholder
});

it('shows five top-level nav items: Generate / Favorites / Library / Recently Added / Recently Played', function () {
    mockSidebarPlex();

    $html = Livewire::test('sidebar')->html();
    $topNav = explode('Your Playlists', $html, 2)[0];

    preg_match_all('/<a [^>]*wire:navigate/i', $topNav, $matches);
    expect($matches[0])->toHaveCount(5);

    expect($topNav)->toContain('Generate')
        ->and($topNav)->toContain('Favorites')
        ->and($topNav)->toContain('Your Library')
        ->and($topNav)->toContain('Recently Added')
        ->and($topNav)->toContain('Recently Played')
        ->and($topNav)->not->toContain('Home')
        ->and($topNav)->not->toContain('Search');
});

it('links the Recently Added and Recently Played nav items to their routes', function () {
    mockSidebarPlex();

    Livewire::test('sidebar')
        ->assertSeeHtml('href="'.route('recentlyAdded').'"')
        ->assertSeeHtml('href="'.route('recentlyPlayed').'"');
});

it('orders root playlists by their saved position, then unplaced ones in Plex order', function () {
    mockSidebarPlex([playlist('p1', 'One'), playlist('p2', 'Two'), playlist('p3', 'Three'), playlist('p4', 'Four')]);
    // Pin p3 to the top of root; p1/p2/p4 stay unplaced (Plex order).
    FolderPlaylist::create(['folder_id' => null, 'plex_playlist_id' => 'p3', 'position' => 0]);

    Livewire::test('sidebar')->assertSeeInOrder(['Three', 'One', 'Two', 'Four']);
});

it('movePlaylist reorders root playlists, materializing a row for each', function () {
    mockSidebarPlex([playlist('p1', 'One'), playlist('p2', 'Two'), playlist('p3', 'Three'), playlist('p4', 'Four')]);

    // Drop p4 immediately before p1 -> order becomes p4, p1, p2, p3
    Livewire::test('sidebar')->call('movePlaylist', 'p4', null, 'p1', 'before');

    $rows = FolderPlaylist::orderBy('position')->get();
    expect($rows)->toHaveCount(4)
        ->and($rows->pluck('folder_id')->unique()->all())->toBe([null])
        ->and($rows->pluck('plex_playlist_id')->all())->toBe(['p4', 'p1', 'p2', 'p3'])
        ->and($rows->pluck('position')->all())->toBe([0, 1, 2, 3]);
});

it('movePlaylist places a playlist at the front of root when targetPlaylistId is null', function () {
    mockSidebarPlex([playlist('p1', 'One'), playlist('p2', 'Two')]);

    Livewire::test('sidebar')->call('movePlaylist', 'p2', null, null, 'before');

    expect(FolderPlaylist::orderBy('position')->pluck('plex_playlist_id')->all())->toBe(['p2', 'p1']);
});

it('movePlaylist is a no-op when the playlist is dropped on itself', function () {
    mockSidebarPlex([playlist('p1', 'One'), playlist('p2', 'Two')]);

    Livewire::test('sidebar')->call('movePlaylist', 'p1', null, 'p1', 'before');

    expect(FolderPlaylist::count())->toBe(0);
});

it('movePlaylist is a no-op when the resulting root order is unchanged', function () {
    mockSidebarPlex([playlist('p1', 'One'), playlist('p2', 'Two')]);
    // p1 is already (Plex order) immediately before p2; "p1 before p2" changes nothing.
    Livewire::test('sidebar')->call('movePlaylist', 'p1', null, 'p2', 'before');

    expect(FolderPlaylist::count())->toBe(0);
});

it('movePlaylist drops a playlist into a folder at the top, bumping existing members', function () {
    mockSidebarPlex([playlist('p1', 'One'), playlist('p2', 'Two')]);
    $folder = Folder::factory()->create();
    FolderPlaylist::create(['folder_id' => $folder->id, 'plex_playlist_id' => 'p2', 'position' => 0]);

    Livewire::test('sidebar')->call('movePlaylist', 'p1', $folder->id, null, 'before');

    $rows = FolderPlaylist::where('folder_id', $folder->id)->orderBy('position')->get();
    expect($rows->pluck('plex_playlist_id')->all())->toBe(['p1', 'p2'])
        ->and($rows->pluck('position')->all())->toBe([0, 1]);
});

it('movePlaylist drops a playlist after a specific member of a folder', function () {
    mockSidebarPlex([playlist('p1', 'One'), playlist('p2', 'Two'), playlist('p3', 'Three')]);
    $folder = Folder::factory()->create();
    FolderPlaylist::create(['folder_id' => $folder->id, 'plex_playlist_id' => 'p1', 'position' => 0]);
    FolderPlaylist::create(['folder_id' => $folder->id, 'plex_playlist_id' => 'p2', 'position' => 1]);

    Livewire::test('sidebar')->call('movePlaylist', 'p3', $folder->id, 'p1', 'after');

    expect(FolderPlaylist::where('folder_id', $folder->id)->orderBy('position')->pluck('plex_playlist_id')->all())
        ->toBe(['p1', 'p3', 'p2']);
});

it('movePlaylist moving a playlist between folders renumbers the source folder', function () {
    mockSidebarPlex([playlist('p1', 'One'), playlist('p2', 'Two'), playlist('p3', 'Three')]);
    $a = Folder::factory()->create();
    $b = Folder::factory()->create();
    FolderPlaylist::create(['folder_id' => $a->id, 'plex_playlist_id' => 'p1', 'position' => 0]);
    FolderPlaylist::create(['folder_id' => $a->id, 'plex_playlist_id' => 'p2', 'position' => 1]);
    FolderPlaylist::create(['folder_id' => $a->id, 'plex_playlist_id' => 'p3', 'position' => 2]);

    Livewire::test('sidebar')->call('movePlaylist', 'p2', $b->id, null, 'before');

    expect(FolderPlaylist::where('folder_id', $a->id)->orderBy('position')->pluck('plex_playlist_id')->all())->toBe(['p1', 'p3'])
        ->and(FolderPlaylist::where('folder_id', $a->id)->orderBy('position')->pluck('position')->all())->toBe([0, 1])
        ->and(FolderPlaylist::where('folder_id', $b->id)->pluck('plex_playlist_id')->all())->toBe(['p2']);
});

it('movePlaylist moving a playlist out of a folder into root materializes root and renumbers the folder', function () {
    mockSidebarPlex([playlist('p1', 'One'), playlist('p2', 'Two'), playlist('p3', 'Three')]);
    $folder = Folder::factory()->create();
    FolderPlaylist::create(['folder_id' => $folder->id, 'plex_playlist_id' => 'p2', 'position' => 0]);
    FolderPlaylist::create(['folder_id' => $folder->id, 'plex_playlist_id' => 'p3', 'position' => 1]);

    // Root currently contains only p1 (p2/p3 are in the folder). Drop p3 after p1 in root.
    Livewire::test('sidebar')->call('movePlaylist', 'p3', null, 'p1', 'after');

    expect(FolderPlaylist::whereNull('folder_id')->orderBy('position')->pluck('plex_playlist_id')->all())->toBe(['p1', 'p3'])
        ->and(FolderPlaylist::where('folder_id', $folder->id)->orderBy('position')->pluck('plex_playlist_id')->all())->toBe(['p2'])
        ->and(FolderPlaylist::where('folder_id', $folder->id)->orderBy('position')->pluck('position')->all())->toBe([0]);
});

it('moveFolder reorders folders and renumbers them densely', function () {
    mockSidebarPlex();
    $f1 = Folder::factory()->create(['name' => 'A', 'position' => 0]);
    $f2 = Folder::factory()->create(['name' => 'B', 'position' => 1]);
    $f3 = Folder::factory()->create(['name' => 'C', 'position' => 2]);

    Livewire::test('sidebar')->call('moveFolder', $f3->id, $f1->id, 'before');

    expect(Folder::orderBy('position')->pluck('name')->all())->toBe(['C', 'A', 'B'])
        ->and(Folder::orderBy('position')->pluck('position')->all())->toBe([0, 1, 2]);
});

it('moveFolder is a no-op when a folder is dropped on itself', function () {
    mockSidebarPlex();
    $f1 = Folder::factory()->create(['name' => 'A', 'position' => 0]);
    $f2 = Folder::factory()->create(['name' => 'B', 'position' => 1]);

    Livewire::test('sidebar')->call('moveFolder', $f1->id, $f1->id, 'after');

    expect(Folder::orderBy('position')->pluck('name')->all())->toBe(['A', 'B']);
});

it('moveFolder is a no-op when the resulting order is unchanged', function () {
    mockSidebarPlex();
    $f1 = Folder::factory()->create(['name' => 'A', 'position' => 0]);
    $f2 = Folder::factory()->create(['name' => 'B', 'position' => 1]);

    Livewire::test('sidebar')->call('moveFolder', $f1->id, $f2->id, 'before');

    expect(Folder::orderBy('position')->pluck('name')->all())->toBe(['A', 'B']);
});

it('has a Generate link above Your Library in the sidebar nav', function () {
    mockSidebarPlex();

    Livewire::test('sidebar')
        ->assertSeeHtml('href="'.route('generate').'"')
        ->assertSeeInOrder(['Generate', 'Your Library']);
});

it('shows a Favorites link in the sidebar top nav', function () {
    mockSidebarPlex();

    Livewire::test('sidebar')
        ->assertSee('Favorites')
        ->assertSeeHtml('href="'.route('favorites').'"');
});
