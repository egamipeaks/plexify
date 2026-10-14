<?php

use App\Enums\AlbumExportStatus;
use App\Jobs\CreateAlbumFromPlaylist;
use App\Models\AlbumExport;
use App\Services\Plex\Dto\MusicSection;
use App\Services\Plex\Dto\Playlist;
use App\Services\Plex\Dto\Track;
use App\Services\Plex\Exceptions\PlexUnreachableException;
use App\Services\Plex\PlexClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->share = sys_get_temp_dir().'/plexify-share-'.Str::random(8);
    File::ensureDirectoryExists("{$this->share}/Music/Src");
    touch("{$this->share}/Music/Src/a.flac");
    config()->set('services.plex.path_from', 'M:\\');
    config()->set('services.plex.path_to', "{$this->share}/");
});

afterEach(fn () => File::deleteDirectory($this->share));

function mockAlbumSidebarPlex(?Closure $tracks = null): void
{
    test()->mock(PlexClient::class, function ($mock) use ($tracks) {
        $mock->makePartial();
        $mock->shouldReceive('playlists')->andReturn(collect([
            new Playlist(id: '42', title: 'Road Trip', trackCount: 1, durationMs: 0, thumb: null, playlistType: 'audio'),
        ]));
        $mock->shouldReceive('thumbUrl')->andReturnNull();
        $mock->shouldReceive('ping')->andReturn(['name' => 'HOME', 'reachable' => true, 'connection' => 'direct', 'machineIdentifier' => 'abc']);
        $mock->shouldReceive('musicSectionTitle')->andReturn('Music');
        $mock->shouldReceive('musicSectionId')->andReturn(6);
        $mock->shouldReceive('musicSections')->andReturn(collect([new MusicSection(6, 'Music', 'M:\\Music')]));

        $tracks
            ? $tracks($mock)
            : $mock->shouldReceive('playlistTracks')->andReturn(collect([
                new Track(id: '1', title: 'Song A', artist: 'A', album: 'X', trackNumber: 1, durationMs: 1, partId: 1, container: 'flac', filePath: 'M:\\Music\\Src\\a.flac'),
            ]));
    });
}

it('queues an album export for a playlist', function () {
    mockAlbumSidebarPlex();

    Livewire::test('sidebar')->call('createAlbum', '42')->assertReturned(true);

    $export = AlbumExport::sole();
    expect($export->plex_playlist_id)->toBe('42')
        ->and($export->title)->toBe('Road Trip')
        ->and($export->status)->toBe(AlbumExportStatus::Queued)
        ->and($export->total)->toBe(1);
    Queue::assertPushed(CreateAlbumFromPlaylist::class, fn ($job) => $job->albumExportId === $export->id);
});

it('refuses a second export while one is in flight', function () {
    mockAlbumSidebarPlex();
    AlbumExport::factory()->running()->create(['plex_playlist_id' => '42']);

    Livewire::test('sidebar')
        ->call('createAlbum', '42')
        ->assertReturned(false)
        ->assertDispatched('notify', type: 'error', message: 'An album for this playlist is already being created.');

    Queue::assertNothingPushed();
});

it('refuses when a source file is missing and names the track', function () {
    mockAlbumSidebarPlex(fn ($mock) => $mock->shouldReceive('playlistTracks')->andReturn(collect([
        new Track(id: '1', title: 'Gone', artist: 'A', album: 'X', trackNumber: 1, durationMs: 1, partId: 1, container: 'flac', filePath: 'M:\\Music\\Src\\gone.flac'),
    ])));

    Livewire::test('sidebar')
        ->call('createAlbum', '42')
        ->assertReturned(false)
        ->assertDispatched('notify', type: 'error', message: 'Couldn\'t create album: The file for "Gone" is missing on the share.');

    expect(AlbumExport::count())->toBe(0);
});

it('refuses when Plex is unreachable', function () {
    mockAlbumSidebarPlex(fn ($mock) => $mock->shouldReceive('playlistTracks')->andThrow(new PlexUnreachableException('down')));

    Livewire::test('sidebar')
        ->call('createAlbum', '42')
        ->assertReturned(false)
        ->assertDispatched('notify', type: 'error', message: 'Couldn\'t load the playlist. Plex may be unreachable.');
});

it('refuses an unknown playlist', function () {
    mockAlbumSidebarPlex();

    Livewire::test('sidebar')
        ->call('createAlbum', '999')
        ->assertReturned(false)
        ->assertDispatched('notify', type: 'error', message: 'That playlist no longer exists.');
});

it('announces a finished export once', function () {
    mockAlbumSidebarPlex();
    $export = AlbumExport::factory()->done('/x')->create(['plex_playlist_id' => '42', 'title' => 'Road Trip']);

    Livewire::test('sidebar')->assertDispatched('notify', type: 'success', message: 'Album "Road Trip" created.');

    expect($export->fresh()->notified_at)->not->toBeNull();

    Livewire::test('sidebar')->assertNotDispatched('notify');
});

it('announces a failed export with its reason', function () {
    mockAlbumSidebarPlex();
    AlbumExport::factory()->failed('ffmpeg exploded')->create(['plex_playlist_id' => '42', 'title' => 'Road Trip']);

    Livewire::test('sidebar')->assertDispatched('notify', type: 'error', message: 'Couldn\'t create album "Road Trip": ffmpeg exploded');
});

it('does not announce an export that is still running', function () {
    mockAlbumSidebarPlex();
    AlbumExport::factory()->running()->create(['plex_playlist_id' => '42']);

    Livewire::test('sidebar')->assertNotDispatched('notify');
});

it('offers Create album in the playlist menu', function () {
    mockAlbumSidebarPlex();

    Livewire::test('sidebar')
        ->assertSeeHtml('data-action="create-album"')
        ->assertSeeHtml('@click="$wire.createAlbum(menu.id); menu = null"')
        ->assertSeeHtml('data-album-done="0"');
});

it('flags a playlist whose album exists so the menu reads Recreate album', function () {
    mockAlbumSidebarPlex();
    AlbumExport::factory()->done('/x')->create(['plex_playlist_id' => '42', 'notified_at' => now()]);

    Livewire::test('sidebar')
        ->assertSeeHtml('data-album-done="1"')
        ->assertSeeHtml("menu?.albumDone ? 'Recreate album' : 'Create album'");
});

it('shows progress on the row and polls while an export runs', function () {
    mockAlbumSidebarPlex();
    AlbumExport::factory()->running()->create(['plex_playlist_id' => '42', 'progress' => 1, 'total' => 3]);

    Livewire::test('sidebar')
        ->assertSee('Creating album… 1/3')
        ->assertDontSee('Playlist · 1 songs')
        ->assertSeeHtml('wire:poll.2s="announceFinishedAlbumExports"');
});

it('does not poll when nothing is running', function () {
    mockAlbumSidebarPlex();

    Livewire::test('sidebar')
        ->assertSee('Playlist · 1 songs')
        ->assertDontSeeHtml('data-album-poll');
});
