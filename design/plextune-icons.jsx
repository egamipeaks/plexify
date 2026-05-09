// Inline SVG icons. All exported to window.PI.
const PIcon = ({ d, size = 16, fill = 'none', stroke = 'currentColor', sw = 2, vb = '0 0 24 24', children }) => (
  <svg width={size} height={size} viewBox={vb} fill={fill} stroke={stroke} strokeWidth={sw} strokeLinecap="round" strokeLinejoin="round" style={{ flex: 'none' }}>
    {d ? <path d={d} /> : children}
  </svg>
);

const PI = {
  Home: (p) => <PIcon {...p} d="M3 11l9-8 9 8M5 10v10h14V10" />,
  Search: (p) => <PIcon {...p} d="M11 4a7 7 0 100 14 7 7 0 000-14zM21 21l-5.2-5.2" />,
  Library: (p) => <PIcon {...p} d="M3 5h4v14H3zM10 5h4v14h-4zM17 5l4 14" />,
  Recent: (p) => <PIcon {...p} d="M12 8v5l3 2M12 22a10 10 0 110-20 10 10 0 010 20" />,
  Plus: (p) => <PIcon {...p} d="M12 5v14M5 12h14" />,
  Play: (p) => <PIcon {...p} fill="currentColor" stroke="none" d="M6 4l14 8-14 8z" />,
  Pause: (p) => <PIcon {...p} fill="currentColor" stroke="none" d="M6 5h4v14H6zM14 5h4v14h-4z" />,
  Prev: (p) => <PIcon {...p} fill="currentColor" stroke="currentColor" sw={1.5} d="M19 5L8 12l11 7zM5 5h2v14H5z" />,
  Next: (p) => <PIcon {...p} fill="currentColor" stroke="currentColor" sw={1.5} d="M5 5l11 7-11 7zM17 5h2v14h-2z" />,
  Shuffle: (p) => <PIcon {...p} d="M16 3h5v5M21 3l-7 7M4 20l16-16M4 4l5 5M14 14l7 7M21 16v5h-5" />,
  Repeat: (p) => <PIcon {...p} d="M17 1l4 4-4 4M3 11V9a4 4 0 014-4h14M7 23l-4-4 4-4M21 13v2a4 4 0 01-4 4H3" />,
  Heart: (p) => <PIcon {...p} d="M12 21s-7-4.5-9-9a5 5 0 019-3 5 5 0 019 3c-2 4.5-9 9-9 9z" />,
  HeartFill: (p) => <PIcon {...p} fill="currentColor" stroke="currentColor" d="M12 21s-7-4.5-9-9a5 5 0 019-3 5 5 0 019 3c-2 4.5-9 9-9 9z" />,
  Queue: (p) => <PIcon {...p} d="M3 6h13M3 12h13M3 18h9M17 14v6M17 14l4 4M17 14l-4 4" />,
  Server: (p) => <PIcon {...p}><rect x="3" y="4" width="18" height="6" rx="1.5" /><rect x="3" y="14" width="18" height="6" rx="1.5" /><circle cx="7" cy="7" r="0.6" fill="currentColor" /><circle cx="7" cy="17" r="0.6" fill="currentColor" /></PIcon>,
  Cast: (p) => <PIcon {...p} d="M2 16v3a1 1 0 001 1h3M2 12V8a2 2 0 012-2h16a2 2 0 012 2v10a2 2 0 01-2 2H10" />,
  Volume: (p) => <PIcon {...p} d="M11 5L6 9H2v6h4l5 4V5zM15 9a3 3 0 010 6M19 5a8 8 0 010 14" />,
  More: (p) => <PIcon {...p}><circle cx="5" cy="12" r="1.4" fill="currentColor" /><circle cx="12" cy="12" r="1.4" fill="currentColor" /><circle cx="19" cy="12" r="1.4" fill="currentColor" /></PIcon>,
  Grip: (p) => <PIcon {...p}><circle cx="9" cy="6" r="1.2" fill="currentColor" /><circle cx="9" cy="12" r="1.2" fill="currentColor" /><circle cx="9" cy="18" r="1.2" fill="currentColor" /><circle cx="15" cy="6" r="1.2" fill="currentColor" /><circle cx="15" cy="12" r="1.2" fill="currentColor" /><circle cx="15" cy="18" r="1.2" fill="currentColor" /></PIcon>,
  Caret: (p) => <PIcon {...p} d="M6 9l6 6 6-6" />,
  ChevR: (p) => <PIcon {...p} d="M9 6l6 6-6 6" />,
  ChevL: (p) => <PIcon {...p} d="M15 6l-6 6 6 6" />,
  Mic: (p) => <PIcon {...p} d="M12 2a3 3 0 00-3 3v6a3 3 0 006 0V5a3 3 0 00-3-3zM5 11a7 7 0 0014 0M12 18v3" />,
  Mini: (p) => <PIcon {...p} d="M4 14h6v6M20 10h-6V4M14 10l7-7M10 14l-7 7" />,
  Filter: (p) => <PIcon {...p} d="M3 4h18M6 12h12M10 20h4" />,
  Folder: (p) => <PIcon {...p} d="M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V7z" />,
  Music: (p) => <PIcon {...p} d="M9 18V5l11-2v13M9 18a3 3 0 11-3-3M20 16a3 3 0 11-3-3" />,
  FolderOpen: (p) => <PIcon {...p} d="M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2H3V7zm0 2h18l-2 9a2 2 0 01-2 1H5a2 2 0 01-2-1V9z" />,
  Pin: (p) => <PIcon {...p} d="M12 2v8M9 5l3-3 3 3M5 11l4 4M19 11l-4 4M12 22v-8" />,
  Download: (p) => <PIcon {...p} d="M12 3v12M5 12l7 7 7-7M3 21h18" />,
  Wifi: (p) => <PIcon {...p} d="M5 12.5a10 10 0 0114 0M8.5 16a5 5 0 017 0M12 19.5h.01" />,
  Sparkle: (p) => <PIcon {...p} d="M12 2v4M12 18v4M2 12h4M18 12h4M5 5l3 3M16 16l3 3M5 19l3-3M16 8l3-3" />,
  Plex: (p) => <PIcon {...p} fill="currentColor" stroke="none" sw={0} d="M5 3h6l-4 9 4 9H5l4-9-4-9zm9 0h5v18h-5l4-9-4-9z" />,
};

window.PI = PI;
