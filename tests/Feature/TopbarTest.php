<?php

use Livewire\Livewire;

it('renders without errors', function () {
    Livewire::test('topbar')->assertOk();
});

it('renders the search input', function () {
    Livewire::test('topbar')->assertSee('topbar-search', escape: false);
});

it('renders the settings link', function () {
    Livewire::test('topbar')->assertSee(route('settings'), escape: false);
});
