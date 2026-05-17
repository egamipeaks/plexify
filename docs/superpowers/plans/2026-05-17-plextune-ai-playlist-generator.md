# AI Playlist Generator Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a `/generate` page where the user chats with an LLM that, grounded in real Plex Genre/Style/Mood tags, proposes a playlist; on accept, the playlist is created on the Plex server.

**Architecture:** A Livewire 4 SFC at `pages/⚡generate.blade.php` drives a `PlaylistGeneratorAgent` (Laravel AI SDK). The agent has tools that wrap `PlexClient` calls so the model can only "see" tracks that exist in the library. A terminal `ProposePlaylist` tool stores the latest proposal in a new `ai_playlist_proposals` table; the page renders it as a card with Accept / Refine / Discard. Conversation history is persisted by the SDK's `RemembersConversations` trait against a single seeded user.

**Tech Stack:** Laravel 13, Livewire 4, `laravel/ai` v0, Pest 4, MySQL, existing `App\Services\Plex\PlexClient`.

**Reference spec:** `docs/superpowers/specs/2026-05-17-plextune-ai-playlist-generator-design.md`. Read it before starting.

---

## File Structure

**Created**
- `app/Ai/Agents/PlaylistGeneratorAgent.php`
- `app/Ai/Tools/ListTaxonomy.php`
- `app/Ai/Tools/FindTracks.php`
- `app/Ai/Tools/PopularTracksForArtist.php`
- `app/Ai/Tools/SimilarArtists.php`
- `app/Ai/Tools/SearchArtists.php`
- `app/Ai/Tools/SearchAlbums.php`
- `app/Ai/Tools/ProposePlaylist.php`
- `app/Ai/Support/LibraryTaxonomy.php`
- `app/Ai/Support/ProposalStore.php`
- `app/Models/AiPlaylistProposal.php`
- `database/migrations/<ts>_create_ai_playlist_proposals_table.php`
- `database/migrations/<ts>_seed_default_user.php`
- `config/ai.php` (published)
- `resources/views/pages/⚡generate.blade.php`
- `resources/views/components/⚡generate-message.blade.php`
- `resources/views/components/⚡generate-proposal-card.blade.php`
- `tests/Unit/Ai/LibraryTaxonomyTest.php`
- `tests/Unit/Ai/ProposalStoreTest.php`
- `tests/Unit/Ai/Tools/ListTaxonomyTest.php`
- `tests/Unit/Ai/Tools/FindTracksTest.php`
- `tests/Unit/Ai/Tools/PopularTracksForArtistTest.php`
- `tests/Unit/Ai/Tools/SimilarArtistsTest.php`
- `tests/Unit/Ai/Tools/SearchArtistsTest.php`
- `tests/Unit/Ai/Tools/SearchAlbumsTest.php`
- `tests/Unit/Ai/Tools/ProposePlaylistTest.php`
- `tests/Feature/GeneratePageTest.php`
- `tests/Browser/GenerateTest.php`

**Modified**
- `composer.json` / `composer.lock` (add `laravel/ai`)
- `app/Services/Plex/PlexClient.php` (new methods: `genres`, `styles`, `moods`, `findTracks`, `popularTracksForArtist`, `similarArtists`)
- `app/Services/Plex/PlexCache.php` (new TTL constants if needed)
- `routes/web.php` (new `/generate` route)
- `resources/views/components/⚡sidebar.blade.php` (add "Generate" link)
- `.env.example` (`OPENAI_API_KEY`, `AI_PROVIDER`, `AI_MODEL`)
- `config/cache.php` (add `AiPlaylistProposal::class` if cached; not currently planned, but flag if it becomes one)
- `tests/Unit/PlexClientTest.php` (extend)
- `CLAUDE.md` (add feature paragraph)

---

## Task 1: Install the Laravel AI SDK

**Files:**
- Modify: `composer.json`, `composer.lock`
- Create: `config/ai.php`, several SDK-provided migrations under `database/migrations/`

- [ ] **Step 1: Install the package**

Run: `composer require laravel/ai`
Expected: package added, autoload regenerated, no errors.

- [ ] **Step 2: Publish config and migrations**

Run: `php artisan vendor:publish --provider="Laravel\\Ai\\AiServiceProvider"`
Expected: prints which files were published. `config/ai.php` and one or more `database/migrations/*_create_ai_*` files appear.

- [ ] **Step 3: Run migrations**

Run: `php artisan migrate`
Expected: new `ai_conversations` and `ai_messages` tables (names may vary by SDK; verify with `php artisan db:show --counts | grep ai_`).

- [ ] **Step 4: Set env defaults**

Add to `.env` (and `.env.example`):
```
OPENAI_API_KEY=
AI_PROVIDER=openai
AI_MODEL=gpt-4o
```

Edit `config/ai.php` so the `default` provider/model read from env: `'provider' => env('AI_PROVIDER', 'openai'), 'model' => env('AI_MODEL', 'gpt-4o')`. (Exact key names depend on the published file; mirror its structure.)

- [ ] **Step 5: Verify the SDK loads**

Run: `php artisan tinker --execute 'dump(class_exists(\Laravel\Ai\Agent::class));'`
Expected: `true`.

- [ ] **Step 6: Commit**

```bash
git add composer.json composer.lock config/ai.php database/migrations .env.example
git commit -m "chore(ai): install laravel/ai SDK and publish config"
```

---

## Task 2: Seed a default user for AI conversations

**Files:**
- Create: `database/migrations/<ts>_seed_default_user.php`

- [ ] **Step 1: Generate the migration**

Run: `php artisan make:migration seed_default_user`

- [ ] **Step 2: Implement the migration**

Replace the file body with:

```php
<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        User::firstOrCreate(
            ['email' => 'plexify@local'],
            ['name' => 'Plexify', 'password' => bcrypt(str()->random(32))],
        );
    }
};
```

(Per project convention, migrations have only `up`.)

- [ ] **Step 3: Run the migration**

Run: `php artisan migrate`
Expected: migration runs. `php artisan tinker --execute 'echo \App\Models\User::count();'` prints `1`.

- [ ] **Step 4: Commit**

```bash
git add database/migrations
git commit -m "chore(ai): seed default Plexify user for AI conversations"
```

---

## Task 3: `ai_playlist_proposals` table and model

**Files:**
- Create: `database/migrations/<ts>_create_ai_playlist_proposals_table.php`
- Create: `app/Models/AiPlaylistProposal.php`

- [ ] **Step 1: Generate the migration**

Run: `php artisan make:migration create_ai_playlist_proposals_table`

Body:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_playlist_proposals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('conversation_id')->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('payload'); // {tracks: [{ratingKey, title, artist, album, year, durationMs, reason}]}
            $table->string('status')->default('pending'); // pending | accepted | discarded
            $table->string('plex_playlist_id')->nullable(); // set after accept
            $table->timestamps();
        });
    }
};
```

Run: `php artisan migrate`.

- [ ] **Step 2: Generate the model**

Run: `php artisan make:model AiPlaylistProposal --no-interaction`

Body:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiPlaylistProposal extends Model
{
    protected $fillable = ['conversation_id', 'name', 'description', 'payload', 'status', 'plex_playlist_id'];

    protected $casts = [
        'payload' => 'array',
    ];
}
```

- [ ] **Step 3: Commit**

```bash
git add database/migrations app/Models/AiPlaylistProposal.php
git commit -m "feat(ai): add ai_playlist_proposals table and model"
```

---

## Task 4: `PlexClient::genres()`, `styles()`, `moods()` (taxonomy)

**Files:**
- Modify: `app/Services/Plex/PlexClient.php`
- Modify: `app/Services/Plex/PlexCache.php` (add `TTL_TAXONOMY = 3600`)
- Test: `tests/Unit/PlexClientTest.php` (extend)

- [ ] **Step 1: Write failing tests**

Append to `tests/Unit/PlexClientTest.php`:

```php
it('fetches genres for the music section', function () {
    Http::fake([
        '*/library/sections/6/genre*' => Http::response([
            'MediaContainer' => ['Directory' => [
                ['key' => '101', 'title' => 'Pop/Rock'],
                ['key' => '102', 'title' => 'Electronic'],
            ]],
        ]),
    ]);

    $plex = makePlexClientWithSection(6); // existing test helper

    $genres = $plex->genres();

    expect($genres)->toHaveCount(2)
        ->and($genres[0])->toMatchArray(['id' => '101', 'name' => 'Pop/Rock']);
});

it('fetches styles for the music section', function () {
    Http::fake([
        '*/library/sections/6/style*' => Http::response([
            'MediaContainer' => ['Directory' => [
                ['key' => '201', 'title' => 'New Wave'],
            ]],
        ]),
    ]);

    expect(makePlexClientWithSection(6)->styles())->toHaveCount(1);
});

it('fetches moods for the music section', function () {
    Http::fake([
        '*/library/sections/6/mood*' => Http::response([
            'MediaContainer' => ['Directory' => [
                ['key' => '301', 'title' => 'Melancholy'],
            ]],
        ]),
    ]);

    expect(makePlexClientWithSection(6)->moods())->toHaveCount(1);
});
```

If `makePlexClientWithSection` doesn't exist, model the test after existing `PlexClientTest` mocks (look at `playlists()` / `recentlyAddedAlbums()` tests for the shape).

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter='fetches genres|fetches styles|fetches moods'`
Expected: 3 FAIL (methods don't exist).

- [ ] **Step 3: Add TTL constant**

Edit `app/Services/Plex/PlexCache.php`: add `public const TTL_TAXONOMY = 3600;` next to the other TTL constants.

- [ ] **Step 4: Implement the methods**

Add to `app/Services/Plex/PlexClient.php` (next to `playlists()`):

```php
/** @return list<array{id: string, name: string}> */
public function genres(): array
{
    return $this->taxonomy('genre');
}

/** @return list<array{id: string, name: string}> */
public function styles(): array
{
    return $this->taxonomy('style');
}

/** @return list<array{id: string, name: string}> */
public function moods(): array
{
    return $this->taxonomy('mood');
}

/** @return list<array{id: string, name: string}> */
private function taxonomy(string $kind): array
{
    return PlexCache::remember("plex:taxonomy:{$kind}", PlexCache::TTL_TAXONOMY, function () use ($kind) {
        $section = $this->musicSectionId();
        $response = $this->http()->get("{$this->baseUrl()}/library/sections/{$section}/{$kind}");

        if (! $response->successful()) {
            throw new PlexUnreachableException("Plex taxonomy fetch failed: {$response->status()}");
        }

        return collect($response->json('MediaContainer.Directory', []))
            ->map(fn (array $row) => ['id' => (string) $row['key'], 'name' => $row['title']])
            ->values()
            ->all();
    });
}
```

(Adapt private helpers `http()`, `baseUrl()`, `musicSectionId()` to whatever the existing class actually exposes; see `playlists()` for the exact pattern.)

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact --filter='fetches genres|fetches styles|fetches moods'`
Expected: 3 PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Plex/PlexClient.php app/Services/Plex/PlexCache.php tests/Unit/PlexClientTest.php
git commit -m "feat(plex): expose genre/style/mood taxonomy lookups"
```

---

## Task 5: `PlexClient::findTracks(array $filters)`

**Files:**
- Modify: `app/Services/Plex/PlexClient.php`
- Test: `tests/Unit/PlexClientTest.php`

- [ ] **Step 1: Write failing tests**

```php
it('finds tracks filtered by style and mood', function () {
    Http::fake([
        '*/library/sections/6/all*style=201*mood=301*' => Http::response(plexTrackListFixture()), // helper that returns MediaContainer JSON
    ]);

    $tracks = makePlexClientWithSection(6)->findTracks([
        'styleIds' => ['201'],
        'moodIds' => ['301'],
        'limit' => 50,
    ]);

    expect($tracks)->toBeInstanceOf(\Illuminate\Support\Collection::class)
        ->and($tracks->first())->toBeInstanceOf(\App\Services\Plex\Dto\Track::class);
});

it('filters by ratingKeys when provided', function () {
    Http::fake([
        '*/library/metadata/12345,67890*' => Http::response(plexTrackListFixture()),
    ]);

    $tracks = makePlexClientWithSection(6)->findTracks(['ratingKeys' => ['12345', '67890']]);

    expect($tracks)->toHaveCount(2);
});
```

(Build `plexTrackListFixture()` in `tests/Pest.php` or inline — return `['MediaContainer' => ['Metadata' => [ ...two Track-shaped rows...]]]`. Model the row shape on existing `recentlyPlayedTracks` test fixtures.)

- [ ] **Step 2: Run, expect fail**

Run: `php artisan test --compact --filter='finds tracks filtered|filters by ratingKeys'`
Expected: 2 FAIL.

- [ ] **Step 3: Implement**

Append to `PlexClient`:

```php
/**
 * @param array{
 *   styleIds?: list<string>, moodIds?: list<string>, genreIds?: list<string>,
 *   artistIds?: list<string>, yearFrom?: int, yearTo?: int,
 *   ratingKeys?: list<string>, limit?: int
 * } $filters
 * @return \Illuminate\Support\Collection<int, \App\Services\Plex\Dto\Track>
 */
public function findTracks(array $filters): \Illuminate\Support\Collection
{
    if (! empty($filters['ratingKeys'])) {
        $ids = implode(',', $filters['ratingKeys']);
        $response = $this->http()->get("{$this->baseUrl()}/library/metadata/{$ids}");
    } else {
        $section = $this->musicSectionId();
        $query = ['type' => 10, 'X-Plex-Container-Size' => $filters['limit'] ?? 50];

        foreach (['styleIds' => 'style', 'moodIds' => 'mood', 'genreIds' => 'genre', 'artistIds' => 'artist'] as $key => $param) {
            if (! empty($filters[$key])) {
                $query[$param] = implode(',', $filters[$key]);
            }
        }
        if (isset($filters['yearFrom'])) {
            $query['year>='] = $filters['yearFrom'];
        }
        if (isset($filters['yearTo'])) {
            $query['year<='] = $filters['yearTo'];
        }

        $response = $this->http()->get("{$this->baseUrl()}/library/sections/{$section}/all", $query);
    }

    if (! $response->successful()) {
        throw new PlexUnreachableException("Plex findTracks failed: {$response->status()}");
    }

    return collect($response->json('MediaContainer.Metadata', []))
        ->map(fn (array $row) => \App\Services\Plex\Dto\Track::fromPlex($row));
}
```

Do NOT cache — filters are too varied and the AI usually iterates.

- [ ] **Step 4: Run tests, expect pass**

Run: `php artisan test --compact --filter='finds tracks filtered|filters by ratingKeys'`
Expected: 2 PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Plex/PlexClient.php tests/Unit/PlexClientTest.php
git commit -m "feat(plex): add findTracks combined filter"
```

---

## Task 6: `PlexClient::popularTracksForArtist($artistId)`

**Files:**
- Modify: `app/Services/Plex/PlexClient.php`
- Test: `tests/Unit/PlexClientTest.php`

- [ ] **Step 1: Failing test**

```php
it('fetches popular tracks for an artist', function () {
    Http::fake([
        '*/library/metadata/58563/popular*' => Http::response(plexTrackListFixture()),
    ]);

    $tracks = makePlexClientWithSection(6)->popularTracksForArtist('58563');

    expect($tracks)->toBeInstanceOf(\Illuminate\Support\Collection::class);
});
```

- [ ] **Step 2: Run, expect fail**

`php artisan test --compact --filter='fetches popular tracks'` → FAIL.

- [ ] **Step 3: Implement**

```php
/** @return \Illuminate\Support\Collection<int, \App\Services\Plex\Dto\Track> */
public function popularTracksForArtist(string $artistId): \Illuminate\Support\Collection
{
    return PlexCache::remember("plex:artist:{$artistId}:popular", PlexCache::TTL_PLAYLISTS, function () use ($artistId) {
        $response = $this->http()->get("{$this->baseUrl()}/library/metadata/{$artistId}/popular");

        if (! $response->successful()) {
            throw new PlexUnreachableException("Plex popular fetch failed: {$response->status()}");
        }

        return collect($response->json('MediaContainer.Metadata', []))
            ->map(fn (array $row) => \App\Services\Plex\Dto\Track::fromPlex($row));
    });
}
```

- [ ] **Step 4: Run, expect pass**

- [ ] **Step 5: Commit**

```bash
git commit -am "feat(plex): popularTracksForArtist"
```

---

## Task 7: `PlexClient::similarArtists($artistId)`

**Files:**
- Modify: `app/Services/Plex/PlexClient.php`
- Test: `tests/Unit/PlexClientTest.php`

- [ ] **Step 1: Failing test**

```php
it('returns similar artists from artist metadata', function () {
    Http::fake([
        '*/library/metadata/58563*' => Http::response([
            'MediaContainer' => ['Metadata' => [[
                'ratingKey' => '58563', 'title' => 'Magnetic Fields',
                'Similar' => [
                    ['id' => '1', 'tag' => 'Stephin Merritt'],
                    ['id' => '2', 'tag' => 'The Gothic Archies'],
                ],
            ]]],
        ]),
    ]);

    $similar = makePlexClientWithSection(6)->similarArtists('58563');

    expect($similar)->toHaveCount(2)
        ->and($similar->first())->toMatchArray(['name' => 'Stephin Merritt']);
});
```

- [ ] **Step 2: Fail**

- [ ] **Step 3: Implement**

```php
/** @return \Illuminate\Support\Collection<int, array{id: string, name: string}> */
public function similarArtists(string $artistId): \Illuminate\Support\Collection
{
    $response = $this->http()->get("{$this->baseUrl()}/library/metadata/{$artistId}");

    if (! $response->successful()) {
        throw new PlexUnreachableException("Plex artist fetch failed: {$response->status()}");
    }

    return collect($response->json('MediaContainer.Metadata.0.Similar', []))
        ->map(fn (array $row) => ['id' => (string) $row['id'], 'name' => $row['tag']])
        ->values();
}
```

Similar tags are name-only on Plex (no ratingKey); the AI can call `searchArtists` to resolve a name back to a `ratingKey` when it wants to use one.

- [ ] **Step 4: Pass**

- [ ] **Step 5: Commit**

```bash
git commit -am "feat(plex): similarArtists from artist metadata"
```

---

## Task 8: `LibraryTaxonomy` support class

**Files:**
- Create: `app/Ai/Support/LibraryTaxonomy.php`
- Test: `tests/Unit/Ai/LibraryTaxonomyTest.php`

- [ ] **Step 1: Failing test**

```php
<?php

use App\Ai\Support\LibraryTaxonomy;
use App\Services\Plex\PlexClient;

it('returns a snapshot of genre/style/mood names from PlexClient', function () {
    $plex = Mockery::mock(PlexClient::class);
    $plex->shouldReceive('genres')->andReturn([['id' => '1', 'name' => 'Pop/Rock']]);
    $plex->shouldReceive('styles')->andReturn([['id' => '2', 'name' => 'New Wave']]);
    $plex->shouldReceive('moods')->andReturn([['id' => '3', 'name' => 'Melancholy']]);

    $snap = (new LibraryTaxonomy($plex))->snapshot();

    expect($snap)->toMatchArray([
        'genres' => ['Pop/Rock'],
        'styles' => ['New Wave'],
        'moods' => ['Melancholy'],
    ]);
});
```

- [ ] **Step 2: Fail**

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Ai\Support;

use App\Services\Plex\PlexClient;

class LibraryTaxonomy
{
    public function __construct(private PlexClient $plex) {}

    /** @return array{genres: list<string>, styles: list<string>, moods: list<string>} */
    public function snapshot(): array
    {
        return [
            'genres' => array_column($this->plex->genres(), 'name'),
            'styles' => array_column($this->plex->styles(), 'name'),
            'moods' => array_column($this->plex->moods(), 'name'),
        ];
    }
}
```

- [ ] **Step 4: Pass**

- [ ] **Step 5: Commit**

```bash
git add app/Ai/Support/LibraryTaxonomy.php tests/Unit/Ai/LibraryTaxonomyTest.php
git commit -m "feat(ai): LibraryTaxonomy snapshot helper"
```

---

## Task 9: `ProposalStore` helper

**Files:**
- Create: `app/Ai/Support/ProposalStore.php`
- Test: `tests/Unit/Ai/ProposalStoreTest.php`

- [ ] **Step 1: Failing test**

```php
<?php

use App\Ai\Support\ProposalStore;
use App\Models\AiPlaylistProposal;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('stores and retrieves the latest proposal for a conversation', function () {
    $store = new ProposalStore();
    $store->put(42, [
        'name' => 'New Wave Faves',
        'description' => 'Twenty essentials',
        'tracks' => [['ratingKey' => '1', 'title' => 'T', 'artist' => 'A', 'reason' => 'classic']],
    ]);

    $proposal = $store->latest(42);

    expect($proposal)->not->toBeNull()
        ->and($proposal->name)->toBe('New Wave Faves')
        ->and($proposal->payload['tracks'])->toHaveCount(1)
        ->and($proposal->status)->toBe('pending');
});

it('returns null when no proposal exists', function () {
    expect((new ProposalStore())->latest(99))->toBeNull();
});

it('marks a proposal accepted with a plex playlist id', function () {
    $store = new ProposalStore();
    $store->put(7, ['name' => 'X', 'description' => null, 'tracks' => []]);
    $store->markAccepted(7, 'abc123');

    expect(AiPlaylistProposal::where('conversation_id', 7)->first())
        ->status->toBe('accepted')
        ->plex_playlist_id->toBe('abc123');
});

it('marks a proposal discarded', function () {
    $store = new ProposalStore();
    $store->put(8, ['name' => 'X', 'description' => null, 'tracks' => []]);
    $store->markDiscarded(8);

    expect(AiPlaylistProposal::where('conversation_id', 8)->first()->status)->toBe('discarded');
});
```

- [ ] **Step 2: Fail**

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Ai\Support;

use App\Models\AiPlaylistProposal;

class ProposalStore
{
    /** @param array{name: string, description: ?string, tracks: array} $proposal */
    public function put(int $conversationId, array $proposal): AiPlaylistProposal
    {
        return AiPlaylistProposal::create([
            'conversation_id' => $conversationId,
            'name' => $proposal['name'],
            'description' => $proposal['description'] ?? null,
            'payload' => ['tracks' => $proposal['tracks'] ?? []],
            'status' => 'pending',
        ]);
    }

    public function latest(int $conversationId): ?AiPlaylistProposal
    {
        return AiPlaylistProposal::where('conversation_id', $conversationId)
            ->latest('id')
            ->first();
    }

    public function markAccepted(int $conversationId, string $plexPlaylistId): void
    {
        $this->latest($conversationId)?->update([
            'status' => 'accepted',
            'plex_playlist_id' => $plexPlaylistId,
        ]);
    }

    public function markDiscarded(int $conversationId): void
    {
        $this->latest($conversationId)?->update(['status' => 'discarded']);
    }
}
```

- [ ] **Step 4: Pass**

- [ ] **Step 5: Commit**

```bash
git add app/Ai/Support/ProposalStore.php tests/Unit/Ai/ProposalStoreTest.php
git commit -m "feat(ai): ProposalStore for per-conversation proposals"
```

---

## Task 10: `ListTaxonomy` tool

**Files:**
- Create: `app/Ai/Tools/ListTaxonomy.php`
- Test: `tests/Unit/Ai/Tools/ListTaxonomyTest.php`

Establishes the pattern used by all later tool tasks. The SDK's exact `Tool`/`Request`/`JsonSchema` class names are confirmed in the Laravel AI SDK docs (see spec); import the actual classes from `Laravel\Ai\Contracts\Tool`, `Laravel\Ai\ToolRequest`, `Laravel\Ai\JsonSchema` (verify on install if names differ — adjust in this task and propagate to subsequent tool tasks).

- [ ] **Step 1: Failing test**

```php
<?php

use App\Ai\Tools\ListTaxonomy;
use App\Services\Plex\PlexClient;
use Laravel\Ai\ToolRequest;

it('returns the requested taxonomy via PlexClient', function () {
    $plex = Mockery::mock(PlexClient::class);
    $plex->shouldReceive('styles')->once()->andReturn([
        ['id' => '201', 'name' => 'New Wave'],
        ['id' => '202', 'name' => 'Vaporwave'],
    ]);

    $tool = new ListTaxonomy($plex);
    $result = $tool->handle(new ToolRequest(['kind' => 'style']));

    expect(json_decode($result, true))->toBe([
        ['id' => '201', 'name' => 'New Wave'],
        ['id' => '202', 'name' => 'Vaporwave'],
    ]);
});

it('rejects an unknown kind', function () {
    $plex = Mockery::mock(PlexClient::class);
    $tool = new ListTaxonomy($plex);

    expect(fn () => $tool->handle(new ToolRequest(['kind' => 'genre']))) // valid
        ->not->toThrow(InvalidArgumentException::class);

    expect(fn () => $tool->handle(new ToolRequest(['kind' => 'bogus'])))
        ->toThrow(InvalidArgumentException::class);
});
```

- [ ] **Step 2: Fail**

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Ai\Tools;

use App\Services\Plex\PlexClient;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\JsonSchema;
use Laravel\Ai\ToolRequest;

class ListTaxonomy implements Tool
{
    public function __construct(private PlexClient $plex) {}

    public function description(): string
    {
        return 'List the available genres, styles, or moods in the user\'s Plex music library. Returns an array of {id, name}. Use this to get ids you can pass to findTracks.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'kind' => $schema->string()->enum(['genre', 'style', 'mood'])->required(),
        ];
    }

    public function handle(ToolRequest $request): string
    {
        $kind = $request['kind'];

        $rows = match ($kind) {
            'genre' => $this->plex->genres(),
            'style' => $this->plex->styles(),
            'mood' => $this->plex->moods(),
            default => throw new InvalidArgumentException("Unknown taxonomy kind: {$kind}"),
        };

        return json_encode($rows);
    }
}
```

- [ ] **Step 4: Pass**

- [ ] **Step 5: Commit**

```bash
git add app/Ai/Tools/ListTaxonomy.php tests/Unit/Ai/Tools/ListTaxonomyTest.php
git commit -m "feat(ai): ListTaxonomy tool"
```

---

## Task 11: `FindTracks` tool

**Files:**
- Create: `app/Ai/Tools/FindTracks.php`
- Test: `tests/Unit/Ai/Tools/FindTracksTest.php`

- [ ] **Step 1: Failing test**

```php
<?php

use App\Ai\Tools\FindTracks;
use App\Services\Plex\Dto\Track;
use App\Services\Plex\PlexClient;
use Laravel\Ai\ToolRequest;

it('forwards filters to PlexClient::findTracks and returns serialized tracks', function () {
    $track = new Track(
        ratingKey: '1', title: 'Bizarre Love Triangle', artist: 'New Order',
        album: 'Substance', year: 1987, durationMs: 264000,
        thumb: null, streamUrl: '', albumId: '2', artistId: '3', playlistItemId: null,
    ); // adjust ctor args to match actual Track DTO

    $plex = Mockery::mock(PlexClient::class);
    $plex->shouldReceive('findTracks')
        ->once()
        ->with(['styleIds' => ['201'], 'limit' => 20])
        ->andReturn(collect([$track]));

    $tool = new FindTracks($plex);
    $result = $tool->handle(new ToolRequest(['styleIds' => ['201'], 'limit' => 20]));

    $rows = json_decode($result, true);
    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray([
            'ratingKey' => '1',
            'title' => 'Bizarre Love Triangle',
            'artist' => 'New Order',
            'album' => 'Substance',
            'year' => 1987,
        ]);
});
```

- [ ] **Step 2: Fail**

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Ai\Tools;

use App\Services\Plex\PlexClient;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\JsonSchema;
use Laravel\Ai\ToolRequest;

class FindTracks implements Tool
{
    public function __construct(private PlexClient $plex) {}

    public function description(): string
    {
        return 'Find tracks in the user\'s library by tag ids and/or year range. Returns up to "limit" tracks (default 50, max 200). Combine styleIds/moodIds/genreIds (AND semantics). Always use ids from listTaxonomy, never names.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'styleIds' => $schema->array()->items($schema->string()),
            'moodIds' => $schema->array()->items($schema->string()),
            'genreIds' => $schema->array()->items($schema->string()),
            'artistIds' => $schema->array()->items($schema->string()),
            'yearFrom' => $schema->integer(),
            'yearTo' => $schema->integer(),
            'limit' => $schema->integer()->min(1)->max(200),
        ];
    }

    public function handle(ToolRequest $request): string
    {
        $filters = array_filter($request->toArray(), fn ($v) => $v !== null && $v !== []);
        $filters['limit'] = min($filters['limit'] ?? 50, 200);

        $tracks = $this->plex->findTracks($filters)
            ->map(fn ($t) => [
                'ratingKey' => $t->ratingKey,
                'title' => $t->title,
                'artist' => $t->artist,
                'album' => $t->album,
                'year' => $t->year,
                'durationMs' => $t->durationMs,
            ])
            ->values()
            ->all();

        return json_encode($tracks);
    }
}
```

- [ ] **Step 4: Pass**

- [ ] **Step 5: Commit**

```bash
git add app/Ai/Tools/FindTracks.php tests/Unit/Ai/Tools/FindTracksTest.php
git commit -m "feat(ai): FindTracks tool"
```

---

## Task 12: `PopularTracksForArtist` tool

Same pattern as Task 11. Schema: `{ artistId: string (required) }`. Calls `$this->plex->popularTracksForArtist($artistId)` and JSON-encodes the same row shape as `FindTracks`. Description: "Get the artist's most popular tracks (the 'fire icon' in Plex). Useful when the user names an artist or to seed a playlist of an artist's bangers."

- [ ] **Step 1: Write the failing test** (mirror Task 11's test; mock `popularTracksForArtist`)
- [ ] **Step 2: Run, expect fail**
- [ ] **Step 3: Implement** (mirror `FindTracks` shape, single required `artistId` param)
- [ ] **Step 4: Run, expect pass**
- [ ] **Step 5: Commit** `feat(ai): PopularTracksForArtist tool`

---

## Task 13: `SimilarArtists` tool

Schema: `{ artistId: string (required) }`. Handle calls `$this->plex->similarArtists($artistId)` and JSON-encodes `[{id, name}]`. Description: "Get artists similar to a given artist (per Plex metadata). Names only; use searchArtists to resolve a name to an id you can pass to findTracks."

- [ ] **Step 1: Failing test** (mock `similarArtists`)
- [ ] **Step 2: Fail**
- [ ] **Step 3: Implement**
- [ ] **Step 4: Pass**
- [ ] **Step 5: Commit** `feat(ai): SimilarArtists tool`

---

## Task 14: `SearchArtists` tool

Schema: `{ query: string (required), limit: int (default 10) }`. Handle calls `$this->plex->searchAll($query)` and returns up to `limit` items from the `artists` collection as `[{id, name}]`. Description: "Search for artists by name. Returns top matches with ids you can pass to other tools."

- [ ] **Step 1: Failing test**
- [ ] **Step 2: Fail**
- [ ] **Step 3: Implement**
- [ ] **Step 4: Pass**
- [ ] **Step 5: Commit** `feat(ai): SearchArtists tool`

---

## Task 15: `SearchAlbums` tool

Schema: `{ query: string (required), limit: int (default 10) }`. Pulls from `searchAll($query)`'s `albums` collection. Returns `[{id, title, artist, year}]`. Description: "Search for albums by title. Useful when the user names an album."

- [ ] **Step 1: Failing test**
- [ ] **Step 2: Fail**
- [ ] **Step 3: Implement**
- [ ] **Step 4: Pass**
- [ ] **Step 5: Commit** `feat(ai): SearchAlbums tool`

---

## Task 16: `ProposePlaylist` terminal tool

**Files:**
- Create: `app/Ai/Tools/ProposePlaylist.php`
- Test: `tests/Unit/Ai/Tools/ProposePlaylistTest.php`

- [ ] **Step 1: Failing test**

```php
<?php

use App\Ai\Support\ProposalStore;
use App\Ai\Tools\ProposePlaylist;
use App\Services\Plex\Dto\Track;
use App\Services\Plex\PlexClient;
use Laravel\Ai\ToolRequest;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('stores the proposal and returns an ack string', function () {
    $track = makeTrack(ratingKey: '12345', title: 'Just Like Heaven', artist: 'The Cure', album: 'Kiss Me');

    $plex = Mockery::mock(PlexClient::class);
    $plex->shouldReceive('findTracks')->once()->andReturn(collect([$track]));

    $tool = new ProposePlaylist($plex, new ProposalStore(), conversationId: 42);
    $result = $tool->handle(new ToolRequest([
        'name' => 'New Wave Essentials',
        'description' => 'Twenty stone-cold classics',
        'trackRatingKeys' => ['12345'],
        'rationalePerTrack' => ['12345' => 'genre-defining 1987 track'],
    ]));

    expect($result)->toContain('Proposal rendered');

    $latest = (new ProposalStore())->latest(42);
    expect($latest->name)->toBe('New Wave Essentials')
        ->and($latest->payload['tracks'])->toHaveCount(1)
        ->and($latest->payload['tracks'][0]['ratingKey'])->toBe('12345')
        ->and($latest->payload['tracks'][0]['reason'])->toBe('genre-defining 1987 track');
});
```

- [ ] **Step 2: Fail**

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Ai\Tools;

use App\Ai\Support\ProposalStore;
use App\Services\Plex\PlexClient;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\JsonSchema;
use Laravel\Ai\ToolRequest;

class ProposePlaylist implements Tool
{
    public function __construct(
        private PlexClient $plex,
        private ProposalStore $store,
        private int $conversationId,
    ) {}

    public function description(): string
    {
        return 'Render a playlist proposal to the user. Call this when you are ready to present a final list. The user will see it as a card with Accept / Refine / Discard. trackRatingKeys MUST be ratingKeys returned by earlier tool calls in this conversation.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->required(),
            'description' => $schema->string(),
            'trackRatingKeys' => $schema->array()->items($schema->string())->required(),
            'rationalePerTrack' => $schema->object(),
        ];
    }

    public function handle(ToolRequest $request): string
    {
        $rationale = $request['rationalePerTrack'] ?? [];

        $tracks = $this->plex->findTracks(['ratingKeys' => $request['trackRatingKeys']])
            ->map(fn ($t) => [
                'ratingKey' => $t->ratingKey,
                'title' => $t->title,
                'artist' => $t->artist,
                'album' => $t->album,
                'year' => $t->year,
                'durationMs' => $t->durationMs,
                'reason' => $rationale[$t->ratingKey] ?? null,
            ])
            ->values()
            ->all();

        $this->store->put($this->conversationId, [
            'name' => $request['name'],
            'description' => $request['description'] ?? null,
            'tracks' => $tracks,
        ]);

        return 'Proposal rendered, awaiting user feedback.';
    }
}
```

- [ ] **Step 4: Pass**

- [ ] **Step 5: Commit**

```bash
git add app/Ai/Tools/ProposePlaylist.php tests/Unit/Ai/Tools/ProposePlaylistTest.php
git commit -m "feat(ai): ProposePlaylist terminal tool"
```

---

## Task 17: `PlaylistGeneratorAgent`

**Files:**
- Create: `app/Ai/Agents/PlaylistGeneratorAgent.php`

(Skip a unit test on this class: it's a wiring shell. We'll exercise it via the feature test in Task 19 with a mocked agent.)

- [ ] **Step 1: Implement the agent**

```php
<?php

namespace App\Ai\Agents;

use App\Ai\Support\LibraryTaxonomy;
use App\Ai\Support\ProposalStore;
use App\Ai\Tools\FindTracks;
use App\Ai\Tools\ListTaxonomy;
use App\Ai\Tools\PopularTracksForArtist;
use App\Ai\Tools\ProposePlaylist;
use App\Ai\Tools\SearchAlbums;
use App\Ai\Tools\SearchArtists;
use App\Ai\Tools\SimilarArtists;
use App\Services\Plex\PlexClient;
use Laravel\Ai\Concerns\Promptable;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;

class PlaylistGeneratorAgent implements Agent, Conversational, HasTools
{
    use Promptable, RemembersConversations;

    public function __construct(
        private PlexClient $plex,
        private LibraryTaxonomy $taxonomy,
        private ProposalStore $proposals,
    ) {}

    public function instructions(): string
    {
        $snap = $this->taxonomy->snapshot();

        $genres = implode(', ', $snap['genres']);
        $styles = implode(', ', $snap['styles']);
        $moods = implode(', ', $snap['moods']);

        return <<<PROMPT
You are a playlist generator for the user's personal Plex music library. You help them craft playlists from natural-language prompts.

Available tag taxonomy in THIS library (use these names only):
- Genres: {$genres}
- Styles: {$styles}
- Moods: {$moods}

Rules:
- Only propose tracks you have personally seen in a tool result this conversation. Never invent ratingKeys, titles, artists, or albums.
- Use listTaxonomy to convert a tag name to its id, then findTracks to query.
- Style is the most useful filter (granular). Mood is good for vibe. Genre is coarse.
- If the user asks for a tag that isn't in the lists above, say so and suggest the closest matches you DO have.
- Aim for the user's requested track count. If not specified, default to 20.
- When ready, call ProposePlaylist with a clear name, one-sentence description, the chosen ratingKeys (in playback order), and a short rationale per track.
- Prefer a mix of well-known and deeper cuts unless the user specifies otherwise.
- If the user asks to refine after seeing a proposal, call ProposePlaylist again with the revised list.
PROMPT;
    }

    public function tools(): iterable
    {
        $conversationId = $this->currentConversationId(); // provided by RemembersConversations

        return [
            new ListTaxonomy($this->plex),
            new FindTracks($this->plex),
            new PopularTracksForArtist($this->plex),
            new SimilarArtists($this->plex),
            new SearchArtists($this->plex),
            new SearchAlbums($this->plex),
            new ProposePlaylist($this->plex, $this->proposals, $conversationId),
        ];
    }
}
```

(If the SDK's trait names or `currentConversationId()` accessor differ from the docs, adjust based on the published code; the implementer should `grep -r 'RemembersConversations' vendor/laravel/ai` to confirm.)

- [ ] **Step 2: Smoke check**

Run: `php artisan tinker --execute 'dump(app(\App\Ai\Agents\PlaylistGeneratorAgent::class)->instructions());'`
Expected: prints the prompt with real genre/style/mood lists from the live Plex server. (If Plex is unreachable, this throws; that's fine here.)

- [ ] **Step 3: Commit**

```bash
git add app/Ai/Agents/PlaylistGeneratorAgent.php
git commit -m "feat(ai): PlaylistGeneratorAgent with grounded taxonomy prompt"
```

---

## Task 18: `/generate` route and Livewire SFC skeleton

**Files:**
- Modify: `routes/web.php`
- Create: `resources/views/pages/⚡generate.blade.php`
- Test: `tests/Feature/GeneratePageTest.php`

- [ ] **Step 1: Failing test**

```php
<?php

use App\Models\User;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    User::firstOrCreate(['email' => 'plexify@local'], ['name' => 'Plexify', 'password' => bcrypt('x')]);
    config()->set('services.openai.key', null);
    putenv('OPENAI_API_KEY=');
});

it('renders the generate page', function () {
    $this->get('/generate')->assertOk()->assertSee('Generate a playlist');
});

it('shows a configuration panel when OPENAI_API_KEY is missing', function () {
    Livewire::test('pages::generate')->assertSee('AI generator not configured');
});
```

(Mock the PlexClient as in other feature tests so taxonomy + ping calls don't hit Plex.)

- [ ] **Step 2: Run, fail**

- [ ] **Step 3: Add the route**

In `routes/web.php`: `Route::livewire('/generate', 'pages::generate')->name('generate');`

- [ ] **Step 4: Create the SFC**

`resources/views/pages/⚡generate.blade.php`:

```blade
@php
new class extends Livewire\Component {
    public function rendering(\Illuminate\View\View $view): void
    {
        $view->layout('components.layouts.app');
    }

    public function configured(): bool
    {
        return ! empty(env('OPENAI_API_KEY'));
    }
}
@endphp

<div class="h-full overflow-y-auto p-6">
    <div class="mx-auto max-w-3xl">
        <h1 class="text-2xl font-bold text-text-1 mb-6">Generate a playlist</h1>

        @if (! $this->configured())
            <div class="rounded-lg bg-surface-1 p-6 text-text-2">
                <p class="font-semibold text-text-1 mb-2">AI generator not configured</p>
                <p>Set <code>OPENAI_API_KEY</code> in your <code>.env</code> file to enable AI playlist generation.</p>
            </div>
        @else
            <p class="text-text-2">Chat UI lands in Task 19.</p>
        @endif
    </div>
</div>
```

- [ ] **Step 5: Run, pass**

Run: `php artisan test --compact --filter=GeneratePageTest`
Expected: 2 PASS.

- [ ] **Step 6: Commit**

```bash
git add routes/web.php resources/views/pages/⚡generate.blade.php tests/Feature/GeneratePageTest.php
git commit -m "feat(generate): /generate route and config-missing panel"
```

---

## Task 19: Chat UI + `send()` action with message persistence

**Files:**
- Modify: `resources/views/pages/⚡generate.blade.php`
- Create: `resources/views/components/⚡generate-message.blade.php`
- Modify: `tests/Feature/GeneratePageTest.php`

- [ ] **Step 1: Failing test**

Append to `tests/Feature/GeneratePageTest.php`:

```php
it('sends a prompt to the agent and renders the response', function () {
    putenv('OPENAI_API_KEY=sk-test');

    // Bind a fake agent that records the prompt and returns a canned assistant message.
    $this->app->bind(\App\Ai\Agents\PlaylistGeneratorAgent::class, function () {
        return new class {
            public ?int $conversationId = null;
            public string $lastPrompt = '';

            public function forUser($user): self { return $this; }
            public function continue(?int $id): self { $this->conversationId = $id; return $this; }
            public function prompt(string $text): object
            {
                $this->lastPrompt = $text;
                return (object) [
                    'conversationId' => 1,
                    'text' => 'Got it. Want jangle pop or synth-driven?',
                ];
            }
        };
    });

    Livewire::test('pages::generate')
        ->set('input', 'Make me a new wave playlist')
        ->call('send')
        ->assertSee('Make me a new wave playlist')
        ->assertSee('Want jangle pop or synth-driven');
});
```

- [ ] **Step 2: Fail**

- [ ] **Step 3: Implement**

Replace the SFC body's `@php` block to add state + `send`, and add a message list + input form. Keep the existing config-missing branch.

```blade
@php
use App\Ai\Agents\PlaylistGeneratorAgent;
use App\Models\User;

new class extends Livewire\Component {
    public string $input = '';
    public ?int $conversationId = null;
    /** @var list<array{role: string, content: string}> */
    public array $messages = [];
    public bool $thinking = false;
    public ?string $errorMessage = null;

    public function rendering(\Illuminate\View\View $view): void
    {
        $view->layout('components.layouts.app');
    }

    public function configured(): bool
    {
        return ! empty(env('OPENAI_API_KEY'));
    }

    public function send(): void
    {
        $text = trim($this->input);
        if ($text === '') { return; }

        $this->messages[] = ['role' => 'user', 'content' => $text];
        $this->input = '';
        $this->thinking = true;
        $this->errorMessage = null;

        try {
            $user = User::where('email', 'plexify@local')->firstOrFail();
            $agent = app(PlaylistGeneratorAgent::class);
            $response = $agent->forUser($user)->continue($this->conversationId)->prompt($text);

            $this->conversationId = $response->conversationId;
            $this->messages[] = ['role' => 'assistant', 'content' => $response->text];
        } catch (\Throwable $e) {
            $this->errorMessage = $e->getMessage();
            $this->dispatch('notify', type: 'error', message: 'AI request failed: '.$e->getMessage());
        } finally {
            $this->thinking = false;
        }
    }
}
@endphp

<div class="h-full overflow-y-auto p-6">
    <div class="mx-auto max-w-3xl">
        <h1 class="text-2xl font-bold text-text-1 mb-6">Generate a playlist</h1>

        @if (! $this->configured())
            <div class="rounded-lg bg-surface-1 p-6 text-text-2">
                <p class="font-semibold text-text-1 mb-2">AI generator not configured</p>
                <p>Set <code>OPENAI_API_KEY</code> in your <code>.env</code> file to enable AI playlist generation.</p>
            </div>
        @else
            <div class="space-y-4 mb-6" data-testid="messages">
                @foreach ($messages as $msg)
                    <x-generate-message :role="$msg['role']" :content="$msg['content']" />
                @endforeach

                @if ($thinking)
                    <div class="text-text-3 italic">Thinking...</div>
                @endif
            </div>

            <form wire:submit="send" class="sticky bottom-0 bg-base pt-4">
                <div class="flex gap-2">
                    <textarea wire:model="input" rows="2" placeholder="Describe the playlist you want..."
                              class="flex-1 rounded-lg bg-surface-1 p-3 text-text-1"></textarea>
                    <button type="submit" class="rounded-lg bg-accent px-4 py-2 font-semibold text-black hover:bg-accent-hover">Send</button>
                </div>
            </form>
        @endif
    </div>
</div>
```

`resources/views/components/⚡generate-message.blade.php`:

```blade
@props(['role', 'content'])

<div @class([
    'rounded-lg p-4',
    'bg-surface-1 text-text-1' => $role === 'user',
    'bg-surface-2 text-text-2' => $role === 'assistant',
])>
    <div class="text-xs uppercase tracking-wide text-text-3 mb-1">{{ $role }}</div>
    <div class="whitespace-pre-wrap">{{ $content }}</div>
</div>
```

- [ ] **Step 4: Run tests, expect pass**

Run: `php artisan test --compact --filter=GeneratePageTest`
Expected: 3 PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/views/pages/⚡generate.blade.php resources/views/components/⚡generate-message.blade.php tests/Feature/GeneratePageTest.php
git commit -m "feat(generate): chat UI with send action"
```

---

## Task 20: Proposal card render

**Files:**
- Create: `resources/views/components/⚡generate-proposal-card.blade.php`
- Modify: `resources/views/pages/⚡generate.blade.php`
- Modify: `tests/Feature/GeneratePageTest.php`

- [ ] **Step 1: Failing test**

Append:

```php
it('renders the latest pending proposal as a card', function () {
    putenv('OPENAI_API_KEY=sk-test');

    // Bind a fake agent that, on prompt(), inserts a proposal directly.
    $this->app->bind(\App\Ai\Agents\PlaylistGeneratorAgent::class, function () {
        return new class {
            public function forUser($user): self { return $this; }
            public function continue(?int $id): self { return $this; }
            public function prompt(string $text): object
            {
                \App\Models\AiPlaylistProposal::create([
                    'conversation_id' => 1,
                    'name' => 'New Wave Essentials',
                    'description' => 'Twenty classics',
                    'payload' => ['tracks' => [
                        ['ratingKey' => '1', 'title' => 'Just Like Heaven', 'artist' => 'The Cure', 'album' => 'Kiss Me', 'year' => 1987, 'reason' => 'classic'],
                    ]],
                    'status' => 'pending',
                ]);
                return (object) ['conversationId' => 1, 'text' => 'Here is a proposal.'];
            }
        };
    });

    Livewire::test('pages::generate')
        ->set('input', 'go')->call('send')
        ->assertSee('New Wave Essentials')
        ->assertSee('Just Like Heaven')
        ->assertSeeHtml('wire:click="acceptProposal"');
});
```

- [ ] **Step 2: Fail**

- [ ] **Step 3: Implement the card**

`resources/views/components/⚡generate-proposal-card.blade.php`:

```blade
@props(['proposal'])

<div class="rounded-lg bg-surface-1 p-6 border border-surface-3" data-testid="proposal-card">
    <h2 class="text-xl font-bold text-text-1">{{ $proposal->name }}</h2>
    @if ($proposal->description)
        <p class="text-text-2 mt-1">{{ $proposal->description }}</p>
    @endif

    <ol class="mt-4 space-y-2 max-h-96 overflow-y-auto">
        @foreach ($proposal->payload['tracks'] as $i => $track)
            <li class="flex items-baseline gap-3 text-sm">
                <span class="text-text-3 w-6 text-right">{{ $i + 1 }}</span>
                <div class="flex-1">
                    <div class="text-text-1">{{ $track['title'] }} <span class="text-text-3">— {{ $track['artist'] }}</span></div>
                    @if (! empty($track['reason']))
                        <div class="text-text-3 text-xs italic">{{ $track['reason'] }}</div>
                    @endif
                </div>
            </li>
        @endforeach
    </ol>

    <div class="mt-4 flex gap-2">
        <button wire:click="acceptProposal" class="rounded-lg bg-accent px-4 py-2 font-semibold text-black hover:bg-accent-hover">Accept and save</button>
        <button wire:click="$set('input', 'Please refine: ')" class="rounded-lg bg-surface-2 px-4 py-2 text-text-1">Refine</button>
        <button wire:click="discardProposal" class="rounded-lg bg-surface-2 px-4 py-2 text-text-1">Discard</button>
    </div>
</div>
```

In the SFC, add a `proposal()` accessor and render the card after the messages loop:

```php
use App\Ai\Support\ProposalStore;
use App\Models\AiPlaylistProposal;

public function proposal(): ?AiPlaylistProposal
{
    if (! $this->conversationId) { return null; }
    $p = app(ProposalStore::class)->latest($this->conversationId);
    return $p && $p->status === 'pending' ? $p : null;
}
```

And in the blade body, after the messages loop:

```blade
@if ($p = $this->proposal())
    <x-generate-proposal-card :proposal="$p" />
@endif
```

- [ ] **Step 4: Pass**

- [ ] **Step 5: Commit**

```bash
git add resources/views/components/⚡generate-proposal-card.blade.php resources/views/pages/⚡generate.blade.php tests/Feature/GeneratePageTest.php
git commit -m "feat(generate): render proposal card"
```

---

## Task 21: `acceptProposal()` writes the playlist to Plex

**Files:**
- Modify: `resources/views/pages/⚡generate.blade.php`
- Modify: `tests/Feature/GeneratePageTest.php`

- [ ] **Step 1: Failing test**

```php
it('creates a Plex playlist on accept and marks proposal accepted', function () {
    putenv('OPENAI_API_KEY=sk-test');

    \App\Models\AiPlaylistProposal::create([
        'conversation_id' => 1,
        'name' => 'NW',
        'description' => null,
        'payload' => ['tracks' => [
            ['ratingKey' => '1', 'title' => 'A', 'artist' => 'X', 'album' => 'Q', 'year' => 1987, 'reason' => null],
            ['ratingKey' => '2', 'title' => 'B', 'artist' => 'Y', 'album' => 'R', 'year' => 1988, 'reason' => null],
        ]],
        'status' => 'pending',
    ]);

    $plex = Mockery::mock(\App\Services\Plex\PlexClient::class);
    $plex->shouldReceive('ping')->andReturn(true);
    $plex->shouldReceive('createPlaylist')->once()->with('NW', '1')->andReturn('new-playlist-id');
    $plex->shouldReceive('addTrackToPlaylist')->once()->with('new-playlist-id', '2');
    $plex->shouldReceive('playlists')->andReturn(collect());
    $plex->shouldReceive('thumbUrl')->andReturn('');
    $this->app->instance(\App\Services\Plex\PlexClient::class, $plex);

    Livewire::test('pages::generate')
        ->set('conversationId', 1)
        ->call('acceptProposal')
        ->assertDispatched('playlist-created');

    expect(\App\Models\AiPlaylistProposal::find(1)->status)->toBe('accepted')
        ->and(\App\Models\AiPlaylistProposal::find(1)->plex_playlist_id)->toBe('new-playlist-id');
});
```

- [ ] **Step 2: Fail**

- [ ] **Step 3: Implement**

Add to the SFC:

```php
public function acceptProposal(): void
{
    $proposal = app(ProposalStore::class)->latest($this->conversationId);
    if (! $proposal || $proposal->status !== 'pending') { return; }

    $tracks = $proposal->payload['tracks'];
    if (empty($tracks)) {
        $this->dispatch('notify', type: 'error', message: 'Proposal has no tracks.');
        return;
    }

    try {
        $plex = app(\App\Services\Plex\PlexClient::class);
        $plexId = $plex->createPlaylist($proposal->name, $tracks[0]['ratingKey']);

        foreach (array_slice($tracks, 1) as $track) {
            $plex->addTrackToPlaylist($plexId, $track['ratingKey']);
        }

        app(ProposalStore::class)->markAccepted($this->conversationId, $plexId);
        $this->dispatch('playlist-created', id: $plexId);
    } catch (\Throwable $e) {
        $this->dispatch('notify', type: 'error', message: 'Could not save playlist: '.$e->getMessage());
    }
}
```

- [ ] **Step 4: Pass**

- [ ] **Step 5: Commit**

```bash
git commit -am "feat(generate): acceptProposal writes playlist to Plex"
```

---

## Task 22: `discardProposal()` and `startNew()`

**Files:**
- Modify: `resources/views/pages/⚡generate.blade.php`
- Modify: `tests/Feature/GeneratePageTest.php`

- [ ] **Step 1: Failing tests**

```php
it('discards the latest proposal', function () {
    putenv('OPENAI_API_KEY=sk-test');
    \App\Models\AiPlaylistProposal::create([
        'conversation_id' => 1, 'name' => 'X', 'payload' => ['tracks' => []], 'status' => 'pending',
    ]);

    Livewire::test('pages::generate')->set('conversationId', 1)->call('discardProposal');

    expect(\App\Models\AiPlaylistProposal::find(1)->status)->toBe('discarded');
});

it('resets state on startNew', function () {
    putenv('OPENAI_API_KEY=sk-test');

    Livewire::test('pages::generate')
        ->set('conversationId', 99)
        ->set('messages', [['role' => 'user', 'content' => 'hi']])
        ->call('startNew')
        ->assertSet('conversationId', null)
        ->assertSet('messages', []);
});
```

- [ ] **Step 2: Fail**

- [ ] **Step 3: Implement**

```php
public function discardProposal(): void
{
    if ($this->conversationId) {
        app(ProposalStore::class)->markDiscarded($this->conversationId);
    }
}

public function startNew(): void
{
    $this->conversationId = null;
    $this->messages = [];
    $this->input = '';
    $this->errorMessage = null;
}
```

Add a "New conversation" button to the blade header:

```blade
<div class="flex items-center justify-between mb-6">
    <h1 class="text-2xl font-bold text-text-1">Generate a playlist</h1>
    @if ($messages)
        <button wire:click="startNew" class="text-sm text-text-2 hover:text-text-1">New conversation</button>
    @endif
</div>
```

- [ ] **Step 4: Pass**

- [ ] **Step 5: Commit**

```bash
git commit -am "feat(generate): discardProposal and startNew"
```

---

## Task 23: Sidebar "Generate" link

**Files:**
- Modify: `resources/views/components/⚡sidebar.blade.php`
- Modify: `tests/Feature/SidebarTest.php`

- [ ] **Step 1: Failing test**

Append:

```php
it('has a Generate link in the sidebar', function () {
    Livewire::test('sidebar')->assertSeeHtml('href="/generate"')->assertSee('Generate');
});
```

- [ ] **Step 2: Fail**

- [ ] **Step 3: Add the link**

In the sidebar's top nav block, above "Your Library":

```blade
<a href="{{ route('generate') }}" wire:navigate
   class="flex items-center gap-3 px-3 py-2 text-text-2 hover:text-text-1 rounded-lg">
    <x-lucide-sparkles class="w-5 h-5" />
    <span>Generate</span>
</a>
```

(Match the styling of the existing "Your Library" / "Search" entries; copy their exact class list.)

- [ ] **Step 4: Pass**

Also re-run any test that asserts the full sidebar structure; update snapshots if needed.

- [ ] **Step 5: Commit**

```bash
git commit -am "feat(sidebar): add Generate link"
```

---

## Task 24: Browser test (live)

**Files:**
- Create: `tests/Browser/GenerateTest.php`

- [ ] **Step 1: Write the test**

```php
<?php

use App\Models\User;

it('navigates to /generate and shows the configuration panel when API key missing', function () {
    User::firstOrCreate(['email' => 'plexify@local'], ['name' => 'Plexify', 'password' => bcrypt('x')]);
    if (env('OPENAI_API_KEY')) {
        $this->markTestSkipped('OPENAI_API_KEY is set; this test only checks the unconfigured fallback.');
    }

    visit('/generate')->assertSee('AI generator not configured');
});

it('runs an end-to-end conversation when configured', function () {
    if (! env('OPENAI_API_KEY')) {
        $this->markTestSkipped('OPENAI_API_KEY is not set.');
    }

    User::firstOrCreate(['email' => 'plexify@local'], ['name' => 'Plexify', 'password' => bcrypt('x')]);

    visit('/generate')
        ->fill('textarea', 'Create a 5-track new wave playlist.')
        ->click('Send')
        ->assertSee('Thinking...', timeout: 1)
        ->assertVisible('[data-testid="proposal-card"]', timeout: 60_000);
});
```

(Adjust Pest browser API names if they differ — see existing `tests/Browser/SearchTest.php` for the project's idioms.)

- [ ] **Step 2: Run**

Run: `php artisan test --compact --filter=GenerateTest tests/Browser/GenerateTest.php`
Expected: first test passes (or skips); second skips when API key missing, runs when present.

- [ ] **Step 3: Commit**

```bash
git add tests/Browser/GenerateTest.php
git commit -m "test(generate): browser test for /generate page"
```

---

## Task 25: Update `CLAUDE.md`

**Files:**
- Modify: `CLAUDE.md`

- [ ] **Step 1: Add a feature paragraph**

Insert (after the M7 follow-up paragraph, before "Gotchas"):

> **AI playlist generator (shipped 2026-MM-DD, branch `feat/ai-playlist-generator`).** Spec: `docs/superpowers/specs/2026-05-17-plextune-ai-playlist-generator-design.md`. Plan: `docs/superpowers/plans/2026-05-17-plextune-ai-playlist-generator.md`. New `/generate` page (`pages/⚡generate.blade.php`) drives a Laravel AI SDK agent (`App\Ai\Agents\PlaylistGeneratorAgent`) that has tools wrapping `PlexClient` (`ListTaxonomy`, `FindTracks`, `PopularTracksForArtist`, `SimilarArtists`, `SearchArtists`, `SearchAlbums`) plus a terminal `ProposePlaylist` tool that writes to a new `ai_playlist_proposals` table. The agent's system prompt embeds a snapshot of the library's real Genre/Style/Mood names so the model only filters by tags that exist. Conversations persist via the SDK's `RemembersConversations` trait against a single seeded `plexify@local` user. Accept creates the playlist on Plex (seed track + add the rest); Refine continues the chat; Discard marks the proposal discarded. Provider/model are `.env`-driven (`AI_PROVIDER=openai`, `AI_MODEL=gpt-4o`).

Add a gotchas bullet:

> - **The `ProposePlaylist` tool is constructed with the live `conversationId`.** Don't try to make it a singleton — the agent's `tools()` method instantiates a fresh `ProposePlaylist` per turn using `currentConversationId()` from `RemembersConversations`.

- [ ] **Step 2: Commit**

```bash
git add CLAUDE.md
git commit -m "docs(claude): record AI playlist generator feature"
```

---

## Self-Review Notes

**Spec coverage:** every section of the spec maps to at least one task — install (T1), seed user (T2), proposal storage (T3), PlexClient extensions (T4–T7), grounding (T8), proposal store (T9), tools (T10–T16), agent (T17), page + actions (T18–T22), sidebar link (T23), browser test (T24), CLAUDE.md (T25). The "no API key" panel is T18. Error handling for Plex unreachable inside tools is via the `PlexUnreachableException` thrown by the new `PlexClient` methods (the SDK surfaces tool exceptions back to the model).

**Type consistency:** `PlaylistGeneratorAgent::tools()` references all seven tool classes exactly as they're named in T10–T16. `ProposalStore::put`/`latest`/`markAccepted`/`markDiscarded` are used in T16 (tool), T20 (card render), T21 (accept), T22 (discard) with matching signatures. `AiPlaylistProposal` columns (`payload`, `status`, `plex_playlist_id`) match across T3, T9, T20, T21.

**Known fuzzy spots flagged in tasks:**
- Exact SDK class names (`Tool`, `ToolRequest`, `JsonSchema`, `RemembersConversations::currentConversationId()`) — implementer must verify after `composer require laravel/ai` and adjust.
- Existing `PlexClient` private helper names (`http()` / `baseUrl()` / `musicSectionId()`) — mirror whatever the class actually uses; the new methods follow `playlists()` as a template.
- `Track` DTO constructor signature in T11 test — match the actual DTO; `makeTrack(...)` helper may be cleanest.
