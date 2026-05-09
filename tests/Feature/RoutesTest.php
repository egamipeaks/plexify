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
    $this->get('/playlist/abc123')
        ->assertOk()
        ->assertSee('Playlist abc123');
});

it('renders the settings route', function () {
    $this->get('/settings')
        ->assertOk()
        ->assertSee('Settings');
});
