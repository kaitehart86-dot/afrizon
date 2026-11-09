#!/usr/bin/env node
/**
 * AfrizoN CRM — serveur de développement local
 * ─────────────────────────────────────────────────────────────
 * كيشغّل المشروع (index.html + api.php) محلياً بلا Apache وبلا PHP مثبّت:
 *   • الملفات الستاتيكية كتقرا مباشرة من القرص → أي تعديل فـ index.html/css/js كيبان فورا
 *   • api.php كيتنفّذ بـ PHP 8.3 مكمپايل لـ WebAssembly (@php-wasm/node)
 *     بـ 64-bit integers (timestamps بالميلي ثانية خدامين بحال production)
 *   • ملفات الداتا (crm_data.json, audit.log …) كتتزامن مع القرص بعد كل طلب،
 *     وأي تعديل فـ api.php كيترفع أوتوماتيكياً (hot reload)
 *
 * ⚠️ هادا غير للتنمية (dev). فـ production كيبقى Apache + PHP عادي.
 *
 * Usage:  npm install && npm run dev       (PORT=8080)
 */
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { PHP } from '@php-wasm/universal';
import { loadNodeRuntime } from '@php-wasm/node';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(__dirname, '..');
const PORT = Number(process.env.PORT || 8080);
const HOST = process.env.HOST || '0.0.0.0';
const PHP_VERSION = process.env.PHP_VERSION || '8.3';
const APP = '/app';
const DEBUG = !!process.env.DEV_DEBUG;

/* ما كنرفعوهش لـ wasm */
const SKIP_DIRS = new Set(['.git', 'node_modules', 'dev', '.arena', 'backups']);

/* ملفات الداتا اللي كيقراها/يكتبها api.php — هي بوحدها اللي كتتزامن مع القرص */
const DATA_FILES = [
  'crm_data.json', 'crm_data.json.backup', 'journal.log', 'journal.log.1',
  'audit.log', 'audit.log.1', 'dl-diag.json', 'digylog_cfg.json', '.crm.etag',
  '.crm.lock', '.dl-auto-state.json', '.dl-stores-cache.json',
  '.dl-networks-cache.json', '.dl-dash-cache.json', 'push_keys.json',
  'push_subs.json', '.dl.lock', '.dl-send-auto.lock', '.dl-send-auto.stamp',
  '.delivery-auto.lock', '.delivery-auto.stamp', '.tracking-auto.lock',
  '.tracking-auto.stamp', '.commission-cut-20261028.done', '.city-price-cut-20261028.done',
];

/* ملفات مسدودة من الويب (نفس .htaccess) — حتى فـ dev */
const DENY = [
  /^\/crm_data\.json/i, /^\/crm_data\.json\.backup/i, /^\/digylog_cfg\.json/i,
  /^\/audit\.log/i, /^\/journal\.log/i, /^\/push_keys\.json/i, /^\/push_subs\.json/i,
  /^\/\.crm\./i, /^\/\.dl/i, /^\/dl-diag\.json$/i, /^\/backups\//i,
  /^\/(import|manual_sync|recover)\.php$/i, /^\/dino\.html$/i,
];

const MIME = {
  '.html': 'text/html; charset=utf-8', '.htm': 'text/html; charset=utf-8',
  '.js': 'application/javascript; charset=utf-8', '.mjs': 'application/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8', '.json': 'application/json; charset=utf-8',
  '.webmanifest': 'application/manifest+json; charset=utf-8',
  '.txt': 'text/plain; charset=utf-8', '.svg': 'image/svg+xml',
  '.png': 'image/png', '.jpg': 'image/jpeg', '.jpeg': 'image/jpeg',
  '.gif': 'image/gif', '.webp': 'image/webp', '.ico': 'image/x-icon',
  '.wasm': 'application/wasm', '.woff': 'font/woff', '.woff2': 'font/woff2',
  '.mp3': 'audio/mpeg', '.pdf': 'application/pdf',
};

/* ───────────── état ───────────── */
let php = null;
let chain = Promise.resolve();
const uploaded = new Map();   // rel → mtimeMs
const diskMirror = new Map(); // data file → Buffer آخر نسخة معروفة

/* ───────────── helpers ───────────── */
function walk(dir, base, out) {
  for (const name of fs.readdirSync(dir)) {
    const abs = path.join(dir, name);
    const rel = base ? base + '/' + name : name;
    const st = fs.statSync(abs);
    if (st.isDirectory()) {
      if (SKIP_DIRS.has(name)) continue;
      walk(abs, rel, out);
    } else if (!/\.zip$/i.test(name)) {
      out.push(rel);
    }
  }
  return out;
}

async function ensureDir(dir) {
  try { php.mkdirTree(dir); } catch (_) { /* already there */ }
}

async function uploadFile(rel) {
  const abs = path.join(ROOT, rel);
  const buf = fs.readFileSync(abs);
  const dir = path.posix.dirname(APP + '/' + rel);
  if (dir !== APP) await ensureDir(dir);
  await php.writeFile(APP + '/' + rel, buf);
  uploaded.set(rel, fs.statSync(abs).mtimeMs);
}

async function uploadProject() {
  const files = walk(ROOT, '', []);
  for (const rel of files) await uploadFile(rel);
  return files.length;
}

/* hot reload: نعاودو نرفعو غير اللي تبدّل على القرص */
async function refreshChanged() {
  for (const rel of walk(ROOT, '', [])) {
    const abs = path.join(ROOT, rel);
    const mtime = fs.statSync(abs).mtimeMs;
    if (uploaded.get(rel) !== mtime) {
      await uploadFile(rel);
      if (DEBUG) console.log('[hot] reloaded', rel);
    }
  }
}

/* الداتا: نقراو الملفات من PHP ونكتبو فـ القرص غير اللي تبدّل */
async function syncBack() {
  for (const name of DATA_FILES) {
    let data;
    try {
      data = Buffer.from(php.readFileAsBuffer(APP + '/' + name));
    } catch (_) {
      continue; // ما كاينش
    }
    const prev = diskMirror.get(name);
    if (prev && prev.equals(data)) continue;
    fs.writeFileSync(path.join(ROOT, name), data);
    diskMirror.set(name, data);
    if (prev) console.log(`  ↳ sync ${name} (${data.length} B)`);
  }
}

async function uploadData() {
  for (const name of DATA_FILES) {
    const abs = path.join(ROOT, name);
    if (!fs.existsSync(abs)) continue;
    const buf = fs.readFileSync(abs);
    await php.writeFile(APP + '/' + name, buf);
    diskMirror.set(name, buf);
  }
}

function serveStatic(req, res, pathname) {
  let rel = decodeURIComponent(pathname);
  if (rel === '/' || rel === '') rel = '/index.html';

  if (DENY.some((re) => re.test(rel))) {
    res.writeHead(403, { 'Content-Type': 'text/plain; charset=utf-8' });
    res.end('403 — accès refusé (protection .htaccess)');
    return;
  }

  const abs = path.join(ROOT, rel);
  if (!abs.startsWith(ROOT) || !fs.existsSync(abs) || !fs.statSync(abs).isFile()) {
    res.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' });
    res.end('404 — ' + rel);
    return;
  }
  const st = fs.statSync(abs);
  const headers = {
    'Content-Type': MIME[path.extname(abs).toLowerCase()] || 'application/octet-stream',
    'Content-Length': st.size,
    'Cache-Control': 'no-store',
  };
  if (req.method === 'HEAD') { res.writeHead(200, headers); res.end(); return; }
  res.writeHead(200, headers);
  fs.createReadStream(abs).pipe(res);
}

async function handlePhp(req, res, parsed, scriptName, body) {
  const started = Date.now();
  await refreshChanged();

  const headers = {};
  for (const [k, v] of Object.entries(req.headers)) {
    if (k === 'accept-encoding') continue; // بلا gzip فـ dev
    headers[k] = Array.isArray(v) ? v.join(', ') : String(v);
  }

  let response;
  try {
    response = await php.run({
      scriptPath: APP + '/' + scriptName,
      relativeUri: req.url,
      protocol: 'http',
      method: req.method,
      headers,
      body: new Uint8Array(body),
      $_SERVER: {
        HTTP_HOST: (req.headers.host || `localhost:${PORT}`),
        SERVER_NAME: (req.headers.host || 'localhost').split(':')[0],
        SERVER_PORT: String(PORT),
        HTTPS: 'off',
        REMOTE_ADDR: (req.socket.remoteAddress || '127.0.0.1').replace('::ffff:', ''),
        REMOTE_PORT: String(req.socket.remotePort || 0),
      },
    });
  } catch (err) {
    const text = err && err.response ? err.response.text : String(err && err.message);
    console.error('[php] fatal:', (text || '').slice(0, 400));
    res.writeHead(500, { 'Content-Type': 'text/plain; charset=utf-8' });
    res.end('PHP error:\n' + text);
    await syncBack();
    return;
  }

  const outHeaders = {};
  for (const [k, v] of Object.entries(response.headers || {})) {
    if (['connection', 'transfer-encoding', 'content-length', 'x-powered-by', 'keep-alive'].includes(k.toLowerCase())) continue;
    outHeaders[k] = Array.isArray(v) ? v.join(', ') : String(v);
  }
  const bytes = response.bytes || new Uint8Array();
  res.writeHead(response.httpStatusCode || 200, outHeaders);
  res.end(bytes.length ? Buffer.from(bytes) : undefined);

  const errs = (response.errors || '').trim();
  console.log(`  php ${req.method} ${req.url} → ${response.httpStatusCode} (${bytes.length}B, ${Date.now() - started}ms)`);
  if (DEBUG && errs) console.log('[debug] php warnings:', errs.slice(0, 800));
  await syncBack();
}

/* ───────────── serveur ───────────── */
async function main() {
  console.log('⚙️  démarrage de PHP %s (wasm, 64-bit int)…', PHP_VERSION);
  php = new PHP(await loadNodeRuntime(PHP_VERSION, { emscriptenOptions: { processId: 1 } }));
  await ensureDir(APP);
  await ensureDir(APP + '/backups');
  const n = await uploadProject();
  await uploadData();
  console.log(`📦 ${n} fichiers chargés dans le FS PHP`);

  const server = http.createServer((req, res) => {
    const parsed = new URL(req.url, `http://${req.headers.host || 'localhost'}`);
    const pathname = parsed.pathname;

    const chunks = [];
    req.on('data', (c) => chunks.push(c));
    req.on('end', () => {
      const body = Buffer.concat(chunks);
      if (/\.php$/i.test(pathname)) {
        const scriptName = pathname.replace(/^\/+/, '');
        if (!fs.existsSync(path.join(ROOT, scriptName))) {
          res.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' });
          res.end('404 — ' + scriptName);
          return;
        }
        if (DENY.some((re) => re.test(pathname))) {
          res.writeHead(403, { 'Content-Type': 'text/plain; charset=utf-8' });
          res.end('403 — accès refusé (protection .htaccess)');
          return;
        }
        /* طلب واحد فـ نفس الوقت: PHP wasm runtime واحد */
        chain = chain
          .then(() => handlePhp(req, res, parsed, scriptName, body))
          .catch((err) => {
            console.error('[req] error', err);
            try {
              res.writeHead(500, { 'Content-Type': 'text/plain; charset=utf-8' });
              res.end('dev-server error: ' + (err && err.message));
            } catch (_) { /* déjà envoyé */ }
          });
      } else if (req.method === 'GET' || req.method === 'HEAD') {
        serveStatic(req, res, pathname);
      } else {
        res.writeHead(405, { 'Content-Type': 'text/plain; charset=utf-8' });
        res.end('method not allowed');
      }
    });
  });

  server.listen(PORT, HOST, () => {
    console.log(`\n✅ AfrizoN CRM (dev) → http://${HOST}:${PORT}`);
    console.log('   statique: disque · api.php: PHP wasm · data: sync après chaque requête\n');
  });

  const bye = () => { console.log('\n👋 arrêt…'); server.close(); setTimeout(() => process.exit(0), 100); };
  process.on('SIGINT', bye);
  process.on('SIGTERM', bye);
}

main().catch((err) => { console.error('fatal:', err); process.exit(1); });
