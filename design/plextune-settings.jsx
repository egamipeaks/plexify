// Settings view for Plextune.

const { useState: useSettingsState } = React;

// ───────────────────────────────────────────────────────────
// Atoms
// ───────────────────────────────────────────────────────────
function SettingRow({ title, hint, children }) {
  return (
    <div className="flex items-start justify-between gap-6 py-4 border-b border-white/5">
      <div className="min-w-0 flex-1">
        <div className="text-[14px] font-semibold text-white">{title}</div>
        {hint && <div className="text-[12px] text-text-2 mt-0.5 leading-snug">{hint}</div>}
      </div>
      <div className="flex-none">{children}</div>
    </div>
  );
}

function Toggle({ on, onChange }) {
  return (
    <button onClick={() => onChange(!on)}
      className={`relative w-10 h-6 rounded-full transition-colors ${on ? 'bg-accent' : 'bg-white/15'}`}>
      <span className={`absolute top-0.5 ${on ? 'left-[18px]' : 'left-0.5'} w-5 h-5 rounded-full bg-white transition-all shadow`} />
    </button>
  );
}

function Select({ value, onChange, options }) {
  return (
    <select value={value} onChange={(e) => onChange(e.target.value)}
      className="bg-surface-2 hover:bg-surface-3 transition-colors text-white text-[13px] font-semibold rounded-md px-3 py-1.5 outline-none ring-1 ring-white/10 focus:ring-white/30 cursor-pointer min-w-[160px]">
      {options.map(opt => (
        <option key={opt.value} value={opt.value} className="bg-surface text-white">{opt.label}</option>
      ))}
    </select>
  );
}

function Stepper({ value, onChange, min = 0, max = 100, step = 1, suffix = '' }) {
  return (
    <div className="flex items-center gap-2">
      <button onClick={() => onChange(Math.max(min, value - step))}
        className="w-7 h-7 rounded-full bg-surface-2 hover:bg-surface-3 grid place-items-center text-white">−</button>
      <span className="text-[13px] font-bold text-white tabular-nums min-w-[60px] text-center">{value}{suffix}</span>
      <button onClick={() => onChange(Math.min(max, value + step))}
        className="w-7 h-7 rounded-full bg-surface-2 hover:bg-surface-3 grid place-items-center text-white">+</button>
    </div>
  );
}

function ButtonGhost({ children, onClick, danger }) {
  return (
    <button onClick={onClick}
      className={`px-3 py-1.5 rounded-md text-[13px] font-semibold ring-1 transition-colors
        ${danger
          ? 'text-red-400 ring-red-500/40 hover:bg-red-500/10'
          : 'text-white ring-white/15 hover:bg-white/5'}`}>
      {children}
    </button>
  );
}

function Section({ id, title, subtitle, children }) {
  return (
    <section id={id} className="pt-8 pb-2 first:pt-2">
      <div className="mb-2">
        <h2 className="text-[20px] font-extrabold text-white tracking-tight">{title}</h2>
        {subtitle && <div className="text-[13px] text-text-2 mt-0.5">{subtitle}</div>}
      </div>
      <div>{children}</div>
    </section>
  );
}

// ───────────────────────────────────────────────────────────
// Settings view
// ───────────────────────────────────────────────────────────
function SettingsView({ onBack }) {
  // Local state — would persist server-side in real app
  const [audioQualityWifi, setAudioQualityWifi] = useSettingsState('flac');
  const [audioQualityCellular, setAudioQualityCellular] = useSettingsState('320');
  const [gapless, setGapless] = useSettingsState(true);
  const [crossfade, setCrossfade] = useSettingsState(0);
  const [normalize, setNormalize] = useSettingsState(true);
  const [normalizeLevel, setNormalizeLevel] = useSettingsState('balanced');
  const [autoplay, setAutoplay] = useSettingsState(true);
  const [explicit, setExplicit] = useSettingsState(true);
  const [showSingles, setShowSingles] = useSettingsState(true);
  const [showCompilations, setShowCompilations] = useSettingsState(false);
  const [defaultSort, setDefaultSort] = useSettingsState('recent');
  const [theme, setTheme] = useSettingsState('dark');
  const [accent, setAccent] = useSettingsState('green');
  const [density, setDensity] = useSettingsState('comfortable');
  const [scrobble, setScrobble] = useSettingsState(true);
  const [downloadQuality, setDownloadQuality] = useSettingsState('flac');
  const [cacheSize, setCacheSize] = useSettingsState(8);

  const accents = [
    { id: 'green',  hex: '#1ed760' },
    { id: 'orange', hex: '#e5a00d' },
    { id: 'blue',   hex: '#3b82f6' },
    { id: 'purple', hex: '#a855f7' },
    { id: 'red',    hex: '#ef4444' },
  ];

  const sections = [
    { id: 'account',   label: 'Account' },
    { id: 'plex',      label: 'Plex Server' },
    { id: 'playback',  label: 'Playback' },
    { id: 'library',   label: 'Library' },
    { id: 'display',   label: 'Display' },
    { id: 'downloads', label: 'Downloads' },
    { id: 'shortcuts', label: 'Shortcuts' },
    { id: 'about',     label: 'About' },
  ];

  const scrollTo = (id) => {
    const el = document.getElementById(id);
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
  };

  return (
    <div className="flex-1 min-h-0 flex overflow-hidden">
      {/* Section nav */}
      <aside className="w-[200px] flex-none px-2 pt-3 pb-2 border-r border-white/5">
        <button onClick={onBack}
          className="w-full text-left flex items-center gap-2 px-3 py-1.5 rounded-md text-[13px] font-semibold text-text-2 hover:text-white hover:bg-surface-2 mb-3">
          <window.PI.ChevL size={14} /> Back
        </button>
        <div className="px-3 pt-1 pb-2 text-[10px] font-bold uppercase tracking-wider text-text-3">Settings</div>
        <nav className="flex flex-col gap-0.5">
          {sections.map(s => (
            <button key={s.id} onClick={() => scrollTo(s.id)}
              className="text-left px-3 py-1.5 rounded-md text-[13px] font-medium text-text-2 hover:text-white hover:bg-surface-2 transition-colors">
              {s.label}
            </button>
          ))}
        </nav>
      </aside>

      {/* Detail panel */}
      <div className="flex-1 min-w-0 overflow-y-auto scroll px-8 pb-12">
        <div className="max-w-[760px] mx-auto pt-4">
          <h1 className="text-[28px] font-extrabold text-white tracking-tight mb-1">Settings</h1>
          <div className="text-[13px] text-text-2 mb-2">Plextune · 1.0.0 (build 248) · connected to <span className="text-white font-semibold">HomeServer</span></div>

          {/* Account */}
          <Section id="account" title="Account">
            <div className="flex items-center gap-4 py-4 border-b border-white/5">
              <div className="w-14 h-14 rounded-full bg-gradient-to-br from-purple-500 to-pink-500 grid place-items-center text-white text-[20px] font-bold flex-none">K</div>
              <div className="min-w-0 flex-1">
                <div className="text-[15px] font-bold text-white">Kyle</div>
                <div className="text-[12px] text-text-2">kyle@example.com · Plex Pass</div>
              </div>
              <ButtonGhost>Manage</ButtonGhost>
            </div>
            <SettingRow title="Sign out" hint="Sign out of this Plex account on this device.">
              <ButtonGhost danger>Sign out</ButtonGhost>
            </SettingRow>
          </Section>

          {/* Plex Server */}
          <Section id="plex" title="Plex Server" subtitle="Where your music comes from.">
            <div className="flex items-center gap-3 py-4 border-b border-white/5">
              <div className="w-10 h-10 rounded-md bg-gradient-to-br from-[#e5a00d] to-[#cc7700] grid place-items-center text-black flex-none">
                <window.PI.Plex size={20} />
              </div>
              <div className="min-w-0 flex-1">
                <div className="text-[14px] font-bold text-white flex items-center gap-1.5">
                  HomeServer
                  <span className="w-1.5 h-1.5 rounded-full bg-accent inline-block" />
                </div>
                <div className="text-[12px] text-text-2">Direct · 1 Gbps · 192.168.1.42:32400 · 2,431 albums · 18,402 tracks</div>
              </div>
              <ButtonGhost>Switch server</ButtonGhost>
            </div>
            <SettingRow title="Music library" hint="Which Plex library to read from.">
              <Select value="music" onChange={() => {}} options={[
                { value: 'music', label: 'Music' },
                { value: 'music-archive', label: 'Music — Archive' },
              ]} />
            </SettingRow>
            <SettingRow title="Connect remotely" hint="Allow streaming when away from your home network. Uses your Plex relay if direct connect fails.">
              <Toggle on={true} onChange={() => {}} />
            </SettingRow>
            <SettingRow title="Allow transcoding" hint="Let the server convert formats your client can't play directly.">
              <Toggle on={true} onChange={() => {}} />
            </SettingRow>
            <SettingRow title="Sync metadata" hint="Pull updated cover art, artist info, and lyrics from Plex.">
              <ButtonGhost>Resync now</ButtonGhost>
            </SettingRow>
          </Section>

          {/* Playback */}
          <Section id="playback" title="Playback">
            <SettingRow title="Audio quality (Wi-Fi)" hint="Higher quality uses more bandwidth.">
              <Select value={audioQualityWifi} onChange={setAudioQualityWifi} options={[
                { value: 'flac',  label: 'Lossless (FLAC)' },
                { value: '320',   label: '320 kbps' },
                { value: '256',   label: '256 kbps' },
                { value: '128',   label: '128 kbps' },
                { value: 'auto',  label: 'Auto' },
              ]} />
            </SettingRow>
            <SettingRow title="Audio quality (Cellular)" hint="Used when you're on a metered connection.">
              <Select value={audioQualityCellular} onChange={setAudioQualityCellular} options={[
                { value: 'flac',  label: 'Lossless (FLAC)' },
                { value: '320',   label: '320 kbps' },
                { value: '192',   label: '192 kbps' },
                { value: '128',   label: '128 kbps' },
              ]} />
            </SettingRow>
            <SettingRow title="Gapless playback" hint="Remove silence between tracks on the same album.">
              <Toggle on={gapless} onChange={setGapless} />
            </SettingRow>
            <SettingRow title="Crossfade" hint="Blend the end of one song into the next.">
              <Stepper value={crossfade} onChange={setCrossfade} min={0} max={12} suffix="s" />
            </SettingRow>
            <SettingRow title="Normalize volume" hint="Even out loudness across albums and playlists.">
              <Toggle on={normalize} onChange={setNormalize} />
            </SettingRow>
            <SettingRow title="Volume level" hint="Target loudness when normalization is on.">
              <Select value={normalizeLevel} onChange={setNormalizeLevel} options={[
                { value: 'quiet',    label: 'Quiet' },
                { value: 'balanced', label: 'Balanced' },
                { value: 'loud',     label: 'Loud' },
              ]} />
            </SettingRow>
            <SettingRow title="Autoplay similar songs" hint="When the queue ends, keep playing tracks like the last one.">
              <Toggle on={autoplay} onChange={setAutoplay} />
            </SettingRow>
            <SettingRow title="Scrobble to last.fm" hint="Send play history to your last.fm account.">
              <Toggle on={scrobble} onChange={setScrobble} />
            </SettingRow>
          </Section>

          {/* Library */}
          <Section id="library" title="Library">
            <SettingRow title="Default sort" hint="How Your Library is ordered when you open it.">
              <Select value={defaultSort} onChange={setDefaultSort} options={[
                { value: 'recent',  label: 'Recently added' },
                { value: 'alpha',   label: 'Alphabetical' },
                { value: 'creator', label: 'By type' },
              ]} />
            </SettingRow>
            <SettingRow title="Show singles & EPs" hint="Include releases under 4 tracks in the Albums view.">
              <Toggle on={showSingles} onChange={setShowSingles} />
            </SettingRow>
            <SettingRow title="Show compilations" hint="Include various-artist compilations in artist albums.">
              <Toggle on={showCompilations} onChange={setShowCompilations} />
            </SettingRow>
            <SettingRow title="Show explicit content" hint="Show tracks marked as explicit by Plex.">
              <Toggle on={explicit} onChange={setExplicit} />
            </SettingRow>
            <SettingRow title="Hidden artists" hint="2 artists are hidden from your library.">
              <ButtonGhost>Manage</ButtonGhost>
            </SettingRow>
          </Section>

          {/* Display */}
          <Section id="display" title="Display">
            <SettingRow title="Theme">
              <Select value={theme} onChange={setTheme} options={[
                { value: 'dark',   label: 'Dark' },
                { value: 'darker', label: 'Pitch black' },
                { value: 'auto',   label: 'Match system' },
              ]} />
            </SettingRow>
            <SettingRow title="Accent color" hint="Used for the Play button, active states, and progress.">
              <div className="flex items-center gap-2">
                {accents.map(a => (
                  <button key={a.id} onClick={() => setAccent(a.id)}
                    className={`w-7 h-7 rounded-full transition-all ${accent === a.id ? 'ring-2 ring-white ring-offset-2 ring-offset-surface' : 'hover:scale-110'}`}
                    style={{ background: a.hex }} />
                ))}
              </div>
            </SettingRow>
            <SettingRow title="Density" hint="Default density for tracklist and artist/album columns.">
              <Select value={density} onChange={setDensity} options={[
                { value: 'comfortable', label: 'Comfortable' },
                { value: 'compact',     label: 'Compact' },
              ]} />
            </SettingRow>
            <SettingRow title="Show cover art in player bar">
              <Toggle on={true} onChange={() => {}} />
            </SettingRow>
            <SettingRow title="Animated equalizer on now-playing rows">
              <Toggle on={true} onChange={() => {}} />
            </SettingRow>
          </Section>

          {/* Downloads */}
          <Section id="downloads" title="Downloads" subtitle="Cache tracks locally for offline listening.">
            <SettingRow title="Download quality">
              <Select value={downloadQuality} onChange={setDownloadQuality} options={[
                { value: 'flac', label: 'Lossless (FLAC)' },
                { value: '320',  label: '320 kbps' },
                { value: '192',  label: '192 kbps' },
              ]} />
            </SettingRow>
            <SettingRow title="Cache size" hint="Maximum disk space used for offline downloads.">
              <Stepper value={cacheSize} onChange={setCacheSize} min={1} max={64} suffix=" GB" />
            </SettingRow>
            <SettingRow title="Download over cellular">
              <Toggle on={false} onChange={() => {}} />
            </SettingRow>
            <SettingRow title="Currently cached" hint="312 tracks · 1.7 GB used">
              <ButtonGhost danger>Clear cache</ButtonGhost>
            </SettingRow>
          </Section>

          {/* Shortcuts */}
          <Section id="shortcuts" title="Keyboard shortcuts">
            <div className="grid grid-cols-2 gap-x-8 gap-y-2 py-4 border-b border-white/5 text-[13px]">
              {[
                ['Play / Pause', 'Space'],
                ['Next track', '→'],
                ['Previous track', '←'],
                ['Volume up', '↑'],
                ['Volume down', '↓'],
                ['Mute', 'M'],
                ['Search', '⌘ K'],
                ['Settings', '⌘ ,'],
                ['Toggle compact', 'C'],
                ['Like / Unlike', 'L'],
              ].map(([label, key]) => (
                <div key={label} className="flex items-center justify-between">
                  <span className="text-text-2">{label}</span>
                  <kbd className="text-[11px] font-bold text-white bg-surface-2 ring-1 ring-white/10 rounded px-2 py-0.5">{key}</kbd>
                </div>
              ))}
            </div>
          </Section>

          {/* About */}
          <Section id="about" title="About">
            <SettingRow title="Version" hint="Plextune 1.0.0 — build 248">
              <ButtonGhost>Check for updates</ButtonGhost>
            </SettingRow>
            <SettingRow title="Open source licenses">
              <ButtonGhost>View</ButtonGhost>
            </SettingRow>
            <SettingRow title="Send diagnostics" hint="Help improve Plextune by sharing anonymous usage data.">
              <Toggle on={true} onChange={() => {}} />
            </SettingRow>
          </Section>
        </div>
      </div>
    </div>
  );
}

window.PSettingsView = SettingsView;
