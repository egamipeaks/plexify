// Playlist detail + Search results views

const { useState: useViewState } = React;

// ───────────────────────────────────────────────────────────
// Playlist header — editable title, gradient backdrop
// ───────────────────────────────────────────────────────────
function PlaylistHeader({ playlist, onPlay, onEdit }) {
  const [editing, setEditing] = useViewState(false);
  const [name, setName] = useViewState(playlist.name);
  React.useEffect(() => { setName(playlist.name); }, [playlist.id]);

  // Mosaic of 4 playlist covers from QUEUE-ish data
  const mosaic = [
    { c1: '#3a5a40', c2: '#a3b18a' },
    { c1: '#22223b', c2: '#9a8c98' },
    { c1: '#370617', c2: '#d62828' },
    { c1: '#001219', c2: '#0a9396' },
  ];

  return (
    <div className="relative rounded-lg overflow-hidden flex-none"
      style={{ background: 'linear-gradient(180deg, #4a3b6b 0%, #2a2438 60%, var(--surface) 100%)' }}>
      <div className="flex items-end gap-6 p-6">
        {/* Mosaic cover */}
        <div className="grid grid-cols-2 grid-rows-2 w-[180px] h-[180px] rounded shadow-2xl overflow-hidden flex-none">
          {mosaic.map((m, i) => (
            <div key={i} style={{ background: `linear-gradient(135deg, ${m.c1}, ${m.c2})` }} />
          ))}
        </div>
        {/* Meta */}
        <div className="min-w-0 flex-1 flex flex-col gap-2">
          <div className="text-[11px] font-bold uppercase tracking-wider text-white">Playlist</div>
          {editing ? (
            <input autoFocus value={name}
              onChange={(e) => setName(e.target.value)}
              onBlur={() => { setEditing(false); onEdit && onEdit(name); }}
              onKeyDown={(e) => { if (e.key === 'Enter') { setEditing(false); onEdit && onEdit(name); } }}
              className="bg-white/10 outline-none ring-1 ring-white/30 rounded px-2 -ml-2 text-white font-extrabold tracking-tight"
              style={{ fontSize: 'clamp(28px, 4.2vw, 56px)', lineHeight: 1.05 }}
            />
          ) : (
            <h1 onClick={() => setEditing(true)}
              className="text-white font-extrabold tracking-tight cursor-text hover:bg-white/5 rounded px-1 -ml-1 self-start"
              style={{ fontSize: 'clamp(28px, 4.2vw, 56px)', lineHeight: 1.05 }}>
              {playlist.name}
            </h1>
          )}
          <div className="text-[14px] text-text-2 max-w-prose">{playlist.sub}</div>
          <div className="flex items-center gap-1.5 text-[13px] text-white flex-wrap">
            <span className="font-bold">You</span>
            <span className="text-text-2">·</span>
            <span><span className="font-bold">{playlist.count}</span> <span className="text-text-2">songs</span></span>
            <span className="text-text-2">·</span>
            <span className="text-text-2">about 3 hr 42 min</span>
            <span className="text-text-2">·</span>
            <span className="text-text-2 inline-flex items-center gap-1">
              <window.PI.Plex size={11} /> HomeServer
            </span>
          </div>
        </div>
      </div>
      <div className="flex items-center gap-4 px-6 pb-4">
        <button onClick={onPlay}
          className="w-14 h-14 rounded-full bg-accent hover:bg-accent-hover hover:scale-105 active:scale-100 transition-transform grid place-items-center text-black shadow-xl">
          <window.PI.Play size={22} />
        </button>
        <button className="w-10 h-10 rounded-full grid place-items-center text-text-2 hover:text-white hover:scale-105 transition-transform"><window.PI.Shuffle size={18} /></button>
        <button className="px-4 py-1.5 rounded-full border border-white/30 text-white text-[13px] font-bold hover:border-white">
          Edit playlist
        </button>
        <button className="text-text-2 hover:text-white"><window.PI.Download size={20} /></button>
      </div>
    </div>
  );
}

// ───────────────────────────────────────────────────────────
// Search results — top result, songs, artists, albums
// ───────────────────────────────────────────────────────────
function SearchResults({ query, onSelectArtist, onSelectAlbum }) {
  // Filter ARTISTS by query (loose match)
  const q = (query || '').toLowerCase();
  const matchedArtists = window.ARTISTS.filter(a => a.name.toLowerCase().includes(q)).slice(0, 6);
  const allAlbums = [];
  Object.entries(window.ALBUMS_BY_ARTIST).forEach(([artistId, albums]) => {
    albums.forEach(al => allAlbums.push({ ...al, artistId, artistName: window.ARTISTS.find(a => a.id === artistId)?.name }));
  });
  // Always include some albums with default artists for fallback
  window.ARTISTS.forEach(a => {
    if (!window.ALBUMS_BY_ARTIST[a.id]) {
      window.FALLBACK_ALBUMS(a).forEach(al => allAlbums.push({ ...al, artistId: a.id, artistName: a.name }));
    }
  });
  const matchedAlbums = allAlbums.filter(al =>
    al.title.toLowerCase().includes(q) || al.artistName.toLowerCase().includes(q)
  ).slice(0, 6);

  // Top result = first matching artist if any
  const topArtist = matchedArtists[0];
  // Songs = sample tracks from for-emma matching query (or all if blank)
  const songs = (window.TRACKS_BY_ALBUM['for-emma'] || []).filter(t =>
    !q || t.title.toLowerCase().includes(q)
  ).slice(0, 4).map(t => ({ ...t, artist: 'Bon Iver', album: 'For Emma, Forever Ago', c1: '#7a8b69', c2: '#c4d6b0' }));

  const isEmpty = q && !topArtist && matchedAlbums.length === 0 && songs.length === 0;

  if (!q) {
    return (
      <div className="flex-1 grid place-items-center text-center px-6">
        <div>
          <div className="text-[20px] font-bold text-white mb-1">Search your library</div>
          <div className="text-text-2 text-[14px]">Find any song, artist, or album from your Plex server.</div>
        </div>
      </div>
    );
  }

  return (
    <div className="flex-1 overflow-y-auto scroll px-6 pb-6">
      {isEmpty ? (
        <div className="pt-16 text-center">
          <div className="text-[20px] font-bold text-white mb-2">No results found for "{query}"</div>
          <div className="text-text-2 text-[14px]">Please make sure your words are spelled correctly, or use fewer or different keywords.</div>
        </div>
      ) : (
        <div className="grid grid-cols-12 gap-6 pt-4">
          {/* Top result */}
          {topArtist && (
            <section className="col-span-12 lg:col-span-5">
              <h2 className="text-[20px] font-bold text-white mb-3">Top result</h2>
              <button onClick={() => onSelectArtist && onSelectArtist(topArtist.id)}
                className="w-full text-left bg-surface-1 hover:bg-surface-2 rounded-lg p-5 transition-colors group relative">
                <window.PCover c1={topArtist.c1} c2={topArtist.c2} size={92} label={topArtist.name} rounded="rounded-full" className="shadow-xl" />
                <div className="text-[28px] font-extrabold text-white tracking-tight mt-4">{topArtist.name}</div>
                <div className="flex items-center gap-2 mt-1">
                  <span className="text-[12px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-full bg-black/40 text-white">Artist</span>
                  <span className="text-[12px] text-text-2">{topArtist.albums} albums in your library</span>
                </div>
                <div className="absolute right-4 bottom-4 opacity-0 group-hover:opacity-100 translate-y-2 group-hover:translate-y-0 transition-all">
                  <span className="w-12 h-12 rounded-full bg-accent grid place-items-center text-black shadow-xl">
                    <window.PI.Play size={18} />
                  </span>
                </div>
              </button>
            </section>
          )}

          {/* Songs */}
          {songs.length > 0 && (
            <section className={topArtist ? "col-span-12 lg:col-span-7" : "col-span-12"}>
              <h2 className="text-[20px] font-bold text-white mb-3">Songs</h2>
              <div className="flex flex-col">
                {songs.map((s, i) => (
                  <button key={i}
                    className="grid items-center gap-3 px-3 py-2 rounded hover:bg-white/[0.07] transition-colors text-left"
                    style={{ gridTemplateColumns: '40px 1fr 1fr 50px' }}>
                    <window.PCover c1={s.c1} c2={s.c2} size={40} label={s.title} rounded="rounded-sm" />
                    <div className="min-w-0">
                      <div className="truncate text-[14px] font-medium text-white">{s.title}</div>
                      <div className="truncate text-[12px] text-text-2">{s.artist}</div>
                    </div>
                    <div className="truncate text-[13px] text-text-2 hidden md:block">{s.album}</div>
                    <div className="text-[12px] tabular-nums text-text-2 text-right">{s.dur}</div>
                  </button>
                ))}
              </div>
            </section>
          )}

          {/* Artists */}
          {matchedArtists.length > 0 && (
            <section className="col-span-12">
              <div className="flex items-center justify-between mb-3">
                <h2 className="text-[20px] font-bold text-white">Artists</h2>
                <button className="text-[12px] font-bold uppercase tracking-wider text-text-2 hover:text-white">Show all</button>
              </div>
              <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-4">
                {matchedArtists.map(a => (
                  <button key={a.id} onClick={() => onSelectArtist && onSelectArtist(a.id)}
                    className="bg-surface-1 hover:bg-surface-2 rounded-md p-4 transition-colors text-left group">
                    <div className="grid place-items-center mb-3">
                      <window.PCover c1={a.c1} c2={a.c2} size={140} label={a.name} rounded="rounded-full" className="shadow-lg w-full aspect-square !h-auto" />
                    </div>
                    <div className="text-[14px] font-semibold text-white truncate">{a.name}</div>
                    <div className="text-[12px] text-text-2 mt-0.5">Artist</div>
                  </button>
                ))}
              </div>
            </section>
          )}

          {/* Albums */}
          {matchedAlbums.length > 0 && (
            <section className="col-span-12">
              <div className="flex items-center justify-between mb-3">
                <h2 className="text-[20px] font-bold text-white">Albums</h2>
                <button className="text-[12px] font-bold uppercase tracking-wider text-text-2 hover:text-white">Show all</button>
              </div>
              <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-4">
                {matchedAlbums.map(al => (
                  <button key={al.id} onClick={() => onSelectAlbum && onSelectAlbum(al.artistId, al.id)}
                    className="bg-surface-1 hover:bg-surface-2 rounded-md p-4 transition-colors text-left group">
                    <window.PCover c1={al.c1} c2={al.c2} size={140} label={al.title} rounded="rounded" className="shadow-lg w-full aspect-square !h-auto mb-3" />
                    <div className="text-[14px] font-semibold text-white truncate">{al.title}</div>
                    <div className="text-[12px] text-text-2 truncate mt-0.5">{al.year} · {al.artistName}</div>
                  </button>
                ))}
              </div>
            </section>
          )}
        </div>
      )}
    </div>
  );
}

function SearchEmpty() {
  const genres = [
    { name: 'Pop',         c1: '#a83278', c2: '#5e2750' },
    { name: 'Rock',        c1: '#7a3d1c', c2: '#3a1a08' },
    { name: 'Hip-Hop',     c1: '#1f6b40', c2: '#0d3a23' },
    { name: 'Indie',       c1: '#3a4a8a', c2: '#1a234a' },
    { name: 'Electronic',  c1: '#6a3aa8', c2: '#2a1850' },
    { name: 'Folk',        c1: '#8a5a2a', c2: '#3a2208' },
    { name: 'Jazz',        c1: '#2a4a4a', c2: '#0a2222' },
    { name: 'Classical',   c1: '#5a3a1a', c2: '#2a1a08' },
    { name: 'R&B',         c1: '#aa5a3a', c2: '#5a2a18' },
    { name: 'Ambient',     c1: '#2a6a8a', c2: '#0a3a5a' },
    { name: 'Punk',        c1: '#aa1a3a', c2: '#5a0818' },
    { name: 'Soundtrack',  c1: '#3a3a5a', c2: '#1a1a2a' },
  ];
  return (
    <div className="flex-1 overflow-y-auto scroll px-6 pb-6 pt-4">
      <h2 className="text-[20px] font-bold text-white mb-4">Browse all</h2>
      <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3">
        {genres.map(g => (
          <button key={g.name}
            className="relative aspect-[8/5] rounded-md overflow-hidden text-left p-3 hover:scale-[1.02] transition-transform"
            style={{ background: `linear-gradient(135deg, ${g.c1}, ${g.c2})` }}>
            <div className="text-white font-extrabold text-[20px] tracking-tight relative z-10">{g.name}</div>
            <svg viewBox="0 0 100 100" className="absolute -right-2 -bottom-3 w-20 h-20 rotate-25" preserveAspectRatio="xMidYMid slice">
              <rect x="10" y="10" width="80" height="80" rx="6" fill="black" opacity="0.35" transform="rotate(25 50 50)" />
            </svg>
          </button>
        ))}
      </div>
    </div>
  );
}

// ───────────────────────────────────────────────────────────
// Playlist detail screen — header + tracklist
// ───────────────────────────────────────────────────────────
function PlaylistDetail({ playlist, playingIndex, isPlaying, onPlay, onDragStart, onDragEnd, compact, onToggleCompact }) {
  // Synthesize tracks for the playlist by sampling QUEUE + TRACKS_BY_ALBUM
  const sample = window.TRACKS_BY_ALBUM['for-emma'];
  const tracks = [];
  const sources = [
    { artist: 'Bon Iver',         album: 'For Emma, Forever Ago', c1: '#7a8b69', c2: '#c4d6b0' },
    { artist: 'Phoebe Bridgers',  album: 'Punisher',              c1: '#22223b', c2: '#9a8c98' },
    { artist: 'The National',     album: 'High Violet',           c1: '#1d2021', c2: '#7a8b69' },
    { artist: 'Mitski',           album: 'Be the Cowboy',         c1: '#370617', c2: '#d62828' },
    { artist: 'Frank Ocean',      album: 'Blonde',                c1: '#001219', c2: '#0a9396' },
    { artist: 'Big Thief',        album: 'Two Hands',             c1: '#3d405b', c2: '#81b29a' },
  ];
  const seeds = ['Skinny Love', 'Motion Sickness', 'Bloodbuzz Ohio', 'Two Slow Dancers', 'Pink + White', 'Not',
                 'Holocene', 'Punisher', 'I Need My Girl', 'Nobody', 'Self Control', 'Forgotten Eyes',
                 'Re: Stacks', 'Garden Song', 'Bloodbuzz Ohio (Live)'];
  const n = Math.min(playlist.count, 14);
  for (let i = 0; i < n; i++) {
    const src = sources[i % sources.length];
    tracks.push({
      n: i + 1,
      title: seeds[i % seeds.length] + (i >= seeds.length ? ' (Alt)' : ''),
      dur: ['3:39', '4:00', '4:36', '3:46', '5:04', '3:21'][i % 6],
      plays: '—',
      liked: i % 3 === 0,
      artist: src.artist,
      album: src.album,
      c1: src.c1, c2: src.c2,
    });
  }
  return (
    <div className="flex-1 min-h-0 flex flex-col px-2 pb-2 overflow-hidden">
      <div className="overflow-y-auto scroll flex-1 flex flex-col">
        <PlaylistHeader playlist={playlist} onPlay={() => onPlay(0)} />
        <div className="flex-1 min-h-0 mt-2">
          <window.PPlaylistTracklist
            tracks={tracks}
            playingIndex={playingIndex}
            onPlay={onPlay}
            onDragStart={onDragStart}
            onDragEnd={onDragEnd}
            compact={compact}
            onToggleCompact={onToggleCompact}
          />
        </div>
      </div>
    </div>
  );
}

window.PPlaylistDetail = PlaylistDetail;
window.PSearchResults = SearchResults;

// ───────────────────────────────────────────────────────────
// Your Library — unified list of playlists + artists + albums
// ───────────────────────────────────────────────────────────
function LibraryView({ onSelectPlaylist, onSelectArtist, onSelectAlbum }) {
  const [filter, setFilter] = useViewState('all'); // all | playlists | artists | albums
  const [grid, setGrid] = useViewState(false);
  const [q, setQ] = useViewState('');
  const [sort, setSort] = useViewState('recent');

  // Build unified rows
  const rows = [];
  window.PLAYLISTS.forEach(p => rows.push({
    kind: 'playlist', id: p.id, name: p.name,
    sub: `Playlist · You · ${p.count} songs`,
    rounded: 'rounded', c1: '#3e3e3e', c2: '#202020',
    pinned: p.id === 'late-night',
  }));
  window.ARTISTS.forEach(a => rows.push({
    kind: 'artist', id: a.id, name: a.name,
    sub: `Artist · ${a.albums} albums`,
    rounded: 'rounded-full', c1: a.c1, c2: a.c2,
  }));
  Object.entries(window.ALBUMS_BY_ARTIST).forEach(([artistId, albums]) => {
    const artistName = window.ARTISTS.find(a => a.id === artistId)?.name;
    albums.forEach(al => rows.push({
      kind: 'album', id: al.id, artistId, name: al.title,
      sub: `Album · ${artistName} · ${al.year}`,
      rounded: 'rounded', c1: al.c1, c2: al.c2,
    }));
  });

  const filtered = rows
    .filter(r => filter === 'all' || r.kind === filter.slice(0, -1))
    .filter(r => !q || r.name.toLowerCase().includes(q.toLowerCase()));

  if (sort === 'alpha') filtered.sort((a, b) => a.name.localeCompare(b.name));
  if (sort === 'creator') filtered.sort((a, b) => a.kind.localeCompare(b.kind));

  const onClickRow = (r) => {
    if (r.kind === 'playlist') onSelectPlaylist && onSelectPlaylist(r.id);
    else if (r.kind === 'artist') onSelectArtist && onSelectArtist(r.id);
    else onSelectAlbum && onSelectAlbum(r.artistId, r.id);
  };

  const Chip = ({ id, label }) => (
    <button onClick={() => setFilter(id)}
      className={`text-[13px] font-semibold px-3 py-1.5 rounded-full transition-colors
        ${filter === id ? 'bg-white text-black' : 'bg-surface-2 hover:bg-surface-3 text-white'}`}>
      {label}
    </button>
  );

  return (
    <div className="flex-1 min-h-0 flex flex-col px-4 pb-2">
      {/* Header row */}
      <div className="flex items-center justify-between gap-3 pt-3 pb-2 flex-none">
        <div className="flex items-center gap-2">
          <h1 className="text-[22px] font-extrabold text-white tracking-tight">Your Library</h1>
          <button title="Create" className="w-8 h-8 grid place-items-center rounded-full text-text-2 hover:text-white hover:bg-surface-2">
            <window.PI.Plus size={18} />
          </button>
        </div>
      </div>

      {/* Filter chips */}
      <div className="flex items-center gap-2 pb-3 flex-none">
        <Chip id="all" label="All" />
        <Chip id="playlists" label="Playlists" />
        <Chip id="artists" label="Artists" />
        <Chip id="albums" label="Albums" />
      </div>

      {/* Search + sort + grid toggle */}
      <div className="flex items-center justify-between gap-3 pb-2 flex-none">
        <div className="flex items-center gap-2 bg-surface-2 hover:bg-surface-3 transition-colors rounded-md h-8 px-3 w-64 ring-1 ring-transparent focus-within:ring-white/20">
          <window.PI.Search size={14} className="text-text-2" />
          <input value={q} onChange={(e) => setQ(e.target.value)}
            className="bg-transparent outline-none text-[13px] flex-1 placeholder:text-text-2"
            placeholder="Search in Your Library" />
        </div>
        <div className="flex items-center gap-3">
          <select value={sort} onChange={(e) => setSort(e.target.value)}
            className="bg-transparent text-[12px] font-semibold text-text-2 hover:text-white outline-none cursor-pointer">
            <option value="recent" className="bg-surface text-white">Recents</option>
            <option value="alpha" className="bg-surface text-white">Alphabetical</option>
            <option value="creator" className="bg-surface text-white">Type</option>
          </select>
          <button onClick={() => setGrid(!grid)} title={grid ? 'List view' : 'Grid view'}
            className="text-text-2 hover:text-white">
            {grid ? (
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round"><line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="18" x2="20" y2="18"/></svg>
            ) : (
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
            )}
          </button>
        </div>
      </div>

      {/* Body */}
      <div className="overflow-y-auto scroll flex-1">
        {grid ? (
          <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3 pb-4">
            {filtered.map(r => (
              <button key={r.kind + r.id} onClick={() => onClickRow(r)}
                className="bg-surface-1 hover:bg-surface-2 rounded-md p-3 transition-colors text-left">
                <window.PCover c1={r.c1} c2={r.c2} size={140} label={r.name} rounded={r.rounded} className="shadow-lg w-full aspect-square !h-auto mb-3" />
                <div className="text-[14px] font-semibold text-white truncate">{r.name}</div>
                <div className="text-[12px] text-text-2 truncate mt-0.5">{r.sub}</div>
              </button>
            ))}
          </div>
        ) : (
          <div className="flex flex-col gap-1 pb-4">
            {filtered.map(r => (
              <button key={r.kind + r.id} onClick={() => onClickRow(r)}
                className="grid items-center gap-3 px-2 py-2 rounded hover:bg-surface-2 transition-colors text-left"
                style={{ gridTemplateColumns: '52px 1fr auto' }}>
                <window.PCover c1={r.c1} c2={r.c2} size={48} label={r.name} rounded={r.rounded} />
                <div className="min-w-0">
                  <div className="text-[15px] font-semibold text-white truncate flex items-center gap-1.5">
                    {r.name}
                    {r.pinned && <window.PI.Pin size={12} className="text-accent" />}
                  </div>
                  <div className="text-[12px] text-text-2 truncate">{r.sub}</div>
                </div>
                <div className="text-[12px] text-text-2 pr-2 hidden md:block">
                  {r.kind === 'playlist' ? '2 days ago' : r.kind === 'album' ? 'Added Mar 14' : ''}
                </div>
              </button>
            ))}
          </div>
        )}
        {filtered.length === 0 && (
          <div className="pt-12 text-center text-text-2 text-[14px]">Nothing matches "{q}".</div>
        )}
      </div>
    </div>
  );
}

window.PLibraryView = LibraryView;

// ───────────────────────────────────────────────────────────
// Playlist tracklist — like album tracklist but per-track artist/album
// ───────────────────────────────────────────────────────────
const PL_COLS_FULL = '24px 16px 1.4fr 1fr 1fr 60px 24px';
const PL_COLS_COMPACT = '20px 1.4fr 1fr 1fr 50px';

function PlaylistTrackRow({ t, idx, isPlaying, onPlay, onDragStart, onDragEnd, compact }) {
  const [liked, setLiked] = React.useState(t.liked);
  if (compact) {
    return (
      <div draggable
        onDragStart={(e) => { e.dataTransfer.effectAllowed = 'copy'; onDragStart && onDragStart(t); }}
        onDragEnd={onDragEnd}
        onDoubleClick={() => onPlay && onPlay(idx)}
        className={`row group grid items-center px-4 py-[3px] rounded text-[13px] cursor-grab active:cursor-grabbing
          hover:bg-white/[0.07] transition-colors ${isPlaying ? 'is-playing' : ''}`}
        style={{ gridTemplateColumns: PL_COLS_COMPACT }}>
        <span className="tabular-nums text-text-2 text-right pr-1">
          {isPlaying
            ? <span className="eq inline-flex"><span/><span/><span/><span/></span>
            : <>
                <span className="row-num">{t.n}</span>
                <button onClick={() => onPlay && onPlay(idx)} className="row-play text-white">
                  <window.PI.Play size={11} />
                </button>
              </>}
        </span>
        <div className={`truncate font-medium ${isPlaying ? 'text-accent' : 'text-white'}`}>{t.title}</div>
        <div className="text-text-2 group-hover:text-white truncate">{t.artist}</div>
        <div className="text-text-2 truncate">{t.album}</div>
        <div className="text-text-2 tabular-nums text-right text-[12px]">{t.dur}</div>
      </div>
    );
  }
  return (
    <div draggable
      onDragStart={(e) => { e.dataTransfer.effectAllowed = 'copy'; onDragStart && onDragStart(t); }}
      onDragEnd={onDragEnd}
      onDoubleClick={() => onPlay && onPlay(idx)}
      className={`row group grid items-center px-4 py-2 rounded text-[14px] cursor-grab active:cursor-grabbing
        hover:bg-white/[0.07] transition-colors ${isPlaying ? 'is-playing' : ''} ${liked ? 'is-liked' : ''}`}
      style={{ gridTemplateColumns: PL_COLS_FULL }}>
      <span className="text-text-3 group-hover:text-white"><window.PI.Grip size={14} /></span>
      <span className="tabular-nums text-text-2 text-right">
        {isPlaying
          ? <span className="eq inline-flex"><span/><span/><span/><span/></span>
          : <>
              <span className="row-num">{t.n}</span>
              <button onClick={() => onPlay && onPlay(idx)} className="row-play text-white">
                <window.PI.Play size={12} />
              </button>
            </>}
      </span>
      <div className="min-w-0 flex items-center gap-3">
        <window.PCover c1={t.c1} c2={t.c2} size={36} label={t.title} rounded="rounded-sm" />
        <div className="min-w-0">
          <div className={`truncate font-medium ${isPlaying ? 'text-accent' : 'text-white'}`}>{t.title}</div>
          <div className="truncate text-[12px] text-text-2 group-hover:text-white">{t.artist}</div>
        </div>
      </div>
      <div className="text-text-2 group-hover:text-white truncate">{t.album}</div>
      <div className="text-text-2 text-[10px] font-bold tracking-wider">
        <span className="px-1.5 py-0.5 rounded bg-white/5 group-hover:bg-white/10">FLAC</span>
      </div>
      <div className="text-text-2 tabular-nums text-right">{t.dur}</div>
      <button onClick={() => setLiked(!liked)}
        className={`row-like grid place-items-center transition-colors ${liked ? 'text-accent opacity-100' : 'text-text-2 hover:text-white'}`}>
        {liked ? <window.PI.HeartFill size={14} /> : <window.PI.Heart size={14} />}
      </button>
    </div>
  );
}

function PlaylistTracklist({ tracks, playingIndex, onPlay, onDragStart, onDragEnd, compact, onToggleCompact }) {
  const clock = (
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="inline"><circle cx="12" cy="12" r="9"/><path d="M12 8v4l2 2"/></svg>
  );
  return (
    <div className="flex-1 min-h-0 flex flex-col relative">
      {onToggleCompact && (
        <div className="absolute right-2 top-1.5 z-20 flex items-center bg-surface-2 rounded p-0.5">
          <button onClick={() => onToggleCompact(false)} title="Comfortable"
            className={`w-6 h-6 grid place-items-center rounded ${!compact ? 'bg-surface-4 text-white' : 'text-text-2 hover:text-white'}`}>
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
          </button>
          <button onClick={() => onToggleCompact(true)} title="Compact list"
            className={`w-6 h-6 grid place-items-center rounded ${compact ? 'bg-surface-4 text-white' : 'text-text-2 hover:text-white'}`}>
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round"><line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="18" x2="20" y2="18"/></svg>
          </button>
        </div>
      )}
      {compact ? (
        <div className="grid items-center px-4 py-1.5 text-[11px] uppercase tracking-wider text-text-2 border-b border-white/10 sticky top-0 bg-surface z-10 pr-20"
             style={{ gridTemplateColumns: PL_COLS_COMPACT }}>
          <span className="text-right pr-1">#</span>
          <span>Title</span>
          <span>Artist</span>
          <span>Album</span>
          <span className="text-right">{clock}</span>
        </div>
      ) : (
        <div className="grid items-center px-4 py-2 text-[11px] uppercase tracking-wider text-text-2 border-b border-white/10 sticky top-0 bg-surface z-10 pr-20"
             style={{ gridTemplateColumns: PL_COLS_FULL }}>
          <span></span>
          <span className="text-right">#</span>
          <span>Title</span>
          <span>Album</span>
          <span>Quality</span>
          <span className="text-right">{clock}</span>
          <span></span>
        </div>
      )}
      <div className="overflow-y-auto scroll flex-1 py-1">
        {tracks.map((t, i) => (
          <PlaylistTrackRow key={i}
            t={t} idx={i}
            isPlaying={i === playingIndex}
            onPlay={onPlay}
            onDragStart={onDragStart}
            onDragEnd={onDragEnd}
            compact={compact} />
        ))}
      </div>
    </div>
  );
}

window.PPlaylistTracklist = PlaylistTracklist;
