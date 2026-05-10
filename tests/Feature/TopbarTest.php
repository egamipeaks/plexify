<?php

use Livewire\Livewire;

it('redirects to the search page when the query updates', function () {
    Livewire::test('topbar')
        ->set('query', 'bon iver')
        ->assertRedirect(route('search', ['q' => 'bon iver']));
});

it('redirects to a bare search page when the query is cleared', function () {
    Livewire::test('topbar')
        ->set('query', '')
        ->assertRedirect(route('search', ['q' => '']));
});
