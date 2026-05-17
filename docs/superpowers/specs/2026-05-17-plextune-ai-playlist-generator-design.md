# AI playlist generator (design)

Conversational AI feature that generates Plex playlists from a natural-language prompt. The user describes what they want ("popular new wave songs, 25 tracks"), the AI proposes a playlist, the user can refine via chat, and on accept the playlist is created in Plex.

## Goals

- Single dedicated page (`/generate`) with a chat UI.
- AI is grounded in the actual library: it only proposes tracks that exist on the Plex server, picked from real Plex tag taxonomy (Genre, Style, Mood).
- Back-and-forth refinement: the user can ask the AI to swap tracks, narrow a year range, add more of an artist, etc.
- Accept gate: the playlist is only written to Plex on explicit accept.
- Provider-agnostic via Laravel AI SDK (`laravel/ai`); defaults to OpenAI.

## Non-goals (v1)

- Multi-user support. Plexify is single-user; we seed a synthetic user.
- Streaming token-by-token output. Playlist generation completes in a few seconds and the user wants the finished list.
- Cross-conversation memory ("remember I like long tracks"). Each conversation is self-contained.
- Audio analysis (BPM, key, energy). We rely on Plex's existing Genre/Style/Mood/popularity tags.
- An AI settings panel. Provider/model live in `.env` and `config/ai.php` for v1.

## User flow

1. User clicks "Generate" in the sidebar (new link with `x-lucide-sparkles` icon, above "Your Library").
2. `/generate` page loads with an empty chat, a placeholder prompt suggestion list, and a "New conversation" button.
3. User types e.g. `Create a playlist of popular new wave songs, 20 tracks` and submits.
4. The page calls the agent synchronously. Internally the agent runs a tool-use loop (`listTaxonomy`, `findTracks`, etc., all resolved server-side); the UI shows a "Thinking..." indicator until the final reply lands. The assistant either asks a clarifying question or proposes a playlist.
5. A proposal renders inline as a card: title, description, scrollable tracklist (cover, title, artist, album, duration, AI's one-line reason per track), and three buttons: **Accept and save**, **Refine** (focuses the input), **Discard** (removes the card, conversation continues).
6. On Accept: the page calls `PlexClient::createPlaylist` then `addTrackToPlaylist` for the rest, marks the conversation `accepted`, stores the new playlist's Plex ratingKey, and replaces the card with a success block linking to `/playlist/{ratingKey}`. The sidebar playlist list re-fetches.
7. "New conversation" archives the current conversation and starts a fresh one.

## Architecture

```
app/
  Ai/
    Agents/PlaylistGeneratorAgent.php   (Agent, HasTools, Conversational)
    Tools/
      ListTaxonomy.php
      FindTracks.php
      PopularTracksForArtist.php
      SimilarArtists.php
      SearchArtists.php
      SearchAlbums.php
      ProposePlaylist.php               (terminal tool, see below)
    Support/
      ProposalStore.php                 (per-conversation latest proposal cache)
      LibraryTaxonomy.php               (fetches + caches all styles/moods/genres)
config/ai.php                           (published from laravel/ai; default provider = openai, model = gpt-4o)
resources/views/pages/⚡generate.blade.php
resources/views/components/⚡generate-message.blade.php       (one message bubble)
resources/views/components/⚡generate-proposal-card.blade.php (the proposal render)
database/migrations/<ts>_create_ai_conversations_etc.php     (provided by laravel/ai vendor:publish)
```

### Why a `ProposePlaylist` terminal tool instead of `HasStructuredOutput`

The SDK's `HasStructuredOutput` forces every assistant turn to fit a single schema, which breaks multi-turn refinement (clarifying-question turns have no playlist to return). A tool whose `handle` stores the proposal in `ProposalStore` and returns `"Proposal rendered, awaiting user feedback."` lets the agent flow naturally: it can ask a question, or it can call `ProposePlaylist`, or it can call `ProposePlaylist` again on the next turn after refinement.

The Livewire page polls the proposal store after each agent turn and renders a card when a fresh one is present.

### Grounding the AI in the real library

On agent boot, `LibraryTaxonomy::snapshot()` returns three arrays of strings: `['Pop/Rock', 'Electronic', ...]`, `['New Wave', 'Vaporwave', 'Lo-Fi', ...]`, `['Bittersweet', 'Energetic', ...]`. Counts from the live server: 18 genres, 226 styles, 246 moods, total well under 1k tokens. This snapshot is injected into the system prompt:

> "The user's library has these genres: ... styles: ... moods: ... Only ever filter by tags that appear in these lists. If the user asks for a tag that isn't present, say so and suggest the nearest matches. Only propose tracks you have personally seen returned by a tool call in this conversation. Never invent ratingKeys, titles, or artists."

The taxonomy is cached in `PlexCache` under `plex:taxonomy:{kind}` for 1 hour. (Genres/styles/moods rarely change.)

### Tool surface (handed to the model)

| Tool | Purpose | Inputs | Output |
|---|---|---|---|
| `listTaxonomy` | Resolve a tag name to its Plex id (the AI gets names in the prompt, but filter calls need ids). | `kind: 'genre'\|'style'\|'mood'` | `[{id, name}]` |
| `findTracks` | Combined filter. Returns up to 200 tracks. | `{styleIds?, moodIds?, genreIds?, yearFrom?, yearTo?, artistIds?, limit?}` | `[{ratingKey, title, artist, album, year, durationMs}]` |
| `popularTracksForArtist` | The "fire icon" top tracks for an artist. | `{artistId}` | `[{ratingKey, title, album, year}]` |
| `similarArtists` | Plex's related-artists list. | `{artistId}` | `[{ratingKey, name}]` |
| `searchArtists` | Name-based lookup when the prompt mentions an artist. | `{query, limit?}` | `[{ratingKey, name}]` |
| `searchAlbums` | Name-based lookup when the prompt mentions an album. | `{query, limit?}` | `[{ratingKey, title, artist, year}]` |
| `ProposePlaylist` | Terminal: render a proposal to the user. | `{name, description, trackRatingKeys: string[], rationalePerTrack: {ratingKey: reason}}` | `"Proposal rendered..."` |

Every tool maps to one `PlexClient` call wrapped by `PlexCache`. New `PlexClient` methods needed:
- `findTracks($filters): Collection<Track>` (combined filter against `/library/sections/{id}/all?type=10`)
- `popularTracksForArtist($artistId): Collection<Track>` (`/library/metadata/{id}/popular`)
- `similarArtists($artistId): Collection<Artist>` (read the `Similar` array from the artist's metadata)
- `genres()`, `styles()`, `moods()` (the section taxonomy endpoints)

`searchArtists`/`searchAlbums` can wrap the existing `searchAll`.

### Conversation persistence

Use the SDK's `RemembersConversations` trait, which creates and manages an `ai_conversations`+`ai_messages` schema via `vendor:publish` + `migrate`. The trait wants a `User` model, so we seed a single user with `User::firstOrCreate(['email' => 'plexify@local'])` in a one-time migration. The page's Livewire component holds a `?int $conversationId` prop; null = start new on first prompt.

A small `ai_playlist_proposals` table we add ourselves (since the SDK doesn't model this concept) keys `conversation_id` to the latest proposal JSON plus a `status` (`pending`, `accepted`, `discarded`).

### Page component (Livewire SFC, `pages::generate`)

State:
- `?int $conversationId`
- `string $input = ''`
- `bool $thinking = false`
- `?array $latestProposal = null` (mirrored from `ai_playlist_proposals`)
- `?string $createdPlaylistId = null` (the Plex ratingKey after accept)
- `?string $errorMessage = null`

Actions:
- `send()`: persists user message, calls `(new PlaylistGeneratorAgent)->forUser($user)->continue($conversationId)->prompt($input)`, reloads messages and proposal, clears input.
- `acceptProposal()`: creates the Plex playlist (seed with first track, then add the rest), updates proposal status, dispatches `notify` on error and stays in the chat, on success sets `$createdPlaylistId` and dispatches `playlist-created` so the sidebar refetches.
- `discardProposal()`: marks status `discarded`, removes the card.
- `startNew()`: nulls `$conversationId`, clears messages/proposal.

Messages render as a vertical list of `<x-generate-message>` bubbles; tool calls collapse to a one-line "Searching library..." line (not raw JSON). The proposal card renders as the last item when present.

### Streaming and async

Synchronous `->prompt()` only for v1. The agent can take 3-10 seconds; we show a spinner in the assistant slot while `thinking` is true. Livewire's request is synchronous so the page is blocked, but this matches the rest of the app's interaction model (album switches in the library page also block).

## Error handling

- **Plex unreachable inside a tool**: the tool's `handle` catches `PlexUnreachableException`, returns `"Plex is currently unreachable; cannot search."` as a string. The model will surface this in its reply. The agent continues; the next turn can retry.
- **AI provider error** (OpenAI down, bad API key, rate limit): caught in `send()`, dispatch a `notify` error toast (existing component), keep the conversation intact so the user can retry.
- **Proposal references unknown ratingKey**: when accepting, we re-fetch each track via `findTracks([ratingKeys])` before creating. Any missing one is dropped silently; if zero remain, accept fails with a toast.
- **`createPlaylist` requires a seed track**: documented Plex constraint. The accept flow uses the first proposed track as the seed and adds the rest.
- **No `OPENAI_API_KEY` set**: page shows an inline panel "AI generator not configured. Set OPENAI_API_KEY in .env." instead of the chat UI.

## Settings and config

- New `.env` keys: `OPENAI_API_KEY=`, `AI_PROVIDER=openai`, `AI_MODEL=gpt-4o`.
- `config/ai.php` published from the SDK; we set `default.provider` and `default.model` from env.
- The Settings page is not modified in v1.

## Testing

- **Unit**: each tool's `handle` and `schema`. `LibraryTaxonomy::snapshot` (cache hit/miss, ordering). `ProposalStore` get/set/clear. New `PlexClient` methods with `Http::fake`.
- **Feature**: `pages::generate` mount with `OPENAI_API_KEY` unset shows the configuration panel. `send()` with a mocked agent persists messages. `acceptProposal()` calls `createPlaylist` + `addTrackToPlaylist` the right number of times. `discardProposal()` updates status. `startNew()` resets state.
- **Browser** (`tests/Browser/GenerateTest.php`): hit `/generate`, type a prompt, mock the agent at the route level to return a fixed proposal, click Accept, assert the new playlist appears in the sidebar. Skip if `OPENAI_API_KEY` is unset (mark with `skip()`).
- Laravel AI SDK should expose a fake/recorder; if not, mock the `PlaylistGeneratorAgent` directly via the container.

## Gotchas to watch

- Adding a property to `Track` (we don't need to here, but if we add anything cacheable, `php artisan cache:clear` is required per the documented serialization gotcha).
- `RemembersConversations` migration uses default Laravel users table — confirm the existing `users` table schema (id, email, name, timestamps) is compatible before publishing.
- The agent constructs a per-request `PlaylistGeneratorAgent`; the Plex tools need `PlexClient` injected. Use Laravel container resolution in tool constructors; the SDK supports DI.
- Livewire 4 stable-root rule: the chat page body wraps in a single `<div>`, conditionals inside.

## Open questions deferred to implementation

- Whether to show the AI's tool calls at all in the UI, or hide them entirely behind a single "Searching library..." indicator. (Lean: hide; show on a debug toggle.)
- Whether the proposal card lists "reason" per track or just title/artist/album. (Lean: include reason — it's the AI's value-add.)
- Cost guardrails. Each conversation can fire 5-15 tool calls; with `gpt-4o` that's a few cents. Not worth metering in v1.
