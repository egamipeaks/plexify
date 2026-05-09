<?php

it('keeps the now-playing state visible when navigating between views', function () {
    $page = visit('/');

    // Confirm initial Library view loads.
    $page->assertSee('Library view stub')
        ->click('Play test track');

    // Once playback starts, the player shows the track title.
    $page->assertSee('Test Track')
        ->assertSee('Local File');

    // Navigate to /search via the sidebar link (wire:navigate).
    $page->click('Search')
        ->assertUrlIs(url('/search'))
        ->assertSee('Search view stub');

    // The player region survived navigation: now-playing title is still visible.
    // This proves the persistent player + @persist('audio') architecture works,
    // because the player component would otherwise have been re-mounted with empty state.
    $page->assertSee('Test Track')
        ->assertSee('Local File');

    // Navigate back to Library — still alive.
    $page->click('Home')
        ->assertUrlIs(url('/'))
        ->assertSee('Test Track');
});
