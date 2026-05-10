<?php

it('renders the library route', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('Library');
});

it('renders the search route', function () {
    $this->get('/search')
        ->assertOk()
        ->assertSee('Search');
});

it('renders the playlist detail route', function () {
    // The page always renders 200 — either the error or not-found panel depending
    // on whether PLEX_TOKEN is configured and the server is reachable.
    $this->get('/playlist/abc123')->assertOk();
});

it('renders the settings route', function () {
    $this->get('/settings')
        ->assertOk()
        ->assertSee('Settings');
});
