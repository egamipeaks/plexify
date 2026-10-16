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

it('renders a success toast with the success style', function () {
    Livewire::test('toasts')
        ->dispatch('notify', type: 'success', message: 'Album created.')
        ->assertSee('Album created.')
        ->assertSeeHtml('data-toast-type="success"');
});

it('keeps the error style for error toasts', function () {
    Livewire::test('toasts')
        ->dispatch('notify', type: 'error', message: 'Nope.')
        ->assertSeeHtml('data-toast-type="error"');
});
