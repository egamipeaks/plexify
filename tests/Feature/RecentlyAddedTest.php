<?php

use App\Services\Plex\Dto\Album;
use App\Services\Plex\Exceptions\PlexUnreachableException;
use App\Services\Plex\PlexClient;
use Livewire\Livewire;

function album(string $id, string $title, string $artistId = '100', string $artist = 'Some Artist', ?int $year = 2026): Album
{
    return new Album(
        id: $id,
        title: $title,
        artist: $artist,
        year: $year,
        thumb: "/library/metadata/{$id}/thumb",
        trackCount: 10,
        durationMs: 0,
        artistId: $artistId,
    );
}

function mockRecentlyAddedPlex(?Closure $extra = null): void
{
    test()->mock(PlexClient::class, function ($mock) use ($extra) {
        $mock->shouldReceive('thumbUrl')->andReturnUsing(fn ($t) => $t ? "https://thumb{$t}" : null);
        $mock->shouldReceive('playlists')->andReturn(collect());
        $mock->shouldReceive('ping')->andReturn(['name' => 'Test', 'reachable' => false, 'connection' => 'down', 'machineIdentifier' => null]);

        if ($extra) {
            $extra($mock);
        }
    });
}

it('renders an album card per Recently Added album', function () {
    mockRecentlyAddedPlex(function ($mock) {
        $mock->shouldReceive('recentlyAddedAlbums')->once()->andReturn(collect([
            album('9001', 'One'),
            album('9002', 'Two'),
        ]));
    });

    $url9001 = htmlspecialchars(route('library', ['artist' => '100', 'album' => '9001']), ENT_QUOTES);
    $url9002 = htmlspecialchars(route('library', ['artist' => '100', 'album' => '9002']), ENT_QUOTES);

    Livewire::test('pages::recently-added')
        ->assertSee('Recently Added')
        ->assertSee('One')
        ->assertSee('Two')
        ->assertSeeHtml("href=\"{$url9001}\"")
        ->assertSeeHtml("href=\"{$url9002}\"");
});

it('shows an empty state when there are no recently added albums', function () {
    mockRecentlyAddedPlex(function ($mock) {
        $mock->shouldReceive('recentlyAddedAlbums')->once()->andReturn(collect());
    });

    Livewire::test('pages::recently-added')
        ->assertSee('Recently Added')
        ->assertSee('No recent additions yet');
});

it('shows an error panel and recovers via retry when Plex is unreachable', function () {
    $calls = 0;
    mockRecentlyAddedPlex(function ($mock) use (&$calls) {
        $mock->shouldReceive('recentlyAddedAlbums')->andReturnUsing(function () use (&$calls) {
            $calls++;

            return $calls === 1
                ? throw new PlexUnreachableException('refused')
                : collect([album('9001', 'After Retry')]);
        });
    });

    Livewire::test('pages::recently-added')
        ->assertSee("Can't reach your Plex server")
        ->call('retry')
        ->assertDontSee("Can't reach your Plex server")
        ->assertSee('After Retry');
});
