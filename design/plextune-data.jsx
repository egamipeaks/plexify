// Data for Plextune.

const ARTISTS = [
  { id: 'bon-iver',  name: 'Bon Iver',         albums: 5, tracks: 62,  c1: '#3a5a40', c2: '#a3b18a' },
  { id: 'phoebe',    name: 'Phoebe Bridgers',  albums: 3, tracks: 32,  c1: '#22223b', c2: '#9a8c98' },
  { id: 'national',  name: 'The National',     albums: 9, tracks: 118, c1: '#1d2021', c2: '#7a8b69' },
  { id: 'sufjan',    name: 'Sufjan Stevens',   albums: 7, tracks: 84,  c1: '#2b2d42', c2: '#8d99ae' },
  { id: 'big-thief', name: 'Big Thief',        albums: 5, tracks: 60,  c1: '#3d405b', c2: '#81b29a' },
  { id: 'frank',     name: 'Frank Ocean',      albums: 3, tracks: 31,  c1: '#001219', c2: '#0a9396' },
  { id: 'tame',      name: 'Tame Impala',      albums: 4, tracks: 47,  c1: '#641220', c2: '#e85d75' },
  { id: 'mitski',    name: 'Mitski',           albums: 6, tracks: 71,  c1: '#370617', c2: '#d62828' },
  { id: 'beach',     name: 'Beach House',      albums: 8, tracks: 96,  c1: '#03045e', c2: '#48cae4' },
  { id: 'fleet',     name: 'Fleet Foxes',      albums: 4, tracks: 48,  c1: '#774936', c2: '#cc7e5a' },
  { id: 'radiohead', name: 'Radiohead',        albums: 9, tracks: 124, c1: '#1a1a1a', c2: '#ff6b35' },
  { id: 'arcade',    name: 'Arcade Fire',      albums: 6, tracks: 73,  c1: '#2d3142', c2: '#ef8354' },
];

const ALBUMS_BY_ARTIST = {
  'bon-iver': [
    { id: 'for-emma',    title: 'For Emma, Forever Ago',   year: 2007, tracks: 9,  dur: '37:13', format: 'FLAC',     c1: '#7a8b69', c2: '#c4d6b0' },
    { id: '22-million',  title: '22, A Million',           year: 2016, tracks: 10, dur: '34:21', format: 'FLAC',     c1: '#1a1a1a', c2: '#5a5a5a' },
    { id: 'ii',          title: 'i,i',                     year: 2019, tracks: 13, dur: '39:34', format: 'FLAC',     c1: '#d4a373', c2: '#faedcd' },
    { id: 'bib',         title: 'Bon Iver, Bon Iver',      year: 2011, tracks: 10, dur: '39:35', format: 'FLAC',     c1: '#3a5a40', c2: '#a3b18a' },
    { id: 'sable',       title: 'SABLE,',                  year: 2024, tracks: 3,  dur: '12:48', format: 'FLAC 24b', c1: '#283618', c2: '#606c38' },
  ],
};

// Default fallback albums for artists w/o explicit data
const FALLBACK_ALBUMS = (artist) => [
  { id: artist.id+'-a1', title: 'Selected Works',  year: 2018, tracks: 12, dur: '46:00', format: 'FLAC',     c1: artist.c1, c2: artist.c2 },
  { id: artist.id+'-a2', title: 'Live at the Hall', year: 2020, tracks: 14, dur: '58:40', format: 'FLAC',     c1: '#222', c2: artist.c2 },
];

const TRACKS_BY_ALBUM = {
  'for-emma': [
    { n: 1, title: 'Flume',                      dur: '3:39', plays: '24,318,402', liked: false },
    { n: 2, title: 'Lump Sum',                   dur: '3:21', plays: '8,142,008',  liked: false },
    { n: 3, title: 'Skinny Love',                dur: '3:58', plays: '147,201,558', liked: true },
    { n: 4, title: 'The Wolves (Act I & II)',    dur: '5:21', plays: '32,704,901', liked: false },
    { n: 5, title: 'Blindsided',                 dur: '5:29', plays: '11,202,348', liked: false },
    { n: 6, title: 'Creature Fear',              dur: '3:06', plays: '14,803,910', liked: false },
    { n: 7, title: 'Team',                       dur: '1:57', plays: '6,213,002',  liked: false },
    { n: 8, title: 'For Emma',                   dur: '3:41', plays: '13,544,770', liked: true },
    { n: 9, title: 'Re: Stacks',                 dur: '6:40', plays: '21,403,118', liked: true },
  ],
};

const PLAYLISTS = [
  { id: 'late-night',   name: 'Late Night',         count: 42, sub: 'songs that feel like 2 a.m.' },
  { id: 'workout',      name: 'Workout',            count: 88, sub: 'high tempo, no skips' },
  { id: 'focus',        name: 'Focus',              count: 120,sub: 'instrumentals · for code' },
  { id: 'road-trip',    name: 'Road Trip',          count: 37, sub: 'long songs, open windows' },
  { id: 'bangers',      name: 'Bangers',            count: 24, sub: 'bangers only' },
  { id: 'demos',        name: 'Demos & B-sides',    count: 11, sub: 'rarities from the library' },
  { id: 'sunday',       name: 'Sunday Coffee',      count: 60, sub: 'slow morning · folk' },
  { id: 'discoveries',  name: '2025 Discoveries',   count: 28, sub: 'auto-curated' },
];

// Folders are app-only (Plex doesn't support them server-side).
// playlistIds outside any folder render at the root.
const PLAYLIST_FOLDERS = [
  { id: 'moods',   name: 'Moods',     playlistIds: ['late-night', 'sunday', 'bangers'] },
  { id: 'work',    name: 'For Work',  playlistIds: ['focus'] },
  { id: 'driving', name: 'Driving',   playlistIds: ['road-trip', 'workout'] },
];

const QUEUE = [
  { title: 'Holocene',          artist: 'Bon Iver',         album: 'Bon Iver, Bon Iver',  dur: '5:36', c1: '#3a5a40', c2: '#a3b18a' },
  { title: 'Re: Stacks',        artist: 'Bon Iver',         album: 'For Emma',            dur: '6:40', c1: '#7a8b69', c2: '#c4d6b0' },
  { title: 'Two Slow Dancers',  artist: 'Mitski',           album: 'Be the Cowboy',       dur: '3:46', c1: '#370617', c2: '#d62828' },
  { title: 'Pink Moon',         artist: 'Nick Drake',       album: 'Pink Moon',           dur: '2:04', c1: '#3d2817', c2: '#a47148' },
  { title: 'Bloodbuzz Ohio',    artist: 'The National',     album: 'High Violet',         dur: '4:36', c1: '#1d2021', c2: '#7a8b69' },
  { title: 'Motion Sickness',   artist: 'Phoebe Bridgers',  album: 'Stranger in the Alps',dur: '4:00', c1: '#22223b', c2: '#9a8c98' },
];

window.ARTISTS = ARTISTS;
window.ALBUMS_BY_ARTIST = ALBUMS_BY_ARTIST;
window.FALLBACK_ALBUMS = FALLBACK_ALBUMS;
window.TRACKS_BY_ALBUM = TRACKS_BY_ALBUM;
window.PLAYLISTS = PLAYLISTS;
window.PLAYLIST_FOLDERS = PLAYLIST_FOLDERS;
window.QUEUE = QUEUE;
