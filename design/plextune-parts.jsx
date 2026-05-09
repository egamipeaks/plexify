// Reusable component parts for Plextune.

const { useState, useRef, useEffect, useImperativeHandle } = React;

// ───────────────────────────────────────────────────────────
// Cover — abstract gradient album art with shape decoration
// ───────────────────────────────────────────────────────────
function Cover({ c1 = '#333', c2 = '#666', size = 64, label, rounded = 'rounded', className = '' }) {
  const seed = (label || '').split('').reduce((a, c) => a + c.charCodeAt(0), 0);
  const variant = seed % 4;
  return (
    <div className={`${rounded} relative overflow-hidden flex-none ${className}`}
         style={{ width: size, height: size, background: `linear-gradient(135deg, ${c1}, ${c2})` }}>
      <svg viewBox="0 0 100 100" preserveAspectRatio="none" className="absolute inset-0 w-full h-full">
        {variant === 0 && (
          <>
            <circle cx="80" cy="20" r="40" fill="white" opacity="0.18" />
            <circle cx="15" cy="85" r="25" fill="white" opacity="0.10" />
          </>
        )}
        {variant === 1 && (
          <>
            <rect x="-20" y="50" width="180" height="14" fill="white" opacity="0.12" transform="rotate(-25 50 50)" />
            <rect x="-20" y="70" width="180" height="6" fill="white" opacity="0.16" transform="rotate(-25 50 50)" />
          </>
        )}
        {variant === 2 && (
          <>
            <circle cx="50" cy="50" r="40" fill="none" stroke="white" strokeWidth="0.8" opacity="0.35" />
            <circle cx="50" cy="50" r="26" fill="none" stroke="white" strokeWidth="0.8" opacity="0.30" />
            <circle cx="50" cy="50" r="12" fill="none" stroke="white" strokeWidth="0.8" opacity="0.25" />
          </>
        )}
        {variant === 3 && (
          <>
            <polygon points="50,5 95,80 5,80" fill="white" opacity="0.10" />
            <polygon points="50,30 80,80 20,80" fill="white" opacity="0.18" />
          </>
        )}
      </svg>
    </div>
  );
}

// ───────────────────────────────────────────────────────────
// Sidebar — nav + library actions + playlists + server chip
// ───────────────────────────────────────────────────────────
function NavItem({ icon: Icon, label, active, badge, onClick }) {
  return (
    <button onClick={onClick} className={`w-full flex items-center gap-4 px-3 py-2.5 rounded-md text-[14px] font-bold transition-colors
      ${active ? 'text-white bg-surface-2' : 'text-text-2 hover:text-white'}`}>
      <Icon size={20} />
      <span className="flex-1 text-left">{label}</span>
      {badge}
    </button>
  );
}

// Context menu — small floating popover
function ContextMenu({ x, y, items, onClose }) {
  const ref = useRef(null);
  useEffect(() => {
    const onDoc = (e) => { if (!ref.current?.contains(e.target)) onClose(); };
    const onKey = (e) => { if (e.key === 'Escape') onClose(); };
    document.addEventListener('mousedown', onDoc);
    document.addEventListener('keydown', onKey);
    return () => { document.removeEventListener('mousedown', onDoc); document.removeEventListener('keydown', onKey); };
  }, [onClose]);
  return (
    <div ref={ref}
      className="fixed z-[1000] min-w-[200px] py-1 rounded-md bg-surface-3 ring-1 ring-white/10 shadow-2xl text-[13px]"
      style={{ left: x, top: y }}>
      {items.map((it, i) => it.divider ? (
        <div key={i} className="my-1 border-t border-white/10" />
      ) : it.submenu ? (
        <SubmenuItem key={i} item={it} onClose={onClose} />
      ) : (
        <button key={i}
          onClick={() => { it.onClick && it.onClick(); onClose(); }}
          disabled={it.disabled}
          className={`w-full text-left px-3 py-1.5 flex items-center gap-3
            ${it.danger ? 'text-red-400 hover:bg-red-500/10' : 'text-white hover:bg-white/10'}
            ${it.disabled ? 'opacity-40 cursor-not-allowed' : ''}`}>
          <span className="flex-1">{it.label}</span>
          {it.shortcut && <span className="text-text-3 text-[11px]">{it.shortcut}</span>}
        </button>
      ))}
    </div>
  );
}

function SubmenuItem({ item, onClose }) {
  const [open, setOpen] = useState(false);
  return (
    <div className="relative" onMouseEnter={() => setOpen(true)} onMouseLeave={() => setOpen(false)}>
      <div className="w-full text-left px-3 py-1.5 flex items-center gap-3 text-white hover:bg-white/10 cursor-default">
        <span className="flex-1">{item.label}</span>
        <window.PI.ChevR size={11} className="text-text-3" />
      </div>
      {open && (
        <div className="absolute left-full top-0 -mt-1 ml-0.5 min-w-[180px] py-1 rounded-md bg-surface-3 ring-1 ring-white/10 shadow-2xl">
          {item.submenu.map((s, j) => (
            <button key={j}
              onClick={() => { s.onClick && s.onClick(); onClose(); }}
              disabled={s.disabled}
              className={`w-full text-left px-3 py-1.5 flex items-center gap-2 text-white hover:bg-white/10
                ${s.disabled ? 'opacity-40 cursor-not-allowed' : ''}`}>
              {s.icon && <s.icon size={12} className="text-text-2" />}
              <span className="flex-1">{s.label}</span>
              {s.checked && <span className="text-accent text-[11px]">●</span>}
            </button>
          ))}
        </div>
      )}
    </div>
  );
}

function FolderedPlaylists({ playlists, activePlaylistId, dropTargetId, onDropTarget, onSelectPlaylist, foldersRef }) {
  const [folders, setFolders] = useState(() => (window.PLAYLIST_FOLDERS || []).map(f => ({ ...f, playlistIds: [...f.playlistIds] })));
  const [openFolders, setOpenFolders] = useState({ moods: true, work: true, driving: false });
  const [folderDrop, setFolderDrop] = useState(null); // folder id, or '__root'
  const [renamingFolder, setRenamingFolder] = useState(null);
  const [renamingPlaylist, setRenamingPlaylist] = useState(null);
  const [nameOverrides, setNameOverrides] = useState({});
  const [menu, setMenu] = useState(null); // { x, y, kind: 'folder'|'playlist', id }

  // Expose API to parent (for "New folder" button in sidebar header)
  useImperativeHandle(foldersRef, () => ({
    createFolder: () => {
      const id = 'folder-' + Date.now();
      setFolders(fs => [...fs, { id, name: 'New Folder', playlistIds: [] }]);
      setOpenFolders(s => ({ ...s, [id]: true }));
      setRenamingFolder(id);
    },
  }), []);

  const playlistById = Object.fromEntries(playlists.map(p => [p.id, p]));
  const inFolder = new Set();
  folders.forEach(f => f.playlistIds.forEach(id => inFolder.add(id)));
  const root = playlists.filter(p => !inFolder.has(p.id));
  const toggle = (id) => setOpenFolders(s => ({ ...s, [id]: !s[id] }));

  const movePlaylistTo = (playlistId, targetFolderId /* null = root */) => {
    setFolders(fs => fs.map(f => ({ ...f, playlistIds: f.playlistIds.filter(id => id !== playlistId) })).map(f =>
      f.id === targetFolderId ? { ...f, playlistIds: [...f.playlistIds, playlistId] } : f
    ));
  };
  const renameFolder = (id, name) => setFolders(fs => fs.map(f => f.id === id ? { ...f, name } : f));
  const deleteFolder = (id) => setFolders(fs => fs.filter(f => f.id !== id)); // playlists fall back to root

  const renamePlaylist = (id, name) => setNameOverrides(o => ({ ...o, [id]: name }));
  const renderPlaylistRow = (p) => {
    const displayName = nameOverrides[p.id] ?? p.name;
    const renaming = renamingPlaylist === p.id;
    return (
      <PlaylistRow p={{ ...p, name: displayName }}
        active={p.id === activePlaylistId}
        dropActive={p.id === dropTargetId}
        renaming={renaming}
        onCommitRename={(v) => { renamePlaylist(p.id, v || displayName); setRenamingPlaylist(null); }}
        onCancelRename={() => setRenamingPlaylist(null)}
        onClick={() => onSelectPlaylist && onSelectPlaylist(p.id)} />
    );
  };

  const handleFolderDrop = (e, folderId) => {
    e.preventDefault();
    setFolderDrop(null);
    const playlistId = e.dataTransfer.getData('plextune/playlist');
    if (playlistId) movePlaylistTo(playlistId, folderId);
  };

  const folderMenu = (e, f) => {
    e.preventDefault(); e.stopPropagation();
    setMenu({ x: e.clientX, y: e.clientY, kind: 'folder', id: f.id });
  };
  const playlistMenu = (e, p) => {
    e.preventDefault(); e.stopPropagation();
    setMenu({ x: e.clientX, y: e.clientY, kind: 'playlist', id: p.id });
  };

  return (
    <>
      {folders.map(f => {
        const items = f.playlistIds.map(id => playlistById[id]).filter(Boolean);
        const open = !!openFolders[f.id];
        const isFolderDrop = folderDrop === f.id;
        return (
          <div key={f.id} className="flex flex-col">
            <div onClick={() => toggle(f.id)}
              onContextMenu={(e) => folderMenu(e, f)}
              onDragOver={(e) => { e.preventDefault(); setFolderDrop(f.id); }}
              onDragLeave={() => setFolderDrop(null)}
              onDrop={(e) => handleFolderDrop(e, f.id)}
              className={`group w-full flex items-center gap-2 px-2 py-1.5 rounded-md text-left transition-colors cursor-pointer
                ${isFolderDrop ? 'bg-accent/15 ring-1 ring-accent/40' : 'hover:bg-surface-2'}`}>
              <window.PI.ChevR size={12}
                className={`text-text-2 transition-transform flex-none ${open ? 'rotate-90' : ''}`} />
              <window.PI.Folder size={16} className="text-text-2 flex-none" />
              {renamingFolder === f.id ? (
                <input autoFocus defaultValue={f.name}
                  onClick={(e) => e.stopPropagation()}
                  onBlur={(e) => { renameFolder(f.id, e.target.value || f.name); setRenamingFolder(null); }}
                  onKeyDown={(e) => {
                    if (e.key === 'Enter') { renameFolder(f.id, e.target.value || f.name); setRenamingFolder(null); }
                    if (e.key === 'Escape') setRenamingFolder(null);
                  }}
                  className="flex-1 min-w-0 bg-white/10 ring-1 ring-white/30 rounded px-1.5 py-0.5 text-[13px] font-bold text-white outline-none" />
              ) : (
                <span onDoubleClick={(e) => { e.stopPropagation(); setRenamingFolder(f.id); }}
                  className="text-[13px] font-bold text-white flex-1 truncate">{f.name}</span>
              )}
              <span className="text-[11px] text-text-3 tabular-nums flex-none">{items.length}</span>
            </div>
            {open && (
              <div className="ml-3 pl-2 border-l border-white/10 flex flex-col gap-0.5 py-0.5">
                {items.map(p => (
                  <div key={p.id}
                    onContextMenu={(e) => playlistMenu(e, p)}
                    onDragOver={(e) => { e.preventDefault(); onDropTarget && onDropTarget(p.id); }}
                    onDragLeave={() => onDropTarget && onDropTarget(null)}
                    onDrop={(e) => { e.preventDefault(); onDropTarget && onDropTarget(null); }}>
                    {renderPlaylistRow(p)}
                  </div>
                ))}
                {items.length === 0 && (
                  <div className="px-2 py-2 text-[11px] text-text-3 italic">Empty — drop a playlist here</div>
                )}
              </div>
            )}
          </div>
        );
      })}
      {/* Root drop zone label + items */}
      {(root.length > 0 || folders.length > 0) && (
        <div
          onDragOver={(e) => { e.preventDefault(); setFolderDrop('__root'); }}
          onDragLeave={() => setFolderDrop(null)}
          onDrop={(e) => handleFolderDrop(e, null)}
          className={`mt-1 rounded-md transition-colors ${folderDrop === '__root' ? 'bg-accent/10 ring-1 ring-accent/30' : ''}`}>
          {folders.length > 0 && (
            <div className="px-2 pt-2 pb-1 text-[10px] uppercase tracking-wider text-text-3 font-bold">Other</div>
          )}
          {root.map(p => (
            <div key={p.id}
              onContextMenu={(e) => playlistMenu(e, p)}
              onDragOver={(e) => { e.preventDefault(); onDropTarget && onDropTarget(p.id); }}
              onDragLeave={() => onDropTarget && onDropTarget(null)}
              onDrop={(e) => { e.preventDefault(); onDropTarget && onDropTarget(null); }}>
              {renderPlaylistRow(p)}
            </div>
          ))}
        </div>
      )}

      {menu && menu.kind === 'folder' && (() => {
        const f = folders.find(x => x.id === menu.id);
        return f && (
          <ContextMenu x={menu.x} y={menu.y} onClose={() => setMenu(null)} items={[
            { label: 'Rename folder', onClick: () => setRenamingFolder(f.id) },
            { label: openFolders[f.id] ? 'Collapse' : 'Expand', onClick: () => toggle(f.id) },
            { divider: true },
            { label: 'Delete folder', danger: true, onClick: () => deleteFolder(f.id) },
          ]} />
        );
      })()}

      {menu && menu.kind === 'playlist' && (() => {
        const p = playlistById[menu.id];
        const currentFolder = folders.find(f => f.playlistIds.includes(menu.id));
        return p && (
          <ContextMenu x={menu.x} y={menu.y} onClose={() => setMenu(null)} items={[
            { label: 'Play', onClick: () => onSelectPlaylist && onSelectPlaylist(p.id) },
            { label: 'Add to queue' },
            { divider: true },
            { label: 'Move to folder', submenu: [
              ...folders.map(f => ({
                label: f.name, icon: window.PI.Folder, checked: currentFolder?.id === f.id,
                onClick: () => movePlaylistTo(p.id, f.id),
              })),
              { label: 'Remove from folder', icon: window.PI.ChevL, disabled: !currentFolder,
                onClick: () => movePlaylistTo(p.id, null) },
            ]},
            { label: 'Rename playlist', onClick: () => setRenamingPlaylist(p.id) },
            { divider: true },
            { label: 'Delete playlist', danger: true },
          ]} />
        );
      })()}
    </>
  );
}

function PlaylistRow({ p, active, dropActive, onClick, renaming, onCommitRename, onCancelRename }) {
  return (
    <button onClick={renaming ? undefined : onClick}
      draggable={!renaming}
      onDragStart={(e) => {
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('plextune/playlist', p.id);
      }}
      className={`playlist-row ${dropActive ? 'drop-active dropzone' : ''} group w-full flex items-center gap-2 px-2 py-1 rounded text-left transition-colors ${renaming ? '' : 'cursor-grab active:cursor-grabbing'}
        ${active ? 'bg-surface-3' : 'hover:bg-surface-2'}`}>
      <window.PI.Music size={12} className={`flex-none ${active ? 'text-accent' : 'text-text-2'}`} />
      <div className="min-w-0 flex-1">
        {renaming ? (
          <input autoFocus defaultValue={p.name}
            onClick={(e) => e.stopPropagation()}
            onBlur={(e) => onCommitRename && onCommitRename(e.target.value)}
            onKeyDown={(e) => {
              e.stopPropagation();
              if (e.key === 'Enter') onCommitRename && onCommitRename(e.target.value);
              if (e.key === 'Escape') onCancelRename && onCancelRename();
            }}
            className="w-full bg-white/10 ring-1 ring-white/30 rounded px-1.5 py-0.5 text-[13px] font-medium text-white outline-none" />
        ) : (
          <div className={`text-[13px] font-medium truncate ${active ? 'text-accent' : 'text-white'}`}>{p.name}</div>
        )}
      </div>
      {active && !renaming && <span className="eq"><span/><span/><span/><span/></span>}
    </button>
  );
}

function Sidebar({ activePlaylistId, dropTargetId, onDropTarget, onSelectPlaylist, onNav, view }) {
  const [filter, setFilter] = useState('');
  const foldersRef = useRef(null);
  const filtered = window.PLAYLISTS.filter(p => !filter || p.name.toLowerCase().includes(filter.toLowerCase()));
  return (
    <aside className="flex flex-col gap-2 h-full min-h-0">
      {/* Top nav card */}
      <div className="bg-surface rounded-lg p-2 flex flex-col gap-1">
        <NavItem icon={window.PI.Home} label="Home" active={view === 'album'} onClick={() => onNav && onNav('album')} />
        <NavItem icon={window.PI.Search} label="Search" active={view === 'search'} onClick={() => onNav && onNav('search')} />
        <NavItem icon={window.PI.Library} label="Your Library" active={view === 'album'} onClick={() => onNav && onNav('album')} />
        <NavItem icon={window.PI.Recent} label="Recently Added" />
      </div>

      {/* Library card */}
      <div className="bg-surface rounded-lg flex-1 min-h-0 flex flex-col">
        <div className="flex items-center justify-between px-4 pt-3 pb-2">
          <div className="flex items-center gap-2 text-text-2 hover:text-white cursor-pointer">
            <window.PI.Library size={18} />
            <span className="text-[14px] font-bold">Your Playlists</span>
          </div>
          <div className="flex items-center gap-1">
            <button title="New folder"
              onClick={() => foldersRef.current?.createFolder()}
              className="w-8 h-8 grid place-items-center rounded-full text-text-2 hover:text-white hover:bg-surface-3">
              <window.PI.Folder size={14} />
            </button>
            <button title="Create playlist"
              className="w-8 h-8 grid place-items-center rounded-full text-text-2 hover:text-white hover:bg-surface-3">
              <window.PI.Plus size={16} />
            </button>
          </div>
        </div>
        {/* Filter bar */}
        <div className="px-3 pb-2 flex items-center justify-between">
          <div className="flex items-center gap-1 text-text-2 hover:text-white cursor-pointer">
            <window.PI.Search size={14} />
          </div>
          <button className="flex items-center gap-1 text-[12px] text-text-2 hover:text-white font-semibold">
            Recents
            <window.PI.Filter size={12} />
          </button>
        </div>
        {/* List */}
        <div className="flex-1 overflow-y-auto scroll px-2 pb-2 flex flex-col gap-0.5">
          <FolderedPlaylists
            playlists={filtered}
            activePlaylistId={activePlaylistId}
            dropTargetId={dropTargetId}
            onDropTarget={onDropTarget}
            onSelectPlaylist={onSelectPlaylist}
            foldersRef={foldersRef}
          />
        </div>
      </div>

      {/* Server chip */}
      <div className="bg-surface rounded-lg p-3 flex items-center gap-3">
        <div className="w-9 h-9 rounded-md bg-gradient-to-br from-[#e5a00d] to-[#cc7700] grid place-items-center text-black flex-none">
          <window.PI.Plex size={18} />
        </div>
        <div className="min-w-0 flex-1">
          <div className="text-[13px] font-bold truncate flex items-center gap-1.5">
            HomeServer
            <span className="w-1.5 h-1.5 rounded-full bg-accent inline-block" />
          </div>
          <div className="text-[11px] text-text-2 truncate flex items-center gap-1">
            <window.PI.Wifi size={10} /> Direct · 1 Gbps · 2,431 albums
          </div>
        </div>
        <button className="w-7 h-7 rounded-full grid place-items-center text-text-2 hover:text-white hover:bg-surface-3">
          <window.PI.Caret size={14} />
        </button>
      </div>
    </aside>
  );
}

// ───────────────────────────────────────────────────────────
// TopBar
// ───────────────────────────────────────────────────────────
function TopBar({ query, onQueryChange, onSearchFocus, onOpenSettings }) {
  return (
    <div className="flex items-center gap-4 px-6 h-16 flex-none">
      <div className="flex gap-2">
        <button className="w-8 h-8 rounded-full bg-black/60 hover:bg-black grid place-items-center text-white"><window.PI.ChevL size={16} /></button>
        <button className="w-8 h-8 rounded-full bg-black/60 hover:bg-black grid place-items-center text-text-3"><window.PI.ChevR size={16} /></button>
      </div>
      <div className="flex-1 max-w-[760px]">
        <div className="flex items-center gap-3 bg-surface-1 hover:bg-surface-2 transition-colors rounded-full h-11 px-4 ring-1 ring-transparent focus-within:ring-white/30">
          <window.PI.Search size={18} className="text-white" />
          <input value={query || ''}
                 onChange={(e) => onQueryChange && onQueryChange(e.target.value)}
                 onFocus={onSearchFocus}
                 className="bg-transparent outline-none text-[14px] flex-1 placeholder:text-text-2"
                 placeholder="What do you want to play?" />
          <span className="text-[11px] text-text-3 border border-text-3/40 rounded px-1.5 py-0.5">⌘K</span>
        </div>
      </div>
      <div className="flex-1" />
      <button onClick={onOpenSettings}
        title="Settings"
        className="w-9 h-9 rounded-full bg-gradient-to-br from-purple-500 to-pink-500 grid place-items-center text-white text-[13px] font-bold hover:ring-2 hover:ring-white/40 transition-all">K</button>
    </div>
  );
}

// ───────────────────────────────────────────────────────────
// Miller column (artists or albums)
// ───────────────────────────────────────────────────────────
function MillerCol({ title, items, selectedId, onSelect, renderItem, compact, onToggleCompact }) {
  return (
    <div className="flex flex-col min-h-0 bg-surface rounded-lg overflow-hidden">
      <div className="px-4 pt-3 pb-2 flex items-center justify-between gap-2 flex-none">
        <div className="text-[13px] font-bold uppercase tracking-wider text-text-2 whitespace-nowrap">{title}</div>
        <div className="flex items-center gap-2 flex-none">
          <button className="text-[11px] text-text-2 hover:text-white font-semibold flex items-center gap-1 whitespace-nowrap">
            <window.PI.Filter size={11} /> A–Z
          </button>
          <span className="text-[11px] text-text-3">{items.length}</span>
          <div className="flex items-center bg-surface-2 rounded p-0.5">
            <button onClick={() => onToggleCompact && onToggleCompact(false)} title="Comfortable"
              className={`w-6 h-6 grid place-items-center rounded ${!compact ? 'bg-surface-4 text-white' : 'text-text-2 hover:text-white'}`}>
              <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
            </button>
            <button onClick={() => onToggleCompact && onToggleCompact(true)} title="Compact list"
              className={`w-6 h-6 grid place-items-center rounded ${compact ? 'bg-surface-4 text-white' : 'text-text-2 hover:text-white'}`}>
              <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round"><line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="18" x2="20" y2="18"/></svg>
            </button>
          </div>
        </div>
      </div>
      <div className="overflow-y-auto scroll flex-1">
        {items.map((it, i) => (
          <button key={it.id || i}
            onClick={() => onSelect(it)}
            className={`w-full flex items-center gap-3 text-left transition-colors
              ${compact ? 'px-3 py-[3px] text-[13px]' : 'px-3 py-1.5'}
              ${it.id === selectedId
                ? (compact ? 'bg-accent/15 text-accent' : 'bg-surface-3 text-white')
                : 'text-text-2 hover:text-white hover:bg-surface-2'}`}>
            {renderItem(it, compact)}
          </button>
        ))}
      </div>
    </div>
  );
}

// ───────────────────────────────────────────────────────────
// Album header strip
// ───────────────────────────────────────────────────────────
function AlbumHeader({ album, artist, onPlay, onDragStart, onDragEnd }) {
  const [collapsed, setCollapsed] = useState(false);
  if (!album) return null;
  if (collapsed) {
    return (
      <div className="relative overflow-hidden rounded-lg" style={{ background: `linear-gradient(180deg, ${album.c2}40 0%, transparent 100%)` }}>
        <div className="px-4 py-2 flex items-center gap-3">
          <div
            draggable
            onDragStart={(e) => {
              e.dataTransfer.effectAllowed = 'copy';
              e.dataTransfer.setData('plextune/album', album.id);
              e.dataTransfer.setData('text/plain', `${album.title} — ${artist?.name || ''}`);
              onDragStart && onDragStart({ kind: 'album', album, artist });
            }}
            onDragEnd={() => onDragEnd && onDragEnd()}
            title="Drag to a playlist to add the whole album"
            className="cursor-grab active:cursor-grabbing flex-none">
            <Cover c1={album.c1} c2={album.c2} size={40} label={album.title} rounded="rounded" className="shadow pointer-events-none" />
          </div>
          <div className="min-w-0 flex-1">
            <div className="text-[14px] font-bold text-white truncate">{album.title}</div>
            <div className="text-[12px] text-text-2 truncate">
              {artist?.name} · {album.year} · {album.tracks} songs · <span className="text-[10px] font-bold tracking-wider px-1 py-0.5 rounded bg-white/10">{album.format}</span>
            </div>
          </div>
          <button onClick={onPlay}
            className="w-9 h-9 rounded-full bg-accent hover:bg-accent-hover grid place-items-center text-black flex-none">
            <window.PI.Play size={14} />
          </button>
          <button className="w-9 h-9 rounded-full grid place-items-center text-text-2 hover:text-white hover:bg-white/10 flex-none">
            <window.PI.Shuffle size={16} />
          </button>
          <button onClick={() => setCollapsed(false)}
            title="Expand"
            className="w-8 h-8 rounded-full grid place-items-center text-text-2 hover:text-white hover:bg-white/10 flex-none">
            <window.PI.ChevR size={14} className="rotate-90" />
          </button>
        </div>
      </div>
    );
  }
  return (
    <div className="relative overflow-hidden rounded-lg" style={{ background: `linear-gradient(180deg, ${album.c2}40 0%, transparent 100%)` }}>
      <button onClick={() => setCollapsed(true)}
        title="Collapse"
        className="absolute top-2 right-2 w-8 h-8 rounded-full grid place-items-center text-text-2 hover:text-white hover:bg-black/30 z-10">
        <window.PI.ChevR size={14} className="-rotate-90" />
      </button>
      <div className="px-6 py-5 flex items-center gap-5">
        <div
          draggable
          onDragStart={(e) => {
            e.dataTransfer.effectAllowed = 'copy';
            e.dataTransfer.setData('plextune/album', album.id);
            e.dataTransfer.setData('text/plain', `${album.title} — ${artist?.name || ''}`);
            onDragStart && onDragStart({ kind: 'album', album, artist });
          }}
          onDragEnd={() => onDragEnd && onDragEnd()}
          title="Drag to a playlist to add the whole album"
          className="cursor-grab active:cursor-grabbing hover:scale-[1.02] active:scale-100 transition-transform">
          <Cover c1={album.c1} c2={album.c2} size={120} label={album.title} rounded="rounded-md" className="shadow-2xl pointer-events-none" />
        </div>
        <div className="flex-1 min-w-0">
          <div className="text-[11px] font-bold uppercase tracking-wider text-white/80">Album</div>
          <h1 className="text-[clamp(22px,3.4vw,40px)] font-black tracking-tight leading-[1.05] truncate">{album.title}</h1>
          <div className="mt-2 flex items-center gap-2 text-[13px] text-text-2 flex-wrap">
            <span className="text-white font-semibold">{artist?.name}</span>
            <span>·</span>
            <span>{album.year}</span>
            <span>·</span>
            <span className="whitespace-nowrap">{album.tracks} songs, {album.dur}</span>
            <span className="ml-2 px-1.5 py-0.5 rounded bg-white/10 text-[10px] font-bold tracking-wider whitespace-nowrap">{album.format}</span>
          </div>
        </div>
      </div>
      <div className="px-6 pb-4 flex items-center gap-4">
        <button onClick={onPlay}
          className="w-14 h-14 rounded-full bg-accent hover:bg-accent-hover grid place-items-center text-black shadow-xl hover:scale-105 active:scale-100 transition-transform">
          <window.PI.Play size={22} />
        </button>
        <button className="w-10 h-10 rounded-full grid place-items-center text-text-2 hover:text-white hover:scale-105 transition-all">
          <window.PI.Shuffle size={22} />
        </button>
        <button className="w-10 h-10 rounded-full grid place-items-center text-text-2 hover:text-white hover:scale-105 transition-all">
          <window.PI.Download size={20} />
        </button>
        <div className="flex-1" />
      </div>
    </div>
  );
}

window.PCover = Cover;
window.PSidebar = Sidebar;
window.PTopBar = TopBar;
window.PMillerCol = MillerCol;
window.PAlbumHeader = AlbumHeader;
