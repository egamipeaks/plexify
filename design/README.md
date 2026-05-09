# Handoff: Plextune — Spotify-style web app for Plex music

## Overview
Plextune is a desktop-first web app for browsing and playing music from a personal Plex Media Server. The UX is modeled after Spotify's three-region shell (left sidebar · main pane · bottom player) but adapted for a power-user listening to a personal library: an artist→album→track miller-column home view, app-side playlist folders (Plex doesn't support folders server-side), and dense compact-toggle modes throughout.

## About the Design Files
The files in this bundle are **design references created in HTML/JSX** — prototypes showing intended look and behavior, not production code to copy directly. They use React via inline Babel and Tailwind CDN to keep the prototype self-contained in a single browser load.

Your task is to **recreate these designs in the target codebase's existing environment** (React + Tailwind is the assumed target — see "Stack" below) using its established patterns, routing, state management, and Plex API integration. If no codebase exists yet, scaffold a Vite + React + Tailwind app and build there.

## Fidelity
**High-fidelity.** Final colors, typography, spacing, iconography, and interactions are decided. Recreate pixel-perfectly within the limits of your component library — the goal is for the production app to feel indistinguishable from the prototype to a user. The only thing to add is real data wiring (Plex API) and persistence.

## Stack (recommended)
- **React 18** + **TypeScript**
- **Tailwind CSS** (the prototype uses Tailwind utility classes throughout — port them directly)
- **Plex API** — talk to the user's Plex Media Server via the official `plex.tv` / PMS HTTP API. Music endpoints under `/library/sections/{id}/all?type=8` (artists), `type=9` (albums), `type=10` (tracks). Auth via Plex token.
- **Zustand or Redux Toolkit** for player + queue state
- **HTML5 Audio** or **howler.js** for playback; Plex transcoder URL for source
- **react-dnd** or native HTML5 DnD for drag-and-drop (the prototype uses native DnD)
- No router needed for the prototype, but in production: **react-router** with routes for `/library`, `/playlist/:id`, `/search`, `/settings`

## Application shell
Three-region grid filling the viewport:
```
┌──────────────────────────────────────────────────────────────┐
│  [topbar: nav arrows · search · avatar]                      │
├──────────┬───────────────────────────────────────────────────┤
│          │                                                   │
│ sidebar  │            main pane (view router)                │
│ ~280px   │                                                   │
│          │                                                   │
├──────────┴───────────────────────────────────────────────────┤
│  [player: now-playing · transport · volume]                  │
└──────────────────────────────────────────────────────────────┘
```
- App shell wrapper: `flex flex-col gap-2 p-2` filling `100vw × 100vh`
- Top region: `flex gap-2 flex-1 min-h-0`
- Sidebar: fixed `w-[280px]` `flex-none`
- Main: `flex-1 min-w-0` with rounded card surfaces

## Screens / Views

### 1. Sidebar (always visible)
Vertical stack of **two rounded cards** stacked with `gap-2`:

**Top nav card** (`bg-surface rounded-lg p-2 flex flex-col gap-1`):
- NavItem: Home (icon + label, 32–36px row)
- NavItem: Search
- NavItem: Your Library *(routes to the Library/album view — same screen as Home)*
- NavItem: Recently Added

NavItem styling: `flex items-center gap-3 px-3 py-2 rounded text-[14px] font-bold`. Active = white text + `bg-surface-2`. Inactive = `text-text-2` hover→white.

**Library/playlists card** (`bg-surface rounded-lg flex flex-col flex-1 min-h-0`):
- Header row: "Your Playlists" title + two icon buttons (New folder, New playlist, both `w-8 h-8 rounded-full text-text-2 hover:text-white hover:bg-surface-3`)
- Filter input (`bg-surface-2 rounded-md h-7 px-2 text-[12px]`) + "Recents ⌄" sort button
- Scrolling folder/playlist list (see Folder UX below)

**Server chip** (`bg-surface rounded-lg p-3` — appears after the playlist list in the same flex column):
- 36px Plex-orange gradient square with Plex icon
- "HomeServer" + green dot (online indicator) + "Direct · 1 Gbps · 2,431 albums"
- Caret button (chevron) to switch servers

### 2. Topbar
`flex items-center gap-4 px-6 h-16`:
- Left: two circular nav arrows (back/forward) — `w-8 h-8 rounded-full bg-black/60`
- Center: search input — `flex-1 max-w-[760px]`, `bg-surface-1 rounded-full h-11 px-4`, magnifying glass icon left, ⌘K hint right
- Right: bell icon, friend activity icon, **avatar circle** that opens **Settings**

Typing in the search input flips the main pane to **Search Results** view. Empty query state shows just "Search your library" centered prompt.

### 3. Library / Home view (the main artist/album/track screen)
Three vertical regions:

**a. Miller columns row** — two side-by-side cards (50/50 split, `grid grid-cols-2 gap-2`), height `220px` (or `260px` in compact mode):
- **Artists** column — list of artists with cover thumbnail (`rounded-full`, 36px), name, album count
- **Albums** column — list of albums for the selected artist with thumbnail (`rounded`, 36px), title, year + format pill (FLAC, FLAC 24b, etc.)

Each column has a header with title + a comfortable/compact toggle (top-right, two icons: 4-square = comfortable, 3-line = compact). Compact mode hides thumbnails and metadata, shows a single-line folder-list with `~3px` row padding.

Selected row: `bg-surface-3 text-white` (comfortable) or `bg-accent/15 text-accent` (compact).

**b. Album header** — gradient strip below the millers, full width:
- Cover: 120×120 rounded shadow-2xl, **draggable** (drag onto a sidebar playlist to add the whole album)
- Right of cover: `ALBUM` eyebrow, large album title (`clamp(22px,3.4vw,40px)` font-black), meta line: artist · year · "N songs, mm:ss" · FLAC pill
- Action row: Play (56px circle, accent green), Shuffle, Download icons
- **Collapse button** top-right (chevron). Collapsed state: 56px-tall single row with 40px cover, title, inline meta, and inline Play / Shuffle / Expand buttons.

**c. Tracklist** — sticky-header table:
- Comfortable columns: `40px(grip) 36px(#) 1.6fr(title+art) 1fr(album) 16px(♥) 60px(time)` with `gridTemplateColumns`
- Compact columns: `20px(#) 1.4fr(title) 1fr(artist) 50px(time)` — no covers, no like, ~3px row padding
- Comfortable/compact toggle in the column header
- Each row: hover bg `white/[0.07]`, playing row tinted accent, draggable to playlists
- Grip handle visible on hover
- No "Plays" column

### 4. Search Results view
- Empty state: centered `<div>` with "Search your library" title + "Find any song, artist, or album from your Plex server." subtitle. **No genre browse grid.**
- With query: classic Spotify layout
  - **Top result** card — large featured artist/album, big play button on hover
  - **Songs** column (right of top result, 4 rows compact)
  - **Artists** row — horizontal cards with circular covers
  - **Albums** row — horizontal cards with square covers
- Click artist/album cards → navigate to album view with that selection

### 5. Playlist Detail view
- **Gradient header** (`linear-gradient(180deg, #4a3b6b → #2a2438 → var(--surface))`):
  - 4-tile mosaic cover (180×180, `grid grid-cols-2 grid-rows-2`)
  - `PLAYLIST` eyebrow, large editable title (click to inline-rename, fontSize `clamp(28px,4.2vw,56px)`)
  - Description, owner, "N songs · total duration"
  - Action row: Play (56px accent circle), Shuffle, **Edit playlist** outlined button, Download. *(No "+", no "⋯" overflow.)*
- **Tracklist** below — same component as Library tracklist but with extra **Album** column shown.

### 6. Settings view
Two-pane layout: **Left rail** (200px nav) + **Right pane** (scrolling page).

Left rail nav: Account · Plex Server · Playback · Library · Display · Downloads · Shortcuts · About. Click → smooth-scroll to anchor in right pane. Back button at top to return to previous view.

Right pane sections (each: `text-[20px] font-bold` heading + grouped rows in `bg-surface rounded-lg`):
- **Account** — Avatar + email + Plex Pass badge + Sign out
- **Plex Server** — server card (icon, name, direct/relay status, ping); Switch server; library picker (which Plex music section to use); transcoding toggle; Resync metadata button
- **Playback** — separate Wi-Fi vs Cellular quality selects (Auto / 96kbps / 256kbps / FLAC); Gapless playback toggle; Crossfade stepper (0–12s); Normalize loudness toggle + level select (Quiet/Normal/Loud); Autoplay; Last.fm scrobble
- **Library** — Default sort (Recently Added / Alphabetical / Most Played); Show singles/comps toggles; Show explicit toggle; Hidden artists count + manage button
- **Display** — Theme select (System/Dark/Light); Accent color swatches (5 swatches: green `#1DB954`, orange `#E5A00D`, blue `#3D91F4`, purple `#9D4EDD`, red `#E63946`); Density default (Comfortable/Compact)
- **Downloads** — Download quality select; Cache size stepper (1–100 GB); Allow on cellular toggle; Clear cache button (danger)
- **Shortcuts** — Two-column kbd reference (Space: play/pause; →/←: next/prev; ⌘K: search; etc.)
- **About** — Version, Check for updates, Open source licenses, Diagnostics

### 7. Player (bottom bar)
`h-[72px] bg-surface rounded-lg px-4 flex items-center gap-4`:
- Left third: now-playing (cover thumb 56px, title + artist, like ♥)
- Center: prev/play/next buttons centered, scrubber (with elapsed / remaining), shuffle + repeat
- Right: **Quality pill** showing "FLAC → 320" (transcoding indicator), Queue, Cast, Volume slider

## Folder UX (sidebar playlists)
Plex doesn't support playlist folders server-side — folders are **app-only**, persisted in user state (localStorage / user prefs).

**Data shape:**
```ts
type Playlist = { id: string; name: string; count: number; sub?: string };
type Folder   = { id: string; name: string; playlistIds: string[] };
// Playlists not in any folder render under an "Other" header.
```

**Behaviors:**
- **Drag a playlist row** onto a folder header → playlist moves into that folder. Drop in the empty "Other" zone → moves out of all folders.
- **Drag a track row** (or **album cover**) onto a playlist → adds to that playlist (visual highlight only in the prototype; production should append the items).
- **Right-click a folder** → context menu: Rename · Expand/Collapse · Delete (danger). Deleting a folder returns its playlists to root.
- **Right-click a playlist** → Play · Add to queue · Move to folder ▶ (submenu lists all folders + "Remove from folder") · Rename playlist · Delete playlist (danger).
- **Double-click a folder name** → inline rename.
- **New folder button** in the playlists header → creates "New Folder" and immediately enters rename mode.
- **Empty folder** shows italic "Empty — drop a playlist here" placeholder.
- **Drop targets** highlight with `bg-accent/15 ring-1 ring-accent/40` while hovering.

## Drag-and-drop summary
Native HTML5 DnD (`draggable`, `dataTransfer.setData`).

| Source | `dataTransfer` MIME | Target |
| --- | --- | --- |
| Track row | `plextune/track` | Playlist row, folder header |
| Album cover (album header) | `plextune/album` | Playlist row, folder header |
| Playlist row | `plextune/playlist` | Folder header, "Other" zone |

## Compact / Comfortable mode
Both miller columns and the tracklist support per-section comfortable/compact toggles (header top-right). Comfortable shows thumbnails + metadata; compact is a single-line folder-list with `~3px` row padding. Persist per-section in user prefs.

## State Management
- **`view`**: `'album' | 'search' | 'playlist' | 'settings'` — main pane router
- **`selectedArtistId`**, **`selectedAlbumId`** — miller column selection
- **`activePlaylistId`** — currently-open playlist (for `view === 'playlist'`)
- **`query`** — search bar text; non-empty switches view to `'search'`
- **`playingIndex`**, **`isPlaying`** — current track index in active list + transport state
- **`queue`** — upcoming tracks
- **`folders`**, **`playlistNameOverrides`**, **`openFolders`** — sidebar app-side state (persist to localStorage)
- **`tracksCompact`**, **`artistsCompact`**, **`albumsCompact`** — compact-mode toggles per section
- **`dropTargetId`**, **`draggingTrack`** — transient DnD state

## Design Tokens

### Colors (Spotify-style dark theme)
| Token | Hex | Usage |
| --- | --- | --- |
| `bg`         | `#000000` | App background (around the rounded cards) |
| `surface`    | `#121212` | Card background (sidebar, main, player) |
| `surface-1`  | `#1a1a1a` | Slight elevation (search input bg) |
| `surface-2`  | `#1f1f1f` | Hover bg for rows |
| `surface-3`  | `#2a2a2a` | Active row, dropdown bg |
| `surface-4`  | `#3a3a3a` | Higher-elevation hover |
| `text-1`     | `#FFFFFF` | Primary text |
| `text-2`     | `#A7A7A7` | Secondary text |
| `text-3`     | `#6A6A6A` | Tertiary / placeholder |
| `accent`     | `#1DB954` | Spotify green — Play buttons, active states, drop highlights |
| `accent-hover` | `#1ED760` | Play button hover |
| `plex-orange-from` | `#E5A00D` | Plex server chip gradient start |
| `plex-orange-to`   | `#CC7700` | Plex server chip gradient end |

### Typography
- Font family: system stack (`-apple-system, "Segoe UI", Roboto, sans-serif`) — Spotify's Circular is proprietary; in production use **Inter** or system stack
- Sizes used: 11 / 12 / 13 / 14 / 15 / 16 / 18 / 20 / 22 / 28 px and `clamp(22px,3.4vw,40px)` / `clamp(28px,4.2vw,56px)` for hero titles
- Weights: 400 (body), 500 (medium), 600 (semibold), 700 (bold), 800 (extrabold), 900 (black headlines)
- Tabular nums on track #s, durations, counts (`tabular-nums`)

### Spacing scale
Tailwind default. Card padding: `p-2` (sidebar nav rows) / `p-3` (server chip) / `px-6 py-5` (album header). Card gap: `gap-2`. Icon button: `w-8 h-8 grid place-items-center rounded-full`. Track rows: `py-2` comfortable, `py-[3px]` compact.

### Border radius
- `rounded` (4px) — small chips, track rows, sidebar items
- `rounded-md` (6px) — search input, album covers in player/header (small)
- `rounded-lg` (8px) — main surface cards
- `rounded-full` — buttons, artist covers, play buttons

### Shadows
- `shadow-lg` — covers in grids
- `shadow-2xl` — album hero cover, dropdown menus
- Drop targets: `ring-1 ring-accent/40` + `bg-accent/15`

## Iconography
The prototype uses inline SVG primitives in `plextune-icons.jsx`. In production, swap for **lucide-react** (close visual match):
- Home, Search, Library, Recent → `Home`, `Search`, `Library`, `Clock`
- Play, Pause, Prev, Next, Shuffle, Repeat → `Play`, `Pause`, `SkipBack`, `SkipForward`, `Shuffle`, `Repeat`
- Heart, Plus, Folder, Music, Grip → `Heart`, `Plus`, `Folder`, `Music`, `GripVertical`
- ChevR (chevron right), Caret, More (kebab) → `ChevronRight`, `ChevronDown`, `MoreHorizontal`
- Plex logo → custom SVG (gradient orange square with stylized "▶")

## Plex API integration notes (for the developer)
- Auth: redirect to `plex.tv` OAuth, store `X-Plex-Token` per user
- List servers: `GET https://plex.tv/api/v2/resources?includeHttps=1` — choose music library section (`type: "artist"`)
- Artists: `GET {server}/library/sections/{id}/all?type=8`
- Albums for artist: `GET {server}/library/metadata/{artistRatingKey}/children`
- Tracks for album: `GET {server}/library/metadata/{albumRatingKey}/children`
- Stream URL: `{server}/library/parts/{partId}/file.flac?X-Plex-Token=...` (direct) or `/video/:/transcode/universal/start.m3u8` (transcoded)
- Playlists: `GET /playlists?playlistType=audio` · `POST /playlists` · `PUT /playlists/{id}/items`
- Folders are **client-only** — store as JSON in localStorage or your app's user prefs API.

## Files in this bundle
- `Plextune.html` — entry HTML, includes Tailwind CDN config + script tags loading the JSX modules
- `plextune-icons.jsx` — inline SVG icon set (`window.PI.*`)
- `plextune-data.jsx` — mock data: `ARTISTS`, `ALBUMS_BY_ARTIST`, `TRACKS_BY_ALBUM`, `PLAYLISTS`, `PLAYLIST_FOLDERS`, `QUEUE`
- `plextune-parts.jsx` — primitives: `Sidebar`, `TopBar`, `MillerCol`, `AlbumHeader`, `Cover`, `FolderedPlaylists`, `PlaylistRow`, `ContextMenu`
- `plextune-views.jsx` — view-level components: `PlaylistDetail`, `SearchResults`, `LibraryView`
- `plextune-settings.jsx` — settings page with all sections
- `plextune-app.jsx` — root `App`, `Tracklist`, `TrackRow`, view router

Open `Plextune.html` in a browser to interact with the prototype while you build.
