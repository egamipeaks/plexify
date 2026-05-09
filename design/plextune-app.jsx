// Plextune main app — assembles the layout.
const { useState, useMemo } = React;

// ───────────────────────────────────────────────────────────
// Tracklist
// ───────────────────────────────────────────────────────────
const TRACK_COLS_FULL = '24px 16px 1fr 1.2fr 56px 60px 24px';
const TRACK_COLS_COMPACT = '20px 1.4fr 1fr 50px';

function TrackRow({ t, idx, isPlaying, onPlay, onDragStart, onDragEnd, compact }) {
  const [liked, setLiked] = React.useState(t.liked);
  if (compact) {
    return (
      <div
        draggable
        onDragStart={(e) => { e.dataTransfer.effectAllowed = 'copy'; onDragStart && onDragStart(t); }}
        onDragEnd={onDragEnd}
        onDoubleClick={() => onPlay && onPlay(t)}
        className={`row group grid items-center px-4 py-[3px] rounded text-[13px] cursor-grab active:cursor-grabbing
          hover:bg-white/[0.07] transition-colors ${isPlaying ? 'is-playing' : ''}`}
        style={{ gridTemplateColumns: TRACK_COLS_COMPACT }}>
        <span className="tabular-nums text-text-2 text-right pr-1">
          {isPlaying
            ? <span className="eq inline-flex"><span/><span/><span/><span/></span>
            : <>
                <span className="row-num">{t.n}</span>
                <button onClick={() => onPlay && onPlay(t)} className="row-play text-white">
                  <window.PI.Play size={11} />
                </button>
              </>}
        </span>
        <div className={`truncate font-medium ${isPlaying ? 'text-accent' : 'text-white'}`}>{t.title}</div>
        <div className="text-text-2 group-hover:text-white truncate">Bon Iver</div>
        <div className="text-text-2 tabular-nums text-right text-[12px]">{t.dur}</div>
      </div>
    );
  }
  return (
    <div
      draggable
      onDragStart={(e) => { e.dataTransfer.effectAllowed = 'copy'; onDragStart && onDragStart(t); }}
      onDragEnd={onDragEnd}
      onDoubleClick={() => onPlay && onPlay(t)}
      className={`row group grid items-center px-4 py-2 rounded text-[14px] cursor-grab active:cursor-grabbing
        hover:bg-white/[0.07] transition-colors ${isPlaying ? 'is-playing' : ''} ${liked ? 'is-liked' : ''}`}
      style={{ gridTemplateColumns: TRACK_COLS_FULL }}>
      <span className="text-text-3 group-hover:text-white">
        <window.PI.Grip size={14} />
      </span>
      <span className="tabular-nums text-text-2 text-right">
        {isPlaying
          ? <span className="eq inline-flex"><span/><span/><span/><span/></span>
          : <>
              <span className="row-num">{t.n}</span>
              <button onClick={() => onPlay && onPlay(t)} className="row-play text-white">
                <window.PI.Play size={12} />
              </button>
            </>}
      </span>
      <div className="min-w-0 flex items-center gap-3">
        <window.PCover c1="#3a5a40" c2="#a3b18a" size={36} label={t.title} rounded="rounded-sm" />
        <div className="min-w-0">
          <div className={`truncate font-medium ${isPlaying ? 'text-accent' : 'text-white'}`}>{t.title}</div>
          <div className="truncate text-[12px] text-text-2 group-hover:text-white">Bon Iver</div>
        </div>
      </div>
      <div className="text-text-2 group-hover:text-white truncate">For Emma, Forever Ago</div>
      <div className="text-text-2 text-[10px] font-bold tracking-wider">
        <span className="px-1.5 py-0.5 rounded bg-white/5 group-hover:bg-white/10">{idx === 0 ? 'FLAC 24b' : 'FLAC'}</span>
      </div>
      <div className="text-text-2 tabular-nums text-right">{t.dur}</div>
      <button
        onClick={() => setLiked(!liked)}
        className={`row-like grid place-items-center transition-colors ${liked ? 'text-accent opacity-100' : 'text-text-2 hover:text-white'}`}>
        {liked ? <window.PI.HeartFill size={14} /> : <window.PI.Heart size={14} />}
      </button>
    </div>
  );
}

function Tracklist({ tracks, playingIndex, onPlay, onDragStart, onDragEnd, compact, onToggleCompact }) {
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
             style={{ gridTemplateColumns: TRACK_COLS_COMPACT }}>
          <span className="text-right pr-1">#</span>
          <span>Title</span>
          <span>Artist</span>
          <span className="text-right">{clock}</span>
        </div>
      ) : (
        <div className="grid items-center px-4 py-2 text-[11px] uppercase tracking-wider text-text-2 border-b border-white/10 sticky top-0 bg-surface z-10 pr-20"
             style={{ gridTemplateColumns: TRACK_COLS_FULL }}>
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
          <TrackRow key={t.n}
            t={t} idx={i}
            isPlaying={i === playingIndex}
            onPlay={() => onPlay(i)}
            onDragStart={onDragStart}
            onDragEnd={onDragEnd}
            compact={compact} />
        ))}
      </div>
    </div>
  );
}

// ───────────────────────────────────────────────────────────
// Queue rail (Now Playing + Up Next)
// ───────────────────────────────────────────────────────────
function QueueRail({ playing, queue }) {
  return (
    <div className="bg-surface rounded-lg flex flex-col h-full min-h-0 overflow-hidden">
      <div className="flex items-center justify-between px-4 pt-3 pb-2 flex-none gap-2">
        <h2 className="text-[15px] font-bold whitespace-nowrap">Up Next</h2>
        <button className="w-8 h-8 grid place-items-center rounded-full text-text-2 hover:text-white hover:bg-surface-3">
          <window.PI.More size={16} />
        </button>
      </div>
      <div className="overflow-y-auto scroll flex-1 px-2 pb-3">
        {/* Now playing */}
        <div className="px-2 pt-2 pb-1 text-[11px] uppercase tracking-wider text-text-2">Now Playing</div>
        <div className="rounded-md p-2 bg-gradient-to-br from-accent/15 to-transparent ring-1 ring-accent/20">
          <div className="flex items-center gap-3">
            <window.PCover c1={playing.c1} c2={playing.c2} size={56} label={playing.title} rounded="rounded-md" />
            <div className="min-w-0 flex-1">
              <div className="truncate font-bold text-[14px] text-accent">{playing.title}</div>
              <div className="truncate text-[12px] text-text-2">{playing.artist}</div>
              <div className="truncate text-[11px] text-text-3 mt-0.5">{playing.album}</div>
            </div>
            <span className="eq"><span/><span/><span/><span/></span>
          </div>
          {/* lyrics-style preview */}
          <div className="mt-3 px-1 text-[12px] text-text-2 leading-relaxed line-clamp-2 italic">
            "...and at once I knew I was not magnificent..."
          </div>
        </div>
        {/* Up Next */}
        <div className="px-2 pt-4 pb-1 flex items-center justify-between">
          <div className="text-[11px] uppercase tracking-wider text-text-2">From: For Emma, Forever Ago</div>
          <button className="text-[11px] font-bold text-text-2 hover:text-white">Clear</button>
        </div>
        {queue.map((q, i) => (
          <div key={i} className="group flex items-center gap-3 px-2 py-1.5 rounded-md hover:bg-surface-2">
            <button className="opacity-0 group-hover:opacity-100 text-text-2 hover:text-white">
              <window.PI.Grip size={14} />
            </button>
            <window.PCover c1={q.c1} c2={q.c2} size={36} label={q.title} rounded="rounded-sm" />
            <div className="min-w-0 flex-1">
              <div className="truncate text-[13px] font-medium">{q.title}</div>
              <div className="truncate text-[11px] text-text-2">{q.artist}</div>
            </div>
            <span className="text-[11px] text-text-3 tabular-nums">{q.dur}</span>
            <button className="opacity-0 group-hover:opacity-100 text-text-2 hover:text-white">
              <window.PI.More size={14} />
            </button>
          </div>
        ))}
      </div>
    </div>
  );
}

// ───────────────────────────────────────────────────────────
// Player bar
// ───────────────────────────────────────────────────────────
function PlayerBar({ playing, isPlaying, onTogglePlay }) {
  const [progress, setProgress] = useState(38);
  const [vol, setVol] = useState(60);
  return (
    <div className="bg-base h-[88px] flex items-center px-4 gap-4 flex-none">
      {/* L: now playing */}
      <div className="flex items-center gap-3 min-w-[280px] max-w-[30%]">
        <window.PCover c1={playing.c1} c2={playing.c2} size={56} label={playing.title} rounded="rounded-md" />
        <div className="min-w-0 flex-1">
          <div className="truncate text-[14px] font-semibold hover:underline cursor-pointer">{playing.title}</div>
          <div className="truncate text-[11px] text-text-2 hover:underline cursor-pointer">{playing.artist}</div>
        </div>
        <button className="text-accent hover:scale-110 transition-transform"><window.PI.HeartFill size={16} /></button>
        <button className="text-text-2 hover:text-white"><window.PI.Mini size={14} /></button>
      </div>

      {/* M: transport + scrubber */}
      <div className="flex-1 flex flex-col items-center gap-1.5 max-w-[722px] mx-auto">
        <div className="flex items-center gap-5">
          <button className="text-text-2 hover:text-white"><window.PI.Shuffle size={16} /></button>
          <button className="text-text-2 hover:text-white"><window.PI.Prev size={18} /></button>
          <button onClick={onTogglePlay}
            className="w-9 h-9 rounded-full bg-white text-black grid place-items-center hover:scale-105 active:scale-100 transition-transform">
            {isPlaying ? <window.PI.Pause size={16} /> : <window.PI.Play size={16} />}
          </button>
          <button className="text-text-2 hover:text-white"><window.PI.Next size={18} /></button>
          <button className="text-accent hover:text-accent-hover relative">
            <window.PI.Repeat size={16} />
            <span className="absolute -bottom-1 left-1/2 -translate-x-1/2 w-1 h-1 rounded-full bg-accent" />
          </button>
        </div>
        <div className="flex items-center gap-2 w-full text-[11px] text-text-2">
          <span className="tabular-nums w-8 text-right">2:08</span>
          <div className="flex-1 group h-1 bg-surface-4 rounded-full relative cursor-pointer">
            <div className="h-full bg-text-2 group-hover:bg-accent rounded-full transition-colors" style={{ width: progress + '%' }} />
            <div className="absolute top-1/2 -translate-y-1/2 w-3 h-3 rounded-full bg-white opacity-0 group-hover:opacity-100" style={{ left: `calc(${progress}% - 6px)` }} />
          </div>
          <span className="tabular-nums w-8">5:36</span>
        </div>
      </div>

      {/* R: utility */}
      <div className="flex items-center gap-3 min-w-[280px] max-w-[30%] justify-end">
        {/* Transcoding indicator */}
        <button title="Transcoding: FLAC → 320 MP3" className="flex items-center gap-1.5 text-[11px] text-text-2 hover:text-white px-2 py-1 rounded border border-white/10">
          <span className="w-1.5 h-1.5 rounded-full bg-[#e5a00d]" />
          <span>FLAC → 320</span>
        </button>
        <button className="text-text-2 hover:text-white"><window.PI.Queue size={16} /></button>
        <button className="text-text-2 hover:text-white"><window.PI.Cast size={16} /></button>
        <div className="flex items-center gap-2 group">
          <button className="text-text-2 hover:text-white"><window.PI.Volume size={16} /></button>
          <div className="w-24 h-1 bg-surface-4 rounded-full relative cursor-pointer group">
            <div className="h-full bg-text-2 group-hover:bg-accent rounded-full transition-colors" style={{ width: vol + '%' }} />
            <div className="absolute top-1/2 -translate-y-1/2 w-3 h-3 rounded-full bg-white opacity-0 group-hover:opacity-100" style={{ left: `calc(${vol}% - 6px)` }} />
          </div>
        </div>
      </div>
    </div>
  );
}

// ───────────────────────────────────────────────────────────
// AlbumView — miller cols + album header + tracklist
// ───────────────────────────────────────────────────────────
function AlbumView({ compact, setCompact, tracksCompact, setTracksCompact,
  selectedArtistId, setSelectedArtistId, selectedAlbumId, setSelectedAlbumId,
  albums, album, artist, tracks, playingIndex, setPlayingIndex, setIsPlaying,
  setDraggingTrack, setDropTargetId }) {
  return (
    <>
      <div className="grid grid-cols-2 gap-2 px-2 pb-2 flex-none" style={{ height: compact ? 260 : 220 }}>
        <window.PMillerCol
          title="Artists"
          items={window.ARTISTS}
          selectedId={selectedArtistId}
          onSelect={(a) => setSelectedArtistId(a.id)}
          compact={compact}
          onToggleCompact={setCompact}
          renderItem={(a, c) => c ? (
            <>
              <span className="w-3 text-text-3 text-center">{a.id === selectedArtistId ? '▸' : ''}</span>
              <span className="flex-1 truncate font-medium">{a.name}</span>
            </>
          ) : (
            <>
              <window.PCover c1={a.c1} c2={a.c2} size={36} label={a.name} rounded="rounded-full" />
              <div className="flex-1 min-w-0">
                <div className="text-[14px] font-semibold truncate text-white">{a.name}</div>
                <div className="text-[11px] text-text-2 truncate">{a.albums} albums · {a.tracks} tracks</div>
              </div>
              {a.id === selectedArtistId && <window.PI.ChevR size={14} className="text-text-2" />}
            </>
          )}
        />
        <window.PMillerCol
          title="Albums"
          items={albums}
          selectedId={selectedAlbumId}
          onSelect={(al) => setSelectedAlbumId(al.id)}
          compact={compact}
          onToggleCompact={setCompact}
          renderItem={(al, c) => c ? (
            <>
              <span className="w-3 text-text-3 text-center">{al.id === selectedAlbumId ? '▸' : ''}</span>
              <span className="flex-1 truncate font-medium">{al.title}</span>
              <span className="text-[11px] text-text-3 tabular-nums flex-none">{al.year}</span>
            </>
          ) : (
            <>
              <window.PCover c1={al.c1} c2={al.c2} size={36} label={al.title} rounded="rounded" />
              <div className="flex-1 min-w-0">
                <div className="text-[14px] font-semibold truncate text-white">{al.title}</div>
                <div className="text-[11px] text-text-2 truncate">{al.year} · {al.tracks} tracks</div>
              </div>
              <span className="hidden xl:inline text-[10px] font-bold tracking-wider px-1.5 py-0.5 rounded bg-white/5 text-text-2 flex-none whitespace-nowrap">{al.format}</span>
            </>
          )}
        />
      </div>
      <div className="px-2 pb-2 flex-none">
        <window.PAlbumHeader album={album} artist={artist}
          onPlay={() => { setPlayingIndex(0); setIsPlaying(true); }}
          onDragStart={(payload) => setDraggingTrack(payload)}
          onDragEnd={() => { setDraggingTrack(null); setDropTargetId(null); }} />
      </div>
      <div className="flex-1 min-h-0 flex flex-col px-2 pb-2 overflow-hidden">
        <Tracklist
          tracks={tracks}
          playingIndex={playingIndex}
          onPlay={(i) => { setPlayingIndex(i); setIsPlaying(true); }}
          onDragStart={setDraggingTrack}
          onDragEnd={() => { setDraggingTrack(null); setDropTargetId(null); }}
          compact={tracksCompact}
          onToggleCompact={setTracksCompact}
        />
      </div>
    </>
  );
}

// ───────────────────────────────────────────────────────────
// App
// ───────────────────────────────────────────────────────────
function App() {
  const [selectedArtistId, setSelectedArtistId] = useState('bon-iver');
  const [selectedAlbumId, setSelectedAlbumId] = useState('for-emma');
  const [playingIndex, setPlayingIndex] = useState(2); // Skinny Love
  const [isPlaying, setIsPlaying] = useState(true);
  const [dropTargetId, setDropTargetId] = useState(null);
  const [draggingTrack, setDraggingTrack] = useState(null);
  const [activePlaylistId, setActivePlaylistId] = useState(null);
  const [view, setView] = useState('album'); // 'album' | 'playlist' | 'search'
  const [query, setQuery] = useState('');
  const [compact, setCompact] = useState(false);
  const [tracksCompact, setTracksCompact] = useState(false);

  const artist = window.ARTISTS.find(a => a.id === selectedArtistId);
  const albums = window.ALBUMS_BY_ARTIST[selectedArtistId] || window.FALLBACK_ALBUMS(artist);
  const album = albums.find(a => a.id === selectedAlbumId) || albums[0];
  const tracks = window.TRACKS_BY_ALBUM[album?.id] || window.TRACKS_BY_ALBUM['for-emma'];

  // Adjust selected album when artist changes
  React.useEffect(() => {
    if (!albums.find(a => a.id === selectedAlbumId)) {
      setSelectedAlbumId(albums[0].id);
    }
  }, [selectedArtistId]);

  const playing = {
    title: tracks[playingIndex].title,
    artist: artist.name,
    album: album.title,
    c1: album.c1, c2: album.c2,
  };

  return (
    <div className="app-shell h-screen w-screen flex flex-col gap-2 p-2">
      {/* Top region: sidebar + main + queue */}
      <div className="flex gap-2 flex-1 min-h-0">
        {/* Left sidebar */}
        <div className="w-[320px] flex-none flex flex-col gap-2 min-h-0">
          <window.PSidebar
            activePlaylistId={activePlaylistId}
            dropTargetId={dropTargetId}
            onDropTarget={setDropTargetId}
            onSelectPlaylist={(id) => { setActivePlaylistId(id); setView('playlist'); }}
            onNav={(v) => { setView(v); if (v !== 'playlist') setActivePlaylistId(null); }}
            view={view}
          />
        </div>

        {/* Main column */}
        <main className="flex-1 min-w-0 bg-surface rounded-lg flex flex-col overflow-hidden">
          <window.PTopBar
            query={query}
            onQueryChange={(q) => { setQuery(q); if (q) setView('search'); }}
            onSearchFocus={() => setView('search')}
            onOpenSettings={() => setView('settings')}
          />
          {view === 'settings' ? (
            <window.PSettingsView onBack={() => setView('album')} />
          ) : view === 'search' ? (
            <window.PSearchResults
              query={query}
              onSelectArtist={(id) => { setSelectedArtistId(id); setView('album'); setQuery(''); }}
              onSelectAlbum={(artistId, albumId) => { setSelectedArtistId(artistId); setSelectedAlbumId(albumId); setView('album'); setQuery(''); }}
            />
          ) : view === 'library' ? (
            <window.PLibraryView
              onSelectPlaylist={(id) => { setActivePlaylistId(id); setView('playlist'); }}
              onSelectArtist={(id) => { setSelectedArtistId(id); setView('album'); }}
              onSelectAlbum={(artistId, albumId) => { setSelectedArtistId(artistId); setSelectedAlbumId(albumId); setView('album'); }}
            />
          ) : view === 'playlist' ? (
            <window.PPlaylistDetail
              playlist={window.PLAYLISTS.find(p => p.id === activePlaylistId) || window.PLAYLISTS[0]}
              playingIndex={playingIndex}
              isPlaying={isPlaying}
              onPlay={(i) => { setPlayingIndex(i); setIsPlaying(true); }}
              onDragStart={setDraggingTrack}
              onDragEnd={() => { setDraggingTrack(null); setDropTargetId(null); }}
              compact={tracksCompact}
              onToggleCompact={setTracksCompact}
            />
          ) : (
            <AlbumView
              compact={compact}
              setCompact={setCompact}
              tracksCompact={tracksCompact}
              setTracksCompact={setTracksCompact}
              selectedArtistId={selectedArtistId}
              setSelectedArtistId={setSelectedArtistId}
              selectedAlbumId={selectedAlbumId}
              setSelectedAlbumId={setSelectedAlbumId}
              albums={albums}
              album={album}
              artist={artist}
              tracks={tracks}
              playingIndex={playingIndex}
              setPlayingIndex={setPlayingIndex}
              setIsPlaying={setIsPlaying}
              draggingTrack={draggingTrack}
              setDraggingTrack={setDraggingTrack}
              setDropTargetId={setDropTargetId}
            />
          )}
        </main>
      </div>

      {/* Player bar */}
      <PlayerBar playing={playing} isPlaying={isPlaying} onTogglePlay={() => setIsPlaying(!isPlaying)} />
    </div>
  );
}

ReactDOM.createRoot(document.getElementById('root')).render(<App />);
