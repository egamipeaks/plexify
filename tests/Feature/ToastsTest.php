<?php

use Livewire\Livewire;

it('shows a toast when a notify event arrives', function () {
    Livewire::test('toasts')
        ->dispatch('notify', type: 'error', message: 'Something broke.')
        ->assertSee('Something broke.');
});

it('dismisses a toast', function () {
    $component = Livewire::test('toasts')
        ->dispatch('notify', type: 'error', message: 'Something broke.')
        ->assertSee('Something broke.');

    $component->call('dismiss', 0)->assertDontSee('Something broke.');
});
