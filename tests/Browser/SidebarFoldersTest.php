<?php

use App\Models\Folder;
use App\Models\FolderPlaylist;
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
    // RefreshDatabase wraps each test in a transaction, but make the intent explicit
    // and guard against future trait changes. (folder_id is nullOnDelete, not cascade,
    // so deleting folders alone would leave orphaned root-placement rows.)
    FolderPlaylist::query()->delete();
    Folder::query()->delete();
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

it('reorders two root playlists from the sidebar and restores the original order', function () {
    $page = visit('/');

    // Wait for at least two sidebar playlist rows to render.
    expect((bool) $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            const deadline = Date.now() + 10000;
            while (Date.now() < deadline) {
                if (document.querySelectorAll('[wire\\:key^="sidebar-pl-"]').length >= 2) return true;
                await sleep(150);
            }
            return false;
        })()
    JS))->toBeTrue('Expected at least 2 playlists in the sidebar (is the Plex server reachable?).');

    $result = $page->script(<<<'JS'
        (async () => {
            const sleep = ms => new Promise(r => setTimeout(r, ms));
            const rows = () => [...document.querySelectorAll('[wire\\:key^="sidebar-pl-"]')];
            const keys = () => rows().map(el => el.getAttribute('wire:key'));
            const plId = el => (el.getAttribute('wire:key') || '').replace('sidebar-pl-', '');

            // The sidebar Livewire component: the closest wire:id ancestor of a sidebar-pl row.
            const anchor = rows()[0];
            const compEl = anchor ? anchor.closest('[wire\\:id]') : null;
            const compId = compEl ? compEl.getAttribute('wire:id') : null;
            const comp = compId ? window.Livewire.find(compId) : null;
            if (!comp || typeof comp.call !== 'function') return { ok: false, reason: 'no sidebar Livewire component', before: [], swapped: [], restored: [] };

            const before = keys();
            const firstId = plId(rows()[0]);
            const secondId = plId(rows()[1]);
            if (!firstId || !secondId) return { ok: false, reason: 'could not read playlist ids from wire:key', before, swapped: [], restored: [] };

            // Move the 2nd root playlist before the 1st.
            await comp.call('movePlaylist', secondId, null, firstId, 'before');
            for (let i = 0; i < 60 && keys()[0] === before[0]; i++) await sleep(150);
            const swapped = keys();

            // Restore: secondId is now first; move it back to after firstId (which is now at index 1).
            await comp.call('movePlaylist', secondId, null, firstId, 'after');
            for (let i = 0; i < 60 && keys()[0] !== before[0]; i++) await sleep(150);
            const restored = keys();

            return { ok: true, reason: '', before, swapped, restored };
        })()
    JS);

    expect($result['ok'])->toBeTrue($result['reason'] ?? 'reorder script failed');
    expect($result['swapped'][0])->toBe($result['before'][1], 'The second playlist should be first after moving it before the first.');
    expect($result['restored'])->toBe($result['before'], 'The original sidebar playlist order should be restored.');
});
