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

            Available tag taxonomy in THIS library (only ever filter by these names):
            - Genres: {$genres}
            - Styles: {$styles}
            - Moods: {$moods}

            Rules:
            - Only propose tracks you have personally seen in a tool result this conversation. Never invent ratingKeys, titles, artists, or albums.
            - Use listTaxonomy to convert a tag name to its id, then findTracks to query.
            - Style is the most useful filter (granular). Mood captures vibe. Genre is coarse.
            - If the user asks for a tag that isn't in the lists above, say so and suggest the closest match you do have.
            - Aim for the user's requested track count. If not specified, default to 20.
            - When ready, call ProposePlaylist with a clear name, one-sentence description, the chosen ratingKeys (in playback order), and a short rationale per track.
            - Prefer a mix of well-known and deeper cuts unless the user specifies otherwise.
            - If the user asks to refine after seeing a proposal, call ProposePlaylist again with the revised list.
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
