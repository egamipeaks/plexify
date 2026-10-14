<?php

use App\Services\Albums\AlbumExportException;
use App\Services\Albums\AlbumPaths;
use App\Services\Plex\Dto\MusicSection;
use App\Services\Plex\Dto\Track;
use App\Services\Plex\PlexClient;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->share = sys_get_temp_dir().'/plexify-share-'.Str::random(8);
    File::ensureDirectoryExists("{$this->share}/Music/Src");
    config()->set('services.plex.path_from', 'M:\\');
    config()->set('services.plex.path_to', "{$this->share}/");

    $this->mock(PlexClient::class, function ($mock) {
        $mock->shouldReceive('musicSectionId')->andReturn(6);
        $mock->shouldReceive('musicSections')->andReturn(collect([new MusicSection(6, 'Music', 'M:\\Music')]));
    });
});

afterEach(fn () => File::deleteDirectory($this->share));

function pathsTrack(?string $file, string $title = 'Song'): Track
{
    return new Track(id: '1', title: $title, artist: 'A', album: 'B', trackNumber: 1, durationMs: 1, partId: 1, container: 'flac', filePath: $file);
}

it('targets Various Artists inside the active library folder', function () {
    expect(app(AlbumPaths::class)->targetFolder('Road: Trip', '42'))->toBe("{$this->share}/Music/Various Artists/Road_ Trip");
});

it('throws when the library folder is outside the mapped prefix', function () {
    config()->set('services.plex.path_from', 'Z:\\');

    app(AlbumPaths::class)->compilationsFolder();
})->throws(AlbumExportException::class);

it('has no problem with mapped existing files', function () {
    touch("{$this->share}/Music/Src/a.flac");

    expect(app(AlbumPaths::class)->problemWith(collect([pathsTrack('M:\\Music\\Src\\a.flac')])))->toBeNull();
});

it('reports an unconfigured mapping', function () {
    config()->set('services.plex.path_to', '');

    expect(app(AlbumPaths::class)->problemWith(collect([pathsTrack('M:\\a')])))->toContain('PLEX_PATH_TO');
});

it('reports an unmounted share', function () {
    config()->set('services.plex.path_to', '/Volumes/definitely-not-mounted-'.Str::random(6).'/');

    expect(app(AlbumPaths::class)->problemWith(collect([pathsTrack('M:\\a')])))->toBe('The music share is not mounted.');
});

it('reports an empty playlist', function () {
    expect(app(AlbumPaths::class)->problemWith(collect()))->toBe('The playlist is empty.');
});

it('names a track whose path cannot be mapped', function () {
    expect(app(AlbumPaths::class)->problemWith(collect([pathsTrack(null, 'Lost Song')])))->toBe('Can\'t locate the file for "Lost Song".');
});

it('names a track whose file is missing on the share', function () {
    expect(app(AlbumPaths::class)->problemWith(collect([pathsTrack('M:\\Music\\Src\\gone.flac', 'Gone')])))->toBe('The file for "Gone" is missing on the share.');
});
