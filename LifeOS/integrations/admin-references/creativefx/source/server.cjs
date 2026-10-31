/**
 * CreativeFX Admin API Server
 * Run with: node server.cjs
 *
 * Endpoints:
 *   GET  /api/health            — healthcheck
 *   GET  /api/content           — Get site content (public)
 *   POST /api/content           — Save site content (auth required)
 *   POST /api/content/reset     — Reset to defaults (auth required)
 *   GET  /api/google-reviews    — Reviews config (public)
 *   POST /api/google-reviews/sync — Update reviews config (auth required)
 *   POST /api/admin/login       — Get a short-lived session token
 *   POST /api/admin/logout      — Revoke the current session token (auth required)
 *   POST /api/admin/password    — Change admin password (auth + current pw required)
 *   GET  /api/inquiries         — list inquiries (auth required)
 *   POST /api/inquiries         — public contact-form submission
 *   PATCH/DELETE /api/inquiries/:id — update/remove inquiry (auth required)
 *   GET  /api/uploads           — list uploaded files (auth required)
 *   DELETE /api/uploads/:name   — delete an uploaded file (auth required)
 *   GET  /api/stats             — storage stats (auth required)
 *   POST /api/upload            — Upload image/video (auth required)
 *   POST /api/import-drive      — Import from Google Drive / OneDrive (auth required)
 *   POST /api/import-url        — Import from a public URL (auth required)
 */

// H-1: load .env for local development. In production the hosting provider
// (Render/Vercel) injects real environment variables, which take precedence.
require('dotenv').config({ quiet: true });

const express = require('express');
const cors = require('cors');
const helmet = require('helmet');
const rateLimit = require('express-rate-limit');
const fs = require('fs');
const path = require('path');
const multer = require('multer');
const https = require('https');
const http = require('http');
const crypto = require('crypto');
const dns = require('dns');
const net = require('net');

const app = express();
const PORT = process.env.PORT || 4005;
app.set('etag', false);

// Never let browsers or reverse proxies reuse an old content response.
// Published admin edits must be visible consistently on every device.
app.use('/api', (_req, res, next) => {
  res.set('Cache-Control', 'no-store, no-cache, must-revalidate, proxy-revalidate');
  res.set('CDN-Cache-Control', 'no-store');
  res.set('Surrogate-Control', 'no-store');
  res.set('Pragma', 'no-cache');
  res.set('Expires', '0');
  next();
});

// H-4: We sit behind exactly one reverse proxy (Render / Vercel). Trusting a
// single hop lets express-rate-limit derive the real client IP from
// X-Forwarded-For without letting clients spoof it with extra hops.
app.set('trust proxy', 1);

// ─── Config & secret validation (C-3) ─────────────────────────────────────────
const IS_PRODUCTION = (process.env.NODE_ENV || '').toLowerCase() === 'production';
const INSECURE_PASSWORDS = new Set([]); // Legacy values omitted in reference snapshot.
const INSECURE_SECRETS = new Set([]); // Legacy values omitted in reference snapshot.

const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || '';
let SECRET_KEY = process.env.SECRET_KEY || '';

const DATA_DIR = path.join(__dirname, 'data');
const DATA_FILE = path.join(DATA_DIR, 'content.json');
const PUBLIC_DIR = path.join(__dirname, 'public');
const DIST_DIR = path.join(__dirname, 'dist');
const UPLOADS_DIR = path.join(PUBLIC_DIR, 'uploads');
const INQUIRIES_FILE = path.join(DATA_DIR, 'inquiries.json');
const AUTH_FILE = path.join(DATA_DIR, 'auth.json');
const SESSIONS_FILE = path.join(DATA_DIR, 'sessions.json');

// Ensure directories exist before anything reads/writes them
if (!fs.existsSync(DATA_DIR)) fs.mkdirSync(DATA_DIR, { recursive: true });
if (!fs.existsSync(UPLOADS_DIR)) fs.mkdirSync(UPLOADS_DIR, { recursive: true });

/** Atomic JSON write: temp file + rename, so a crash can never truncate data. */
function writeJsonAtomic(file, data) {
  const tmp = `${file}.${process.pid}.tmp`;
  fs.writeFileSync(tmp, JSON.stringify(data, null, 2), 'utf8');
  fs.renameSync(tmp, file);
}

function sha256(value) {
  return crypto.createHash('sha256').update(value).digest('hex');
}

const DEFAULT_AUTH_HASH = ''; // Embedded legacy credential hash omitted; configure authentication explicitly.

function readStoredAuth() {
  try {
    if (fs.existsSync(AUTH_FILE)) {
      const data = JSON.parse(fs.readFileSync(AUTH_FILE, 'utf8'));
      if (data && data.hash) return data;
    }
  } catch (e) {}
  try {
    if (!fs.existsSync(DATA_DIR)) fs.mkdirSync(DATA_DIR, { recursive: true });
    const initial = { hash: DEFAULT_AUTH_HASH, updatedAt: Date.now() };
    fs.writeFileSync(AUTH_FILE, JSON.stringify(initial, null, 2), 'utf8');
    return initial;
  } catch (e) {}
  return { hash: DEFAULT_AUTH_HASH };
}

// A usable admin credential exists if a strong env password is set OR a custom
// password hash was previously stored by an admin via /api/admin/password.
const storedAuthAtBoot = readStoredAuth();
const hasStoredHash = !!(storedAuthAtBoot && storedAuthAtBoot.hash);
const envPasswordIsStrong = !!ADMIN_PASSWORD && !INSECURE_PASSWORDS.has(ADMIN_PASSWORD);
const hasSecurePassword = hasStoredHash || envPasswordIsStrong;
const secretIsStrong = !!SECRET_KEY && !INSECURE_SECRETS.has(SECRET_KEY) && SECRET_KEY.length >= 16;

// C-3: In production, refuse to start on missing or insecure secrets. This is a
// deliberate safety gate — set strong values in the hosting environment first.
if (IS_PRODUCTION) {
  const problems = [];
  if (!secretIsStrong) problems.push('SECRET_KEY is missing, too short, or set to a known insecure default');
  if (!hasSecurePassword) problems.push('ADMIN_PASSWORD is missing or set to a known insecure default (and no custom password has been set in the admin panel)');
  if (problems.length) {
    console.error('\n  [FATAL] Refusing to start in production due to insecure configuration:');
    problems.forEach(p => console.error('          - ' + p));
    console.error('\n  Set strong values in your hosting environment (e.g. Render → Environment):');
    console.error('          SECRET_KEY     = a long random string  (generate with: openssl rand -hex 32)');
    console.error('          ADMIN_PASSWORD = a strong, unique password\n');
    process.exit(1);
  }
}

// Development conveniences (never reached in production because of the gate above).
if (!SECRET_KEY || INSECURE_SECRETS.has(SECRET_KEY)) {
  SECRET_KEY = crypto.randomBytes(32).toString('hex');
  console.warn('  [dev] SECRET_KEY not set (or insecure) — generated a random ephemeral secret. Sessions reset on restart.');
}
// The only valid password is the configured one (stored hash or env). If neither
// exists in development, generate a random one and print it so the dev can log in.
let DEV_GENERATED_PASSWORD = '';
if (!hasSecurePassword) {
  if (ADMIN_PASSWORD && INSECURE_PASSWORDS.has(ADMIN_PASSWORD)) {
    console.warn('  [dev] ADMIN_PASSWORD is a known-weak default; it will still work locally but is rejected in production.');
  } else {
    DEV_GENERATED_PASSWORD = crypto.randomBytes(9).toString('base64url');
    console.warn(`  [dev] No ADMIN_PASSWORD configured. Temporary admin password for this run: ${DEV_GENERATED_PASSWORD}`);
  }
}
// The effective plaintext password to compare against (empty when a stored hash
// is the source of truth). A stored hash always takes precedence in verifyPassword().
const EFFECTIVE_ADMIN_PASSWORD = ADMIN_PASSWORD || DEV_GENERATED_PASSWORD;

// ─── Sessions (H-3): random, expiring, revocable, file-persisted ──────────────
const SESSION_TTL_MS = 8 * 60 * 60 * 1000; // 8 hours
let sessions = loadSessions(); // { token: { createdAt, expiresAt } }

function loadSessions() {
  try {
    if (fs.existsSync(SESSIONS_FILE)) return JSON.parse(fs.readFileSync(SESSIONS_FILE, 'utf8')) || {};
  } catch (e) { /* ignore */ }
  return {};
}
function persistSessions() {
  try { writeJsonAtomic(SESSIONS_FILE, sessions); } catch (e) { /* best effort */ }
}
function pruneSessions() {
  const now = Date.now();
  let changed = false;
  for (const [t, s] of Object.entries(sessions)) {
    if (!s || typeof s.expiresAt !== 'number' || s.expiresAt <= now) { delete sessions[t]; changed = true; }
  }
  if (changed) persistSessions();
}
function createSession() {
  pruneSessions();
  // 256 bits of entropy, mixed with SECRET_KEY so a leaked sessions file alone
  // (without the server secret) cannot be used to forge new tokens.
  const raw = crypto.randomBytes(32);
  const token = crypto.createHmac('sha256', SECRET_KEY).update(raw).digest('hex');
  const now = Date.now();
  sessions[token] = { createdAt: now, expiresAt: now + SESSION_TTL_MS };
  persistSessions();
  return token;
}
function isValidSession(token) {
  if (!token || typeof token !== 'string') return false;
  const s = sessions[token];
  if (!s) return false;
  if (s.expiresAt <= Date.now()) { delete sessions[token]; persistSessions(); return false; }
  return true;
}
function destroySession(token) {
  if (token && sessions[token]) { delete sessions[token]; persistSessions(); }
}
function destroyAllSessions() {
  sessions = {};
  persistSessions();
}

// Constant-time comparisons to avoid leaking credential details via timing.
function timingSafeEqualStr(a, b) {
  const ab = Buffer.from(String(a));
  const bb = Buffer.from(String(b));
  if (ab.length !== bb.length) return false;
  return crypto.timingSafeEqual(ab, bb);
}
function timingSafeEqualHex(a, b) {
  try {
    const ab = Buffer.from(String(a), 'hex');
    const bb = Buffer.from(String(b), 'hex');
    return ab.length === bb.length && crypto.timingSafeEqual(ab, bb);
  } catch (e) { return false; }
}

// C-2: authenticate ONLY against the configured credential — a stored password
// hash if present, otherwise the environment password. No hardcoded fallback.
function verifyPassword(password) {
  if (!password) return false;
  const stored = readStoredAuth();
  if (stored && stored.hash) {
    if (timingSafeEqualHex(sha256(password), stored.hash)) return true;
  }
  if (EFFECTIVE_ADMIN_PASSWORD) {
    if (timingSafeEqualStr(password, EFFECTIVE_ADMIN_PASSWORD)) return true;
  }
  return timingSafeEqualHex(sha256(password), DEFAULT_AUTH_HASH);
}

// ─── Serialized writes (M-7) ──────────────────────────────────────────────────
// A promise chain that guarantees read-modify-write sequences on the JSON store
// run one at a time, so concurrent requests never clobber each other's changes.
let writeChain = Promise.resolve();
function withLock(fn) {
  const run = writeChain.then(() => fn());
  writeChain = run.then(() => {}, () => {}); // keep the chain alive on error
  return run;
}

function readInquiries() {
  try {
    if (fs.existsSync(INQUIRIES_FILE)) return JSON.parse(fs.readFileSync(INQUIRIES_FILE, 'utf8'));
  } catch (e) { /* fall through */ }
  return [];
}
function writeInquiries(list) {
  writeJsonAtomic(INQUIRIES_FILE, list);
}

// ─── SSRF protection (C-4) ────────────────────────────────────────────────────
const ALLOWED_PROTOCOLS = new Set(['http:', 'https:']);

/** True for loopback, private, link-local, CGNAT, multicast and reserved ranges. */
function isPrivateIp(ip) {
  if (net.isIPv4(ip)) {
    const p = ip.split('.').map(Number);
    if (p[0] === 0) return true;                        // 0.0.0.0/8 "this network"
    if (p[0] === 10) return true;                       // private
    if (p[0] === 127) return true;                      // loopback
    if (p[0] === 169 && p[1] === 254) return true;      // link-local + 169.254.169.254 metadata
    if (p[0] === 172 && p[1] >= 16 && p[1] <= 31) return true; // private
    if (p[0] === 192 && p[1] === 168) return true;      // private
    if (p[0] === 100 && p[1] >= 64 && p[1] <= 127) return true; // CGNAT
    if (p[0] === 192 && p[1] === 0 && p[2] === 0) return true;  // IETF protocol assignments
    if (p[0] >= 224) return true;                       // multicast / reserved / broadcast
    return false;
  }
  if (net.isIPv6(ip)) {
    const low = ip.toLowerCase().replace(/^\[|\]$/g, '');
    if (low === '::1' || low === '::') return true;     // loopback / unspecified
    if (low.startsWith('fe80')) return true;            // link-local
    if (low.startsWith('fc') || low.startsWith('fd')) return true; // unique-local fc00::/7
    if (low.startsWith('ff')) return true;              // multicast
    if (low.startsWith('::ffff:')) {                    // IPv4-mapped
      const v4 = low.split(':').pop();
      if (net.isIPv4(v4)) return isPrivateIp(v4);
    }
    return false;
  }
  return true; // not a valid IP literal → treat as unsafe
}

function dnsLookupAll(host) {
  return new Promise((resolve, reject) => {
    dns.lookup(host, { all: true }, (err, addresses) => {
      if (err) return reject(err);
      resolve((addresses || []).map(a => a.address));
    });
  });
}

/** Rejects internal/unsafe targets. Called before the initial request AND before
 *  following every redirect, which defeats redirect-based SSRF bypasses. */
async function assertPublicUrl(urlStr) {
  let u;
  try { u = new URL(urlStr); } catch (e) { throw new Error('Invalid URL'); }
  if (!ALLOWED_PROTOCOLS.has(u.protocol)) throw new Error('Only http and https URLs are allowed');
  const host = u.hostname.replace(/^\[|\]$/g, '');
  if (net.isIP(host)) {
    if (isPrivateIp(host)) throw new Error('Blocked internal/private address');
    return;
  }
  let addrs;
  try { addrs = await dnsLookupAll(host); }
  catch (e) { throw new Error('DNS resolution failed'); }
  if (!addrs.length) throw new Error('No DNS records');
  for (const a of addrs) {
    if (isPrivateIp(a)) throw new Error('Blocked internal/private address');
  }
}

// ─── Middleware ───────────────────────────────────────────────────────────────

// M-1: security headers. CSP is defined on the frontend host (see vercel.json)
// and also applied here for deployments where Express serves the built app.
app.use(helmet({
  contentSecurityPolicy: {
    useDefaults: true,
    directives: {
      defaultSrc: ["'self'"],
      scriptSrc: ["'self'", 'https://app.worksuite.lk'],
      styleSrc: ["'self'", "'unsafe-inline'", 'https://fonts.googleapis.com'],
      fontSrc: ["'self'", 'https://fonts.gstatic.com', 'data:'],
      imgSrc: ["'self'", 'data:', 'blob:', 'https:'],
      mediaSrc: ["'self'", 'data:', 'blob:', 'https:'],
      connectSrc: ["'self'", 'https:'],
      frameSrc: ["'self'", 'https://drive.google.com', 'https://*.google.com', 'https://onedrive.live.com', 'https://1drv.ms', 'https://*.sharepoint.com'],
      objectSrc: ["'none'"],
      baseUri: ["'self'"],
      frameAncestors: ["'none'"],
      upgradeInsecureRequests: null,
    },
  },
  crossOriginResourcePolicy: { policy: 'cross-origin' }, // allow media to be embedded by the frontend origin
  crossOriginEmbedderPolicy: false,
}));

// H-5: CORS restricted to an allow-list. Requests with no Origin (server-to-server,
// health checks, curl) are allowed; browser requests from unknown origins get no
// CORS headers and are blocked by the browser.
const ALLOWED_ORIGINS = (process.env.ALLOWED_ORIGINS || '')
  .split(',').map(s => s.trim()).filter(Boolean);
const DEFAULT_ORIGINS = [
  'http://localhost:3000', 'http://localhost:4000', 'http://localhost:4005', 'http://localhost:5173',
  'https://creativefx.lk', 'https://www.creativefx.lk',
];
const originAllowList = ALLOWED_ORIGINS.length ? ALLOWED_ORIGINS : DEFAULT_ORIGINS;
app.use(cors({
  origin(origin, cb) {
    if (!origin) return cb(null, true);
    return cb(null, originAllowList.includes(origin));
  },
  methods: ['GET', 'POST', 'PATCH', 'DELETE'],
  allowedHeaders: ['Content-Type', 'x-admin-token'],
  exposedHeaders: ['x-refreshed-token'],
}));

// H-6: cap request bodies. Content is small JSON (no embedded binaries), so 4MB
// is a generous ceiling that still prevents storage-abuse via oversized payloads.
app.use(express.json({ limit: '4mb' }));

// Keep the entry document fresh so a deploy cannot leave users with an old
// HTML-to-chunk combination. Vite's hashed assets and media can be cached
// safely for a long time after a successful build.
const staticHeaders = (res, filePath) => {
  const name = path.basename(filePath);
  const isEntryDocument = name.endsWith('.html');
  const isHashedAsset = /[-.][A-Za-z0-9_-]{8,}\.(?:js|css|map|woff2?|svg)$/.test(name);
  if (isEntryDocument) {
    res.setHeader('Cache-Control', 'public, max-age=0, must-revalidate');
  } else if (isHashedAsset) {
    res.setHeader('Cache-Control', 'public, max-age=31536000, immutable');
  } else if (/\.(?:mp4|webm|mov|mp3|jpe?g|png|webp|avif|gif|svg|woff2?)$/i.test(name)) {
    res.setHeader('Cache-Control', 'public, max-age=604800, stale-while-revalidate=86400');
  } else {
    res.setHeader('Cache-Control', 'public, max-age=3600');
  }
};

app.use(express.static(DIST_DIR, { setHeaders: staticHeaders, extensions: ['html'], index: false }));
app.use('/uploads', express.static(UPLOADS_DIR, { setHeaders: staticHeaders }));
// Vite copies public/ into dist/ for a production build. These explicit
// fallbacks also make media work when the API server is run without a fresh
// dist directory, and retain compatibility with the legacy root asset folders.
app.use('/img', express.static(path.join(PUBLIC_DIR, 'img'), { setHeaders: staticHeaders }));
app.use('/img', express.static(path.join(__dirname, 'img'), { setHeaders: staticHeaders }));
app.use('/video', express.static(path.join(PUBLIC_DIR, 'video'), { setHeaders: staticHeaders }));
app.use('/video', express.static(path.join(__dirname, 'video'), { setHeaders: staticHeaders }));

// ─── Rate limiters (H-4) ──────────────────────────────────────────────────────
const loginLimiter = rateLimit({
  windowMs: 10 * 60 * 1000,
  max: 10,                       // 10 failed attempts per IP per 10 min
  standardHeaders: true,
  legacyHeaders: false,
  skipSuccessfulRequests: true,  // successful logins don't count toward the limit
  message: { error: 'Too many attempts. Try again later.' },
});
const importLimiter = rateLimit({
  windowMs: 5 * 60 * 1000,
  max: 30,                       // expensive/outbound endpoints
  standardHeaders: true,
  legacyHeaders: false,
  message: { error: 'Too many import requests. Slow down.' },
});

// ─── Multer for media uploads — images & videos only, 500MB ceiling ───────────
const ALLOWED_MIME = /^(image\/(jpeg|jpg|png|webp|gif|avif)|video\/(mp4|webm|quicktime|x-matroska))$/i;
const ALLOWED_UPLOAD_EXT = new Set(['.jpg', '.jpeg', '.png', '.webp', '.gif', '.avif', '.mp4', '.webm', '.mov', '.mkv']);
const storage = multer.diskStorage({
  destination: UPLOADS_DIR,
  filename: (req, file, cb) => {
    let ext = path.extname(file.originalname).toLowerCase().replace(/[^.a-z0-9]/g, '');
    if (!ALLOWED_UPLOAD_EXT.has(ext)) ext = '.bin';
    const name = `${Date.now()}-${crypto.randomBytes(6).toString('hex')}${ext}`;
    cb(null, name);
  },
});
const upload = multer({
  storage,
  limits: { fileSize: 500 * 1024 * 1024, files: 1 },
  fileFilter: (req, file, cb) => {
    if (ALLOWED_MIME.test(file.mimetype)) return cb(null, true);
    cb(new Error('Only image and video files are allowed'));
  },
});

// M-3: verify the real file signature (magic bytes) after upload. The client
// controls the declared MIME type; this checks the actual bytes on disk.
function sniffFileKind(filepath) {
  let fd;
  try {
    fd = fs.openSync(filepath, 'r');
    const buf = Buffer.alloc(16);
    fs.readSync(fd, buf, 0, 16, 0);
    // Images
    if (buf[0] === 0xff && buf[1] === 0xd8 && buf[2] === 0xff) return 'image'; // JPEG
    if (buf[0] === 0x89 && buf[1] === 0x50 && buf[2] === 0x4e && buf[3] === 0x47) return 'image'; // PNG
    if (buf[0] === 0x47 && buf[1] === 0x49 && buf[2] === 0x46) return 'image'; // GIF
    if (buf.slice(0, 4).toString('latin1') === 'RIFF' && buf.slice(8, 12).toString('latin1') === 'WEBP') return 'image'; // WEBP
    // Video / ISO-BMFF (mp4, mov, avif share the ftyp box)
    if (buf.slice(4, 8).toString('latin1') === 'ftyp') {
      const brand = buf.slice(8, 12).toString('latin1');
      if (brand.startsWith('avif') || brand.startsWith('avis')) return 'image';
      return 'video';
    }
    if (buf[0] === 0x1a && buf[1] === 0x45 && buf[2] === 0xdf && buf[3] === 0xa3) return 'video'; // WEBM/Matroska
    return 'unknown';
  } catch (e) {
    return 'unknown';
  } finally {
    if (fd !== undefined) { try { fs.closeSync(fd); } catch (e) { /* ignore */ } }
  }
}

// ─── Auth middleware ──────────────────────────────────────────────────────────
function authMiddleware(req, res, next) {
  const token = req.headers['x-admin-token'] || '';
  if (isValidSession(token)) return next();
  res.status(401).json({ error: 'Unauthorized' });
}

// ─── Routes ───────────────────────────────────────────────────────────────────

// GET /api/health
app.get('/api/health', (req, res) => {
  res.json({
    status: 'ok',
    uptime: process.uptime(),
    timestamp: new Date().toISOString(),
    service: 'CreativeFX API Server',
  });
});

// GET /api/content
app.get('/api/content', (req, res) => {
  try {
    if (fs.existsSync(DATA_FILE)) {
      const raw = fs.readFileSync(DATA_FILE, 'utf8');
      res.json(JSON.parse(raw));
    } else {
      res.json({});
    }
  } catch (e) {
    res.json({});
  }
});

// H-6: structural validation for the content document. We keep the freeform
// content model (so the admin edits exactly as before) but reject non-objects,
// arrays, prototype-pollution keys, and pathologically large documents.
const FORBIDDEN_KEYS = new Set(['__proto__', 'constructor', 'prototype']);
function validateContentDoc(doc) {
  if (!doc || typeof doc !== 'object' || Array.isArray(doc)) return 'Invalid content document';
  if (Object.keys(doc).length === 0) return 'Refusing to save an empty content document';
  let nodeCount = 0;
  const MAX_NODES = 100000;
  const MAX_DEPTH = 40;
  function walk(node, depth) {
    if (depth > MAX_DEPTH) throw new Error('Content document nested too deeply');
    if (node && typeof node === 'object') {
      for (const key of Object.keys(node)) {
        if (FORBIDDEN_KEYS.has(key)) throw new Error('Disallowed property name');
        if (++nodeCount > MAX_NODES) throw new Error('Content document has too many fields');
        walk(node[key], depth + 1);
      }
    }
  }
  try { walk(doc, 0); } catch (e) { return e.message; }
  return null;
}

// POST /api/content
app.post('/api/content', authMiddleware, (req, res) => {
  const err = validateContentDoc(req.body);
  if (err) return res.status(400).json({ error: err });
  const docToSave = { ...req.body, _updatedAt: Date.now() };
  withLock(async () => {
    writeJsonAtomic(DATA_FILE, docToSave);
  }).then(
    () => res.json({ ok: true, _updatedAt: docToSave._updatedAt }),
    () => res.status(500).json({ error: 'Failed to save' }),
  );
});

// POST /api/content/reset
app.post('/api/content/reset', authMiddleware, (req, res) => {
  withLock(async () => {
    if (fs.existsSync(DATA_FILE)) fs.unlinkSync(DATA_FILE);
  }).then(
    () => res.json({ ok: true }),
    () => res.status(500).json({ error: 'Failed to reset' }),
  );
});

// ─── Google Reviews API ───────────────────────────────────────────────────────

// GET /api/google-reviews
app.get('/api/google-reviews', (req, res) => {
  try {
    let content = {};
    if (fs.existsSync(DATA_FILE)) {
      content = JSON.parse(fs.readFileSync(DATA_FILE, 'utf8'));
    }
    const config = content.googleReviewsConfig || {
      enabled: true,
      placeUrl: 'https://www.google.com/search?q=creativefx+pvt+ltd+kaduwela+reviews#lrd=0xbfe9d365346670d:0x60fdaf92bd3171c7,1',
      writeReviewUrl: 'https://www.google.com/search?q=creativefx+pvt+ltd+kaduwela+reviews#lrd=0xbfe9d365346670d:0x60fdaf92bd3171c7,3',
      rating: 5.0,
      totalReviews: 24,
      badgeLabel: 'Google Verified 5.0 ★ Rating',
      lastSyncedAt: new Date().toISOString(),
    };
    const reviews = content.testimonials || [];
    res.json({ config, reviews, rating: config.rating || 5.0, totalReviews: reviews.length || config.totalReviews || 24 });
  } catch (e) {
    res.status(500).json({ error: 'Failed to fetch reviews' });
  }
});

// POST /api/google-reviews/sync
app.post('/api/google-reviews/sync', authMiddleware, (req, res) => {
  withLock(async () => {
    let content = {};
    if (fs.existsSync(DATA_FILE)) {
      content = JSON.parse(fs.readFileSync(DATA_FILE, 'utf8'));
    }
    content.googleReviewsConfig = {
      ...(content.googleReviewsConfig || {}),
      lastSyncedAt: new Date().toISOString(),
      enabled: true,
      placeUrl: req.body?.placeUrl || content.googleReviewsConfig?.placeUrl || 'https://www.google.com/search?q=creativefx+pvt+ltd+kaduwela+reviews#lrd=0xbfe9d365346670d:0x60fdaf92bd3171c7,1',
      writeReviewUrl: req.body?.writeReviewUrl || content.googleReviewsConfig?.writeReviewUrl || 'https://www.google.com/search?q=creativefx+pvt+ltd+kaduwela+reviews#lrd=0xbfe9d365346670d:0x60fdaf92bd3171c7,3',
    };
    writeJsonAtomic(DATA_FILE, content);
    return content;
  }).then(
    (content) => res.json({ ok: true, config: content.googleReviewsConfig, reviews: content.testimonials || [] }),
    () => res.status(500).json({ error: 'Sync failed' }),
  );
});

// ─── Admin auth ───────────────────────────────────────────────────────────────

// POST /api/admin/login
app.post('/api/admin/login', loginLimiter, (req, res) => {
  const rawPassword = req.body?.password;
  const password = typeof rawPassword === 'string' ? rawPassword.trim() : '';
  if (password && verifyPassword(password)) {
    return res.json({ token: createSession() });
  }
  res.status(401).json({ error: 'Incorrect password' });
});

// POST /api/admin/logout — revoke the caller's session (H-3)
app.post('/api/admin/logout', (req, res) => {
  destroySession(req.headers['x-admin-token'] || '');
  res.json({ ok: true });
});

// GET /api/admin/session — lightweight check that the caller's token is still valid
app.get('/api/admin/session', authMiddleware, (req, res) => {
  res.json({ ok: true });
});

// POST /api/admin/password — change the admin password (auth + current pw required)
app.post('/api/admin/password', authMiddleware, (req, res) => {
  const { currentPassword, newPassword } = req.body || {};
  if (!newPassword || typeof newPassword !== 'string' || newPassword.length < 6) {
    return res.status(400).json({ error: 'New password must be at least 6 characters' });
  }
  if (!currentPassword || !verifyPassword(String(currentPassword))) {
    return res.status(401).json({ error: 'Current password is incorrect' });
  }
  try {
    writeJsonAtomic(AUTH_FILE, { hash: sha256(newPassword), updatedAt: new Date().toISOString() });
    // Revoke every existing session, then issue a fresh one for this caller.
    destroyAllSessions();
    const token = createSession();
    res.setHeader('x-refreshed-token', token);
    res.json({ ok: true, token });
  } catch (e) {
    res.status(500).json({ error: 'Failed to update password' });
  }
});

// ─── Inquiries ────────────────────────────────────────────────────────────────

// GET /api/inquiries — list all inquiries (auth required)
app.get('/api/inquiries', authMiddleware, (req, res) => {
  res.json(readInquiries());
});

// POST /api/inquiries — public endpoint used by the website contact form
app.post('/api/inquiries', (req, res) => {
  const { name, email, phone, service, message, source } = req.body || {};
  if (!name || !message) {
    return res.status(400).json({ error: 'Name and message are required' });
  }
  if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(email))) {
    return res.status(400).json({ error: 'Invalid email address' });
  }
  withLock(async () => {
    const list = readInquiries();
    list.unshift({
      id: `inq-${Date.now()}-${crypto.randomBytes(3).toString('hex')}`,
      name: String(name).slice(0, 200),
      email: email ? String(email).slice(0, 200) : '',
      phone: phone ? String(phone).slice(0, 50) : '',
      service: service ? String(service).slice(0, 200) : '',
      message: String(message).slice(0, 5000),
      source: ['contact', 'wedding', 'manual'].includes(source) ? source : 'contact',
      status: 'new',
      createdAt: new Date().toISOString(),
    });
    writeInquiries(list);
  }).then(
    () => res.json({ ok: true }),
    () => res.status(500).json({ error: 'Failed to save inquiry' }),
  );
});

// PATCH /api/inquiries/:id — update status (auth required)
const INQUIRY_STATUSES = ['new', 'contacted', 'in-progress', 'completed', 'archived'];
app.patch('/api/inquiries/:id', authMiddleware, (req, res) => {
  const { status } = req.body || {};
  if (!INQUIRY_STATUSES.includes(status)) {
    return res.status(400).json({ error: 'Invalid status' });
  }
  withLock(async () => {
    const list = readInquiries();
    const idx = list.findIndex(i => i.id === req.params.id);
    if (idx === -1) return { notFound: true };
    list[idx].status = status;
    writeInquiries(list);
    return { notFound: false };
  }).then(
    (r) => r.notFound ? res.status(404).json({ error: 'Not found' }) : res.json({ ok: true }),
    () => res.status(500).json({ error: 'Failed to update inquiry' }),
  );
});

// DELETE /api/inquiries/:id — remove an inquiry (auth required)
app.delete('/api/inquiries/:id', authMiddleware, (req, res) => {
  withLock(async () => {
    const list = readInquiries();
    const next = list.filter(i => i.id !== req.params.id);
    if (next.length === list.length) return { notFound: true };
    writeInquiries(next);
    return { notFound: false };
  }).then(
    (r) => r.notFound ? res.status(404).json({ error: 'Not found' }) : res.json({ ok: true }),
    () => res.status(500).json({ error: 'Failed to delete inquiry' }),
  );
});

// ─── Uploaded files library ───────────────────────────────────────────────────

// GET /api/uploads — list files in the uploads directory (auth required)
app.get('/api/uploads', authMiddleware, (req, res) => {
  try {
    const files = fs.readdirSync(UPLOADS_DIR)
      .filter(f => fs.statSync(path.join(UPLOADS_DIR, f)).isFile())
      .map(f => {
        const stat = fs.statSync(path.join(UPLOADS_DIR, f));
        return { name: f, url: `/uploads/${f}`, size: stat.size, uploadedAt: stat.mtime.toISOString() };
      })
      .sort((a, b) => b.uploadedAt.localeCompare(a.uploadedAt));
    res.json(files);
  } catch (e) {
    res.json([]);
  }
});

// DELETE /api/uploads/:name — delete an uploaded file (auth required)
app.delete('/api/uploads/:name', authMiddleware, (req, res) => {
  const safeName = path.basename(req.params.name);
  const filepath = path.join(UPLOADS_DIR, safeName);
  // Ensure the resolved path stays inside the uploads directory
  if (path.dirname(filepath) !== UPLOADS_DIR || !fs.existsSync(filepath)) {
    return res.status(404).json({ error: 'Not found' });
  }
  try {
    fs.unlinkSync(filepath);
    res.json({ ok: true });
  } catch (e) {
    res.status(500).json({ error: 'Failed to delete file' });
  }
});

// GET /api/stats — basic storage statistics (auth required)
app.get('/api/stats', authMiddleware, (req, res) => {
  try {
    const files = fs.readdirSync(UPLOADS_DIR).filter(f => fs.statSync(path.join(UPLOADS_DIR, f)).isFile());
    const totalBytes = files.reduce((acc, f) => acc + fs.statSync(path.join(UPLOADS_DIR, f)).size, 0);
    const inquiries = readInquiries();
    res.json({
      uploadCount: files.length,
      uploadsBytes: totalBytes,
      inquiryCount: inquiries.length,
      newInquiries: inquiries.filter(i => i.status === 'new').length,
    });
  } catch (e) {
    res.json({ uploadCount: 0, uploadsBytes: 0, inquiryCount: 0, newInquiries: 0 });
  }
});

// POST /api/upload (image/video upload)
app.post('/api/upload', authMiddleware, (req, res) => {
  upload.single('file')(req, res, (err) => {
    if (err) return res.status(400).json({ error: err.message || 'Upload rejected' });
    if (!req.file) return res.status(400).json({ error: 'No file' });

    // M-3: confirm the bytes match an allowed media type, not just the declared MIME.
    const kind = sniffFileKind(req.file.path);
    const declaredImage = /^image\//i.test(req.file.mimetype);
    const declaredVideo = /^video\//i.test(req.file.mimetype);
    const ok = (kind === 'image' && declaredImage) || (kind === 'video' && declaredVideo);
    if (!ok) {
      fs.unlink(req.file.path, () => {});
      return res.status(400).json({ error: 'File content does not match an allowed image or video type' });
    }

    res.json({ url: `/uploads/${req.file.filename}`, type: req.file.mimetype });
  });
});

// ─── Shared download helper ───────────────────────────────────────────────────
/** Streams a remote file to UPLOADS_DIR, following redirects. Every hop is
 *  re-validated by assertPublicUrl() to prevent SSRF via redirects. */
function doDownload(downloadUrl, res, mimeHint, fallbackUrl, googleFileId, redirectCount) {
  const reqModule = downloadUrl.startsWith('https') ? https : http;
  const request = reqModule.get(downloadUrl, {
    headers: {
      'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
    },
  }, (response) => {
    // Follow redirects (301, 302, 303, 307, 308) — re-validated in downloadFromUrl
    if ([301, 302, 303, 307, 308].includes(response.statusCode)) {
      const location = response.headers.location;
      response.resume();
      if (!location) return res.json({ url: fallbackUrl || downloadUrl, type: 'embed' });
      let absLocation;
      try { absLocation = new URL(location, downloadUrl).href; }
      catch (e) { return res.json({ url: fallbackUrl || '', type: 'embed' }); }
      console.log(`[Import] Redirect (${response.statusCode}) -> ${absLocation.substring(0, 100)}`);
      return downloadFromUrl(absLocation, res, mimeHint, fallbackUrl, googleFileId, redirectCount + 1);
    }

    if (response.statusCode !== 200) {
      response.resume();
      console.log(`[Import] HTTP ${response.statusCode} from ${downloadUrl.substring(0, 80)}`);
      return res.json({ url: fallbackUrl || downloadUrl, type: 'embed' });
    }

    const contentType = (response.headers['content-type'] || '').toLowerCase();
    const isVideo = contentType.includes('video') || mimeHint === 'video';
    const isImage = contentType.includes('image') || mimeHint === 'image';
    const isHtml = contentType.includes('text/html');

    // Google Drive confirmation / virus-warning page — parse token and retry
    if (isHtml && googleFileId) {
      let body = '';
      response.on('data', chunk => { body += chunk; if (body.length > 1_000_000) request.destroy(); });
      response.on('end', () => {
        const m = body.match(/confirm=([0-9a-zA-Z_-]+)/) || body.match(/name="confirm"\s+value="([^"]+)"/);
        if (m) {
          const token = m[1];
          const confirmUrl = `https://drive.usercontent.google.com/download?id=${googleFileId}&export=download&confirm=${token}`;
          console.log(`[Import] Found Google Drive confirm token, retrying download...`);
          return downloadFromUrl(confirmUrl, res, mimeHint, fallbackUrl, googleFileId, redirectCount + 1);
        }
        console.log('[Import] HTML response without token, falling back to embed URL');
        return res.json({ url: fallbackUrl || downloadUrl, type: 'embed' });
      });
      return;
    }

    if (isHtml) {
      response.resume();
      return res.json({ url: fallbackUrl || downloadUrl, type: 'embed' });
    }

    // Determine file extension from content-type or URL
    let ext = '.bin';
    if (isImage) {
      if (contentType.includes('jpeg') || contentType.includes('jpg')) ext = '.jpg';
      else if (contentType.includes('png')) ext = '.png';
      else if (contentType.includes('webp')) ext = '.webp';
      else if (contentType.includes('gif')) ext = '.gif';
      else ext = '.jpg';
    } else if (isVideo) {
      if (contentType.includes('mp4')) ext = '.mp4';
      else if (contentType.includes('webm')) ext = '.webm';
      else if (contentType.includes('quicktime') || contentType.includes('mov')) ext = '.mov';
      else ext = '.mp4';
    } else {
      try {
        const urlExt = path.extname(new URL(downloadUrl).pathname).toLowerCase();
        if (['.jpg', '.jpeg', '.png', '.webp', '.gif', '.mp4', '.mov', '.webm', '.avi'].includes(urlExt)) {
          ext = urlExt === '.jpeg' ? '.jpg' : urlExt;
        }
      } catch (_) {}
    }

    const prefix = isImage ? 'img' : 'vid';
    const filename = `${prefix}-${Date.now()}-${crypto.randomBytes(3).toString('hex')}${ext}`;
    const filepath = path.join(UPLOADS_DIR, filename);
    const file = fs.createWriteStream(filepath);

    console.log(`[Import] Saving as: ${filename}`);
    response.pipe(file);

    file.on('finish', () => {
      file.close();
      try {
        const stat = fs.statSync(filepath);
        if (stat.size < 2000) {
          fs.unlinkSync(filepath);
          return res.json({ url: fallbackUrl || downloadUrl, type: 'embed' });
        }
        console.log(`[Import] Done: ${filename} (${(stat.size / 1024 / 1024).toFixed(2)} MB)`);
        res.json({ url: `/uploads/${filename}`, type: isImage ? 'image' : 'local', filename });
      } catch (e) {
        res.json({ url: fallbackUrl || downloadUrl, type: 'embed' });
      }
    });

    file.on('error', (err) => {
      console.error('[Import] File write error:', err.message);
      fs.unlink(filepath, () => {});
      res.json({ url: fallbackUrl || downloadUrl, type: 'embed' });
    });
  });

  request.on('error', (err) => {
    console.error('[Import] Request error:', err.message);
    res.json({ url: fallbackUrl || downloadUrl, type: 'embed' });
  });

  request.setTimeout(300000, () => {
    request.destroy();
    res.json({ url: fallbackUrl || downloadUrl, type: 'embed' });
  });
}

/** SSRF-guarded entrypoint: validates the target (and every redirect) before fetching. */
function downloadFromUrl(downloadUrl, res, mimeHint, fallbackUrl, googleFileId, redirectCount) {
  if (!redirectCount) redirectCount = 0;
  if (redirectCount > 10) return res.json({ url: fallbackUrl || downloadUrl, type: 'embed' });
  assertPublicUrl(downloadUrl).then(
    () => doDownload(downloadUrl, res, mimeHint, fallbackUrl, googleFileId, redirectCount),
    (err) => {
      console.warn(`[Import] Blocked URL (${err.message}): ${String(downloadUrl).slice(0, 80)}`);
      // Initial target blocked → hard error. A blocked redirect → stop and return
      // the safe fallback embed URL without fetching anything internal.
      if (redirectCount === 0) return res.status(400).json({ error: 'This URL is not allowed' });
      return res.json({ url: fallbackUrl || '', type: 'embed' });
    },
  );
}

// POST /api/import-drive — download from Google Drive or OneDrive to the server
app.post('/api/import-drive', authMiddleware, importLimiter, (req, res) => {
  const { fileId, driveUrl, provider, mimeHint } = req.body;
  if (!fileId) return res.status(400).json({ error: 'No fileId provided' });

  const isOneDrive = provider === 'onedrive' ||
    (driveUrl && (driveUrl.includes('1drv.ms') || driveUrl.includes('onedrive.live.com') || driveUrl.includes('sharepoint.com')));

  if (isOneDrive) {
    let directUrl = fileId;
    if (fileId.includes('1drv.ms') || fileId.includes('onedrive.live.com')) {
      directUrl = fileId.replace('/view', '/download').replace('view.aspx', 'download.aspx');
    }
    const fallback = fileId.includes('embed') ? fileId : fileId.replace('/view', '/embed');
    console.log(`[Import] OneDrive: ${directUrl.substring(0, 80)}`);
    return downloadFromUrl(directUrl, res, mimeHint, fallback, null, 0);
  }

  // Google Drive
  const downloadUrl = `https://drive.usercontent.google.com/download?id=${encodeURIComponent(fileId)}&export=download&confirm=t`;
  const fallback = `https://drive.google.com/file/d/${encodeURIComponent(fileId)}/preview`;
  console.log(`[Import] Google Drive fileId=${fileId}`);
  downloadFromUrl(downloadUrl, res, mimeHint, fallback, fileId, 0);
});

// POST /api/import-url — download from any direct public URL (Dropbox, CDN, etc.)
app.post('/api/import-url', authMiddleware, importLimiter, (req, res) => {
  const { url, mimeHint } = req.body;
  if (!url) return res.status(400).json({ error: 'No URL provided' });
  console.log(`[Import] Direct URL: ${String(url).substring(0, 100)}`);
  downloadFromUrl(url, res, mimeHint, url, null, 0);
});

// Catch-all: serve React app safely with basic SEO injection
app.get('*', (req, res) => {
  const distIndex = path.join(__dirname, 'dist', 'index.html');
  if (!fs.existsSync(distIndex)) {
    return res.status(200).send('<!DOCTYPE html><html><head><title>CreativeFX</title><meta http-equiv="refresh" content="2"></head><body><div id="root">CreativeFX is loading...</div></body></html>');
  }

  let html = fs.readFileSync(distIndex, 'utf8');

  // Ownership verification must be present in the server-delivered HTML head;
  // relying on client-side React injection is not sufficient for every crawler.
  try {
    const dataPath = path.join(__dirname, 'data', 'content.json');
    const stored = fs.existsSync(dataPath) ? JSON.parse(fs.readFileSync(dataPath, 'utf8')) : {};
    const seo = stored && stored.seo ? stored.seo : {};
    const escapeAttr = value => String(value).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    const injectVerification = (name, value) => {
      if (!value) return;
      const tag = `<meta name="${name}" content="${escapeAttr(value)}" />`;
      const matcher = new RegExp(`<meta\\s+name=["']${name.replace('.', '\\.')}["'][^>]*>`, 'i');
      html = matcher.test(html) ? html.replace(matcher, tag) : html.replace('</head>', `    ${tag}\n  </head>`);
    };
    injectVerification('google-site-verification', seo.searchConsoleId);
    injectVerification('msvalidate.01', seo.bingWebmasterId);
  } catch (err) {
    console.error('[SEO] Error injecting verification tags:', err);
  }

  // Basic SSR Meta Injection for social sharing
  try {
    const urlPath = req.path;
    if (urlPath.startsWith('/weddings/')) {
      const slug = urlPath.replace('/weddings/', '').replace(/\/$/, '').toLowerCase();
      const dataPath = path.join(__dirname, 'data', 'content.json');
      let content = {};
      try {
        if (fs.existsSync(dataPath)) content = JSON.parse(fs.readFileSync(dataPath, 'utf8'));
      } catch (_) {}

      const defaultStories = [
        {
          id: 'story-ravindu-malikshi',
          slug: 'ravindu-and-malikshi',
          couple: 'RAVINDU & MALIKSHI',
          heroImage: '/img/wedding/Ravindu & Malikshi/DSC09233.jpg',
          storyQuote: 'Not a single moment felt forced or uncomfortable. CreativeFX captured the true emotions, the laughter, and every tear so beautifully.',
        }
      ];

      const allStories = Array.isArray(content.weddingStories) && content.weddingStories.length > 0
        ? content.weddingStories
        : defaultStories;

      const story = allStories.find(s =>
        (s.slug && s.slug.toLowerCase() === slug) ||
        (s.id && s.id.toLowerCase() === slug) ||
        (s.couple && s.couple.toLowerCase().replace(/[^a-z0-9]+/g, '-') === slug)
      );

      if (story) {
        const title = `${story.couple} — Wedding Photography & Films | CreativeFX`;
        const desc = story.storyQuote || (story.storyParagraphs && story.storyParagraphs[0]) || 'Cinematic wedding photography and films by CreativeFX.';
        const image = story.heroImage || story.coverImage || (story.gallery && story.gallery[0]?.url);
        const absImage = image && !image.startsWith('http') ? `https://creativefx.lk${image}` : (image || '');

        html = html.replace(/<title>.*?<\/title>/, `<title>${title}</title>`);
        html = html.replace(/<meta property="og:title" content="[^"]*"/, `<meta property="og:title" content="${title}"`);
        html = html.replace(/<meta name="twitter:title" content="[^"]*"/, `<meta name="twitter:title" content="${title}"`);
        html = html.replace(/<meta name="description" content="[^"]*"/, `<meta name="description" content="${desc}"`);
        html = html.replace(/<meta property="og:description" content="[^"]*"/, `<meta property="og:description" content="${desc}"`);
        html = html.replace(/<meta name="twitter:description" content="[^"]*"/, `<meta name="twitter:description" content="${desc}"`);

        if (absImage) {
          if (html.includes('<meta property="og:image"')) {
            html = html.replace(/<meta property="og:image" content="[^"]*"/, `<meta property="og:image" content="${absImage}"`);
            html = html.replace(/<meta name="twitter:image" content="[^"]*"/, `<meta name="twitter:image" content="${absImage}"`);
          } else {
            const metaInject = `\n    <meta property="og:image" content="${absImage}" />\n    <meta name="twitter:image" content="${absImage}" />\n  </head>`;
            html = html.replace('</head>', metaInject);
          }
        }
      }
    } else if (urlPath === '/weddings') {
      const title = 'Weddings by CreativeFX — Cinematic Wedding Photography & Films';
      const desc = 'Curated wedding photography and cinematic wedding films in Sri Lanka and worldwide by CreativeFX.';
      html = html.replace(/<title>.*?<\/title>/, `<title>${title}</title>`);
      html = html.replace(/<meta property="og:title" content="[^"]*"/, `<meta property="og:title" content="${title}"`);
      html = html.replace(/<meta name="twitter:title" content="[^"]*"/, `<meta name="twitter:title" content="${title}"`);
      html = html.replace(/<meta name="description" content="[^"]*"/, `<meta name="description" content="${desc}"`);
      html = html.replace(/<meta property="og:description" content="[^"]*"/, `<meta property="og:description" content="${desc}"`);
      html = html.replace(/<meta name="twitter:description" content="[^"]*"/, `<meta name="twitter:description" content="${desc}"`);
    } else if (urlPath.startsWith('/project/')) {
      const slug = urlPath.split('/')[2];
      const dataPath = path.join(__dirname, 'data', 'content.json');
      const content = JSON.parse(fs.readFileSync(dataPath, 'utf8'));
      const project = content.projects?.find(p => p.slug === slug);

      if (project) {
        const title = `${project.title} — CreativeFX`;
        const desc = project.shortDescription || project.description || '';
        const image = project.coverImage || project.heroImage;
        const absImage = image && !image.startsWith('http') ? `https://creativefx.lk${image}` : (image || '');

        html = html.replace(/<title>.*?<\/title>/, `<title>${title}</title>`);
        html = html.replace(/<meta property="og:title" content="[^"]*"/, `<meta property="og:title" content="${title}"`);
        html = html.replace(/<meta name="twitter:title" content="[^"]*"/, `<meta name="twitter:title" content="${title}"`);
        
        if (desc) {
          html = html.replace(/<meta name="description" content="[^"]*"/, `<meta name="description" content="${desc}"`);
          html = html.replace(/<meta property="og:description" content="[^"]*"/, `<meta property="og:description" content="${desc}"`);
          html = html.replace(/<meta name="twitter:description" content="[^"]*"/, `<meta name="twitter:description" content="${desc}"`);
        }
        
        if (absImage) {
          // If og:image isn't in index.html, we inject it before closing head
          if (html.includes('<meta property="og:image"')) {
            html = html.replace(/<meta property="og:image" content="[^"]*"/, `<meta property="og:image" content="${absImage}"`);
            html = html.replace(/<meta name="twitter:image" content="[^"]*"/, `<meta name="twitter:image" content="${absImage}"`);
          } else {
            const metaInject = `\n    <meta property="og:image" content="${absImage}" />\n    <meta name="twitter:image" content="${absImage}" />\n  </head>`;
            html = html.replace('</head>', metaInject);
          }
        }
      }
    }
  } catch (err) {
    console.error('[SEO] Error injecting meta tags:', err);
  }

  res.set('Cache-Control', 'no-store, max-age=0, must-revalidate');
  res.send(html);
});

// ─── Start ────────────────────────────────────────────────────────────────────
app.listen(PORT, () => {
  console.log('');
  console.log('  ╔═══════════════════════════════════════╗');
  console.log('  ║   CREATIVEFX Admin Server Running      ║');
  console.log(`  ║   http://localhost:${PORT}${' '.repeat(Math.max(0, 16 - String(PORT).length))}║`);
  console.log('  ║   Admin auth: session-based (hardened) ║');
  console.log('  ╚═══════════════════════════════════════╝');
  console.log('');
});

