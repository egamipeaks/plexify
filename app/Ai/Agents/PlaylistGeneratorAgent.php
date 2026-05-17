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
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use RuntimeException;
use Stringable;

class PlaylistGeneratorAgent implements Agent, Conversational, HasTools
{
    use Promptable, RemembersConversations;

    public function __construct(
        private PlexClient $plex,
        private LibraryTaxonomy $taxonomy,
        private ProposalStore $proposals,
    ) {}

    public function instructions(): Stringable|string
    {
        $snap = $this->taxonomy->snapshot();

        $genres = implode(', ', $snap['genres']);
        $styles = implode(', ', $snap['styles']);
        $moods = implode(', ', $snap['moods']);

        return <<<PROMPT
            You are a playlist generator for the user's personal Plex music library. You help them craft playlists from natural-language prompts.

            Available tag taxonomy in THIS library (the only names that filter; use them via listTaxonomy to get ids):
            - Genres: {$genres}
            - Styles: {$styles}
            - Moods: {$moods}

            How you must work:
            1. The user's prompt is a vibe, not a tag. Translate it into concrete filters yourself before asking the user anything. Examples:
               - "80s" => yearFrom=1980, yearTo=1989 (a year range, not a tag)
               - "summer", "beach", "feel-good", "upbeat" => map to relevant Moods (e.g. Bright, Cheerful, Energetic, Fun, Carefree, Summery, Party, Sunday Afternoon) that ACTUALLY appear in the Moods list above; intersect with relevant Styles (Dance-Pop, Synth-Pop, Disco, New Wave, Pop/Rock).
               - "chill", "study", "background" => Mellow, Calm, Relaxed, Ambient, Dreamy moods.
               - A named artist or album => searchArtists / searchAlbums first, then popularTracksForArtist or findTracks.
            2. ALWAYS call tools. Do NOT reply with "no matches" or ask the user to relax their request without first running at least 2-3 different findTracks queries with different filter combinations. Broaden by removing one filter at a time, then by widening the year range, then by switching from Style to Mood, before declaring failure.
            3. Build the playlist incrementally. Call findTracks with a tight filter, see what you got, then loop with looser filters until you have enough tracks for the user's requested count (default 20).
            4. When you have enough, call ProposePlaylist exactly once with name, description, trackRatingKeys in playback order, and a short rationale per track.

            Hard rules:
            - Only propose tracks you have personally seen in a tool result this conversation. Never invent ratingKeys, titles, artists, or albums.
            - Style is granular, Mood captures vibe, Genre is coarse. Combine them with year ranges; you almost never want only a Genre filter.
            - If after several genuine attempts the library truly has nothing close, say so honestly AND state which filters you tried and what they returned. Do not claim "no matches" without that evidence.
            - If the user refines after seeing a proposal, run new findTracks queries and call ProposePlaylist again with the revised list.
            - Aim for a mix of well-known and deeper cuts unless the user specifies otherwise.
            PROMPT;
    }

    public function tools(): iterable
    {
        $conversationId = $this->currentConversation();

        if ($conversationId === null) {
            throw new RuntimeException(
                'PlaylistGeneratorAgent::tools() invoked with no conversation. '.
                'The caller must pre-create one via ConversationStore::storeConversation() and use ->continue($id, as: $user) before prompt().'
            );
        }

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

    public function model(): string
    {
        return config('ai.model', 'gpt-4o');
    }
}
