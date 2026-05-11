<?php

use App\Models\Folder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * End-to-end coverage for the sidebar folders + drag-and-drop UI.
 *
 * Hits the live Plex library configured via PLEX_TOKEN — requires the server to be reachable
 * with at least one audio playlist (same environmental dependency as the other Browser tests).
 * List interactions are driven via script() (page.evaluate) for the same reasons documented in
 * the other browser tests (strict click() locators, no auto-wait on assertVisible()).
 *
 * Cleans up the Folder rows it creates so the dev database is left as it was found.
 */

afterEach(function () {
    // Belt-and-suspenders cleanup: RefreshDatabase wraps each test in a transaction,
    // but this makes the intent explicit and guards against future trait changes.
    Folder::query()->delete(); // cascades folder_playlists
});

it('creates a folder from the sidebar and it survives a reload', function () {
    $page = visit('/');

    $created = (bool) $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            const btn = document.querySelector('button[title="New folder"]');
            if (!btn) return false;
            btn.click();
            const deadline = Date.now() + 8000;
            while (Date.now() < deadline) {
                if (document.querySelector('[wire\\:key^="folder-"]')) return true;
                await sleep(150);
            }
            return false;
        })()
    JS);
    expect($created)->toBeTrue('Expected a folder row after clicking "New folder".');

    expect(Folder::count())->toBe(1);

    // Reload — the folder is DB-backed, so it should still be there.
    $page = visit('/');
    $page->assertPresent('[wire\:key^="folder-"]');
});

it('reveals the "New playlist" drop zone while a track is being dragged', function () {
    $page = visit('/');

    $zoneVisible = (bool) $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));

            // Get a track row to drag: open the first artist, then the first album.
            const deadline = Date.now() + 15000;
            const clickFirst = (sel) => { const el = document.querySelector(sel); if (el) el.click(); return !!el; };

            // Artists column
            while (Date.now() < deadline && !document.querySelector('[data-region="artists-column"] button')) await sleep(150);
            if (!clickFirst('[data-region="artists-column"] button')) return false;
            while (Date.now() < deadline && !document.querySelector('[data-region="albums-column"] button')) await sleep(150);
            if (!clickFirst('[data-region="albums-column"] button')) return false;
            while (Date.now() < deadline && !document.querySelector('[data-region="tracklist"] button[draggable="true"]')) await sleep(150);

            const row = document.querySelector('[data-region="tracklist"] button[draggable="true"]');
            if (!row) return false;

            const dt = new DataTransfer();
            row.dispatchEvent(new DragEvent('dragstart', { bubbles: true, dataTransfer: dt }));
            await sleep(150);

            const zone = document.querySelector('[data-region="new-playlist-zone"]');
            const visible = !!zone && getComputedStyle(zone).display !== 'none';

            row.dispatchEvent(new DragEvent('dragend', { bubbles: true, dataTransfer: dt }));
            return visible;
        })()
    JS);

    expect($zoneVisible)->toBeTrue('Expected the "New playlist" drop zone to appear when dragging a track.');
});

it('drops a playlist into a folder and renders it nested', function () {
    $page = visit('/');

    $nested = (bool) $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            const deadline = Date.now() + 12000;

            // Ensure there is at least one playlist row.
            while (Date.now() < deadline && !document.querySelector('[wire\\:key^="sidebar-pl-"] a')) await sleep(150);
            const pl = document.querySelector('[wire\\:key^="sidebar-pl-"] a');
            if (!pl) return false;
            const playlistKey = pl.closest('[wire\\:key^="sidebar-pl-"]').getAttribute('wire:key');

            // Create a folder.
            const newFolderBtn = document.querySelector('button[title="New folder"]');
            if (!newFolderBtn) return false;
            newFolderBtn.click();
            while (Date.now() < deadline && !document.querySelector('[wire\\:key^="folder-"]')) await sleep(150);
            const folderEl = document.querySelector('[wire\\:key^="folder-"]');
            if (!folderEl) return false;
            const folderHeader = folderEl.querySelector('div'); // first child div carries the @drop handler

            // Simulate dragging the playlist <a> onto the folder header.
            const dt = new DataTransfer();
            pl.dispatchEvent(new DragEvent('dragstart', { bubbles: true, dataTransfer: dt }));
            folderHeader.dispatchEvent(new DragEvent('dragover', { bubbles: true, dataTransfer: dt }));
            folderHeader.dispatchEvent(new DragEvent('drop', { bubbles: true, dataTransfer: dt }));
            pl.dispatchEvent(new DragEvent('dragend', { bubbles: true, dataTransfer: dt }));

            // Wait for the Livewire round-trip: the playlist row should now live inside the folder element.
            const after = Date.now() + 8000;
            while (Date.now() < after) {
                const folder = document.querySelector('[wire\\:key^="folder-"]');
                if (folder && folder.querySelector(`[wire\\:key="${playlistKey}"]`)) return true;
                await sleep(200);
            }
            return false;
        })()
    JS);

    expect($nested)->toBeTrue('Expected the dropped playlist to render nested inside the new folder.');
});
