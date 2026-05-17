<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * End-to-end browser coverage for the /generate page.
 *
 * Two scenarios:
 * 1. When OPENAI_API_KEY is unset: page displays the "AI generator not configured" panel.
 * 2. When OPENAI_API_KEY is set: input form is displayed; verify textarea and button exist.
 *    (Full end-to-end with live API calls is not tested because OpenAI API responses are
 *     unpredictable and we don't want CI to depend on an external service.)
 */

it('shows the AI not-configured panel when OPENAI_API_KEY is empty', function () {
    if (! empty(getenv('OPENAI_API_KEY'))) {
        $this->markTestSkipped('OPENAI_API_KEY is set; this test only checks the unconfigured fallback.');
    }

    visit('/generate')
        ->assertSee('Generate a playlist')
        ->assertSee('AI generator not configured');
});

it('displays the input form when OPENAI_API_KEY is set', function () {
    if (empty(getenv('OPENAI_API_KEY'))) {
        $this->markTestSkipped('OPENAI_API_KEY is not set.');
    }

    $page = visit('/generate');

    $page->assertSee('Generate a playlist');

    // Verify the input textarea and send button are present.
    $hasForm = (bool) $page->script(<<<'JS'
        () => {
            const textarea = document.querySelector('textarea[wire\\:model="input"]');
            const form = document.querySelector('form[wire\\:submit="send"]');
            const button = form ? form.querySelector('button[type="submit"]') : null;
            return !!(textarea && form && button);
        }
    JS);

    expect($hasForm)->toBeTrue('Expected the input form to be present on the /generate page.');
});
