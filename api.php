<?php
/**
 * AfrizoN CRM — api.php (v3.82)
 *
 * Contract used by index.html (unchanged):
 *   GET  api.php                       → { key: {t, d}, ... }
 *   POST api.php {key, t, d}           → {ok:true, t:<effective t>}   (header X-Sync-Token required)
 *
 * v3.101 — جميع طلبيات Storeep/الشيت الجداد كيتعيّنو لمريم فقط (server-enforced)
 * v3.99 — إشعار أوتوماتيك ملي تدخل/تتبدل طلبية باسم بنت (CRM + الشيت)
 * v3.98 — ID ديال طلبيات الشيت ديما = 1
 * v3.97 — الشيت → CRM: POST {action:'sheet_orders', rows:[...]} كيزيد الطلبيات الجداد
 *   (تكرار سيرفر-سايد: تاريخ + هاتف + منتوج). الجبد الكامل من CRM ما تبدلش.
 *
 * v3.82 — التسليك النهائي: تغيير الباسوورد كيوصل للسيرفر + يوزر ممسوح ما كيرجعش
 *   + المتصفح كيعاود يصيفط اليوزرز/الطلبيات إلا لقا السيرفر راهو رجع لنسخة قديمة.
 *   POST api.php {action: ...}         → Digylog actions  (header X-Sync-Token required)
 *
 * v3.81 — الإصلاح الجذري ديال "اليوزر الجديد كيتمسح من بعد لحضات":
 *   - journal replay ولى كيقارن الوقت: سطر قديم عمرُو ما كيقدر يتربح على داتا أحدث
 *     منه (v3.80 كانت كتطبق آخر سطر ديما — سطر قديم فـ journal الميرا كان كيمسح
 *     اليوزرز/الطلبيات فكل قرية).
 *   - قرية الملفات: primary + mirror كيتدمجو مفتاح بمفتاح (الأحدث كيربح)، واليوزرز
 *     كيتوحّدو بالـ id — ملف قديم فـ أي بلاصة ما بقاش كيخبي يوزرز.
 *   - t ديال أي مفتاح ما كينقصش عمرو (noop:same كان كيرجّع الوقت للور مع ساعة
 *     متأخرة → الأجهزة الأخرى كيوقفو يشوفو التبدلات).
 *   - POST كيرجّع t الفعلي → المتصفح كيسجلو → كلشي متزامن مع ساعة السيرفر.
 *   - فشل الكتابة فـ journal/MIRA كيتسجل فـ audit.log (ما بقاش صامت).
 *
 * v3.41 hardening:
 *   - key whitelist (only known CRM keys are accepted)
 *   - stale-write rejection (older `t` than stored never overwrites newer data)
 *   - unwrap protection (rejects double-wrapped {t,d:{t,d}} payloads → fixes corrupted custom_sheets / team_photos)
 *   - exclusive lock around read-modify-write (two agents saving at once no longer lose a write)
 *   - rotating backups (last 30 writes + 1 per day) instead of a single .backup that got overwritten
 *   - payload size limit + minimal audit log
 *   - data dir outside public_html when available (../crm-afrizon-data), falls back to local file
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-Sync-Token');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { exit; }

/* ---------- config ---------- */
$SECRET = '8c907fc0f4ffe0b9775a6b7c3c0fc7700e5724c0d78343df';

/* ---------- نسخة السيرفر ----------
 * كنرجعوها فـ export=version وفـ جواب digylog_sync، باش نعرفو بالضبط
 * أشمن api.php خدام دابا فـ production (ufiq le déploiement ma bditch). */
define('CRM_API_VERSION', '3.94');
/* جيل المرسل: ملي كيتبدل الكود، كل الطلبيات لي سبقت كتجرب من جديد.
 * (ماشي كيبقاوش محبوسة بـ 20 محاولة قديمة ولا بـ backoff طويل.) */
define('CRM_DL_SENDER_GEN', 4);

/* v3.67: deterministic data dir + mirror.
 * قبل: كان كيبدل المجلد بصمت إلا كان !is_writable → جوج ملفات بيانات مفروشين =
 * طلبيات كتظهر وكتختفي. دابا: كنختارو المجلد بالـ epoch (أحدث بيانات) ونكتبو
 * فـ الجوج بلاصات ديما حتى يرجع التوأمية بيناتهم. */
$DIR_EXT = __DIR__ . '/../crm-afrizon-data';
$DIR_LOC = __DIR__;
function crm_epoch_dir($d) {
  $m = 0;
  $s = @file_get_contents($d . '/crm_data.json');
  if ($s !== false && $s !== '') {
    $j = json_decode($s, true);
    if (is_array($j)) { foreach ($j as $v) { if (is_array($v) && isset($v['t']) && (int)$v['t'] > $m) $m = (int)$v['t']; } }
    else { $m = -1; } // undecodable file must never win
  }
  foreach (array($d . '/journal.log', $d . '/journal.log.1') as $jf) {
    if (!file_exists($jf)) continue;
    $fh = @fopen($jf, 'r'); if (!$fh) continue;
    $sz = @filesize($jf); if ($sz > 8192) @fseek($fh, -8192, SEEK_END);
    $tail = '';
    while (($l = fgets($fh)) !== false) { $l = trim($l); if ($l !== '') $tail = $l; }
    @fclose($fh);
    $e = json_decode((string)$tail, true);
    if (is_array($e) && isset($e['t']) && (int)$e['t'] > $m) $m = (int)$e['t'];
  }
  return $m;
}
$PRIMARY = $DIR_EXT; $MIRROR = $DIR_LOC;
$__eE = 0; $__eL = 0;
if (!is_dir($DIR_EXT)) { $PRIMARY = $DIR_LOC; $MIRROR = null; }
else {
  $__eE = crm_epoch_dir($DIR_EXT); $__eL = crm_epoch_dir($DIR_LOC);
  if ($__eL > $__eE) { $PRIMARY = $DIR_LOC; $MIRROR = $DIR_EXT; }
}
$DATA_DIR    = $PRIMARY;
$DATA_FILE   = $PRIMARY . '/crm_data.json';
$MIRROR_FILE = ($MIRROR !== null) ? $MIRROR . '/crm_data.json' : null;
$LOCK_FILE   = $PRIMARY . '/.crm.lock';
$BACKUP_DIR  = $PRIMARY . '/backups';
$AUDIT_FILE  = $PRIMARY . '/audit.log';
$JOURNAL     = $PRIMARY . '/journal.log';
$JOURNAL_M   = ($MIRROR !== null) ? $MIRROR . '/journal.log' : null;
$PUSH_KEYS_FILE = $DATA_DIR . '/push_keys.json';   // v3.98: مفاتيح Web Push (مسدودة من الويب)
$PUSH_SUBS_FILE = $DATA_DIR . '/push_subs.json';   // v3.98: هواتف البنات (مسدودة من الويب)
/* PERF v3.90: 8MB journal = replay ديال 8 ميغا JSON فـ كل قراية. 512KB كافيين
 * بزاف حيت كل كتابة كتسبقها كتابة كاملة ديال crm_data.json تحت القفل. */
$JOURNAL_MAX = 512 * 1024;
$MAX_BODY    = 24 * 1024 * 1024;   // 24 MB per write
$KEEP_WRITES = 30;                 // rotating pre-write backups
$KEEP_DAYS   = 30;                 // daily snapshots
$ETAG_CACHE  = $PRIMARY . '/.crm.etag';   // PERF v3.90: cache ديال ETag باش GET يولي 304 بلا قراية
$BACKUP_MIN_GAP = 120;             // PERF v3.90: نسخة احتياطية كل دقيقتين كحد أقصى (ماشي فكل كتابة)
$TOMBSTONE_TTL  = 21 * 86400;      // PERF v3.90: الطلبية الممسوحة كتتنحا نهائياً من بعد 21 يوم
$TOMBSTONE_MAX  = 150;             // PERF v3.90: وفأي حال، ما كنحتافظوش بأكثر من 150 طومبستون

$ALLOWED_KEYS = array(
  'afrizon_users_v1','afrizon_orders_v5','afrizon_agent_names_v1','afrizon_chat_v1',
  'afrizon_worktimes_v1','afrizon_remarques_v1','afrizon_avances_v1','afrizon_adspend_v1',
  'afrizon_perfrows_v1','afrizon_livraison_v1','afrizon_history_v1','afrizon_villes_v2',
  'afrizon_catalog_v1','sheet_pièce','afrizon_team_photos_v1','tabs_list_v1',
  'custom_sheets_v1','afrizon_period_v1','afrizon_period_v2',
  'afrizon_backup_v1','afrizon_backup_v1_agents',
  'afrizon_dupok_v1','afrizon_returns_v1','afrizon_return_audit_v1','afrizon_stock_movements_v1'   // v3.94 + retours: المكررات لي تعلّمات «ماشي مكررة»
);

/* ---------- helpers ---------- */
function crm_token() {
  if (isset($_SERVER['HTTP_X_SYNC_TOKEN'])) return (string)$_SERVER['HTTP_X_SYNC_TOKEN'];
  if (isset($_GET['token'])) return (string)$_GET['token'];
  return '';
}
function crm_out($arr, $code = 200) {
  if ($code !== 200) http_response_code($code);
  echo json_encode($arr, JSON_UNESCAPED_UNICODE);
  exit;
}
function crm_audit($line) {
  global $AUDIT_FILE;
  $ip = $_SERVER['REMOTE_ADDR'] ?? '-';
  @file_put_contents($AUDIT_FILE, date('Y-m-d H:i:s') . " | $ip | $line\n", FILE_APPEND | LOCK_EX);
  if (@filesize($AUDIT_FILE) > 2 * 1024 * 1024) { @rename($AUDIT_FILE, $AUDIT_FILE . '.1'); }
}
/** يحافظ على أحدث نسخة إلا تكرّر نفس المفتاح */
function crm_migrate_keys($j) {
  $o = array();
  foreach ($j as $k => $v) {
    $nk = $k;
    if (isset($o[$nk]) && isset($o[$nk]['t']) && isset($v['t']) && $o[$nk]['t'] >= $v['t']) continue;
    $o[$nk] = $v;
  }
  return $o;
}
/* PERF v3.90: crm_read_raw() كانت كتتسما 3 مرات فـ كل POST — كل مرة كتقرا وتفكّك
 * crm_data.json (1.1MB) + الميرا (1.1MB) + replay ديال 4 ملفات journal.
 * = أكثر من 10MB ديال JSON decoding باش تحفظ طلبية وحدة.
 * دابا النتيجة كتتخزن فالذاكرة داخل نفس الـ request. القراية لي تحت القفل
 * خاصها تكون طرية ($force=true) حيت ممكن شي request آخر كتب بيناتهم. */
function crm_read_raw($force = false) {
  static $cache = null;
  if (!$force && $cache !== null) return $cache;
  $cache = crm_read_raw_fresh();
  return $cache;
}
function crm_read_raw_fresh() {
  global $DATA_FILE, $BACKUP_DIR, $MIRROR_FILE;
  $j = null;
  if (file_exists($DATA_FILE)) {
    $s = @file_get_contents($DATA_FILE);
    $j = json_decode((string)$s, true);
    if (!is_array($j)) crm_audit("corrupt | main file undecodable | len=" . strlen((string)$s));
  }
  if (!is_array($j)) {
    // corrupted/missing main file → try newest backup (journal replay below restores newest state anyway)
    $c = glob($BACKUP_DIR . '/b-*.json');
    if ($c) { rsort($c); foreach ($c as $f) { $j = json_decode((string)@file_get_contents($f), true); if (is_array($j)) { crm_audit("recover | from=" . basename($f)); break; } } }
  }
  if (!is_array($j)) $j = array();
  /* v3.81: دمج primary + mirror مفتاح بمفتاح (الأحدث t كيربح) + توحيد اليوزرز بالـ id.
     قبل: إلا الميرا (crm-afrizon-data) كانت فيها نسخة قديمة، كل قرية كترجع للقديم
     → "اليوزر الجديد كيتمسح من بعد لحضات". دابا ملف قديم فأي بلاصة ما كيقدر
     يخبي داتا أحدث، واليوزرز لي ضاعو من أحد الملفين كيرجعو. */
  if ($MIRROR_FILE && is_file($MIRROR_FILE)) {
    $m = json_decode((string)@file_get_contents($MIRROR_FILE), true);
    if (is_array($m)) {
      /* v3.82: ليستة اليوزرز ديال الملف الرئيسي خاصها تنحفظ قبل دمج المفاتيح —
         إلا الميرا كان أحدث، الدمج كيبدل اليوزرز ديال الرئيسي قبل الاتحاد
         → اليوزرز لي غير فالرئيسي كانو كيضيعو. */
      $pu = (isset($j['afrizon_users_v1']['d']) && is_array($j['afrizon_users_v1']['d'])) ? crm_unwrap($j['afrizon_users_v1']['d']) : null;
      $mirrorOlderKeys = 0;
      foreach ($m as $mk => $mv) {
        if (!is_array($mv) || !isset($mv['t'])) continue;
        if (!isset($j[$mk]) || !is_array($j[$mk]) || (int)$mv['t'] > (int)$j[$mk]['t']) { $j[$mk] = $mv; $mirrorOlderKeys++; }
      }
      if ($mirrorOlderKeys > 0) crm_audit("mirror-merge | newer-from-mirror keys=$mirrorOlderKeys");
      $ub = (isset($m['afrizon_users_v1']['d']) && is_array($m['afrizon_users_v1']['d'])) ? crm_unwrap($m['afrizon_users_v1']['d']) : null;
      if (is_array($pu) && is_array($ub)) {
        $un = crm_users_union($pu, $ub);
        if (count($un) !== count($pu)) { crm_audit("users-rescue | merged users: " . count($pu) . " + " . count($ub) . " → " . count($un)); $j['afrizon_users_v1']['d'] = $un; $j['afrizon_users_v1']['t'] = max((int)$j['afrizon_users_v1']['t'], (int)$m['afrizon_users_v1']['t']); }
      }
    }
  }
  // v3.67: journal replay — أحدث كتابة ناجحة كتربح ديما، حتى إلا crm_data.json تبدل يدويا
  // (FTP) ولا تخسر → الطلبيات الجديدة عمرهم ما كيتنساو.
  return crm_journal_replay(crm_migrate_keys($j));
}
/* v3.81: توحيد ليستة اليوزرز بالـ id — أحدث _u كيربح (الحذف _del محترم).
   كتستعمل فـ دمج الملفات (primary+mirror) باش يوزر مفقود من ملف واحد يرجع. */
function crm_users_union($a, $b) {
  $out = array();
  foreach ($a as $u) { if (is_array($u) && isset($u['id'])) $out[(string)$u['id']] = $u; }
  foreach ($b as $u) {
    if (!is_array($u) || !isset($u['id'])) continue;
    $id = (string)$u['id'];
    if (!isset($out[$id])) { $out[$id] = $u; continue; }
    /* v3.82: الحذف نهائي — إلا شي نسخة فيهم _del هي لي كتربح (من الجوج جيهات).
       جوج طومبستونات → الأحدث _u كيربح. حي ضد ميت → الميت كيربح ديما. */
    $cd = !empty($out[$id]['_del']); $nd = !empty($u['_del']);
    if ($cd || $nd) {
      $cu = isset($out[$id]['_u']) ? (float)$out[$id]['_u'] : -1;
      $nu = isset($u['_u']) ? (float)$u['_u'] : -1;
      if (!$cd || ($nd && $nu > $cu)) $out[$id] = $u;
      continue;
    }
    $cu = isset($out[$id]['_u']) ? (float)$out[$id]['_u'] : -1;
    $nu = isset($u['_u']) ? (float)$u['_u'] : -1;
    if ($nu > $cu) $out[$id] = $u;
  }
  return array_values($out);
}
/* v3.67: journal (WAL) — كل كتابة ناجحة كتسجل سطر فـ journal.log. القراية كتعاود
 * التسجيلات فوق الملف الأساسي: إلا الملف الأساسي رجع لنسخة قديمة ولا تفسد،
 * أحدث حالة كترجع بوحدها من الـ journal. */
function crm_journal_append($k, $t, $d) {
  global $JOURNAL, $JOURNAL_M, $JOURNAL_MAX;
  $line = json_encode(array('k' => $k, 't' => $t, 'd' => $d), JSON_UNESCAPED_UNICODE);
  if ($line === false) return;
  foreach (array($JOURNAL, $JOURNAL_M) as $jf) {
    if (!$jf) continue;
    // v3.81: الفشل كيتسجل فـ audit.log — قبل كان صامت وماحدش كان كيعرف علاش الداتا كتضيع
    if (@file_put_contents($jf, $line . "\n", FILE_APPEND | LOCK_EX) === false) { crm_audit("journal-fail | $jf"); continue; }
    if (@filesize($jf) > $JOURNAL_MAX) {
      // كل كتابة كتسبقها كتابة كاملة ديال الملف الرئيسي تحت القفل → محتوى الـ journalولى
      // مكرر → نحيدوه حتى ما يثقلش القراية (replay) فالطلبات الجاية.
      @unlink($jf);
    }
  }
}
function crm_journal_replay($data) {
  global $JOURNAL, $JOURNAL_M;
  $files = array($JOURNAL . '.1', $JOURNAL, ($JOURNAL_M !== null ? $JOURNAL_M . '.1' : null), $JOURNAL_M);
  foreach ($files as $jf) {
    if (!$jf || !file_exists($jf)) continue;
    $fh = @fopen($jf, 'r'); if (!$fh) continue;
    while (($l = fgets($fh)) !== false) {
      $l = trim($l); if ($l === '') continue;
      $e = json_decode($l, true);
      if (!is_array($e) || !isset($e['k'], $e['t'], $e['d']) || !is_array($e['d'])) continue;
      $k = (string)$e['k']; $et = (int)$e['t'];
      /* v3.81: سطر الـ journal ما كيقبلش يتربح على داتا أحدث منه.
         v3.80 كانت كتطبق آخر سطر ديما بالترتيب → سطر قديم فـ journal الميرا
         (ولا أي journal متأخر) كان كيمسح اليوزرز/الطلبيات الجداد فكل قرية.
         هادشي هو بالضبط "اليوزر الجديد كيتمسح من بعد لحضات". */
      $cur = (isset($data[$k]) && is_array($data[$k]) && isset($data[$k]['t'])) ? (int)$data[$k]['t'] : 0;
      if ($et <= $cur) continue;
      $data[$k] = array('t' => $et, 'd' => $e['d']);
    }
    @fclose($fh);
  }
  return $data;
}
/** unwrap {t,d:{t,d:X}} → X (defensive: corruption produced by old import.php) */
function crm_unwrap($v) {
  $g = 0;
  while (is_array($v) && isset($v['t']) && array_key_exists('d', $v) && is_numeric($v['t']) && count($v) <= 2 && $g++ < 5) { $v = $v['d']; }
  return $v;
}
/* PERF v3.90: بصمة رخيصة (stat فقط، بلا قراية) ديال كل ملفات الداتا.
 * كتخدم باش نعرفو واش تبدل شي حاجة بلا ما نقراو 2.2MB ديال JSON. */
function crm_data_sig() {
  global $DATA_FILE, $MIRROR_FILE, $JOURNAL, $JOURNAL_M;
  $p = '';
  foreach (array($DATA_FILE, $MIRROR_FILE, $JOURNAL, $JOURNAL_M, $JOURNAL . '.1') as $f) {
    if (!$f) continue;
    $p .= '|' . (@filemtime($f) ?: 0) . ':' . (@filesize($f) ?: 0);
  }
  return md5($p);
}
/* كيرجع الـ ETag المخزن إلا البصمة باقة هي هي، وإلا null (خاصنا نقراو بصح). */
function crm_etag_cached($sig) {
  global $ETAG_CACHE;
  $s = @file_get_contents($ETAG_CACHE);
  if ($s === false || $s === '') return null;
  $j = json_decode($s, true);
  if (!is_array($j) || !isset($j['sig'], $j['etag'])) return null;
  return ($j['sig'] === $sig) ? (string)$j['etag'] : null;
}
function crm_etag_store($sig, $etag) {
  global $ETAG_CACHE;
  @file_put_contents($ETAG_CACHE, json_encode(array('sig' => $sig, 'etag' => $etag)), LOCK_EX);
}

function crm_backup() {
  global $DATA_FILE, $BACKUP_DIR, $KEEP_WRITES, $KEEP_DAYS, $BACKUP_MIN_GAP;
  if (!file_exists($DATA_FILE)) return;
  if (!is_dir($BACKUP_DIR)) @mkdir($BACKUP_DIR, 0755, true);
  if (!is_dir($BACKUP_DIR)) return;
  /* PERF v3.90: قبل، كل كتابة كانت كتنسخ 1.1MB على القرص. مع 10 وكلاء كيخدمو
   * هادشي ميات الميغاوات ديال I/O فالساعة. دابا نسخة كل دقيقتين كحد أقصى —
   * الحماية باقية (الـ journal + الملف الرئيسي + الميرا) والـ I/O نقص ~95%. */
  $last = 0;
  $b0 = glob($BACKUP_DIR . '/b-*.json');
  if ($b0) { foreach ($b0 as $f) { $mt = @filemtime($f); if ($mt > $last) $last = $mt; } }
  if ($last > 0 && (time() - $last) < $BACKUP_MIN_GAP) {
    $daily0 = $BACKUP_DIR . '/d-' . date('Ymd') . '.json';
    if (!file_exists($daily0)) @copy($DATA_FILE, $daily0);
    return;
  }
  @copy($DATA_FILE, $BACKUP_DIR . '/b-' . date('Ymd-His') . '-' . substr((string)microtime(true) * 1000 % 1000, 0, 3) . '.json');
  $daily = $BACKUP_DIR . '/d-' . date('Ymd') . '.json';
  if (!file_exists($daily)) @copy($DATA_FILE, $daily);
  // rotate
  $b = glob($BACKUP_DIR . '/b-*.json'); if ($b && count($b) > $KEEP_WRITES) { sort($b); foreach (array_slice($b, 0, count($b) - $KEEP_WRITES) as $f) @unlink($f); }
  $d = glob($BACKUP_DIR . '/d-*.json'); if ($d && count($d) > $KEEP_DAYS)   { sort($d); foreach (array_slice($d, 0, count($d) - $KEEP_DAYS) as $f) @unlink($f); }
}
// v3.65: field-level merge — a field edited later (per-field stamp _f) is never overwritten
// by a whole-row write coming from a browser that still held an older copy of the row.
// v3.66: DATE LOCK — dateCreation / dateConfirmation / dateExp / dateLiv can only change
// when the incoming row carries a STRICTLY NEWER per-field stamp (_f.date*). A whole-row
// write from an old/stale browser (no _f, or older _f) can never move a date anymore —
// the stored value always wins. This is the server side of "التاريخ ما كيتبدلش بوحدو".
function crm_merge_row($a, $b) {
  $ua = isset($a['_u']) ? (float)$a['_u'] : 0; $ub = isset($b['_u']) ? (float)$b['_u'] : 0;
  $base = $ub >= $ua ? $b : $a; $oth = $ub >= $ua ? $a : $b;
  if (!empty($a['_del']) || !empty($b['_del'])) return !empty($a['_del']) ? $a : $b;   // v3.103: الحذف نهائي (المسوح ما كيرجعش)
  $bf = (isset($base['_f']) && is_array($base['_f'])) ? $base['_f'] : array();
  $of = (isset($oth['_f']) && is_array($oth['_f'])) ? $oth['_f'] : array();
  foreach ($of as $k => $to) {
    $to = (float)$to; $tb = isset($bf[$k]) ? (float)$bf[$k] : 0;
    if ($to > $tb && array_key_exists($k, $oth)) { $base[$k] = $oth[$k]; $bf[$k] = $to; }
  }
  if ($bf) $base['_f'] = $bf;
  // v3.66 date lock: $a is the STORED row (crm_merge_orders always calls with stored first).
  // If the value differs and the incoming side has no strictly newer explicit date edit,
  // restore the stored value (and its stamp).
  foreach (array('dateCreation','dateConfirmation','dateExp','dateLiv') as $dk) {
    if (!array_key_exists($dk, $a)) continue;
    $av = (string)$a[$dk];
    $bv = array_key_exists($dk, $b) ? (string)$b[$dk] : '';
    if ($av === $bv) continue;
    $ta = (isset($a['_f'][$dk]) && is_numeric($a['_f'][$dk])) ? (float)$a['_f'][$dk] : 0;
    $tb = (isset($b['_f'][$dk]) && is_numeric($b['_f'][$dk])) ? (float)$b['_f'][$dk] : 0;
    if ($tb <= $ta) {
      $base[$dk] = $av;
      if ($ta > 0) {
        $f0 = (isset($base['_f']) && is_array($base['_f'])) ? $base['_f'] : array();
        $f0[$dk] = $ta; $base['_f'] = $f0;
      }
    }
  }
  return $base;
}
function crm_merge_orders($cur, $in, $reset = 0) {
  $nowms = (int)(microtime(true) * 1000);
  $byId = array(); $order = array();
  foreach ($cur as $o) { if (!is_array($o) || !isset($o['id'])) continue; $id = (string)$o['id']; $byId[$id] = $o; $order[] = $id; }
  foreach ($in as $o) {
    if (!is_array($o) || !isset($o['id'])) continue;
    // v3.66: clamp future stamps (device clock ahead of server) — a client can never
    // win a merge just because its clock is wrong; _u and every _f stamp are capped at server time.
    if (isset($o['_u']) && (float)$o['_u'] > $nowms) { $o['_u'] = $nowms; }
    if (isset($o['_f']) && is_array($o['_f'])) {
      foreach ($o['_f'] as $fk => $ft) { if (is_numeric($ft) && (float)$ft > $nowms) $o['_f'][$fk] = $nowms; }
    }
    $id = (string)$o['id'];
    $beforeDates = array();
    if (isset($byId[$id])) {
      foreach (array('dateCreation','dateConfirmation') as $dk) { $beforeDates[$dk] = isset($byId[$id][$dk]) ? (string)$byId[$id][$dk] : ''; }
    }
    if (!isset($byId[$id])) {
      // v3.62: rows older than the last reset (coming from a stale browser cache) are never resurrected
      $u = isset($o['_u']) ? (float)$o['_u'] : 0;
      if ($reset > 0 && $u < $reset) continue;
      $byId[$id] = $o; $order[] = $id; continue;
    }
    $byId[$id] = crm_merge_row($byId[$id], $o);
    // v3.66 audit: every time a merge actually moves a date, leave a trace in audit.log
    foreach ($beforeDates as $dk => $bv) {
      $av = isset($byId[$id][$dk]) ? (string)$byId[$id][$dk] : '';
      if ($bv !== '' && $av !== $bv) crm_audit("date-change | id=$id | $dk: $bv → $av");
    }
  }
  $out = array(); foreach (array_unique($order) as $id) $out[] = $byId[$id];
  usort($out, function($x, $y) { $a = (float)$x['id']; $b = (float)$y['id']; return $a == $b ? 0 : ($a < $b ? 1 : -1); });
  return $out;
}
/* v3.78: دمج موحد — كتابة أحدث كتربح (حتى الحذف)، كتابة متأخرة كتزيد غير الناقص.
   هذا لي كيمنع اختفاء اليوزر الجديد ملي ساعة الجهاز متأخرة ولا جوج زادو فنفس اللحظة. */
function crm_row_key($row) {
  if (is_array($row) && isset($row['id'])) return 'i:' . (string)$row['id'];
  if (is_scalar($row)) return 's:' . (string)$row;
  return 'j:' . json_encode($row);
}
/* PERF v3.90: تنقية الـ tombstones.
 * 599 طلبية من 1408 (42%) كانو عندهم _del=true وباقين فالداتا للأبد — كيتحملو
 * كل مزامنة، كيتدمجو صف بصف، وكياخدو بلاصة فـ localStorage.
 * دابا أي طلبية ممسوحة وقديمة بأكثر من 30 يوم كتتنحا نهائياً. 30 يوم أوسع بزاف
 * من الـ grace ديال المتصفح (15 دقيقة) إذن ما كاين حتى خطر ديال الرجوع. */
function crm_purge_tombstones($rows, $ttl, $max) {
  /* v3.103: الطلبيات الممسوحة ما كتتمسحش نهائياً — كانت كترجع من بعد شي مدة
   * (المتصفح كيرجع نسخة قديمة + الحذف كان كيطيح بعد 150). دابا القديمة كتتصغّر
   * غير (تبقى id + _del + التاريخ/الهاتف/الاسم/المنتوج) باش الحذف يبقى دائم. */
  if (!is_array($rows)) return $rows;
  $cut = ((int)(microtime(true) * 1000)) - ($ttl * 1000);
  $out = array(); $slim = 0;
  foreach ($rows as $r) {
    if (is_array($r) && !empty($r['_del'])) {
      $u = isset($r['_u']) ? (float)$r['_u'] : 0;
      if ($u > 0 && $u < $cut) {
        $r = array(
          'id' => isset($r['id']) ? $r['id'] : '', '_del' => true, '_u' => $u,
          'dateCreation' => isset($r['dateCreation']) ? $r['dateCreation'] : '',
          'telephone' => isset($r['telephone']) ? $r['telephone'] : '',
          'nom' => isset($r['nom']) ? $r['nom'] : '',
          'produit' => isset($r['produit']) ? $r['produit'] : ''
        );
        $slim++;
      }
    }
    $out[] = $r;
  }
  if ($slim > 0) crm_audit("purge | tombstones slimmed=$slim");
  return $out;
}
function crm_merge_list($cur, $in, $tCur, $tIn) {
  if ($tIn >= $tCur) return $in;
  $have = array();
  foreach ($cur as $row) $have[crm_row_key($row)] = true;
  foreach ($in as $row) { $kk = crm_row_key($row); if (!isset($have[$kk])) { $cur[] = $row; $have[$kk] = true; } }
  return $cur;
}
function crm_write($data) {
  global $DATA_FILE;
  $tmp = $DATA_FILE . '.tmp.' . getmypid();
  $json = json_encode($data, JSON_UNESCAPED_UNICODE);
  if ($json === false) return false;
  if (@file_put_contents($tmp, $json, LOCK_EX) === false) return false;
  if (!@rename($tmp, $DATA_FILE)) { @unlink($tmp); return false; }
  return true;
}
/* v3.67: write primary + mirror — الجوج ملفات ديما متطابقين، ما بقاش split-brain */
function crm_write_all($data) {
  global $MIRROR_FILE;
  $ok = crm_write($data);
  if ($MIRROR_FILE) {
    $tmp = $MIRROR_FILE . '.tmp.' . getmypid();
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    if ($json !== false && @file_put_contents($tmp, $json, LOCK_EX) !== false) {
      if (!@rename($tmp, $MIRROR_FILE)) { @unlink($tmp); crm_audit("mirror-fail | rename | $MIRROR_FILE"); } // v3.81: ما بقاش صامت
    } else {
      if (file_exists($tmp)) @unlink($tmp);
      crm_audit("mirror-fail | write | $MIRROR_FILE"); // v3.81: ما بقاش صامت
    }
  }
  return $ok;
}

/* ================================================================
 * v3.98 — إشعارات Web Push للبنات (طلبية جديدة ليها من الشيت)
 *   push_keys.json : مفاتيح VAPID (كتتولد وحدها أول مرة). مسدودة من الويب (.htaccess)
 *   push_subs.json : الهواتف المسجّلة {agent, endpoint, p256dh, auth}. مسدودة من الويب
 *   GET  api.php?pushkey=1&token=...          → المفتاح العام
 *   POST {action:'push_subscribe', agent, sub:{endpoint, keys:{p256dh, auth}}}
 *   POST {action:'push_test', agent}          → إشعار تجريبي لهاد البنت
 *   الإشعارات كتصيفط أوتوماتيك ملي كتدخل طلبيات جداد من الشيت (crm_sheet_orders)
 * ================================================================ */
function crm_push_b64e($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function crm_push_b64d($s) {
  $s = strtr((string)$s, '-_', '+/');
  return base64_decode($s . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
}
function crm_push_read($file) {
  if (!file_exists($file)) return array();
  $j = json_decode((string)@file_get_contents($file), true);
  return is_array($j) ? $j : array();
}
function crm_push_write($file, $arr) {
  $tmp = $file . '.tmp.' . getmypid();
  $json = json_encode($arr, JSON_UNESCAPED_UNICODE);
  if ($json === false || @file_put_contents($tmp, $json, LOCK_EX) === false) return false;
  if (!@rename($tmp, $file)) { @unlink($tmp); return false; }
  return true;
}
/* نقطة P-256 خام (65 بايت، تبدا بـ 0x04) → مفتاح عام PEM */
function crm_push_pub_from_raw($raw) {
  if (!is_string($raw) || strlen($raw) !== 65 || $raw[0] !== "\x04") return false;
  $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw;
  return @openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n");
}
/* مفتاح EC → نقطة عامة خام 65 بايت */
function crm_push_raw_pub($key) {
  $d = openssl_pkey_get_details($key);
  if (!$d || !isset($d['ec']['x'], $d['ec']['y'])) return false;
  return "\x04" . str_pad($d['ec']['x'], 32, "\x00", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\x00", STR_PAD_LEFT);
}
/* مفاتيح VAPID: كتتقرا من الملف، ولا كتتولد أول مرة */
function crm_push_keys() {
  global $PUSH_KEYS_FILE;
  $k = crm_push_read($PUSH_KEYS_FILE);
  if (!empty($k['priv']) && !empty($k['pub'])) return $k;
  $key = @openssl_pkey_new(array('curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC));
  if (!$key) return null;
  $pem = '';
  if (!openssl_pkey_export($key, $pem)) return null;
  $raw = crm_push_raw_pub($key);
  if ($raw === false) return null;
  $k = array('priv' => $pem, 'pub' => crm_push_b64e($raw));
  return crm_push_write($PUSH_KEYS_FILE, $k) ? $k : null;
}
/* توقيع ECDSA (DER) → 64 بايت خام (r||s) كما يطلب JWT */
function crm_push_der2raw($der) {
  $p = 2;
  if (ord($der[1]) & 0x80) $p = 2 + (ord($der[1]) & 0x7f);
  $rl = ord($der[$p + 1]); $r = substr($der, $p + 2, $rl);
  $p2 = $p + 2 + $rl;
  $sl = ord($der[$p2 + 1]); $s = substr($der, $p2 + 2, $sl);
  return str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT) . str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);
}
/* RFC 8292 (VAPID): Authorization header */
function crm_push_vapid_auth($endpoint) {
  $k = crm_push_keys();
  if (!$k) return null;
  $pk = @openssl_pkey_get_private($k['priv']);
  if (!$pk) return null;
  $p = parse_url($endpoint);
  $aud = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
  $h = crm_push_b64e(json_encode(array('typ' => 'JWT', 'alg' => 'ES256')));
  $b = crm_push_b64e(json_encode(array('aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => 'mailto:admin@afrizon.ma')));
  $in = $h . '.' . $b;
  if (!openssl_sign($in, $der, $pk, OPENSSL_ALGO_SHA256)) return null;
  return 'vapid t=' . $in . '.' . crm_push_b64e(crm_push_der2raw($der)) . ', k=' . $k['pub'];
}
/* RFC 8291: تشفير aes128gcm. $asKey = مفتاح مؤقت جديد لكل رسالة */
function crm_push_encrypt($payload, $uaPub, $auth, $asKey) {
  $asPub = crm_push_raw_pub($asKey);
  $peer  = crm_push_pub_from_raw($uaPub);
  if ($asPub === false || $peer === false || strlen($auth) !== 16) return null;
  $ecdh = openssl_pkey_derive($peer, $asKey, 32);
  if ($ecdh === false) return null;
  $salt    = random_bytes(16);
  $ikm     = hash_hkdf('sha256', $ecdh, 32, "WebPush: info\x00" . $uaPub . $asPub, $auth);
  $cek     = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
  $nonce   = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);
  $ct = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
  if ($ct === false) return null;
  return $salt . pack('N', 4096) . chr(strlen($asPub)) . $asPub . $ct . $tag;
}
/* يبني الطلب (بلا ما يصيفطو): [url, headers, body] أو null */
function crm_push_build($sub, $payload, $asKey = null) {
  $auth = crm_push_vapid_auth($sub['endpoint']);
  if ($auth === null) return null;
  if ($asKey === null) $asKey = @openssl_pkey_new(array('curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC));
  if (!$asKey) return null;
  $body = crm_push_encrypt(json_encode($payload, JSON_UNESCAPED_UNICODE), crm_push_b64d($sub['p256dh']), crm_push_b64d($sub['auth']), $asKey);
  if ($body === null) return null;
  return array($sub['endpoint'], array(
    'Content-Type: application/octet-stream',
    'Content-Encoding: aes128gcm',
    'TTL: 3600',
    'Urgency: high',
    'Authorization: ' . $auth,
  ), $body);
}
/* يصيفط فعلياً. كيرجع كود HTTP (201 = واصل) ولا 0 إلا فشل */
function crm_push_post($req) {
  $ch = curl_init($req[0]);
  curl_setopt_array($ch, array(
    CURLOPT_POST => true, CURLOPT_POSTFIELDS => $req[2], CURLOPT_HTTPHEADER => $req[1],
    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
  ));
  curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return $code;
}
/* خدمات Push المعروفة فقط (باش السيرفر ما يصيفطش لأي عنوان) */
function crm_push_endpoint_ok($ep) {
  if (strpos($ep, 'https://') !== 0 || strlen($ep) > 2000) return false;
  $host = (string)parse_url($ep, PHP_URL_HOST);
  foreach (array('googleapis.com', 'mozilla.com', 'push.apple.com', 'windows.com') as $ok) {
    if ($host === $ok || substr($host, -strlen('.' . $ok)) === '.' . $ok) return true;
  }
  return false;
}
/* يصيفط لكل هواتف البنت. كيحيد الهواتف لي ما بقاوش مسجلين (404/410) */
function crm_push_to_agent($agent, $payload) {
  global $PUSH_SUBS_FILE;
  $subs = crm_push_read($PUSH_SUBS_FILE);
  $sent = 0; $removed = false; $keep = array();
  foreach ($subs as $s) {
    if (!is_array($s) || !isset($s['agent'], $s['endpoint']) || strcasecmp($s['agent'], $agent) !== 0) { $keep[] = $s; continue; }
    $req = crm_push_build($s, $payload);
    $code = $req ? crm_push_post($req) : 0;
    if ($code === 404 || $code === 410) { $removed = true; continue; }
    if ($code >= 200 && $code < 300) $sent++;
    else crm_audit('push-fail | ' . $agent . ' | code=' . $code);
    $keep[] = $s;
  }
  if ($removed) crm_push_write($PUSH_SUBS_FILE, $keep);
  return $sent;
}
/* بعد الصيفط من الشيت: إشعار وحدة لكل بنت (بلا ما نصيفطو إشعار لكل طلبية) */
function crm_push_batch($byAgent) {
  foreach ($byAgent as $agent => $list) {
    $n = count($list);
    if ($n === 0) continue;
    $lines = array();
    foreach (array_slice($list, 0, 3) as $o) {
      $lines[] = trim($o['nom'] . ' · ' . $o['produit'] . ' · ' . $o['ville'], ' ·');
    }
    if ($n > 3) $lines[] = '+ ' . ($n - 3) . ' أخرى';
    crm_push_to_agent($agent, array(
      'title' => $n === 1 ? '📥 طلبية جديدة ليك' : '📥 عندك ' . $n . ' طلبيات جداد',
      'body'  => implode("\n", $lines),
      'url'   => './',
      'tag'   => 'pv-orders',
    ));
  }
}
function crm_push_subscribe($b) {
  global $PUSH_SUBS_FILE;
  $agent = trim((string)($b['agent'] ?? ''));
  $sub   = $b['sub'] ?? null;
  if ($agent === '' || !is_array($sub) || !is_array($sub['keys'] ?? null)) crm_out(array('ok'=>false, 'err'=>'bad-sub'), 400);
  $ep   = (string)($sub['endpoint'] ?? '');
  $p256 = (string)($sub['keys']['p256dh'] ?? '');
  $auth = (string)($sub['keys']['auth'] ?? '');
  if (!crm_push_endpoint_ok($ep) || strlen(crm_push_b64d($p256)) !== 65 || strlen(crm_push_b64d($auth)) !== 16)
    crm_out(array('ok'=>false, 'err'=>'bad-sub'), 400);
  $subs = array();
  foreach (crm_push_read($PUSH_SUBS_FILE) as $s) {
    if (is_array($s) && isset($s['endpoint']) && $s['endpoint'] !== $ep) $subs[] = $s;   // نفس الهاتف = تسجيل جديد
  }
  $subs[] = array('agent' => $agent, 'endpoint' => $ep, 'p256dh' => $p256, 'auth' => $auth, 't' => time());
  if (!crm_push_write($PUSH_SUBS_FILE, $subs)) crm_out(array('ok'=>false, 'err'=>'write-failed'), 500);
  crm_out(array('ok'=>true, 'agent'=>$agent));
}
function crm_push_test($b) {
  $agent = trim((string)($b['agent'] ?? ''));
  if ($agent === '') crm_out(array('ok'=>false, 'err'=>'bad-agent'), 400);
  $n = crm_push_to_agent($agent, array(
    'title' => '🔔 تجربة الإشعارات',
    'body'  => 'إلا وصلك هاد الإشعار، كل شي خدام مزيان.',
    'url'   => './', 'tag' => 'pv-test',
  ));
  crm_out(array('ok'=>true, 'sent'=>$n));
}

/* ================================================================
 * v3.97 — الشيت → CRM (Storeep / Google Sheet → الطلبيات)
 *   POST api.php {action:'sheet_orders', rows:[{dateCreation, nom, telephone, ...}]}
 *   كيزيد غير الطلبيات الجداد. التكرار كيتحسب سيرفر-سايد بـ:
 *     التاريخ + الهاتف (آخر 9 أرقام) + المنتوج   (إلا ما كاينش هاتف → الاسم)
 *   فالتالي إلا تصيفطات نفس السطر مرتين، ما كيتزادش مرتين.
 *   كيرجع: {ok, added, dup, skip, t, results:[{i, st:'added'|'dup'|'skip', id?, why?}]}
 * ================================================================ */
/* v3.99: إشعار أوتوماتيك لكل طلبية جديدة باسم بنت (من الشيت ولا زيدتيها بيدك)
 *   أو ملي تبدل لها الطلبية للاسم ديالها. كيحسب غير التغييرات الطرية (دقيقتين)،
 *   وكيوقف إلا كانو بزاف (حماية من الاستيراد/الاسترجاع). */
function crm_push_collect_changes($old, $new, $nowms) {
  $oldBy = array();
  if (is_array($old)) { foreach ($old as $o) { if (is_array($o) && isset($o['id'])) $oldBy[(string)$o['id']] = $o; } }
  $by = array(); $n = 0;
  foreach ($new as $o) {
    if (!is_array($o) || !empty($o['_del']) || !isset($o['id'])) continue;
    $ag = trim((string)(isset($o['agent']) ? $o['agent'] : ''));
    if ($ag === '') continue;
    $prev = isset($oldBy[(string)$o['id']]) ? $oldBy[(string)$o['id']] : null;
    if ($prev !== null && trim((string)(isset($prev['agent']) ? $prev['agent'] : '')) === $ag) continue;
    $when = (float)(isset($o['_u']) ? $o['_u'] : 0);
    if (isset($o['_f']['agent'])) $when = max($when, (float)$o['_f']['agent']);
    if ($when < $nowms - 120000) continue;
    $by[$ag][] = array(
      'nom'    => (string)(isset($o['nom']) ? $o['nom'] : ''),
      'produit'=> (string)(isset($o['produit']) ? $o['produit'] : ''),
      'ville'  => (string)(isset($o['ville']) ? $o['ville'] : ''),
    );
    $n++;
  }
  return ($n > 30) ? array() : $by;
}
function crm_sheet_text($v) {
  if ($v === null || is_array($v) || is_object($v)) return '';
  return trim((string)$v);
}
function crm_sheet_num($v, $def) {
  if ($v === null || is_array($v) || is_object($v)) return $def;
  $s = str_replace(',', '.', trim((string)$v));
  if ($s === '' || !is_numeric($s)) return $def;
  $f = (float)$s;
  return ($f == floor($f)) ? (int)$f : $f;
}
/* صيغ مقبولة: YYYY-MM-DD (وبالساعة) ولا DD/MM/YYYY (ولا DD-MM-YYYY) */
function crm_sheet_date($v) {
  $s = crm_sheet_text($v);
  if ($s === '') return '';
  if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $s, $m)) { $y = (int)$m[1]; $mo = (int)$m[2]; $d = (int)$m[3]; }
  elseif (preg_match('/^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{4})/', $s, $m)) { $d = (int)$m[1]; $mo = (int)$m[2]; $y = (int)$m[3]; }
  else return null;   // ماشي تاريخ صحيح
  return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
}
/* الهاتف: غير الأرقام، +212 / 212 → 0، ورقم 9 أرقام بلا 0 → كنزيدو 0 */
function crm_sheet_phone($v) {
  $d = preg_replace('/\D+/', '', crm_sheet_text($v));
  if (strlen($d) === 12 && strpos($d, '212') === 0) $d = '0' . substr($d, 3);
  elseif (strlen($d) === 9 && $d[0] !== '0') $d = '0' . $d;
  return $d;
}
function crm_sheet_key($date, $phone, $nom, $produit) {
  $p = crm_sheet_phone($phone);
  $p9 = strlen($p) >= 9 ? substr($p, -9) : '';
  $who = $p9 !== '' ? 'p:' . $p9
                    : 'n:' . strtolower(preg_replace('/\s+/u', ' ', crm_sheet_text($nom)));
  return $date . '|' . $who . '|' . strtolower(preg_replace('/\s+/u', ' ', crm_sheet_text($produit)));
}
/* كينقّي سطر الشيت ويحوّلو لطلبية CRM. كيرجع [سبب الرفض|null, الطلبية|null] */
function crm_sheet_norm($r) {
  $raw = isset($r['dateCreation']) ? $r['dateCreation'] : '';
  $dc = crm_sheet_date($raw);
  if ($dc === null || $dc === '') return array(crm_sheet_text($raw) === '' ? 'no-date' : 'bad-date', null);
  $tel = crm_sheet_phone(isset($r['telephone']) ? $r['telephone'] : '');
  $nom = crm_sheet_text(isset($r['nom']) ? $r['nom'] : '');
  if ($tel === '' && $nom === '') return array('no-name-or-phone', null);

  $o = array();
  $o['dateCreation'] = $dc;
  $dco = crm_sheet_date(isset($r['dateConfirmation']) ? $r['dateConfirmation'] : '');
  $o['dateConfirmation'] = ($dco === null || $dco === '') ? $dc : $dco;
  foreach (array('dateExp', 'dateLiv') as $f) {
    $x = crm_sheet_date(isset($r[$f]) ? $r[$f] : '');
    $o[$f] = ($x === null) ? '' : $x;
  }
  $o['telephone'] = $tel;
  $o['nom'] = $nom;
  foreach (array('idCmd','ville','adresse','produit','statut','livraison','remarques','agent','link',
                 'carousell','carosellFlag','originLead','livreur','tracking','motif','fees') as $f) {
    $o[$f] = crm_sheet_text(isset($r[$f]) ? $r[$f] : '');
  }
  $o['qte']        = max(1, (int)crm_sheet_num(isset($r['qte']) ? $r['qte'] : '', 1));
  $o['prix']       = crm_sheet_num(isset($r['prix']) ? $r['prix'] : '', 0);
  $o['upsell']     = crm_sheet_num(isset($r['upsell']) ? $r['upsell'] : '', 0);
  $o['commission'] = crm_sheet_num(isset($r['commission']) ? $r['commission'] : '', 0);
  return array(null, $o);
}
/* Storeep intake: كل طلبية جديدة كتتعيّن دائماً لمريم فقط.
 * التعيين مفروض فالسيرفر وما كيعتمدش على agent جاي من الشيت. */
function crm_sheet_orders($b) {
  global $LOCK_FILE;
  $rows = (isset($b['rows']) && is_array($b['rows'])) ? array_values($b['rows']) : null;
  if ($rows === null) crm_out(array('ok'=>false, 'err'=>'bad-rows'), 400);
  if (count($rows) > 2000) crm_out(array('ok'=>false, 'err'=>'too-many-rows', 'max'=>2000), 413);

  $fh = @fopen($LOCK_FILE, 'c');
  if ($fh) @flock($fh, LOCK_EX);

  $data = crm_read_raw(true);
  $cur = isset($data['afrizon_orders_v5']['d']) ? crm_unwrap($data['afrizon_orders_v5']['d']) : array();
  if (!is_array($cur)) $cur = array();
  $cur = array_values($cur);
  $prevT = isset($data['afrizon_orders_v5']['t']) ? (int)$data['afrizon_orders_v5']['t'] : 0;

  /* مفاتيح الطلبيات الموجودة (الحية) + أكبر id (حتى الممسوحة) باش ما نعاودوش نستعملو id */
  $keys = array(); $maxId = 0;
  foreach ($cur as $o) {
    if (!is_array($o)) continue;
    if (isset($o['id']) && is_numeric($o['id']) && (int)$o['id'] > $maxId) $maxId = (int)$o['id'];
    $keys[crm_sheet_key(
      isset($o['dateCreation']) ? (string)$o['dateCreation'] : '',
      isset($o['telephone'])    ? $o['telephone'] : '',
      isset($o['nom'])          ? $o['nom'] : '',
      isset($o['produit'])      ? $o['produit'] : ''
    )] = true;
  }

  $nowms  = (int)(microtime(true) * 1000);
  $nextId = max((int)(microtime(true) * 1000000), $maxId + 1);
  $out = array(); $added = 0; $dup = 0; $skip = 0;
  $addedBy = array();   // v3.98: الطلبيات الجداد لكل بنت (للإشعارات)
  foreach ($rows as $i => $r) {
    if (!is_array($r)) { $out[] = array('i'=>$i, 'st'=>'skip', 'why'=>'bad-row'); $skip++; continue; }
    list($why, $o) = crm_sheet_norm($r);
    if ($why !== null) { $out[] = array('i'=>$i, 'st'=>'skip', 'why'=>$why); $skip++; continue; }
    $k = crm_sheet_key($o['dateCreation'], $o['telephone'], $o['nom'], $o['produit']);
    if (isset($keys[$k])) { $out[] = array('i'=>$i, 'st'=>'dup'); $dup++; continue; }

    $o['agent'] = 'Meryam';                       // Storeep → مريم فقط (server-enforced)
    $o['idCmd'] = '1';                              // v3.98: ID ديما default = 1
    $o['src']   = 'sheet';
    $o['originLead'] = 'Facebook';                 // كل طلبيات الشيت جاية من Facebook
    $o['id'] = $nextId++;
    $o['_u'] = $nowms;
    $f = array();
    foreach ($o as $fk => $fv) { if ($fk !== 'id' && $fk !== '_u') $f[$fk] = $nowms; }
    $o['_f'] = $f;

    $cur[] = $o;
    $keys[$k] = true;
    $out[] = array('i'=>$i, 'st'=>'added', 'id'=>$o['id'], 'agent'=>$o['agent']);
    $added++;
    $addedBy[$o['agent']][] = array('nom'=>$o['nom'], 'produit'=>$o['produit'], 'ville'=>$o['ville']);
  }

  if ($added === 0) {
    if ($fh) { @flock($fh, LOCK_UN); @fclose($fh); }
    if ($skip > 0) crm_audit("sheet-orders | added=0 | dup=$dup | skip=$skip");
    crm_out(array('ok'=>true, 'added'=>0, 'dup'=>$dup, 'skip'=>$skip, 'results'=>$out));
  }

  crm_backup();
  $t = max($prevT + 1, $nowms);
  $data['afrizon_orders_v5'] = array('t' => $t, 'd' => $cur);
  $ok = crm_write_all($data);
  if ($ok) crm_journal_append('afrizon_orders_v5', $t, $cur);
  if ($fh) { @flock($fh, LOCK_UN); @fclose($fh); }

  if (!$ok) crm_out(array('ok'=>false, 'err'=>'write-failed'), 500);
  crm_audit("sheet-orders | added=$added | dup=$dup | skip=$skip | t=$t");
  try { crm_push_batch($addedBy); } catch (Throwable $__e) { crm_audit('push-error | ' . $__e->getMessage()); }   // v3.98: ما كيوقفش الصيفط
  crm_out(array('ok'=>true, 'added'=>$added, 'dup'=>$dup, 'skip'=>$skip, 't'=>$t, 'results'=>$out));
}

/* ================================================================
 * v3.100 — طلبية سريعة من البنت (من صفحة quick.html)
 *   POST api.php {action:'quick_order', agent:'imane', order:{nom, telephone, ville, adresse, produit, qte, prix, dateCreation?}}
 *   الطلبية كتتسجل باسم البنت لي داخلة بحسابها (ما كتدخلش فالدور ديال الشيت).
 *   كتصيفط إشعار لهاد البنت أوتوماتيك. التكرار: نفس التاريخ + الهاتف + المنتوج → 409.
 *   كيرجع: {ok, id, agent} | {ok:false, err}
 * ================================================================ */
function crm_quick_order($b) {
  global $LOCK_FILE;
  $agents = array('Meryam', 'AYA', 'imane', 'safa');
  $ag = isset($b['agent']) ? trim((string)$b['agent']) : '';
  if (!in_array($ag, $agents, true)) crm_out(array('ok'=>false, 'err'=>'bad-agent'), 400);
  $r = (isset($b['order']) && is_array($b['order'])) ? $b['order'] : null;
  if ($r === null) crm_out(array('ok'=>false, 'err'=>'bad-order'), 400);
  foreach (array('nom', 'telephone', 'ville', 'adresse', 'produit', 'qte', 'prix') as $f) {
    if (!isset($r[$f]) || trim((string)$r[$f]) === '') crm_out(array('ok'=>false, 'err'=>'missing-' . $f), 400);
  }
  if (!isset($r['dateCreation']) || trim((string)$r['dateCreation']) === '') $r['dateCreation'] = date('Y-m-d');
  list($why, $o) = crm_sheet_norm($r);
  if ($why !== null) crm_out(array('ok'=>false, 'err'=>$why), 400);
  if ($o['telephone'] === '') crm_out(array('ok'=>false, 'err'=>'missing-telephone'), 400);

  $fh = @fopen($LOCK_FILE, 'c');
  if ($fh) @flock($fh, LOCK_EX);
  $data = crm_read_raw(true);
  $cur = isset($data['afrizon_orders_v5']['d']) ? crm_unwrap($data['afrizon_orders_v5']['d']) : array();
  if (!is_array($cur)) $cur = array();
  $cur = array_values($cur);
  $prevT = isset($data['afrizon_orders_v5']['t']) ? (int)$data['afrizon_orders_v5']['t'] : 0;

  $keyNew = crm_sheet_key($o['dateCreation'], $o['telephone'], $o['nom'], $o['produit']);
  $maxId = 0;
  foreach ($cur as $x) {
    if (!is_array($x)) continue;
    if (isset($x['id']) && is_numeric($x['id']) && (int)$x['id'] > $maxId) $maxId = (int)$x['id'];
    if (!empty($x['_del'])) continue;
    $k = crm_sheet_key(
      isset($x['dateCreation']) ? (string)$x['dateCreation'] : '',
      isset($x['telephone'])    ? $x['telephone'] : '',
      isset($x['nom'])          ? $x['nom'] : '',
      isset($x['produit'])      ? $x['produit'] : ''
    );
    if ($k === $keyNew) {
      if ($fh) { @flock($fh, LOCK_UN); @fclose($fh); }
      crm_out(array('ok'=>false, 'err'=>'dup'), 409);
    }
  }

  $nowms = (int)(microtime(true) * 1000);
  $o['agent'] = $ag;
  $o['idCmd'] = '1';
  $o['src']   = 'manual';
  $o['id']    = max((int)(microtime(true) * 1000000), $maxId + 1);
  $o['_u']    = $nowms;
  $f = array();
  foreach ($o as $fk => $fv) { if ($fk !== 'id' && $fk !== '_u') $f[$fk] = $nowms; }
  $o['_f'] = $f;
  $cur[] = $o;

  crm_backup();
  $t = max($prevT + 1, $nowms);
  $data['afrizon_orders_v5'] = array('t' => $t, 'd' => $cur);
  $ok = crm_write_all($data);
  if ($ok) crm_journal_append('afrizon_orders_v5', $t, $cur);
  if ($fh) { @flock($fh, LOCK_UN); @fclose($fh); }
  if (!$ok) crm_out(array('ok'=>false, 'err'=>'write-failed'), 500);
  crm_audit("quick-order | agent=$ag | id=" . $o['id'] . " | t=$t");
  try {
    crm_push_batch(array($ag => array(array('nom'=>$o['nom'], 'produit'=>$o['produit'], 'ville'=>$o['ville']))));
  } catch (Throwable $__e) { crm_audit('push-error | ' . $__e->getMessage()); }
  crm_out(array('ok'=>true, 'id'=>$o['id'], 'agent'=>$ag, 't'=>$t));
}

/* ================================================================
 * v3.101 — Digylog: إرسال الطلبيات المؤكدة (Confirmé) أوتوماتيك
 *   كل بنت ليها متجر خاص فـDigylog (store). الطلبية كتمشي لمتجر البنت لي عندها.
 *   الإعدادات فملف digylog_cfg.json (التوكن ما كيتعرضش). كيتشغل بـcron:
 *     GET api.php?action=digylog_sync&token=...   (كل دقيقة)
 *   الطلبية كتتصيفط مرة وحدة: كتتعلم dlSent. إلا فشل، كتعاود (حتى 5 مرات).
 *   غير الطلبيات لي تأكدات (statut = Confirmé) بعد التفعيل (_f.statut >= since).
 * ================================================================ */
function crm_dl_base() { return 'https://api.digylog.com/api/v2/seller'; }
function crm_dl_agents() { return array('Meryam', 'AYA', 'imane', 'safa'); }
function crm_dl_cfg_paths() {
  global $DATA_DIR, $DIR_LOC, $DIR_EXT;
  $paths = array();
  foreach (array($DATA_DIR, $DIR_LOC, $DIR_EXT) as $dir) {
    $dir = rtrim((string)$dir, '/');
    if ($dir === '') continue;
    $path = $dir . '/digylog_cfg.json';
    if (!in_array($path, $paths, true)) $paths[] = $path;
  }
  return $paths;
}
function crm_dl_cfg_merge_missing($base, $other) {
  if (!is_array($base)) $base = array();
  if (!is_array($other)) return $base;
  foreach ($other as $k => $v) {
    if ($k === 'stores' && is_array($v)) {
      if (!isset($base[$k]) || !is_array($base[$k])) $base[$k] = array();
      foreach ($v as $agent => $store) if ((!isset($base[$k][$agent]) || trim((string)$base[$k][$agent]) === '') && trim((string)$store) !== '') $base[$k][$agent] = $store;
      continue;
    }
    $missing = !array_key_exists($k, $base) || $base[$k] === null || (is_string($base[$k]) && trim($base[$k]) === '');
    if ($missing && $v !== null && (!is_string($v) || trim($v) !== '')) $base[$k] = $v;
  }
  return $base;
}
function crm_dl_cfg_get() {
  $merged = array(); $sourceCount = 0; $active = null; $index = 0;
  foreach (crm_dl_cfg_paths() as $path) {
    $raw = @file_get_contents($path);
    $cfg = json_decode((string)$raw, true);
    if ($index === 0 && is_array($cfg)) $active = $cfg;
    $index++;
    if (!is_array($cfg)) continue;
    $merged = crm_dl_cfg_merge_missing($merged, $cfg);
    $sourceCount++;
  }
  /* Heal only when the active copy is missing fields found in its mirror. */
  if ($sourceCount > 0 && (!is_array($active) || json_encode($active) !== json_encode($merged))) crm_dl_cfg_set($merged);
  return $merged;
}
function crm_dl_cfg_set($c) {
  if (!is_array($c)) return false;
  $json = json_encode($c, JSON_UNESCAPED_UNICODE);
  if ($json === false) return false;
  $ok = false;
  foreach (crm_dl_cfg_paths() as $path) {
    $dir = dirname($path);
    if (!is_dir($dir)) continue;
    if (@file_put_contents($path, $json, LOCK_EX) !== false) $ok = true;
  }
  return $ok;
}
function crm_dl_http($method, $path, $token, $body = null) {
  if (!function_exists('curl_init')) return array(0, '', 'no-curl');
  $ch = curl_init(crm_dl_base() . $path);
  $h = array('Accept: application/json', 'Content-Type: application/json', 'Referer: https://apiseller.digylog.com', 'Authorization: Bearer ' . $token);
  curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
  curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
  curl_setopt($ch, CURLOPT_TIMEOUT, 25);
  if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
  $resp = curl_exec($ch);
  $err  = (string)curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return array($code, $resp === false ? '' : (string)$resp, $err);
}
/* Generic read-only HTTP call to any Digylog URL (Bearer token). */
function crm_dl_http_url($method, $url, $token, $ref, $headers = null, $body = null) {
  if (!function_exists('curl_init')) return array(0, '', 'no-curl');
  $ch = curl_init($url);
  $h = ($headers !== null && is_array($headers) && count($headers))
      ? $headers
      : array('Accept: application/json', 'Content-Type: application/json', 'Referer: ' . $ref, 'Origin: ' . rtrim($ref, '/'), 'Authorization: Bearer ' . $token);
  curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
  curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 6);
  curl_setopt($ch, CURLOPT_TIMEOUT, 18);
  if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
  $resp = curl_exec($ch);
  $err = (string)curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return array($code, $resp === false ? '' : (string)$resp, $err);
}
/* Read-only Digylog order lookup used by the clickable Tracking cell.
 * The configured bearer token never leaves the server and no CRM/Digylog data
 * is modified. Only Tracking numbers already present in this CRM are accepted. */
function crm_dl_order_status($b) {
  $tracking = strtoupper(trim((string)(isset($b['tracking']) ? $b['tracking'] : '')));
  if ($tracking === '' || !preg_match('/^[A-Z0-9_-]{3,80}$/', $tracking)) crm_out(array('ok'=>false, 'err'=>'bad-tracking'), 400);

  $data = crm_read_raw(true);
  $orders = isset($data['afrizon_orders_v5']['d']) ? crm_unwrap($data['afrizon_orders_v5']['d']) : array();
  $found = false;
  if (is_array($orders)) foreach ($orders as $o) {
    if (!is_array($o) || !empty($o['_del'])) continue;
    if (strtoupper(trim((string)(isset($o['tracking']) ? $o['tracking'] : ''))) === $tracking) { $found = true; break; }
  }
  if (!$found) crm_out(array('ok'=>false, 'err'=>'tracking-not-in-crm'), 404);

  $cfg = crm_dl_cfg_get();
  $token = isset($cfg['token']) ? trim((string)$cfg['token']) : '';
  if ($token === '') crm_out(array('ok'=>false, 'err'=>'no-digylog-token'), 503);

  $apiTokens = crm_dl_api_tokens();
  if ($token !== '' && !in_array($token, $apiTokens, true)) array_unshift($apiTokens, $token);
  list($infoCode, $infoBody, $infoErr) = crm_dl_http_tokens('GET', '/order/' . rawurlencode($tracking) . '/infos', $apiTokens);
  if ($infoCode < 200 || $infoCode >= 300) {
    crm_audit('digylog-order-status | tracking=' . $tracking . ' | http=' . $infoCode);
    crm_out(array('ok'=>false, 'err'=>'digylog-http', 'code'=>$infoCode), $infoCode === 404 ? 404 : 502);
  }
  $info = json_decode($infoBody, true);
  if (!is_array($info)) crm_out(array('ok'=>false, 'err'=>'digylog-invalid-response'), 502);

  list($refsCode, $refsBody, $refsErr) = crm_dl_http_tokens('GET', '/order/' . rawurlencode($tracking) . '/refs', $apiTokens);
  $refsRaw = $refsCode >= 200 && $refsCode < 300 ? json_decode($refsBody, true) : array();
  if (!is_array($refsRaw)) $refsRaw = array();
  $refs = array();
  foreach ($refsRaw as $r) if (is_array($r)) $refs[] = array(
    'designation'=>(string)(isset($r['designation']) ? $r['designation'] : ''),
    'quantity'=>max(1, (int)(isset($r['quantity']) ? $r['quantity'] : 1))
  );

  crm_out(array('ok'=>true, 'order'=>array(
    'tracking'=>(string)(isset($info['traking']) ? $info['traking'] : (isset($info['tracking']) ? $info['tracking'] : $tracking)),
    'status'=>(string)(isset($info['status']) ? $info['status'] : ''),
    'statusId'=>isset($info['idStatus']) ? $info['idStatus'] : null,
    'name'=>(string)(isset($info['name']) ? $info['name'] : ''),
    'phone'=>(string)(isset($info['phone']) ? $info['phone'] : ''),
    'city'=>(string)(isset($info['city']) ? $info['city'] : ''),
    'address'=>(string)(isset($info['address']) ? $info['address'] : ''),
    'price'=>isset($info['price']) ? $info['price'] : null,
    'createdAt'=>(string)(isset($info['createdAt']) ? $info['createdAt'] : ''),
    'updatedAt'=>(string)(isset($info['updatedAt']) ? $info['updatedAt'] : ''),
    'cashStatus'=>(string)(isset($info['cash_status']) ? $info['cash_status'] : ''),
    'paidAt'=>(string)(isset($info['paidAt']) ? $info['paidAt'] : ''),
    'store'=>(string)(isset($info['store']) ? $info['store'] : ''),
    'bl'=>(string)(isset($info['bl']) ? $info['bl'] : ''),
    'deliveryCost'=>isset($info['deliveryCost']) ? $info['deliveryCost'] : null,
    'refs'=>$refs
  )));
}

function crm_dl_order_row($o) {
  return array(
    'num'         => (string)$o['id'],
    'name'        => trim((string)$o['nom']),
    'phone'       => crm_dl_phone(isset($o['telephone']) ? $o['telephone'] : ''),
    'address'     => trim((string)$o['adresse']),
    'city'        => trim((string)$o['ville']),
    'price'       => (float)$o['prix'],
    'openproduct' => 1,
    'cantry'      => 0,
    'note'        => '',
    'refs'        => array(array('ref' => '', 'designation' => trim((string)$o['produit']), 'quantity' => max(1, (int)$o['qte'])))
  );
}
/* Digylog official API v2.4 - POST /orders.
 * mode=1 (standard network) + status=0 (add only) => the parcels are created in
 * "Commandes non envoyées" and are NOT dispatched to a carrier or a call centre.
 * status=1 would add AND send, so it is never used by the automatic path. */
function crm_dl_orders_body($store,$network,$rows,$status) {
  $orders=array();
  foreach($rows as $row){
    $refs=array();
    foreach(isset($row['refs'])&&is_array($row['refs'])?$row['refs']:array() as $ref){
      $des='';
      if(isset($ref['designation']))$des=trim((string)$ref['designation']);
      elseif(isset($ref['ref']))$des=trim((string)$ref['ref']);
      $refs[]=array('designation'=>$des,'quantity'=>max(1,(int)(isset($ref['quantity'])?$ref['quantity']:1)));
    }
    $orders[]=array(
      'num'=>(string)$row['num'],'type'=>1,'mode'=>1,'network'=>(string)$network,'store'=>(string)$store,
      'name'=>(string)$row['name'],'phone'=>(string)$row['phone'],'address'=>(string)$row['address'],'city'=>(string)$row['city'],'price'=>(float)$row['price'],
      'refs'=>$refs,'openproduct'=>1,'port'=>1,'note'=>isset($row['note'])?(string)$row['note']:''
    );
  }
  return array('network'=>(int)$network,'store'=>(string)$store,'mode'=>1,'status'=>(int)$status,'checkDuplicate'=>1,'orders'=>$orders);
}
/* ---- small helpers for the automatic sender ---- */
/* Where the sender writes its lock, stamp and status files. The data dir is
 * used first so every API instance shares the same lock; the external dir and
 * the system temp dir are the fallbacks. */
function crm_dl_tmpdir() {
  global $DATA_DIR, $DIR_EXT;
  foreach (array($DATA_DIR, $DIR_EXT, rtrim(sys_get_temp_dir(),'/')) as $d) {
    $d = rtrim((string)$d,'/');
    if ($d==='' || !is_dir($d)) continue;
    if (is_writable($d)) return $d;
  }
  return rtrim(sys_get_temp_dir(),'/');
}
function crm_dl_state_path($name) { return crm_dl_tmpdir().'/'.$name; }
function crm_dl_state_write($name,$data) {
  $json=json_encode($data,JSON_UNESCAPED_UNICODE);
  if ($json===false) return false;
  return @file_put_contents(crm_dl_state_path($name),$json,LOCK_EX)!==false;
}
function crm_dl_state_read($name) {
  $p=crm_dl_state_path($name);
  if (!is_file($p)) return null;
  $s=@file_get_contents($p);
  if ($s===false||$s==='') return null;
  $j=json_decode($s,true);
  return is_array($j)?$j:null;
}
/* Digylog expects a 10 digit Moroccan number (05/06/07/08). */
function crm_dl_phone($v) {
  $d=preg_replace('/\D+/','',(string)$v);
  if ($d==='') return '';
  if (strlen($d)===13 && strpos($d,'00212')===0) $d='0'.substr($d,5);
  elseif (strlen($d)===12 && strpos($d,'212')===0) $d='0'.substr($d,3);
  if (strlen($d)===9 && $d[0]!=='0') $d='0'.$d;
  return $d;
}
function crm_dl_phone_ok($p) { return (bool)preg_match('/^0\d{9}$/',(string)$p); }
/* agent -> Digylog store (Boutique). Configured per agent, else default, else
 * the single store of the account when there is exactly one. */
function crm_dl_store_map($c) {
  $map=array();
  if (isset($c['stores'])&&is_array($c['stores'])) {
    foreach ($c['stores'] as $ag=>$st) {
      $ag=trim((string)$ag); $st=trim((string)$st);
      if ($ag===''||$st==='') continue;
      $map[crm_tracking_norm($ag)]=$st;
    }
  }
  return array('map'=>$map,'default'=>isset($c['defaultStore'])?trim((string)$c['defaultStore']):'');
}
/* Boutique سميات با اسم البنت (Meryam -> "Meryam" / "Boutique Meryam" / ...) */
/* Boutique "Slim-Bio-HJ" = initiale ديال "Hajar" (H…J).
 * كنطابقو: كل حرف فـالاختصار خاصو يلقا سميته بالترتيب فـاسم البنت.
 * ("hj" ⊆ "hajar" ✓ ، "mr" ⊆ "meryam" ✓ ، "sf" ⊆ "safa" ✓) */
function crm_dl_initials_match($agentKey,$token) {
  $t=crm_tracking_norm($token);
  if ($t==='' || strlen($t)<2 || strlen($t)>5) return false;
  if ($agentKey==='' || strlen($agentKey)<3) return false;
  $i=0;$n=strlen($agentKey);
  for ($j=0;$j<$n && $i<strlen($t);$j++) if ($agentKey[$j]===$t[$i]) $i++;
  return $i===strlen($t);
}
function crm_dl_store_by_name($agentKey,$discovered) {
  if ($agentKey==='' || !is_array($discovered) || count($discovered)===0) return '';
  $best='';$bestScore=0;$bestLen=9999;
  foreach ($discovered as $st) {
    $ns=crm_tracking_norm($st);
    if ($ns==='') continue;
    $score=0;
    if (strlen($ns)>=3 && (strpos($ns,$agentKey)!==false || strpos($agentKey,$ns)!==false)) $score=100+strlen($agentKey);
    else {
      $parts=preg_split('/[-_\s]+/', trim((string)$st));
      $last=(count($parts)>0)?$parts[count($parts)-1]:'';
      if ($last!=='' && crm_dl_initials_match($agentKey,$last)) $score=50+strlen(crm_tracking_norm($last));
    }
    if ($score===0) continue;
    if ($score>$bestScore || ($score===$bestScore && strlen($ns)<$bestLen)) { $best=(string)$st; $bestScore=$score; $bestLen=strlen($ns); }
  }
  return $best;
}
/* واش المتجر موجود فعلا فـالحساب؟ (إلا ما عرفناش القائمة، كنبقيو على的配置) */
function crm_dl_store_known($store,$discovered) {
  if (!is_array($discovered) || count($discovered)===0) return true;
  $n=crm_tracking_norm($store);
  if ($n==='') return false;
  foreach ($discovered as $d) if (crm_tracking_norm($d)===$n) return true;
  return false;
}
function crm_dl_store_for_agent($agent,$smap,$discovered) {
  $k=crm_tracking_norm($agent);
  if ($k!=='' && isset($smap['map'][$k])) {
    $st=$smap['map'][$k];
    if (crm_dl_store_known($st,$discovered)) return $st;
    /* المتجر المحدد ما كاينش فـالحساب => نجربو سمية Boutique ولا المتجر الوحيد. */
    $byName=crm_dl_store_by_name($k,$discovered);
    if ($byName!=='') return $byName;
    if (count($discovered)===1) return (string)$discovered[0];
    return $st;
  }
  $byName=crm_dl_store_by_name($k,$discovered);
  if ($byName!=='') return $byName;
  if ($smap['default']!=='') {
    if (crm_dl_store_known($smap['default'],$discovered)) return $smap['default'];
    if (count($discovered)===1) return (string)$discovered[0];
    return $smap['default'];
  }
  if (is_array($discovered) && count($discovered)===1) return (string)$discovered[0];
  return '';
}
/* Réseaux (transporteurs) ديال الحساب — cached 15 minutes. */
function crm_dl_networks($token) {
  $cached=crm_dl_state_read('.dl-networks-cache.json');
  if (is_array($cached)&&isset($cached['at'])&&isset($cached['list'])&&is_array($cached['list'])
      && ((int)(microtime(true))-(int)$cached['at'])<900) return $cached['list'];
  $list=array();
  if ($token!=='') {
    list($code,$body,$err)=crm_dl_http_tokens('GET','/networks',crm_dl_api_tokens());
    if ($code>=200&&$code<300) {
      $j=json_decode((string)$body,true);
      $arr=array();
      if (is_array($j)) {
        if (isset($j['networks'])&&is_array($j['networks'])) $arr=$j['networks'];
        elseif (isset($j['data'])&&is_array($j['data'])) $arr=$j['data'];
        elseif (isset($j[0])) $arr=$j;
        foreach ($arr as $n) {
          if (!is_array($n)) continue;
          $id=isset($n['id'])?(int)$n['id']:0;
          if ($id<=0) continue;
          $list[]=array('id'=>$id,'name'=>isset($n['name'])?trim((string)$n['name']):'');
        }
      }
    }
  }
  crm_dl_state_write('.dl-networks-cache.json',array('at'=>(int)(microtime(true)*1000),'list'=>$list));
  return $list;
}
/* Boutiques offered by the connected Digylog account (cached 15 minutes). */
function crm_dl_discover_stores($token) {
  $cached=crm_dl_state_read('.dl-stores-cache.json');
  if (is_array($cached)&&isset($cached['at'])&&isset($cached['names'])&&is_array($cached['names'])) {
    $age=((int)(microtime(true))-(int)$cached['at']);
    /* إلا ما لقينا حتى متجر، ما نخبيوش النتيجة بزاف (60 ثانية برك). */
    if ($age < (count($cached['names'])>0 ? 900 : 60)) return $cached['names'];
  }
  $names=array();
  if ($token!=='') {
    list($code,$body,$err)=crm_dl_http_tokens('GET','/stores',crm_dl_api_tokens());
    if ($code>=200&&$code<300) {
      $j=json_decode((string)$body,true);
      $list=array();
      if (is_array($j)) {
        if (isset($j['stores'])&&is_array($j['stores'])) $list=$j['stores'];
        elseif (isset($j['data'])&&is_array($j['data'])) $list=$j['data'];
        elseif (isset($j[0])) $list=$j;
        foreach ($list as $s) {
          if (!is_array($s)) continue;
          $n='';
          foreach (array('name','store','label','libelle') as $k) if (isset($s[$k])&&trim((string)$s[$k])!==''){$n=trim((string)$s[$k]);break;}
          if ($n!==''&&!in_array($n,$names,true)) $names[]=$n;
        }
      }
    }
  }
  crm_dl_state_write('.dl-stores-cache.json',array('at'=>(int)(microtime(true)*1000),'names'=>$names));
  return $names;
}
/* Digylog answer -> per order result.
 * A 2xx answer without any error marker means the batch was accepted.
 * When Digylog returns one created order per submitted order, the record is
 * matched to the CRM order by the exact external reference (num) that we sent;
 * the Tracking is only used when that exact record carries it. */
function crm_dl_parse_created($resp,$ids) {
  $out=array();
  foreach ($ids as $id) $out[(string)$id]=array('ok'=>false,'tracking'=>'','err'=>'');
  $j=json_decode((string)$resp,true);
  if (!is_array($j)) { foreach ($out as $k=>$v) $out[$k]['err']='digylog-invalid-response'; return $out; }
  $err='';
  if (isset($j['success'])&&$j['success']===false) $err='digylog-success-false';
  elseif (isset($j['isSuccess'])&&($j['isSuccess']===false||$j['isSuccess']===0)) $err='digylog-isSuccess-false';
  elseif (isset($j['errors'])&&!empty($j['errors'])) $err=is_string($j['errors'])?$j['errors']:json_encode($j['errors']);
  if ($err!=='') {
    $msg=''; if (isset($j['message']))$msg=(string)$j['message'];
    foreach ($out as $k=>$v) $out[$k]['err']=mb_substr(($msg!==''?$msg.': ':'').$err,0,180);
    return $out;
  }
  /* An explicit success marker without any order record is still a success. */
  $explicitOk = (isset($j['success'])&&$j['success']===true) || (isset($j['isSuccess'])&&($j['isSuccess']===true||$j['isSuccess']===1));
  /* list of created orders (documented shape: one record per submitted order) */
  $recs=array(); $list=null;
  if (isset($j['orders'])&&is_array($j['orders'])) $list=$j['orders'];
  elseif (isset($j['data'])&&is_array($j['data'])) $list=$j['data'];
  elseif (isset($j[0])) $list=$j;
  elseif (isset($j['num'])||isset($j['tracking'])||isset($j['traking'])) $list=array($j);
  if ($list!==null) {
    foreach ($list as $item) {
      if (!is_array($item)) continue;
      $bad=(isset($item['isSuccess'])&&($item['isSuccess']===false||$item['isSuccess']===0))||(isset($item['errors'])&&!empty($item['errors']));
      $msg='';
      if (isset($item['errors'])&&!empty($item['errors']))$msg=is_string($item['errors'])?$item['errors']:json_encode($item['errors']);
      elseif (isset($item['message']))$msg=(string)$item['message'];
      $recs[]=array(
        'num'=>crm_tracking_deep_pick($item,array('num','orderNum','order_num','externalId','external_id','externalReference','clientReference','client_reference','orderNumber','order_number')),
        'tracking'=>crm_tracking_deep_pick($item,array('tracking','traking','trackingNumber','tracking_number','barcode')),
        'bad'=>$bad,
        'msg'=>$msg
      );
    }
  }
  if (count($recs)===0) {
    if ($explicitOk) { foreach ($out as $k=>$v) { $out[$k]['ok']=true; } return $out; }
    /* Neither a created order nor a success marker: the answer is not a
       confirmation, so the orders stay pending and are retried later. */
    $msg = isset($j['message']) ? (string)$j['message'] : '';
    if ($msg==='') $msg = mb_substr((string)$resp, 0, 120);
    foreach ($out as $k=>$v) $out[$k]['err']=mb_substr(($msg!==''?$msg.': ':'').'digylog-no-order-confirmed',0,200);
    return $out;
  }
  /* 1) exact reference match: the record that carries our own num is the only
        one allowed to give a Tracking to that order. */
  $used=array(); $matched=0;
  foreach ($out as $id=>$v) {
    foreach ($recs as $ri=>$rec) {
      if (isset($used[$ri])) continue;
      if ($rec['num']===''||!crm_dl_same_ref($rec['num'],$id)) continue;
      $used[$ri]=true;
      if ($rec['bad']) { $out[$id]['err']=mb_substr(($rec['msg']!==''?$rec['msg']:'digylog-order-rejected'),0,180); continue; }
      $out[$id]['ok']=true;
      if ($rec['tracking']!=='') $out[$id]['tracking']=(string)$rec['tracking'];
      $matched++;
    }
  }
  /* 2) No reference echoed back. Digylog created exactly as many parcels as
        we sent, so the orders are accepted, but the Tracking is NOT taken:
        without our own num we cannot prove which record belongs to which
        order, and a wrong Tracking would corrupt the exact Tracking sync.
        The exact Tracking sync fills it in later. */
  if ($matched===0 && count($recs)===count($out)) {
    $ri=0;
    foreach ($out as $id=>$v) {
      $rec=$recs[$ri]; $ri++;
      if ($rec['bad']) { $out[$id]['err']=mb_substr(($rec['msg']!==''?$rec['msg']:'digylog-order-rejected'),0,180); continue; }
      $out[$id]['ok']=true;
    }
    return $out;
  }
  /* 3) Digylog answered 2xx but did not name every submitted order. The
        unmapped ones are NOT confirmed, so they stay pending and are retried.
        Digylog receives checkDuplicate=1 with our own num (the CRM id), so a
        retry cannot create a second parcel for the same order. */
  foreach ($out as $id=>$v) {
    if ($v['ok']) continue;
    if ($v['err']==='') $out[$id]['err']='digylog-no-record-for-order';
  }
  return $out;
}
function crm_dl_same_ref($a,$b) {
  $a=trim((string)$a); $b=trim((string)$b);
  if ($a===''||$b==='') return false;
  if ($a===$b) return true;
  $da=preg_replace('/\D+/','',$a); $db=preg_replace('/\D+/','',$b);
  return ($da!==''&&$da===$db);
}
/* Every agent that appears in the CRM, so the admin page can offer a store
 * for each of them (a new girl must not be silently skipped). */
function crm_dl_admin_agents() {
  $agents=array();
  foreach (crm_dl_agents() as $ag) $agents[]=$ag;
  $c=crm_dl_cfg_get();
  if (isset($c['stores'])&&is_array($c['stores'])) foreach ($c['stores'] as $ag=>$st) {
    $ag=trim((string)$ag); if ($ag!==''&&!in_array($ag,$agents,true)) $agents[]=$ag;
  }
  $data=crm_read_raw(true);
  $orders=isset($data['afrizon_orders_v5']['d'])?crm_unwrap($data['afrizon_orders_v5']['d']):array();
  if (is_array($orders)) foreach ($orders as $o) {
    if (!is_array($o)||!empty($o['_del'])) continue;
    $ag=trim((string)(isset($o['agent'])?$o['agent']:''));
    if ($ag!==''&&!in_array($ag,$agents,true)) $agents[]=$ag;
  }
  sort($agents);
  return $agents;
}
/* admin: POST {action:'digylog_admin', op:'get'|'save'|'test'|'dry'} + header X-Sync-Token */
function crm_dl_admin($b) {
  global $SECRET, $AUDIT_FILE;
  if (!hash_equals($SECRET, crm_token())) crm_out(array('ok'=>false, 'err'=>'token'), 403);
  $op = isset($b['op']) ? (string)$b['op'] : 'get';
  $c = crm_dl_cfg_get();
  $t = isset($c['token']) ? (string)$c['token'] : '';
  $sellerToken = isset($c['sellerToken']) ? crm_tracking_bearer($c['sellerToken']) : '';
  $agents = crm_dl_admin_agents();
  $stores = array();
  foreach ($agents as $ag) $stores[$ag] = (isset($c['stores'][$ag]) ? (string)$c['stores'][$ag] : '');

  if ($op === 'get') {
    /* the store of every agent, computed on the server (shown in the page) */
    $sc = crm_dl_state_read('.dl-stores-cache.json');
    $boutiques = (is_array($sc) && isset($sc['names']) && is_array($sc['names'])) ? $sc['names'] : array();
    $agentStores = crm_dl_agent_stores(crm_dl_store_map($c), $boutiques);
    crm_out(array('ok'=>true, 'agentStores'=>$agentStores, 'cfg'=>array(
      'apiVersion' => CRM_API_VERSION,
      'senderGen'  => CRM_DL_SENDER_GEN,
      'enabled'   => !empty($c['enabled']) ? 1 : 0,
      'autoSend'  => array_key_exists('autoSend',$c) ? (int)$c['autoSend'] : 1,
      'network'   => isset($c['network']) ? (int)$c['network'] : 1,
      'sentType'  => isset($c['sentType']) ? (int)$c['sentType'] : 0,
      'defaultStore' => isset($c['defaultStore']) ? (string)$c['defaultStore'] : '',
      'stores'    => $stores,
      'agents'    => $agents,
      'since'     => isset($c['since']) ? (int)$c['since'] : 0,
      'hasToken'  => $t !== '',
      'tokenTail' => $t !== '' ? substr($t, -4) : '',
      'hasSellerToken' => $sellerToken !== '',
      'sellerTokenTail' => $sellerToken !== '' ? substr($sellerToken, -4) : ''
    )));
  }
  if ($op === 'api_test') {
    /* read-only: GET /stores with every stored token, one click tells which one works */
    $results = array();
    $cfg = crm_dl_cfg_get();
    $tries = array();
    foreach (array('token', 'sellerToken') as $k) {
      if (!isset($cfg[$k])) continue;
      $t = ($k === 'sellerToken') ? crm_tracking_bearer($cfg[$k]) : trim((string)$cfg[$k]);
      if ($t === '') continue;
      $label = $k === 'sellerToken' ? 'Seller Authorization' : 'API token';
      if (isset($tries[$t])) continue;
      $tries[$t] = $label;
    }
    foreach ($tries as $t => $label) {
      list($code, $body, $err) = crm_dl_http('GET', '/stores', $t);
      $msg = trim((string)$body);
      if (strlen($msg) > 220) $msg = substr($msg, 0, 220);
      $dec = json_decode($msg, true);
      $detail = '';
      if (is_array($dec)) {
        if (isset($dec['error_description'])) $detail = (string)$dec['error_description'];
        elseif (isset($dec['error']['message'])) $detail = (string)$dec['error']['message'];
        elseif (isset($dec['message'])) $detail = (string)$dec['message'];
      }
      if ($detail === '') $detail = ($msg !== '') ? $msg : (string)$err;
      $results[] = array('field' => $label, 'tail' => substr($t, -4), 'code' => (int)$code,
        'ok' => ($code >= 200 && $code < 300), 'detail' => $detail);
    }
    crm_out(array('ok' => true, 'results' => $results));
  }
  if ($op === 'seller_test') {
    $incoming = isset($b['sellerToken']) && trim((string)$b['sellerToken']) !== ''
      ? crm_tracking_bearer($b['sellerToken']) : $sellerToken;
    if ($incoming === '') crm_out(array('ok' => false, 'err' => 'no-seller-token'), 400);
    $url = 'https://api.digylog.com/api/seller/v48/orders/grid?datatype=1&countPerPage=1&currentPage=1&sort=0';
    list($code, $body, $err) = crm_dl_http_url('GET', $url, $incoming, 'https://seller.digylog.com/');
    $msg = trim((string)$body);
    if (strlen($msg) > 300) $msg = substr($msg, 0, 300);
    $okAuth = ($code >= 200 && $code < 300);
    $msgDec = json_decode($msg, true);
    $detail = '';
    if (is_array($msgDec)) {
      if (isset($msgDec['error_description'])) $detail = (string)$msgDec['error_description'];
      elseif (isset($msgDec['error']['message'])) $detail = (string)$msgDec['error']['message'];
      elseif (isset($msgDec['message'])) $detail = (string)$msgDec['message'];
    }
    if ($detail === '') $detail = $msg;
    if ($detail === '' && $err !== '') $detail = $err;
    crm_out(array('ok' => true, 'authOk' => $okAuth, 'code' => $code, 'detail' => $detail, 'tail' => substr($incoming, -4)));
  }
  if ($op === 'tracking_token_save') {
    $incoming = isset($b['sellerToken']) ? crm_tracking_bearer($b['sellerToken']) : '';
    if ($incoming === '') crm_out(array('ok'=>false,'err'=>'no-seller-token'),400);
    $c['sellerToken'] = $incoming;
    crm_dl_cfg_set($c);
    crm_audit('digylog-seller-token-saved | tail=' . substr($incoming,-4));
    crm_out(array('ok'=>true,'hasSellerToken'=>true,'sellerTokenTail'=>substr($incoming,-4)));
  }
  if ($op === 'save') {
    if (isset($b['token']) && trim((string)$b['token']) !== '') $c['token'] = trim((string)$b['token']);
    if (isset($b['sellerToken']) && trim((string)$b['sellerToken']) !== '') {
      $st = crm_tracking_bearer($b['sellerToken']);
      if ($st !== '') { $c['sellerToken'] = $st; crm_audit('digylog-seller-token-saved | tail=' . substr($st, -4)); }
    }
    if (isset($b['network'])) $c['network'] = (int)$b['network'];
    if (isset($b['sentType'])) {
      $st = (int)$b['sentType'];
      if (!in_array($st, array(0, 1, 2), true)) crm_out(array('ok'=>false, 'err'=>'bad-sentType'), 400);
      $c['sentType'] = $st;
    }
    if (isset($b['stores']) && is_array($b['stores'])) {
      $s = array();
      foreach (crm_dl_admin_agents() as $ag) $s[$ag] = isset($b['stores'][$ag]) ? trim((string)$b['stores'][$ag]) : '';
      foreach ($b['stores'] as $ag => $st) { $ag=trim((string)$ag); if ($ag!=='') $s[$ag] = trim((string)$st); }
      $c['stores'] = $s;
    }
    if (isset($b['defaultStore'])) $c['defaultStore'] = trim((string)$b['defaultStore']);
    if (isset($b['autoSend'])) $c['autoSend'] = !empty($b['autoSend']) ? 1 : 0;
    $en = !empty($b['enabled']) ? 1 : 0;
    if ($en && empty($c['enabled'])) $c['since'] = (int)(microtime(true) * 1000);   // أول تفعيل: غير الطلبيات الجداد
    $c['enabled'] = $en;
    crm_dl_cfg_set($c);
    crm_audit('digylog-save | enabled=' . $en . ' | sentType=' . (isset($c['sentType']) ? $c['sentType'] : 0));
    crm_out(array('ok'=>true));
  }
  if ($op === 'test') {
    if ($t === '') crm_out(array('ok'=>false, 'err'=>'no-token'), 400);
    $out = array();
    foreach (array('/networks', '/stores') as $p) {
      list($code, $body, $err) = crm_dl_http('GET', $p, $t);
      $out[$p] = array('code'=>$code, 'body'=>substr($body, 0, 1500), 'err'=>$err);
    }
    /* PUT /webhook/test: Digylog يصيفط حدث تجريبي لـwebhook المسجل (num=55 ما كاينش فـCRM، كيتجاهل).
     * إلا 400 "webhook not configured" = ما كاينش webhook مسجل. */
    list($tc, $tb, $te) = crm_dl_http('PUT', '/webhook/test', $t, array('eventType' => 'order-status-changed'));
    $out['/webhook/test'] = array('code'=>$tc, 'body'=>substr($tb, 0, 1500), 'err'=>$te);
    /* تشخيص: السيرفر كيجرب الـwebhook نفسو (نفس الرابط اللي مسجل فـDigylog) وكيعرض الجواب */
    $selfBody = json_encode(array('eventType'=>'order-status-changed', 'payload'=>array('num'=>'55', 'idStatus'=>'6', 'status'=>'Livrée')));
    $ch = curl_init('https://afrizon.store/api.php?action=digylog_hook&token=' . urlencode($SECRET));
    curl_setopt_array($ch, array(CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$selfBody, CURLOPT_HTTPHEADER=>array('Content-Type: application/json'), CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>15));
    $sb = curl_exec($ch); $sc = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $se = (string)curl_error($ch); curl_close($ch);
    $out['/self-hook'] = array('code'=>$sc, 'body'=>substr((string)$sb, 0, 800), 'err'=>$se);
    /* تشخيص: آخر سطور الـwebhook من audit.log (شكل الطلب الحقيقي اللي وصل من Digylog) */
    $__al = (is_file($AUDIT_FILE)) ? array_slice(array_values(array_filter(explode("\n", (string)@file_get_contents($AUDIT_FILE)))), -500) : array();
    $__hk = array_values(array_filter($__al, function ($l) { return strpos($l, 'digylog-hook') !== false; }));
    $out['/hook-log'] = array('lines' => array_slice($__hk, -6));
    crm_out(array('ok'=>true, 'test'=>$out));
  }
  if ($op === 'status') {
    /* Read-only diagnosis: why was nothing sent? Never modifies the CRM. */
    $token = isset($c['token']) ? trim((string)$c['token']) : '';
    $smap = crm_dl_store_map($c);
    $boutiques = crm_dl_discover_stores($token);
    $data = crm_read_raw(true);
    $orders = isset($data['afrizon_orders_v5']['d']) ? crm_unwrap($data['afrizon_orders_v5']['d']) : array();
    if (!is_array($orders)) $orders = array();
    $eligible=0; $sent=0; $skippedStores=0; $badPhone=0; $missing=0; $byAgent=array();
    $agents=array();
    foreach ($orders as $o) {
      if (!is_array($o) || !empty($o['_del'])) continue;
      $ag = trim((string)(isset($o['agent']) ? $o['agent'] : ''));
      if ($ag!==''&&!in_array($ag,$agents,true)) $agents[]=$ag;
      $st = isset($o['statut']) ? (string)$o['statut'] : '';
      if (crm_tracking_norm($st) !== 'confirme') continue;
      if (trim((string)(isset($o['livraison']) ? $o['livraison'] : '')) !== '') continue;
      if (!empty($o['dlSent'])) { $sent++; continue; }
      $eligible++;
      $byAgent[$ag] = isset($byAgent[$ag]) ? $byAgent[$ag]+1 : 1;
      if (!crm_dl_phone_ok(crm_dl_phone(isset($o['telephone']) ? $o['telephone'] : ''))) { $badPhone++; continue; }
      $store = crm_dl_store_for_agent($ag, $smap, $boutiques);
      if ($store === '') $skippedStores++;
    }
    foreach ($smap['map'] as $ag=>$st) if ($ag!==''&&!in_array($ag,$agents,true)) $agents[]=$ag;
    foreach (crm_dl_agents() as $ag) if ($ag!==''&&!in_array($ag,$agents,true)) $agents[]=$ag;
    sort($agents);
    $last = crm_dl_auto_state();
    $diag = crm_dl_diag_build(array());
    crm_out(array('ok'=>true,'status'=>array(
      'apiVersion'      => CRM_API_VERSION,
      'senderGen'       => CRM_DL_SENDER_GEN,
      'pending'         => $diag['pending'],
      'pendingCount'    => $diag['pendingCount'],
      'networks'        => $diag['networks'],
      'agentStores'     => $diag['agentStores'],
      'hasToken'        => $token !== '',
      'autoSend'        => array_key_exists('autoSend',$c) ? (int)$c['autoSend'] : 1,
      'enabled'         => !empty($c['enabled']) ? 1 : 0,
      'network'         => isset($c['network']) ? (int)$c['network'] : 1,
      'sentType'        => isset($c['sentType']) ? (int)$c['sentType'] : 0,
      'stores'          => $smap['map'],
      'defaultStore'    => $smap['default'],
      'boutiques'       => $boutiques,
      'agents'          => $agents,
      'eligible'        => $eligible,
      'alreadySent'     => $sent,
      'byAgent'         => $byAgent,
      'noStore'         => $skippedStores,
      'badPhone'        => $badPhone,
      'lastRun'         => $last
    )));
  }
  if ($op === 'tracking_candidates') crm_out(crm_tracking_candidates());
  if ($op === 'tracking_sync') {
    $ids = isset($b['ids']) && is_array($b['ids']) ? $b['ids'] : array();
    $syncToken = isset($b['sellerToken']) && trim((string)$b['sellerToken']) !== '' ? (string)$b['sellerToken'] : $sellerToken;
    crm_out(crm_tracking_sync($ids, $syncToken));
  }
  if ($op === 'delivery_sync') {
    $tracking = strtoupper(trim((string)(isset($b['tracking']) ? $b['tracking'] : '')));
    if ($tracking !== '' && !preg_match('/^[A-Z0-9_-]{3,80}$/', $tracking)) crm_out(array('ok'=>false,'err'=>'bad-tracking'),400);
    crm_out(crm_delivery_sync_batch($tracking !== '' ? 1 : 20, $tracking));
  }
  if ($op === 'dry') crm_out(crm_dl_run(true));
  crm_out(array('ok'=>false, 'err'=>'bad-op'), 400);
}

/* ================= Tracking-only Digylog import =================
 * هاد المسار مسموح ليه يكتب tracking فقط. ما كيقراش status وما كيكتبش
 * livraison/dateLiv/statut نهائياً. الـwebhook ديال الحالات باقي موقوف. */
function crm_tracking_bearer($raw) {
  $v = trim((string)$raw, " \t\r\n\"'");
  $v = preg_replace('/^authorization\s*:\s*/i', '', $v);
  $v = preg_replace('/^bearer\s+/i', '', trim($v));
  return trim($v, " \t\r\n\"'");
}
function crm_tracking_phone($v) {
  $d = preg_replace('/\D+/', '', (string)$v);
  if (strlen($d) === 12 && strpos($d, '212') === 0) $d = '0' . substr($d, 3);
  return $d;
}
function crm_tracking_norm($v) {
  $v = trim((string)$v);
  $v = function_exists('mb_strtolower') ? mb_strtolower($v, 'UTF-8') : strtolower($v);
  $v = str_replace(array('é','è','ê','ë','à','â','ä','î','ï','ô','ö','ù','û','ü','ç'), array('e','e','e','e','a','a','a','i','i','o','o','u','u','u','c'), $v);
  return preg_replace('/[^a-z0-9]+/u', '', $v);
}
function crm_tracking_pick($a, $keys) {
  if (!is_array($a)) return '';
  foreach ($keys as $k) if (array_key_exists($k,$a) && !is_array($a[$k]) && !is_object($a[$k]) && trim((string)$a[$k]) !== '') return trim((string)$a[$k]);
  return '';
}
function crm_tracking_deep_pick($a,$keys,$depth=0) {
  if(!is_array($a)||$depth>6)return '';
  $v=crm_tracking_pick($a,$keys);if($v!=='')return $v;
  foreach($a as $child)if(is_array($child)){ $v=crm_tracking_deep_pick($child,$keys,$depth+1);if($v!=='')return $v; }
  return '';
}
function crm_tracking_date($v) {
  $v=trim((string)$v); if($v==='')return '';
  if(preg_match('/^(\d{4})[-\/]?(\d{2})[-\/]?(\d{2})/',$v,$m))return $m[1].'-'.$m[2].'-'.$m[3];
  if(preg_match('/^(\d{2})[\/\-](\d{2})[\/\-](\d{4})/',$v,$m))return $m[3].'-'.$m[2].'-'.$m[1];
  return '';
}
function crm_tracking_records($v, &$out, $depth=0) {
  if($depth>10 || !is_array($v))return;
  $tr=crm_tracking_pick($v,array('traking','tracking','trackingNumber','tracking_number','barcode'));
  if($tr!=='' && preg_match('/^[A-Za-z0-9_-]{5,40}$/',$tr)) {
    $key=strtoupper($tr); if(!isset($out[$key]))$out[$key]=$v;
  }
  foreach($v as $child)if(is_array($child))crm_tracking_records($child,$out,$depth+1);
}
/* التوكنات المرشحة: Seller Authorization أولاً، ثم التوكن ديال الـAPI.
 * (الـSeller Authorization كتموت بزاف فالحسابات، والتوكن الرئيسي كيدوز.) */
/* Every token that can open the Digylog API, best first. A token can be
   regenerated at any time in the Digylog «Configuration de l'API» page, so the
   server always tries them one after the other instead of trusting one. */
function crm_dl_api_tokens() {
  $cfg = crm_dl_cfg_get();
  $list = array();
  foreach (array('token', 'sellerToken') as $k) {
    if (!isset($cfg[$k])) continue;
    $t = ($k === 'sellerToken') ? crm_tracking_bearer($cfg[$k]) : trim((string)$cfg[$k]);
    if ($t !== '' && !in_array($t, $list, true)) $list[] = $t;
  }
  return $list;
}
/* Runs one Digylog call with every known token and keeps the first answer that
   the API accepts. On a POST only an authentication rejection (401/403) is
   retried with the next token: nothing was created, so it is safe. */
function crm_dl_http_tokens($method, $path, $tokens, $body = null, $retryOnlyAuth = false) {
  $last = array(0, '', 'no-token');
  foreach ((array)$tokens as $tk) {
    if (trim((string)$tk) === '') continue;
    list($code, $resp, $err) = crm_dl_http($method, $path, $tk, $body);
    $last = array($code, $resp, $err);
    if ($code >= 200 && $code < 300) return $last;
    if ($retryOnlyAuth && $code !== 401 && $code !== 403) break;
  }
  return $last;
}
function crm_dl_token_candidates($extra='') {
  $cfg=crm_dl_cfg_get();$list=array();
  $e=trim((string)$extra);
  if ($e!=='') { $e=crm_tracking_bearer($e); if ($e!=='' && !in_array($e,$list,true)) $list[]=$e; }
  foreach (array('sellerToken','token') as $k) {
    if (!isset($cfg[$k])) continue;
    $t = ($k==='token') ? trim((string)$cfg[$k]) : crm_tracking_bearer($cfg[$k]);
    if ($t!=='' && !in_array($t,$list,true)) $list[]=$t;
  }
  return $list;
}
function crm_tracking_internal_get($phone, $token) {
  if(!function_exists('curl_init'))return array(0,'','no-curl');
  $tokens=is_array($token)?$token:array($token);
  $last=array(0,'','no-token');
  foreach ($tokens as $tk) {
    if (trim((string)$tk)==='') continue;
    $url='https://api.digylog.com/api/seller/v48/historic/orders?phone='.rawurlencode($phone).'&datatype=1&countPerPage=100&currentPage=1&sort=0';
    $ch=curl_init($url);
    curl_setopt_array($ch,array(CURLOPT_HTTPHEADER=>array('Accept: application/json, text/plain, */*','Referer: https://seller.digylog.com/','Origin: https://seller.digylog.com','Authorization: Bearer '.$tk),CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>25));
    $body=curl_exec($ch);$err=(string)curl_error($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    $last=array($code,$body===false?'':(string)$body,$err);
    if ($code>=200&&$code<300) return $last;
  }
  return $last;
}
function crm_tracking_candidates() {
  $data=crm_read_raw(true);$orders=isset($data['afrizon_orders_v5']['d'])?crm_unwrap($data['afrizon_orders_v5']['d']):array();if(!is_array($orders))$orders=array();
  $ids=array();foreach($orders as $o){if(!is_array($o)||!empty($o['_del'])||trim((string)(isset($o['tracking'])?$o['tracking']:''))!==''||crm_tracking_phone(isset($o['telephone'])?$o['telephone']:'')==='')continue;
    $liv=crm_tracking_norm(isset($o['livraison'])?$o['livraison']:'');
    if(!empty($o['dlSent'])||in_array($liv,array('expediervers','livree','retour','retournee','rembourse','rechange'),true))$ids[]=(string)$o['id'];
  }
  return array('ok'=>true,'ids'=>$ids,'total'=>count($ids));
}
function crm_tracking_score($record,$order) {
  $ref=crm_tracking_deep_pick($record,array('num','externalReference','external_reference','clientReference','client_reference','reference','ref','orderNumber','order_number'));
  if($ref!=='' && $ref===(string)$order['id'])return array('score'=>100,'safe'=>true,'why'=>'crm-reference');
  $rd=crm_tracking_date(crm_tracking_deep_pick($record,array('createdAt','created_at','date','creationDate','dateCreation','createdDate')));
  $od=crm_tracking_date(isset($order['dateCreation'])?$order['dateCreation']:'');$dateOk=($rd!==''&&$od!==''&&$rd===$od);$score=$dateOk?10:0;$extra=0;
  $rp=crm_tracking_deep_pick($record,array('price','prix','amount','total','orderPrice'));
  if($rp!==''&&is_numeric($rp)&&abs((float)$rp-(float)(isset($order['prix'])?$order['prix']:0))<0.01){$score+=4;$extra++;}
  $rc=crm_tracking_norm(crm_tracking_deep_pick($record,array('city','ville','destination','cityName')));
  if($rc!==''&&$rc===crm_tracking_norm(isset($order['ville'])?$order['ville']:'')){$score+=3;$extra++;}
  $rn=crm_tracking_norm(crm_tracking_deep_pick($record,array('name','nom','fullname','customerName','client')));
  if($rn!==''&&$rn===crm_tracking_norm(isset($order['nom'])?$order['nom']:'')){$score+=2;$extra++;}
  return array('score'=>$score,'safe'=>($dateOk&&$extra>0),'why'=>$dateOk?'date+extra':'insufficient');
}
function crm_tracking_sync($requestedIds,$rawToken) {
  global $LOCK_FILE;
  $tokens=is_array($rawToken)?$rawToken:crm_dl_token_candidates($rawToken);
  if(!$tokens)return array('ok'=>false,'err'=>'no-seller-token');
  $token=$tokens[0];
  $want=array();foreach($requestedIds as $id){$id=(string)$id;if($id!=='')$want[$id]=true;if(count($want)>=20)break;}if(!$want)return array('ok'=>false,'err'=>'no-ids');
  $data=crm_read_raw(true);$orders=isset($data['afrizon_orders_v5']['d'])?crm_unwrap($data['afrizon_orders_v5']['d']):array();if(!is_array($orders))$orders=array();$todo=array();
  foreach($orders as $o){$id=is_array($o)&&isset($o['id'])?(string)$o['id']:'';if($id!==''&&isset($want[$id])&&empty($o['_del'])&&trim((string)(isset($o['tracking'])?$o['tracking']:''))==='')$todo[]=$o;}
  $found=array();$cache=array();$checked=0;$notFound=0;$ambiguous=0;$httpCode=0;
  foreach($todo as $o){$phone=crm_tracking_phone(isset($o['telephone'])?$o['telephone']:'');if($phone===''){continue;}$checked++;
    if(!isset($cache[$phone])){list($code,$body,$err)=crm_tracking_internal_get($phone,$tokens);$httpCode=$code;$records=array();if($code>=200&&$code<300){$j=json_decode($body,true);if(is_array($j))crm_tracking_records($j,$records);}$cache[$phone]=array('code'=>$code,'records'=>$records);}
    $pack=$cache[$phone];if($pack['code']<200||$pack['code']>=300)return array('ok'=>false,'err'=>'digylog-http-'.$pack['code'],'code'=>$pack['code'],'checked'=>$checked-1);
    $best='';$bestScore=-1;$ties=0;$bestSafe=false;
    foreach($pack['records'] as $tr=>$record){$m=crm_tracking_score($record,$o);if($m['score']>$bestScore){$best=$tr;$bestScore=$m['score'];$bestSafe=$m['safe'];$ties=1;}elseif($m['score']===$bestScore)$ties++;}
    if($best!==''&&$bestSafe&&$ties===1)$found[(string)$o['id']]=$best;elseif(count($pack['records'])===0)$notFound++;else$ambiguous++;
  }
  $linked=0;if($found){$fh=@fopen($LOCK_FILE,'c');if($fh)@flock($fh,LOCK_EX);$data=crm_read_raw(true);$cur=isset($data['afrizon_orders_v5']['d'])?crm_unwrap($data['afrizon_orders_v5']['d']):array();if(!is_array($cur))$cur=array();$prevT=isset($data['afrizon_orders_v5']['t'])?(int)$data['afrizon_orders_v5']['t']:0;$now=(int)(microtime(true)*1000);
    foreach($cur as $i=>$o){$id=is_array($o)&&isset($o['id'])?(string)$o['id']:'';if($id===''||!isset($found[$id])||trim((string)(isset($o['tracking'])?$o['tracking']:''))!=='')continue;if(!isset($o['_f'])||!is_array($o['_f']))$o['_f']=array();
      /* السطر الوحيد المسموح به: tracking. لا Suivie لا status لا تاريخ. */
      $o['tracking']=$found[$id];$o['_f']['tracking']=$now;$o['_u']=$now;$cur[$i]=$o;$linked++;}
    if($linked){crm_backup();$t=max($prevT+1,$now);$data['afrizon_orders_v5']=array('t'=>$t,'d'=>array_values($cur));$ok=crm_write_all($data);if($ok)crm_journal_append('afrizon_orders_v5',$t,array_values($cur));}
    if($fh){@flock($fh,LOCK_UN);@fclose($fh);}}
  crm_audit('tracking-only-sync | checked='.$checked.' | linked='.$linked.' | ambiguous='.$ambiguous.' | notFound='.$notFound);
  return array('ok'=>true,'checked'=>$checked,'linked'=>$linked,'ambiguous'=>$ambiguous,'notFound'=>$notFound,'httpCode'=>$httpCode,'trackingOnly'=>true);
}


/* Automatic Tracking import after a CRM order is sent to Digylog.
 * Phone is used only to ask Digylog for a small candidate set. Assignment is
 * NEVER made by phone/name/date: the Digylog record must carry the exact CRM
 * order id in its `num`/reference field. The only CRM field written is tracking. */
function crm_tracking_exact_from_payload($payload,$orderId) {
  $records=array();crm_tracking_records($payload,$records);$hits=array();
  foreach($records as $tracking=>$record){
    $ref=crm_tracking_deep_pick($record,array('num','orderNum','order_num','externalId','external_id','externalReference','external_reference','clientReference','client_reference','reference','ref','orderNumber','order_number'));
    if($ref!=='' && (string)$ref===(string)$orderId)$hits[strtoupper((string)$tracking)]=true;
  }
  $keys=array_keys($hits);return count($keys)===1?$keys[0]:'';
}
function crm_tracking_internal_many($phones,$token) {
  $phones=array_values(array_unique(array_filter($phones)));$out=array();
  if(!$phones)return $out;
  if(!function_exists('curl_multi_init')){
    foreach($phones as $phone){list($code,$body,$err)=crm_tracking_internal_get($phone,$token);$out[$phone]=array('code'=>$code,'body'=>$body,'err'=>$err);}return $out;
  }
  $mh=curl_multi_init();$chs=array();
  foreach($phones as $phone){
    $url='https://api.digylog.com/api/seller/v48/historic/orders?phone='.rawurlencode($phone).'&datatype=1&countPerPage=100&currentPage=1&sort=0';
    $ch=curl_init($url);curl_setopt_array($ch,array(CURLOPT_HTTPHEADER=>array('Accept: application/json, text/plain, */*','Referer: https://seller.digylog.com/','Origin: https://seller.digylog.com','Authorization: Bearer '.$token),CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>20));
    curl_multi_add_handle($mh,$ch);$chs[$phone]=$ch;
  }
  $running=null;do{$mrc=curl_multi_exec($mh,$running);if($running)curl_multi_select($mh,1.0);}while($running&&$mrc===CURLM_OK);
  foreach($chs as $phone=>$ch){$out[$phone]=array('code'=>(int)curl_getinfo($ch,CURLINFO_HTTP_CODE),'body'=>(string)curl_multi_getcontent($ch),'err'=>(string)curl_error($ch));curl_multi_remove_handle($mh,$ch);curl_close($ch);}curl_multi_close($mh);
  return $out;
}
function crm_tracking_auto_sync($limit=30) {
  global $LOCK_FILE;
  $cfg=crm_dl_cfg_get();$tokens=crm_dl_token_candidates();
  if(!$tokens)return array('ok'=>false,'err'=>'no-seller-token','checked'=>0,'linked'=>0);
  $data=crm_read_raw(true);$orders=isset($data['afrizon_orders_v5']['d'])?crm_unwrap($data['afrizon_orders_v5']['d']):array();if(!is_array($orders))$orders=array();
  $candidates=array();
  foreach($orders as $o){
    if(!is_array($o)||!empty($o['_del'])||trim((string)(isset($o['tracking'])?$o['tracking']:''))!=='')continue;
    $liv=crm_tracking_norm(isset($o['livraison'])?$o['livraison']:'');if(empty($o['dlSent'])&&$liv!=='expediervers')continue;
    $id=isset($o['id'])?(string)$o['id']:'';$phone=crm_tracking_phone(isset($o['telephone'])?$o['telephone']:'');if($id===''||$phone==='')continue;
    $candidates[]=array('id'=>$id,'phone'=>$phone);
  }
  $total=count($candidates);if(!$total)return array('ok'=>true,'checked'=>0,'pending'=>0,'linked'=>0,'exactOnly'=>true);
  $take=min(max(1,(int)$limit),$total);$cursor=isset($cfg['trackingCursor'])?max(0,(int)$cfg['trackingCursor']):0;if($cursor>=$total)$cursor=0;$batch=array();
  for($i=0;$i<$take;$i++)$batch[]=$candidates[($cursor+$i)%$total];
  $cfg['trackingCursor']=($cursor+$take)%max(1,$total);crm_dl_cfg_set($cfg);
  $phones=array_map(function($x){return $x['phone'];},$batch);
  $responses=crm_tracking_internal_many($phones,$tokens[0]);
  /* إلا التوكن الأول ما دخلش (Seller Authorization)، نجربو التوكن ديال الـAPI. */
  if (count($tokens)>1) {
    $allBad=true;foreach($responses as $r){if((int)$r['code']>=200&&(int)$r['code']<300){$allBad=false;break;}}
    if ($allBad) { for($ti=1;$ti<count($tokens);$ti++) { $alt=crm_tracking_internal_many($phones,$tokens[$ti]); $okAlt=false; foreach($alt as $r){if((int)$r['code']>=200&&(int)$r['code']<300){$okAlt=true;break;}} $responses=$alt; if($okAlt) break; } }
  }
  $found=array();$httpErrors=0;$ambiguous=0;
  foreach($batch as $item){$pack=isset($responses[$item['phone']])?$responses[$item['phone']]:array('code'=>0,'body'=>'');if($pack['code']<200||$pack['code']>=300){$httpErrors++;continue;}$payload=json_decode($pack['body'],true);if(!is_array($payload))continue;$tracking=crm_tracking_exact_from_payload($payload,$item['id']);if($tracking!=='')$found[$item['id']]=$tracking;else$ambiguous++;}
  $linked=0;if($found){
    $fh=@fopen($LOCK_FILE,'c');if($fh)@flock($fh,LOCK_EX);$data=crm_read_raw(true);$cur=isset($data['afrizon_orders_v5']['d'])?crm_unwrap($data['afrizon_orders_v5']['d']):array();if(!is_array($cur))$cur=array();$prevT=isset($data['afrizon_orders_v5']['t'])?(int)$data['afrizon_orders_v5']['t']:0;$now=(int)(microtime(true)*1000);
    foreach($cur as $i=>$o){$id=is_array($o)&&isset($o['id'])?(string)$o['id']:'';if($id===''||!isset($found[$id])||trim((string)(isset($o['tracking'])?$o['tracking']:''))!=='')continue;if(!isset($o['_f'])||!is_array($o['_f']))$o['_f']=array();$o['tracking']=$found[$id];$o['_f']['tracking']=$now;$o['_u']=$now;$cur[$i]=$o;$linked++;}
    if($linked){crm_backup();$t=max($prevT+1,$now);$data['afrizon_orders_v5']=array('t'=>$t,'d'=>array_values($cur));$ok=crm_write_all($data);if($ok)crm_journal_append('afrizon_orders_v5',$t,array_values($cur));}
    if($fh){@flock($fh,LOCK_UN);@fclose($fh);}
  }
  crm_audit('digylog-tracking-auto | checked='.count($batch).' | linked='.$linked.' | exactMiss='.$ambiguous.' | httpErrors='.$httpErrors);
  return array('ok'=>true,'checked'=>count($batch),'pending'=>$total,'linked'=>$linked,'exactMiss'=>$ambiguous,'httpErrors'=>$httpErrors,'exactOnly'=>true);
}
/* Browser-triggered sync: makes the feature automatic while the CRM is open,
 * even when hosting cron is missing. A server lock/throttle means all staff
 * devices together can start at most one Digylog lookup every 45 seconds. */
function crm_tracking_auto_guarded() {
  global $DATA_DIR;
  $lock=@fopen($DATA_DIR.'/.tracking-auto.lock','c');
  if(!$lock||!@flock($lock,LOCK_EX|LOCK_NB)){if($lock)@fclose($lock);return array('ok'=>true,'skipped'=>'busy','linked'=>0);}
  $stamp=$DATA_DIR.'/.tracking-auto.stamp';$last=is_file($stamp)?(int)@filemtime($stamp):0;
  if($last>0&&time()-$last<45){@flock($lock,LOCK_UN);@fclose($lock);return array('ok'=>true,'skipped'=>'throttled','retryAfter'=>45-(time()-$last),'linked'=>0);}
  @touch($stamp);
  $result=crm_tracking_auto_sync(30);
  /* The same guarded browser heartbeat also checks final Digylog delivery states.
     This keeps Livrée working and adds Retournée without a second client timer. */
  $result['deliverySync']=crm_delivery_auto_guarded();
  @flock($lock,LOCK_UN);@fclose($lock);return $result;
}

/* ================= Livrée + Retournée sync by exact Tracking =================
 * The stored Tracking is the only matching key. The only business field this
 * process may write is livraison: Digylog Livrée -> Livrée, Retournée -> Retour.
 * It never writes statut, dateLiv, tracking, client data, prices, or other fields. */
function crm_delivery_status($info) {
  if(!is_array($info))return '';
  /* Prefer real status fields. A generic top-level `label` can be the parcel
     label/barcode and must never hide the actual Digylog status. */
  $st=crm_tracking_deep_pick($info,array('statusName','status_name','orderStatus','order_status','statut','status','stateName','state_name','state'));
  if($st==='')$st=crm_tracking_deep_pick($info,array('label'));
  $n=crm_tracking_norm($st);
  /* Digylog may append the return reason to “Retournée”. Match that terminal
     prefix, while still refusing a generic intermediate “Retour ...” state. */
  if(strpos($n,'retourne')===0)return 'Retour';
  $id=crm_tracking_deep_pick($info,array('idStatus','statusId','status_id','id_status'));
  if((string)$id==='6'||$n==='livree'||strpos($n,'livree')===0)return 'Livrée';
  return '';
}
function crm_delivery_is_delivered($info) { return crm_delivery_status($info)==='Livrée'; }
function crm_delivery_is_returned($info) { return crm_delivery_status($info)==='Retour'; }
function crm_delivery_fetch_infos($trackings,$token) {
  $out=array();if(!function_exists('curl_multi_init'))return $out;
  $tokens=is_array($token)?array_values($token):array($token);
  $mh=curl_multi_init();$chs=array();
  foreach($trackings as $tr){$tr=strtoupper(trim((string)$tr));if($tr==='')continue;
    $ch=curl_init(crm_dl_base().'/order/'.rawurlencode($tr).'/infos');
    curl_setopt_array($ch,array(CURLOPT_HTTPHEADER=>array('Accept: application/json','Referer: https://apiseller.digylog.com','Authorization: Bearer '.$token),CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>20));
    curl_multi_add_handle($mh,$ch);$chs[$tr]=$ch;
  }
  $running=null;do{$mrc=curl_multi_exec($mh,$running);if($running)curl_multi_select($mh,1.0);}while($running&&$mrc===CURLM_OK);
  foreach($chs as $tr=>$ch){
    $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$body=(string)curl_multi_getcontent($ch);$j=json_decode($body,true);
    $state=($code>=200&&$code<300&&is_array($j))?crm_delivery_status($j):'';
    $out[$tr]=array('code'=>$code,'state'=>$state,'delivered'=>$state==='Livrée','returned'=>$state==='Retour');
    curl_multi_remove_handle($mh,$ch);curl_close($ch);
  }
  curl_multi_close($mh);
  /* the whole batch was rejected (the token was rotated): retry with the next one */
  $allBad=true;foreach($out as $r){if((int)$r['code']>=200&&(int)$r['code']<300){$allBad=false;break;}}
  if($allBad&&count($tokens)>1){
    $alt=crm_delivery_fetch_infos($trackings,array_slice($tokens,1));
    $altOk=false;foreach($alt as $r){if((int)$r['code']>=200&&(int)$r['code']<300){$altOk=true;break;}}
    if($altOk)return $alt;
  }
  return $out;
}
function crm_delivery_sync_batch($limit=20,$onlyTracking='') {
  global $LOCK_FILE;
  $cfg=crm_dl_cfg_get();$token=isset($cfg['token'])?trim((string)$cfg['token']):'';
  $apiTokens=crm_dl_api_tokens();if($token!==''&&!in_array($token,$apiTokens,true))array_unshift($apiTokens,$token);
  if(!$apiTokens)return array('ok'=>false,'err'=>'no-token','checked'=>0,'updated'=>0);
  $onlyTracking=strtoupper(trim((string)$onlyTracking));
  $data=crm_read_raw(true);$orders=isset($data['afrizon_orders_v5']['d'])?crm_unwrap($data['afrizon_orders_v5']['d']):array();if(!is_array($orders))$orders=array();
  /* Retour is terminal. Livrée remains eligible because a delivered parcel can
     later be returned. Active deliveries are checked before already-Livrée rows. */
  $map=array();foreach($orders as $o){
    if(!is_array($o)||!empty($o['_del']))continue;$tr=strtoupper(trim((string)(isset($o['tracking'])?$o['tracking']:'')));$liv=trim((string)(isset($o['livraison'])?$o['livraison']:''));
    if($tr===''||$liv==='Retour'||($onlyTracking!==''&&$tr!==$onlyTracking))continue;
    if(!isset($map[$tr]))$map[$tr]=array('count'=>0,'priority'=>$liv==='Livrée'?1:0);$map[$tr]['count']++;if($liv!=='Livrée')$map[$tr]['priority']=0;
  }
  /* A duplicated Tracking is ambiguous and is never allowed to modify rows.
     Active and already-Livrée rows have independent cursors: old delivered
     history can never postpone current Expédier-vers returns again. */
  $active=array();$final=array();$ambiguous=0;
  foreach($map as $tr=>$meta){if($meta['count']!==1){$ambiguous++;continue;}if($meta['priority']===0)$active[]=$tr;else $final[]=$tr;}
  sort($active);sort($final);$total=count($active)+count($final);
  if($total===0)return array('ok'=>true,'checked'=>0,'pending'=>0,'activePending'=>0,'finalPending'=>0,'delivered'=>0,'returned'=>0,'updated'=>0,'ambiguous'=>$ambiguous,'requestedTracking'=>$onlyTracking,'exactOnly'=>true);
  $limit=max(1,(int)$limit);$batch=array();
  if($onlyTracking!=='')$batch=array_merge($active,$final);
  else{
    $activeN=count($active);$finalN=count($final);$activeTake=$activeN?min($activeN,max(1,(int)ceil($limit*.8))):0;$finalTake=$finalN?min($finalN,max(0,$limit-$activeTake)):0;
    $left=$limit-$activeTake-$finalTake;if($left>0&&$activeTake<$activeN){$add=min($left,$activeN-$activeTake);$activeTake+=$add;$left-=$add;}if($left>0&&$finalTake<$finalN)$finalTake+=min($left,$finalN-$finalTake);
    $activeCursor=isset($cfg['deliveryActiveCursor'])?max(0,(int)$cfg['deliveryActiveCursor']):0;if($activeCursor>=max(1,$activeN))$activeCursor=0;
    $finalCursor=isset($cfg['deliveryFinalCursor'])?max(0,(int)$cfg['deliveryFinalCursor']):0;if($finalCursor>=max(1,$finalN))$finalCursor=0;
    for($i=0;$i<$activeTake;$i++)$batch[]=$active[($activeCursor+$i)%$activeN];for($i=0;$i<$finalTake;$i++)$batch[]=$final[($finalCursor+$i)%$finalN];
    $cfg['deliveryActiveCursor']=$activeN?(($activeCursor+$activeTake)%$activeN):0;$cfg['deliveryFinalCursor']=$finalN?(($finalCursor+$finalTake)%$finalN):0;crm_dl_cfg_set($cfg);
  }
  $infos=crm_delivery_fetch_infos($batch,$apiTokens);$targets=array();$delivered=0;$returned=0;$httpErrors=0;$unrecognized=0;
  foreach($infos as $tr=>$r){$state=isset($r['state'])?(string)$r['state']:'';if(isset($r['code'])&&((int)$r['code']<200||(int)$r['code']>=300))$httpErrors++;elseif($state==='Livrée'){$targets[$tr]=$state;$delivered++;}elseif($state==='Retour'){$targets[$tr]=$state;$returned++;}else $unrecognized++;}
  $updated=0;$writeOk=true;if($targets){
    $fh=@fopen($LOCK_FILE,'c');if($fh)@flock($fh,LOCK_EX);$data=crm_read_raw(true);$cur=isset($data['afrizon_orders_v5']['d'])?crm_unwrap($data['afrizon_orders_v5']['d']):array();if(!is_array($cur))$cur=array();$prevT=isset($data['afrizon_orders_v5']['t'])?(int)$data['afrizon_orders_v5']['t']:0;$now=(int)(microtime(true)*1000);
    foreach($cur as $i=>$o){
      if(!is_array($o)||!empty($o['_del']))continue;$tr=strtoupper(trim((string)(isset($o['tracking'])?$o['tracking']:'')));if(!isset($targets[$tr]))continue;
      $next=$targets[$tr];$liv=trim((string)(isset($o['livraison'])?$o['livraison']:''));
      /* Never regress a confirmed return back to Livrée. */
      if($liv===$next||($liv==='Retour'&&$next==='Livrée'))continue;
      if(!isset($o['_f'])||!is_array($o['_f']))$o['_f']=array();
      /* The only business-field write performed by this sync. */
      $o['livraison']=$next;$o['_f']['livraison']=$now;$o['_u']=$now;$cur[$i]=$o;$updated++;
    }
    if($updated){crm_backup();$t=max($prevT+1,$now);$data['afrizon_orders_v5']=array('t'=>$t,'d'=>array_values($cur));$writeOk=crm_write_all($data);if($writeOk)crm_journal_append('afrizon_orders_v5',$t,array_values($cur));else $updated=0;}
    if($fh){@flock($fh,LOCK_UN);@fclose($fh);}
  }
  crm_audit('digylog-delivery-tracking-only | checked='.count($batch).' | delivered='.$delivered.' | returned='.$returned.' | updated='.$updated.' | ambiguous='.$ambiguous.' | httpErrors='.$httpErrors.' | unrecognized='.$unrecognized.' | writeOk='.($writeOk?1:0));
  return array('ok'=>(bool)$writeOk,'checked'=>count($batch),'pending'=>$total,'activePending'=>count($active),'finalPending'=>count($final),'delivered'=>$delivered,'returned'=>$returned,'updated'=>$updated,'ambiguous'=>$ambiguous,'httpErrors'=>$httpErrors,'unrecognized'=>$unrecognized,'requestedTracking'=>$onlyTracking,'exactOnly'=>true);
}
/* Dedicated automatic status heartbeat, independent from Tracking import and
 * shared by every open CRM device. At most one batch runs every 45 seconds. */
function crm_delivery_auto_guarded() {
  global $DATA_DIR;
  $lock=@fopen($DATA_DIR.'/.delivery-auto.lock','c');
  if(!$lock||!@flock($lock,LOCK_EX|LOCK_NB)){if($lock)@fclose($lock);return array('ok'=>true,'skipped'=>'busy','updated'=>0);}
  $stamp=$DATA_DIR.'/.delivery-auto.stamp';$last=is_file($stamp)?(int)@filemtime($stamp):0;
  if($last>0&&time()-$last<45){@flock($lock,LOCK_UN);@fclose($lock);return array('ok'=>true,'skipped'=>'throttled','retryAfter'=>45-(time()-$last),'updated'=>0);}
  @touch($stamp);$result=crm_delivery_sync_batch(60);@flock($lock,LOCK_UN);@fclose($lock);return $result;
}

/* Automatic Confirmé + empty Suivie sender.
 * A shared 7 second throttle and the existing .dl.lock guarantee that several
 * CRM devices (or several browser tabs) can never create the same order twice. */
function crm_dl_send_guarded() {
  $lock=@fopen(crm_dl_state_path('.dl-send-auto.lock'),'c');
  if(!$lock||!@flock($lock,LOCK_EX|LOCK_NB)){if($lock)@fclose($lock);return array('ok'=>true,'skipped'=>'busy','sent'=>0);}
  $stamp=crm_dl_state_path('.dl-send-auto.stamp');$last=is_file($stamp)?(int)@filemtime($stamp):0;
  if($last>0&&time()-$last<7){@flock($lock,LOCK_UN);@fclose($lock);return array('ok'=>true,'skipped'=>'throttled','retryAfter'=>7-(time()-$last),'sent'=>0);}
  @touch($stamp);
  $result=crm_dl_run(false,0,true,false,true);
  @flock($lock,LOCK_UN);@fclose($lock);return $result;
}
/* Last automatic run, so the admin page can show why nothing was sent. */
function crm_dl_auto_state() {
  $st=crm_dl_state_read('.dl-auto-state.json');
  return is_array($st)?$st:array('ok'=>false,'sent'=>0,'failed'=>0,'when'=>0);
}
function crm_dl_auto_state_save($data) {
  $data['when']=(int)(microtime(true)*1000);
  crm_dl_state_write('.dl-auto-state.json',$data);
}
/* ================================================================
 * Diagnostic file (public, sans données clients ni tokens).
 * permet de voir POURQUOI rien n'a été envoyé, sans devtools.
 * ================================================================ */
function crm_dl_diag_file() { return __DIR__ . '/dl-diag.json'; }
function crm_dl_diag_save($data) {
  if (!is_array($data)) $data = array();
  $data['apiVersion'] = CRM_API_VERSION;
  $data['senderGen']  = CRM_DL_SENDER_GEN;
  $data['updatedAt']  = gmdate('c');
  $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  if ($json === false) return false;
  /* ما كنبانشش أرقام الهواتف (بلا سبب) فـالملف العام. */
  $json = preg_replace('/(?<![0-9])0[5-8][0-9]{8}(?![0-9])/', '0*********', (string)$json);
  $path = crm_dl_diag_file();
  $last = is_file($path) ? (int)@filemtime($path) : 0;
  $lastRun = isset($data['lastRun']) && is_array($data['lastRun']) ? $data['lastRun'] : array();
  $important = (!empty($data['sent']) || !empty($data['failed']) || !empty($data['skipped'])
                || !empty($lastRun['sent']) || !empty($lastRun['failed']) || !empty($lastRun['skipped'])
                || !empty($lastRun['errors']) || !empty($lastRun['err']));
  if (!$important && $last > 0 && (time() - $last) < 30) return true;
  return @file_put_contents($path, $json, LOCK_EX) !== false;
}
function crm_dl_diag_build($extra) {
  $c = crm_dl_cfg_get();
  $smap = crm_dl_store_map($c);
  $token = isset($c['token']) ? trim((string)$c['token']) : '';
  $seller = isset($c['sellerToken']) ? crm_tracking_bearer($c['sellerToken']) : '';
  $sc = crm_dl_state_read('.dl-stores-cache.json');
  $boutiques = (is_array($sc) && isset($sc['names']) && is_array($sc['names'])) ? $sc['names'] : array();
  $nc = crm_dl_state_read('.dl-networks-cache.json');
  $networks = (is_array($nc) && isset($nc['list']) && is_array($nc['list'])) ? $nc['list'] : array();
  /* الطلبيات لي مستعدة (بلا أسماء ولا هواتف) */
  $pending = array();
  $data = crm_read_raw();
  $orders = isset($data['afrizon_orders_v5']['d']) ? crm_unwrap($data['afrizon_orders_v5']['d']) : array();
  if (is_array($orders)) foreach ($orders as $o) {
    if (!is_array($o) || !empty($o['_del'])) continue;
    $st = isset($o['statut']) ? (string)$o['statut'] : '';
    if (crm_tracking_norm($st) !== 'confirme') continue;
    if (trim((string)(isset($o['livraison']) ? $o['livraison'] : '')) !== '') continue;
    if (!empty($o['dlSent'])) continue;
    $gen = isset($o['dlGen']) ? (int)$o['dlGen'] : 0;
    $pending[] = array(
      'id'     => (string)(isset($o['id']) ? $o['id'] : ''),
      'agent'  => (string)(isset($o['agent']) ? $o['agent'] : ''),
      'tries'  => isset($o['dlTries']) ? (int)$o['dlTries'] : 0,
      'err'    => mb_substr((string)(isset($o['dlErr']) ? $o['dlErr'] : ''), 0, 200),
      'gen'    => $gen,
      'stale'  => ($gen !== CRM_DL_SENDER_GEN) ? 1 : 0
    );
    if (count($pending) >= 40) break;
  }
  $out = array(
    'apiVersion'   => CRM_API_VERSION,
    'senderGen'    => CRM_DL_SENDER_GEN,
    'hasToken'     => $token !== '',
    'hasSellerToken'=> $seller !== '',
    'autoSend'     => array_key_exists('autoSend',$c) ? (int)$c['autoSend'] : 1,
    'enabled'      => !empty($c['enabled']) ? 1 : 0,
    'network'      => isset($c['network']) ? (int)$c['network'] : 1,
    'sentType'     => isset($c['sentType']) ? (int)$c['sentType'] : 0,
    'stores'       => $smap['map'],
    'defaultStore' => $smap['default'],
    'boutiques'    => $boutiques,
    'networks'     => $networks,
    'agentStores'  => crm_dl_agent_stores($smap,$boutiques),
    'pending'      => $pending,
    'pendingCount' => count($pending),
    'lastRun'      => crm_dl_auto_state()
  );
  if (is_array($extra)) foreach ($extra as $k=>$v) $out[$k] = $v;
  return $out;
}
/* المتجر (Boutique) ديال كل بنت — كيتحسب بحال ما كيتحسب وقت الإرسال. */
function crm_dl_agent_stores($smap,$boutiques) {
  $out=array();
  foreach (crm_dl_admin_agents() as $ag) {
    $s=crm_dl_store_for_agent($ag,$smap,$boutiques);
    if ($s!=='') $out[$ag]=$s;
  }
  return $out;
}

/* Send one chunk of CRM orders to Digylog and return the per order result.
 * $route: 'v2'  -> POST /orders          (Digylog API v2.4, status=0 = add only)
 *         'std' -> POST /orders/standard (older Digylog accounts, sentType)
 * A chunk that is rejected as a whole is retried order by order, so a single
 * bad line can never block the other orders of the same batch. */
function crm_dl_send_chunk($token,$store,$network,$rows,$status,$sentTyp,$route) {
  $out = array('sent'=>array(),'failed'=>array(),'tracking'=>array(),'route'=>$route,'routeUnusable'=>false);
  if (!$rows) return $out;
  $rawRows = array_map(function ($r) { return $r['row']; }, $rows);
  $ids = array(); foreach ($rows as $r) $ids[] = (string)$r['id'];
  if ($route==='std') {
    $body = array('network'=>(int)$network,'store'=>(string)$store,'sentType'=>(int)$sentTyp,'checkDuplicate'=>1,'orders'=>$rawRows);
    $path = '/orders/standard';
  } else {
    $body = crm_dl_orders_body($store, $network, $rawRows, $status);
    $path = '/orders';
  }
  /* نجربو كل التوكنات واحد واحد: توكن يمكن يتجدد فأي وقت فديجيلوج */
  $try=array($token);foreach(crm_dl_api_tokens() as $t)if(!in_array($t,$try,true))$try[]=$t;
  list($code, $resp, $err) = crm_dl_http('POST', $path, $token, $body);
  if ($code===401 || $code===403) {
    foreach($try as $t){
      if ($t==='' || $t===$token) continue;
      list($c2,$r2,$e2)=crm_dl_http('POST',$path,$t,$body);
      $code=$c2; $resp=$r2; $err=$e2;
      if ($c2!==401 && $c2!==403) break;
    }
  }
  if ($route==='v2' && ($code===404 || $code===405 || $code===501)) {
    /* This account does not expose the v2 route. Nothing was created by the
       rejected call, so it is safe to try the legacy one. */
    $out['route']='std';
    $out['routeUnusable']=true;
    return crm_dl_send_chunk($token, $store, $network, $rows, $status, $sentTyp, 'std');
  }
  $parsed = crm_dl_parse_created($resp, $ids);
  $accepted = 0; foreach ($parsed as $p) if (!empty($p['ok'])) $accepted++;
  /* A server error or a dead connection is a transport problem: the whole
     chunk stays pending and is retried later as a chunk. A rejected payload
     is isolated order by order so one bad line cannot block the others. */
  $transportFail = ($code < 200 || $code >= 500);
  $shapeRejected = ($code===400 || $code===415 || $code===422);
  $noOrderAccepted = ($code >= 200 && $code < 300 && $accepted===0 && count($ids)>0);
  if (($shapeRejected || $noOrderAccepted) && count($rows)>1) {
    /* isolate: retry every order of this chunk on its own */
    $single = array('sent'=>array(),'failed'=>array(),'tracking'=>array(),'route'=>$out['route'],'routeUnusable'=>false);
    foreach ($rows as $r) {
      $one = crm_dl_send_chunk($token, $store, $network, array($r), $status, $sentTyp, $single['route']);
      foreach ($one['sent'] as $id=>$v) $single['sent'][$id]=$v;
      foreach ($one['failed'] as $id=>$v) $single['failed'][$id]=$v;
      foreach ($one['tracking'] as $id=>$v) $single['tracking'][$id]=$v;
      if ($one['route']!==$single['route']) $single['route']=$one['route'];
      if ($one['routeUnusable']) $single['routeUnusable']=true;
    }
    return $single;
  }
  if ($transportFail) {
    /* transport / HTTP failure: the whole chunk is still pending */
    $why = $err !== '' ? $err : ('http-' . $code . ' ' . mb_substr((string)$resp, 0, 150));
    foreach ($ids as $id) $out['failed'][$id] = $why;
    return $out;
  }
  if ($shapeRejected) {
    /* Digylog رفض الـpayload (400/415/422). ما كايناش داعي نعاودو بالـroute
       القديم: غادي يبقى نفس الخطأ. كنخزنو الجواب كامل باش يبقى Diagnosis واضح. */
    $why = 'http-' . $code . ' ' . mb_substr((string)$resp, 0, 300);
    foreach ($ids as $id) $out['failed'][$id] = $why;
    return $out;
  }
  foreach ($parsed as $id => $p) {
    if (!empty($p['ok'])) {
      $out['sent'][$id] = mb_substr((string)$resp, 0, 200);
      if ($p['tracking'] !== '') $out['tracking'][$id] = $p['tracking'];
    } else {
      $out['failed'][$id] = $p['err'] !== '' ? $p['err'] : 'digylog-create-failed';
    }
  }
  return $out;
}

/* Automatic / manual Digylog sender.
 * $forceSentType : 0 => Commandes non envoyées (add only, never dispatched).
 * $includeExisting : also send the Confirmé orders that were confirmed before.
 * $auto : the automatic path. It never depends on the admin "enabled" switch,
 *         it only needs a token, so it works right after deployment.
 * $withStatus : false on the fast path (no expensive status polling). */
function crm_dl_run($dry = false,$forceSentType=null,$includeExisting=false,$withStatus=true,$auto=false) {
  global $LOCK_FILE, $DATA_DIR;
  $c = crm_dl_cfg_get();
  $since   = isset($c['since']) ? (int)$c['since'] : 0;
  $token   = isset($c['token']) ? trim((string)$c['token']) : '';
  $network = isset($c['network']) ? (int)$c['network'] : 1;
  $sentTyp = $forceSentType!==null?(int)$forceSentType:(isset($c['sentType'])?(int)$c['sentType']:0);
  $autoOn  = array_key_exists('autoSend',$c) ? (int)$c['autoSend'] : 1;
  if (!$dry && $auto && $autoOn===0) { crm_dl_diag_save(crm_dl_diag_build(array('lastRun'=>array('ok'=>true,'skipped'=>'auto-send-off','sent'=>0,'when'=>(int)(microtime(true)*1000))))); return array('ok'=>true,'skipped'=>'auto-send-off','sent'=>0); }
  if (!$dry && !$auto && empty($c['enabled'])) return $withStatus?array('ok'=>true,'skipped'=>'sending-disabled','trackingSync'=>crm_tracking_auto_sync(30),'deliveredSync'=>crm_delivery_sync_batch(20)):array('ok'=>true,'skipped'=>'sending-disabled','sent'=>0);
  if (!$dry && $token === '') { crm_dl_diag_save(crm_dl_diag_build(array('lastRun'=>array('ok'=>false,'err'=>'no-token','sent'=>0,'when'=>(int)(microtime(true)*1000))))); return array('ok'=>false, 'err'=>'no-token'); }
  if ($since <= 0 && !$dry && !$includeExisting) return array('ok'=>false, 'err'=>'not-enabled-properly');
  /* شبكة (transporteur) غير موجودة فـالحساب => ناخدو أول شبكة متوفرة،
     حيت Digylog كيرفض الطلبية كاملة إلا كانت الشبكة غالطة. */
  if (!$dry && $token !== '') {
    $nets = crm_dl_networks($token);
    if (is_array($nets) && count($nets)>0) {
      $ids = array(); foreach ($nets as $n) $ids[] = (string)$n['id'];
      if (!in_array((string)$network, $ids, true)) $network = (int)$nets[0]['id'];
    }
  }

  $runLock = @fopen(crm_dl_state_path('.dl.lock'), 'c');
  if ($runLock && !$dry && !@flock($runLock, LOCK_EX | LOCK_NB)) return array('ok'=>true, 'skipped'=>'busy');

  /* 1) read (short lock) + pick the orders */
  $fh = @fopen($LOCK_FILE, 'c'); if ($fh) @flock($fh, LOCK_EX);
  $data = crm_read_raw(true);
  $cur = isset($data['afrizon_orders_v5']['d']) ? crm_unwrap($data['afrizon_orders_v5']['d']) : array();
  if (!is_array($cur)) $cur = array();
  $prevT = isset($data['afrizon_orders_v5']['t']) ? (int)$data['afrizon_orders_v5']['t'] : 0;
  if ($fh) { @flock($fh, LOCK_UN); @fclose($fh); }

  $smap = crm_dl_store_map($c);
  $discovered = null;
  $groups = array(); $skipped = array(); $candidates = 0;
  foreach ($cur as $o) {
    if (!is_array($o) || !empty($o['_del']) || !empty($o['dlSent'])) continue;
    $st = isset($o['statut']) ? (string)$o['statut'] : '';
    if (crm_tracking_norm($st) !== 'confirme') continue;
    if (trim((string)(isset($o['livraison']) ? $o['livraison'] : '')) !== '') continue;   // Suivie فارغ = ما تصيفطاتش بعد
    $stamp = (isset($o['_f']['statut']) && is_numeric($o['_f']['statut'])) ? (float)$o['_f']['statut'] : 0;
    if (!$includeExisting && !($since > 0 ? ($stamp >= $since) : $dry)) continue;
    $tries = isset($o['dlTries']) ? (int)$o['dlTries'] : 0;$nextTry=isset($o['dlNextTry'])?(int)$o['dlNextTry']:0;
    /* جيل جديد ديال المرسل => إعادة المحاولة من الصفر. (الطلبيات ما كتبقاوش
       محبوسة بسبب 20 محاولة قديمة ولا بسبب backoff طويل.) */
    $gen = isset($o['dlGen']) ? (int)$o['dlGen'] : 0;
    if ($gen !== CRM_DL_SENDER_GEN) { $tries = 0; $nextTry = 0; }
    if (!$dry && $nextTry > (int)(microtime(true)*1000)) continue;
    $id = (string)$o['id'];
    $ag = isset($o['agent']) ? (string)$o['agent'] : '';
    $miss = '';
    foreach (array('nom', 'telephone', 'adresse', 'ville', 'produit') as $f) {
      if (!isset($o[$f]) || trim((string)$o[$f]) === '') { $miss = $f; break; }
    }
    if ($miss !== '') { $skipped[] = array('id'=>$id, 'why'=>'missing-' . $miss); continue; }
    $phone = crm_dl_phone(isset($o['telephone']) ? $o['telephone'] : '');
    if (!crm_dl_phone_ok($phone)) { $skipped[] = array('id'=>$id, 'why'=>'bad-phone'); continue; }
    /* كنجيبو قائمة Boutiques ديال الحساب (مخزنة 15 دقيقة) باش نقدرو نعرفو
       واش المتجر المحدد موجود فعلا، ولا ن-matchيو سمية Boutique. */
    if ($discovered === null && $token !== '') $discovered = crm_dl_discover_stores($token);
    $store = crm_dl_store_for_agent($ag, $smap, $discovered);
    if ($store === '') { $skipped[] = array('id'=>$id, 'why'=>'no-store-for-agent', 'agent'=>$ag); continue; }
    $groups[$store][] = array('id'=>$id, 'row'=>crm_dl_order_row($o));
    $candidates++;
  }

  if ($dry) {
    $sum = array();
    foreach ($groups as $store => $rows) {
      $sum[] = array('store'=>$store, 'count'=>count($rows), 'sample'=>crm_dl_orders_body($store, $network, array_slice(array_map(function ($r) { return $r['row']; }, $rows), 0, 1), 0));
    }
    $note = ($since > 0) ? 'هادي هي الطلبيات لي غادي تمشي للمتاجر (بعد التفعيل).' : 'مازال ما تفعلاتش الإرسال. هاد الأرقام معاينة فقط: الطلبيات القديمة ما غاديش تتصيفط. ملي تفعّل، غير الطلبيات Confirmé الجداد غادي يمشيو.';
    /* تشخيص: كل طلبية Confirmé (بلا بيانات حساسة) + علاش ما تصيفطاتش */
    $dbg = array(); $dbgN = 0;
    foreach ($cur as $o) {
      if (!is_array($o) || !empty($o['_del'])) continue;
      $st = isset($o['statut']) ? (string)$o['statut'] : '';
      if (stripos($st, 'confirm') === false) continue;
      $stp = (isset($o['_f']['statut']) && is_numeric($o['_f']['statut'])) ? (float)$o['_f']['statut'] : 0;
      $dbgN++;
      $dbg[] = array(
        'id'      => (string)(isset($o['id']) ? $o['id'] : ''),
        'nom'     => mb_substr((string)(isset($o['nom']) ? $o['nom'] : ''), 0, 20),
        'agent'   => (string)(isset($o['agent']) ? $o['agent'] : ''),
        'liv'     => (string)(isset($o['livraison']) ? $o['livraison'] : ''),
        'stampOk' => ($since > 0 ? ($stp >= $since ? 1 : 0) : -1),
        'dlSent'  => !empty($o['dlSent']) ? 1 : 0,
        'tries'   => isset($o['dlTries']) ? (int)$o['dlTries'] : 0,
        'err'     => mb_substr((string)(isset($o['dlErr']) ? $o['dlErr'] : ''), 0, 160)
      );
    }
    usort($dbg, function ($a, $b) { return ($b['stampOk'] - $a['stampOk']) ?: ($b['dlSent'] - $a['dlSent']); });
    $dbg = array_slice($dbg, 0, 40);
    return array('ok'=>true, 'dry'=>true, 'note'=>$note, 'sentType'=>$sentTyp, 'network'=>$network, 'since'=>$since, 'groups'=>$sum, 'skipped'=>$skipped, 'confirmedTotal'=>$dbgN, 'confirmedDebug'=>$dbg);
  }

  /* 2) send to Digylog (no lock inside: the run lock is already held) */
  $sent = array(); $failed = array(); $sentTracking = array();
  $status = ($sentTyp===1) ? 1 : 0;   /* status 0 = add only => Commandes non envoyées */
  /* sentType 2 (call centre) only exists on the legacy route; the automatic
     path always uses 0 = Commandes non envoyées. */
  $route = ($sentTyp===2) ? 'std' : 'v2';
  foreach ($groups as $store => $rows) {
    for ($i = 0; $i < count($rows); $i += 25) {
      $chunk = array_slice($rows, $i, 25);
      $res = crm_dl_send_chunk($token, $store, $network, $chunk, $status, $sentTyp, $route);
      if ($res['route']!==$route) $route = $res['route'];   /* keep the route that worked */
      foreach ($res['sent'] as $id => $v) $sent[$id] = $v;
      foreach ($res['failed'] as $id => $v) $failed[$id] = $v;
      foreach ($res['tracking'] as $id => $v) $sentTracking[$id] = $v;
    }
  }

  /* 3) write the result (lock) */
  $applied = 0;
  if ($sent || $failed) {
    $fh = @fopen($LOCK_FILE, 'c'); if ($fh) @flock($fh, LOCK_EX);
    $data = crm_read_raw(true);
    $cur = isset($data['afrizon_orders_v5']['d']) ? crm_unwrap($data['afrizon_orders_v5']['d']) : array();
    if (!is_array($cur)) $cur = array();
    $prevT = isset($data['afrizon_orders_v5']['t']) ? (int)$data['afrizon_orders_v5']['t'] : 0;
    $now = (int)(microtime(true) * 1000);
    foreach ($cur as $i => $o) {
      if (!is_array($o) || !isset($o['id'])) continue;
      $id = (string)$o['id'];
      if (!isset($o['_f']) || !is_array($o['_f'])) $o['_f'] = array();
      if (isset($sent[$id])) {
        $o['dlSent'] = 1; $o['dlAt'] = $now; $o['dlRes'] = $sent[$id];$o['dlErr']='';$o['dlNextTry']=0;$o['dlTries']=0;$o['dlGen']=CRM_DL_SENDER_GEN;
        $o['livraison'] = 'Expédier vers'; $o['_f']['livraison'] = $now;   // بعد ما تتسجل فـDigylog: Suivie = Expédier vers
        if (isset($sentTracking[$id]) && trim((string)(isset($o['tracking'])?$o['tracking']:'')) === '') { $o['tracking']=$sentTracking[$id]; $o['_f']['tracking']=$now; }
        $o['_f']['dlSent'] = $now; $o['_u'] = $now; $applied++;
      } elseif (isset($failed[$id])) {
        $newErr = substr((string)$failed[$id], 0, 300);
        $oldErr = (string)(isset($o['dlErr']) ? $o['dlErr'] : '');
        $oldGen = isset($o['dlGen']) ? (int)$o['dlGen'] : 0;
        /* نفس الخطأ وعمود dlNextTry مازال فالمستقبل => ما كايناش حاجة تتبدل،
           وما كيكتبش الداتا من جديد (خفيفة على السيرفر). */
        if ($newErr === $oldErr && $oldGen === CRM_DL_SENDER_GEN
            && isset($o['dlNextTry']) && (int)$o['dlNextTry'] > $now) continue;
        $o['dlTries'] = (isset($o['dlTries']) ? (int)$o['dlTries'] : 0) + 1;
        $o['dlErr'] = $newErr;$delay=min(60000,10000*(1<<min(5,max(0,$o['dlTries']-1))));$o['dlNextTry']=$now+$delay;
        $o['dlGen']=CRM_DL_SENDER_GEN;
        $o['_f']['dlTries'] = $now; $o['_f']['dlErr'] = $now;$o['_f']['dlNextTry']=$now;$o['_u'] = $now; $applied++;
      } else continue;
      $cur[$i] = $o;
    }
    if ($applied > 0) {
      crm_backup();
      $t = max($prevT + 1, $now);
      $data['afrizon_orders_v5'] = array('t' => $t, 'd' => array_values($cur));
      $ok = crm_write_all($data);
      if ($ok) crm_journal_append('afrizon_orders_v5', $t, array_values($cur));
    }
    if ($fh) { @flock($fh, LOCK_UN); @fclose($fh); }
  }
  if ($runLock) { @flock($runLock, LOCK_UN); @fclose($runLock); }
  /* Each cron run checks known Trackings exactly and synchronizes only the
     final delivery field: Digylog Livrée -> Livrée, Retournée -> Retour. */
  $trackingSync=$withStatus?crm_tracking_auto_sync(30):array('skipped'=>'fast-send');
  $deliverySync=$withStatus?crm_delivery_sync_batch(20):array('skipped'=>'fast-send');
  crm_audit('digylog-sync | sent=' . count($sent) . ' | failed=' . count($failed) . ' | skipped=' . count($skipped) . ' | applied=' . $applied . ' | sentType='.$sentTyp.' | status='.$status.' | route='.$route.' | auto='.($auto?1:0).' | trackingLinked=' . (isset($trackingSync['linked'])?(int)$trackingSync['linked']:0) . ' | deliveredUpdated=' . (isset($deliverySync['updated'])?(int)$deliverySync['updated']:0));
  $state = array('ok'=>true,'sent'=>count($sent),'failed'=>count($failed),'skipped'=>count($skipped),'applied'=>$applied,'candidates'=>$candidates,'status'=>$status,'route'=>$route,'errors'=>array_slice($failed,0,10,true),'skipReasons'=>array_slice($skipped,0,10));
  if ($auto) crm_dl_auto_state_save($state);
  crm_dl_diag_save(crm_dl_diag_build(array('lastRun'=>$state,'runType'=>$auto?'auto':'manual','route'=>$route,'status'=>$status,'network'=>$network)));
  return array('ok'=>true,'sent'=>count($sent),'failed'=>count($failed),'skipped'=>count($skipped),'applied'=>$applied,'sentType'=>$sentTyp,'status'=>$status,'route'=>$route,'destination'=>$status===0?'Commandes non envoyées':'dispatched','errors'=>$failed,'apiVersion'=>CRM_API_VERSION,'trackingSync'=>$trackingSync,'deliveredSync'=>$deliverySync);
}

/* Digylog webhook: instant Livrée / Retournée synchronization.
 * Exact Tracking is preferred; exact CRM id (Digylog num) is the fallback.
 * No phone/name matching is allowed and livraison is the only business field written. */
function crm_dl_hook() {
  global $SECRET, $LOCK_FILE;
  $tk=isset($_GET['token'])?(string)$_GET['token']:'';
  if(!hash_equals($SECRET,$tk))crm_out(array('ok'=>false,'err'=>'token'),403);
  $raw=(string)file_get_contents('php://input');$j=json_decode($raw,true);
  if(!is_array($j))crm_out(array('ok'=>false,'err'=>'bad-json'),400);
  if(isset($j['type'])&&$j['type']==='subscribe'&&isset($j['key'])){header('Content-Type: application/json; charset=utf-8');echo json_encode(array('key'=>(string)$j['key']),JSON_UNESCAPED_UNICODE);exit;}
  $ev=isset($j['eventType'])?(string)$j['eventType']:(isset($j['type'])?(string)$j['type']:'');
  if($ev!==''&&$ev!=='order-status-changed')crm_out(array('ok'=>true,'ignored'=>$ev));
  $p=(isset($j['payload'])&&is_array($j['payload'])&&count($j['payload'])>0)?$j['payload']:$j;
  $tracking=strtoupper(trim((string)crm_tracking_deep_pick($p,array('traking','tracking','trackingNumber','tracking_number','barcode'))));
  $num=trim((string)crm_tracking_deep_pick($p,array('num','orderNum','order_num','externalId','external_id')));
  $newLiv=crm_delivery_status($p);if($newLiv==='')$newLiv=crm_delivery_status($j);
  if($newLiv==='')crm_out(array('ok'=>true,'ignored'=>'non-final-status'));
  if($tracking===''&&$num==='')crm_out(array('ok'=>true,'ignored'=>'no-exact-key'));

  $fh=@fopen($LOCK_FILE,'c');if($fh)@flock($fh,LOCK_EX);
  $data=crm_read_raw(true);$cur=isset($data['afrizon_orders_v5']['d'])?crm_unwrap($data['afrizon_orders_v5']['d']):array();if(!is_array($cur))$cur=array();
  $hits=array();
  foreach($cur as $i=>$o){
    if(!is_array($o)||!empty($o['_del']))continue;
    $rowTracking=strtoupper(trim((string)(isset($o['tracking'])?$o['tracking']:'')));
    if($tracking!==''&&$rowTracking===$tracking)$hits[]=$i;
  }
  if($tracking===''||count($hits)===0){
    $hits=array();foreach($cur as $i=>$o)if(is_array($o)&&empty($o['_del'])&&isset($o['id'])&&$num!==''&&(string)$o['id']===$num)$hits[]=$i;
  }
  if(count($hits)!==1){if($fh){@flock($fh,LOCK_UN);@fclose($fh);}crm_audit('digylog-hook | tracking='.$tracking.' | num='.$num.' | ignored='.(count($hits)>1?'ambiguous':'not-found'));crm_out(array('ok'=>true,'found'=>false,'ambiguous'=>count($hits)>1));}
  $hit=$hits[0];$o=$cur[$hit];$liv=trim((string)(isset($o['livraison'])?$o['livraison']:''));
  if($liv===$newLiv||($liv==='Retour'&&$newLiv==='Livrée')){if($fh){@flock($fh,LOCK_UN);@fclose($fh);}crm_out(array('ok'=>true,'found'=>true,'changed'=>false,'livraison'=>$liv));}
  $now=(int)(microtime(true)*1000);if(!isset($o['_f'])||!is_array($o['_f']))$o['_f']=array();
  /* The webhook's only business-field write. */
  $o['livraison']=$newLiv;$o['_f']['livraison']=$now;$o['_u']=$now;$cur[$hit]=$o;
  $prevT=isset($data['afrizon_orders_v5']['t'])?(int)$data['afrizon_orders_v5']['t']:0;crm_backup();$t=max($prevT+1,$now);$data['afrizon_orders_v5']=array('t'=>$t,'d'=>array_values($cur));$ok=crm_write_all($data);if($ok)crm_journal_append('afrizon_orders_v5',$t,array_values($cur));
  if($fh){@flock($fh,LOCK_UN);@fclose($fh);}crm_audit('digylog-hook | tracking='.$tracking.' | num='.$num.' | livraison='.$newLiv.' | changed='.($ok?1:0));
  crm_out(array('ok'=>(bool)$ok,'found'=>true,'changed'=>(bool)$ok,'livraison'=>$newLiv));
}
if(isset($_GET['action'])&&$_GET['action']==='digylog_hook')crm_dl_hook();


/* ================================================================
 * Suivi des Retours — linked to afrizon_orders_v5 by order_id.
 * Stores warehouse reception facts only; livraison remains independent.
 * ================================================================ */
function crm_returns_norm($v) {
  $v=trim((string)$v);$v=function_exists('mb_strtolower')?mb_strtolower($v,'UTF-8'):strtolower($v);
  $v=str_replace(array('é','è','ê','ë','à','â','ä','î','ï','ô','ö','ù','û','ü','ç'),array('e','e','e','e','a','a','a','i','i','o','o','u','u','u','c'),$v);
  return preg_replace('/[^a-z0-9]+/u','',$v);
}
function crm_returns_is_return($o) {
  $s=crm_returns_norm(isset($o['livraison'])?$o['livraison']:'');
  return strpos($s,'retour')!==false||$s==='rembourse';
}
function crm_returns_tracking($o) {
  foreach(array('Tracking','tracking','dlTracking','digylogTracking','trackingAlt') as $k){$v=strtoupper(trim((string)(isset($o[$k])?$o[$k]:'')));if($v!=='')return $v;}
  return '';
}
function crm_returns_iso($ms=null) {
  try{$tz=new DateTimeZone('Africa/Casablanca');$d=new DateTime('now',$tz);if($ms!==null&&is_numeric($ms))$d->setTimestamp((int)floor((float)$ms/1000));return $d->format(DateTime::ATOM);}catch(Exception $e){return date('c');}
}
function crm_returns_actor($b,$data,$need='view') {
  $id=isset($b['actorId'])?(string)$b['actorId']:'';$users=isset($data['afrizon_users_v1']['d'])?crm_unwrap($data['afrizon_users_v1']['d']):array();if(!is_array($users))$users=array();$u=null;
  foreach($users as $x)if(is_array($x)&&empty($x['_del'])&&isset($x['id'])&&(string)$x['id']===$id){$u=$x;break;}
  if(!$u)return array('ok'=>false,'err'=>'user-not-found');$base=isset($u['role'])?(string)$u['role']:'user';$rr=isset($u['returnRole'])?(string)$u['returnRole']:'';
  $role=$base==='admin'?'admin':($rr==='supervisor'?'supervisor':'agent_retour');
  $allowed=$need==='view'||$need==='scan'||(($need==='override'||$need==='restock'||$need==='correct')&&($role==='admin'||$role==='supervisor'));
  return array('ok'=>$allowed,'err'=>$allowed?'':'forbidden','id'=>$id,'name'=>(string)(isset($u['username'])?$u['username']:(isset($u['agent'])?$u['agent']:'Utilisateur')),'role'=>$role);
}
function crm_returns_map($data) {
  $rows=isset($data['afrizon_returns_v1']['d'])?crm_unwrap($data['afrizon_returns_v1']['d']):array();if(!is_array($rows))$rows=array();$m=array();foreach($rows as $r)if(is_array($r)&&isset($r['order_id']))$m[(string)$r['order_id']]=$r;return array($rows,$m);
}
function crm_returns_audit(&$data,$event,$orderId,$tracking,$actor,$details=array()) {
  global $DATA_DIR;$now=crm_returns_iso();$a=array('id'=>(string)(microtime(true)*1000000),'event'=>$event,'order_id'=>(string)$orderId,'tracking'=>(string)$tracking,'at'=>$now,'actor_id'=>(string)$actor['id'],'actor'=>(string)$actor['name'],'role'=>(string)$actor['role'],'details'=>$details);
  $list=isset($data['afrizon_return_audit_v1']['d'])?crm_unwrap($data['afrizon_return_audit_v1']['d']):array();if(!is_array($list))$list=array();array_unshift($list,$a);if(count($list)>5000)$list=array_slice($list,0,5000);$t=(int)(microtime(true)*1000);$data['afrizon_return_audit_v1']=array('t'=>$t,'d'=>$list);
  @file_put_contents($DATA_DIR.'/returns_audit.log',json_encode($a,JSON_UNESCAPED_UNICODE)."\n",FILE_APPEND|LOCK_EX);return $a;
}
function crm_returns_item($o,$r,$audit=array()) {
  $id=(string)(isset($o['id'])?$o['id']:'');$tr=crm_returns_tracking($o);$declMs=isset($o['_f']['livraison'])?$o['_f']['livraison']:null;
  return array('order_id'=>$id,'commande'=>trim((string)(isset($o['idCmd'])?$o['idCmd']:''))!==''?(string)$o['idCmd']:'ORD-'.$id,'tracking'=>$tr,'client'=>(string)(isset($o['nom'])?$o['nom']:''),'phone'=>(string)(isset($o['telephone'])?$o['telephone']:''),'ville'=>(string)(isset($o['ville'])?$o['ville']:''),'adresse'=>(string)(isset($o['adresse'])?$o['adresse']:''),'produit'=>(string)(isset($o['produit'])?$o['produit']:''),'qte'=>max(1,(int)(isset($o['qte'])?$o['qte']:1)),'prix'=>(float)(isset($o['prix'])?$o['prix']:0),'transporteur'=>(string)(isset($o['livreur'])&&trim((string)$o['livreur'])!==''?$o['livreur']:'Digylog'),'return_reason'=>(string)(isset($o['motif'])&&trim((string)$o['motif'])!==''?$o['motif']:'Non renseigné'),'created_at'=>(string)(isset($o['dateCreation'])?$o['dateCreation']:''),'confirmed_at'=>(string)(isset($o['dateConfirmation'])?$o['dateConfirmation']:''),'shipped_at'=>(string)(isset($o['dateExp'])?$o['dateExp']:''),'returned_at'=>isset($r['returned_at'])?$r['returned_at']:($declMs?crm_returns_iso($declMs):''),'delivery_status'=>(string)(isset($o['livraison'])?$o['livraison']:''),'warehouse_status'=>isset($r['warehouse_status'])?$r['warehouse_status']:'En attente','scanned_at'=>isset($r['scanned_at'])?$r['scanned_at']:'','scanned_by'=>isset($r['scanned_by'])?$r['scanned_by']:'','product_condition'=>isset($r['product_condition'])?$r['product_condition']:'','notes'=>isset($r['notes'])?$r['notes']:'','stock_reintegrated_at'=>isset($r['stock_reintegrated_at'])?$r['stock_reintegrated_at']:'','audit'=>$audit);
}
function crm_returns_list($b) {
  $data=crm_read_raw(true);$actor=crm_returns_actor($b,$data,'view');if(empty($actor['ok']))crm_out(array('ok'=>false,'err'=>$actor['err']),403);list($records,$rm)=crm_returns_map($data);$orders=isset($data['afrizon_orders_v5']['d'])?crm_unwrap($data['afrizon_orders_v5']['d']):array();if(!is_array($orders))$orders=array();$aud=isset($data['afrizon_return_audit_v1']['d'])?crm_unwrap($data['afrizon_return_audit_v1']['d']):array();if(!is_array($aud))$aud=array();$byOrder=array();foreach($aud as $a)if(is_array($a)&&isset($a['order_id'])){$oid=(string)$a['order_id'];if(!isset($byOrder[$oid]))$byOrder[$oid]=array();if(count($byOrder[$oid])<50)$byOrder[$oid][]=$a;}
  $items=array();foreach($orders as $o){if(!is_array($o)||!empty($o['_del']))continue;$id=(string)$o['id'];if(!crm_returns_is_return($o)&&!isset($rm[$id]))continue;$items[]=crm_returns_item($o,isset($rm[$id])?$rm[$id]:array(),isset($byOrder[$id])?$byOrder[$id]:array());}
  usort($items,function($a,$b){return strcmp((string)($b['scanned_at']?:$b['returned_at']),(string)($a['scanned_at']?:$a['returned_at']));});$today=(new DateTime('now',new DateTimeZone('Africa/Casablanca')))->format('Y-m-d');$received=0;$todayN=0;foreach($items as $x){if($x['warehouse_status']==='Reçu au dépôt')$received++;if(substr((string)$x['scanned_at'],0,10)===$today)$todayN++;}
  crm_out(array('ok'=>true,'actor'=>array('name'=>$actor['name'],'role'=>$actor['role']),'items'=>$items,'kpis'=>array('total'=>count($items),'pending'=>count($items)-$received,'received'=>$received,'today'=>$todayN),'serverTime'=>crm_returns_iso()));
}
function crm_returns_collect_known_tracking($v,$known,&$hits,$depth=0) {
  if($depth>10)return;
  if(is_array($v)){foreach($v as $x)crm_returns_collect_known_tracking($x,$known,$hits,$depth+1);return;}
  if(is_object($v)){crm_returns_collect_known_tracking((array)$v,$known,$hits,$depth+1);return;}
  $n=strtoupper(trim(preg_replace('/\s+/','',(string)$v)));if($n!==''&&isset($known[$n]))$hits[$known[$n]]=true;
}
function crm_returns_has_exact_value($v,$needle,$depth=0) {
  if($depth>10)return false;if(is_array($v)){foreach($v as $x)if(crm_returns_has_exact_value($x,$needle,$depth+1))return true;return false;}if(is_object($v))return crm_returns_has_exact_value((array)$v,$needle,$depth+1);return strcasecmp(trim((string)$v),trim((string)$needle))===0;
}
function crm_returns_digylog_get_url($url,$token) {
  if(!function_exists('curl_init'))return array(0,'','no-curl');$ch=curl_init($url);curl_setopt_array($ch,array(CURLOPT_HTTPHEADER=>array('Accept: application/json, text/plain, */*','Referer: https://seller.digylog.com/','Origin: https://seller.digylog.com','Authorization: Bearer '.$token),CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>20));$body=curl_exec($ch);$err=(string)curl_error($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);return array($code,$body===false?'':(string)$body,$err);
}
function crm_returns_resolve_qr_tracking($qr,$orders) {
  $qr=trim((string)$qr);if(!preg_match('/^tk[a-z0-9]{12,80}$/i',$qr))return array('tracking'=>'','reason'=>'not-digylog-qr');
  $known=array();foreach($orders as $o){if(!is_array($o)||!empty($o['_del']))continue;$tr=crm_returns_tracking($o);$n=strtoupper(trim(preg_replace('/\s+/','',$tr)));if($n!=='')$known[$n]=$tr;}
  if(!$known)return array('tracking'=>'','reason'=>'no-crm-trackings');
  $cfg=crm_dl_cfg_get();$token=isset($cfg['token'])?trim((string)$cfg['token']):'';if($token==='')return array('tracking'=>'','reason'=>'no-digylog-token');
  $hits=array();$codes=array();foreach(array('/order/'.rawurlencode($qr).'/infos','/order/'.rawurlencode($qr).'/refs') as $path){list($code,$body,$err)=crm_dl_http('GET',$path,$token);$codes[]=$code;if($code<200||$code>=300)continue;$j=json_decode($body,true);if(is_array($j))crm_returns_collect_known_tracking($j,$known,$hits);}
  $keys=array_keys($hits);if(count($keys)===1)return array('tracking'=>$keys[0],'reason'=>'digylog-exact','codes'=>$codes);
  /* Some Digylog labels expose an opaque tk... token rather than the visible
     Tracking. Ask the seller-history search using that exact token and accept
     only a single Tracking which already exists in CRM. */
  $base='https://api.digylog.com/api/seller/v48/historic/orders';$qs=array('tracking','search','barcode','q');
  foreach($qs as $param){$url=$base.'?'.rawurlencode($param).'='.rawurlencode($qr).'&datatype=1&countPerPage=10&currentPage=1&sort=0';list($code,$body,$err)=crm_returns_digylog_get_url($url,$token);$codes[]=$code;if($code<200||$code>=300)continue;$j=json_decode($body,true);$one=array();if(is_array($j)&&crm_returns_has_exact_value($j,$qr))crm_returns_collect_known_tracking($j,$known,$one);$found=array_keys($one);if(count($found)===1)return array('tracking'=>$found[0],'reason'=>'digylog-qr-search-exact','codes'=>$codes);}
  return array('tracking'=>'','reason'=>count($keys)>1?'ambiguous-digylog-response':'digylog-qr-unresolved','codes'=>$codes);
}
function crm_returns_find_order(&$orders,$query,&$matches) {
  $q=strtoupper(trim(preg_replace('/\s+/','',(string)$query)));$hit=-1;$matches=0;foreach($orders as $i=>$o){if(!is_array($o)||!empty($o['_del']))continue;$tr=strtoupper(trim(preg_replace('/\s+/','',crm_returns_tracking($o))));$id=strtoupper(trim((string)(isset($o['id'])?$o['id']:'')));$cmd=strtoupper(trim((string)(isset($o['idCmd'])?$o['idCmd']:'')));if($q!==''&&($tr===$q||$id===$q||$cmd===$q||('ORD-'.$id)===$q)){$hit=$i;$matches++;}}return $hit;
}
function crm_returns_scan($b) {
  global $LOCK_FILE;
  $query=isset($b['tracking'])?trim((string)$b['tracking']):'';if($query==='')crm_out(array('ok'=>false,'err'=>'empty-tracking'),400);
  /* Resolve Digylog's printed QR token before taking the write lock. The
     resolver accepts only a Tracking value that already exists exactly in CRM. */
  $pre=crm_read_raw(true);$preActor=crm_returns_actor($b,$pre,'scan');if(empty($preActor['ok']))crm_out(array('ok'=>false,'err'=>$preActor['err']),403);
  $preOrders=isset($pre['afrizon_orders_v5']['d'])?crm_unwrap($pre['afrizon_orders_v5']['d']):array();if(!is_array($preOrders))$preOrders=array();$preMatches=0;$resolved=$query;$preIdx=crm_returns_find_order($preOrders,$resolved,$preMatches);$qrMeta=array('tracking'=>'','reason'=>'direct');
  if($preMatches===0&&preg_match('/^tk[a-z0-9]{12,80}$/i',$query)){$qrMeta=crm_returns_resolve_qr_tracking($query,$preOrders);if(!empty($qrMeta['tracking']))$resolved=(string)$qrMeta['tracking'];elseif(!empty($b['visibleTracking'])){$manualMatches=0;$manualIdx=crm_returns_find_order($preOrders,(string)$b['visibleTracking'],$manualMatches);if($manualMatches===1){$resolved=crm_returns_tracking($preOrders[$manualIdx]);$qrMeta=array('tracking'=>$resolved,'reason'=>'operator-visible-exact');}else crm_out(array('ok'=>false,'err'=>$manualMatches>1?'tracking-ambiguous':'tracking-not-found'),$manualMatches>1?409:404);}else crm_out(array('ok'=>false,'err'=>'qr-tracking-unresolved','qr'=>$query,'reason'=>$qrMeta['reason'],'canPairVisibleTracking'=>true),404);}
  elseif($preMatches>1)crm_out(array('ok'=>false,'err'=>'tracking-ambiguous'),409);

  $fh=@fopen($LOCK_FILE,'c');if($fh)@flock($fh,LOCK_EX);
  $data=crm_read_raw(true);$actor=crm_returns_actor($b,$data,'scan');
  if(empty($actor['ok'])){if($fh){@flock($fh,LOCK_UN);@fclose($fh);}crm_out(array('ok'=>false,'err'=>$actor['err']),403);}
  $orders=isset($data['afrizon_orders_v5']['d'])?crm_unwrap($data['afrizon_orders_v5']['d']):array();if(!is_array($orders))$orders=array();$matches=0;$idx=crm_returns_find_order($orders,$resolved,$matches);
  if($matches!==1){if($fh){@flock($fh,LOCK_UN);@fclose($fh);}crm_out(array('ok'=>false,'err'=>$matches>1?'tracking-ambiguous':'tracking-not-found','qr'=>$query,'resolvedTracking'=>$resolved),$matches>1?409:404);}
  $o=$orders[$idx];$override=!empty($b['override']);$reason=trim((string)(isset($b['overrideReason'])?$b['overrideReason']:''));
  if(!crm_returns_is_return($o)){
    if(!$override){if($fh){@flock($fh,LOCK_UN);@fclose($fh);}crm_out(array('ok'=>false,'err'=>'not-declared-return','canOverride'=>$actor['role']==='admin'||$actor['role']==='supervisor','resolvedTracking'=>$resolved),409);}
    $oa=crm_returns_actor($b,$data,'override');if(empty($oa['ok'])||$reason===''){if($fh){@flock($fh,LOCK_UN);@fclose($fh);}crm_out(array('ok'=>false,'err'=>'override-forbidden-or-reason-required'),403);}
  }
  list($records,$rm)=crm_returns_map($data);$id=(string)$o['id'];$tr=crm_returns_tracking($o);
  $r=isset($rm[$id])?$rm[$id]:array('id'=>(string)(microtime(true)*1000000),'order_id'=>$id,'tracking_number'=>$tr,'returned_at'=>crm_returns_iso(isset($o['_f']['livraison'])?$o['_f']['livraison']:null),'warehouse_status'=>'En attente','created_at'=>crm_returns_iso());
  if(isset($r['scanned_at'])&&$r['scanned_at']!==''){if($fh){@flock($fh,LOCK_UN);@fclose($fh);}crm_out(array('ok'=>false,'err'=>'already-received','existing'=>crm_returns_item($o,$r),'resolvedTracking'=>$tr),409);}
  $now=crm_returns_iso();$r['tracking_number']=$tr;if(strcasecmp($query,$tr)!==0)$r['qr_code']=$query;$r['warehouse_status']=$override?'À vérifier':'Reçu au dépôt';$r['scanned_at']=$now;$r['scanned_by']=$actor['name'];$r['scanned_by_id']=$actor['id'];$r['updated_at']=$now;if($override){$r['override_reason']=$reason;$r['override_by']=$actor['name'];}
  $found=false;foreach($records as $i=>$x)if(is_array($x)&&isset($x['order_id'])&&(string)$x['order_id']===$id){$records[$i]=$r;$found=true;break;}if(!$found)$records[]=$r;
  $ms=(int)(microtime(true)*1000);$data['afrizon_returns_v1']=array('t'=>$ms,'d'=>array_values($records));if(!isset($o['_f'])||!is_array($o['_f']))$o['_f']=array();$o['warehouseReturnStatus']=$r['warehouse_status'];$o['returnScannedAt']=$now;$o['returnScannedBy']=$actor['name'];$o['_f']['warehouseReturnStatus']=$ms;$o['_f']['returnScannedAt']=$ms;$o['_f']['returnScannedBy']=$ms;$o['_u']=$ms;$orders[$idx]=$o;$ot=isset($data['afrizon_orders_v5']['t'])?(int)$data['afrizon_orders_v5']['t']:0;$data['afrizon_orders_v5']=array('t'=>max($ot+1,$ms),'d'=>array_values($orders));
  $auditDetails=$override?array('reason'=>$reason):array();if(strcasecmp($query,$tr)!==0){$auditDetails['qr_code']=$query;$auditDetails['resolved_tracking']=$tr;}$audit=crm_returns_audit($data,$override?'return_received_override':'return_received',$id,$tr,$actor,$auditDetails);
  crm_backup();$ok=crm_write_all($data);if($ok){crm_journal_append('afrizon_returns_v1',$ms,array_values($records));crm_journal_append('afrizon_orders_v5',$data['afrizon_orders_v5']['t'],array_values($orders));crm_journal_append('afrizon_return_audit_v1',$data['afrizon_return_audit_v1']['t'],$data['afrizon_return_audit_v1']['d']);}
  if($fh){@flock($fh,LOCK_UN);@fclose($fh);}if(!$ok)crm_out(array('ok'=>false,'err'=>'save-failed'),500);crm_out(array('ok'=>true,'message'=>'Retour reçu avec succès','resolvedTracking'=>$tr,'item'=>crm_returns_item($o,$r)));
}
function crm_returns_control($b) {
  global $LOCK_FILE;
  $id=(string)(isset($b['orderId'])?$b['orderId']:'');
  $cond=(string)(isset($b['productCondition'])?$b['productCondition']:'');
  $allowed=array('Produit intact','Emballage endommagé','Produit endommagé','Produit manquant','Colis vide','À vérifier');
  if(!in_array($cond,$allowed,true))crm_out(array('ok'=>false,'err'=>'bad-condition'),400);
  $fh=@fopen($LOCK_FILE,'c');if($fh)@flock($fh,LOCK_EX);
  $data=crm_read_raw(true);$actor=crm_returns_actor($b,$data,'scan');
  if(empty($actor['ok'])){if($fh){@flock($fh,LOCK_UN);@fclose($fh);}crm_out(array('ok'=>false,'err'=>$actor['err']),403);}
  list($records,$rm)=crm_returns_map($data);$hit=-1;
  foreach($records as $i=>$r)if(is_array($r)&&isset($r['order_id'])&&(string)$r['order_id']===$id){$hit=$i;break;}
  if($hit<0||empty($records[$hit]['scanned_at'])){if($fh){@flock($fh,LOCK_UN);@fclose($fh);}crm_out(array('ok'=>false,'err'=>'return-not-received'),409);}
  $warehouse=$cond==='À vérifier'?'À vérifier':(in_array($cond,array('Produit endommagé','Produit manquant','Colis vide'),true)?'Anomalie':'Reçu au dépôt');
  $notes=trim((string)(isset($b['notes'])?$b['notes']:''));$now=crm_returns_iso();$ms=(int)(microtime(true)*1000);
  $records[$hit]['product_condition']=$cond;$records[$hit]['notes']=$notes;$records[$hit]['warehouse_status']=$warehouse;$records[$hit]['updated_at']=$now;
  $data['afrizon_returns_v1']=array('t'=>$ms,'d'=>$records);
  $orders=isset($data['afrizon_orders_v5']['d'])?crm_unwrap($data['afrizon_orders_v5']['d']):array();if(!is_array($orders))$orders=array();
  foreach($orders as $i=>$o){if(!is_array($o)||!isset($o['id'])||(string)$o['id']!==$id)continue;if(!isset($o['_f'])||!is_array($o['_f']))$o['_f']=array();$o['warehouseReturnStatus']=$warehouse;$o['returnProductCondition']=$cond;$o['returnNotes']=$notes;foreach(array('warehouseReturnStatus','returnProductCondition','returnNotes')as$f)$o['_f'][$f]=$ms;$o['_u']=$ms;$orders[$i]=$o;break;}
  $ot=isset($data['afrizon_orders_v5']['t'])?(int)$data['afrizon_orders_v5']['t']:0;$data['afrizon_orders_v5']=array('t'=>max($ot+1,$ms),'d'=>array_values($orders));
  crm_returns_audit($data,'return_controlled',$id,(string)$records[$hit]['tracking_number'],$actor,array('condition'=>$cond,'warehouse_status'=>$warehouse));
  crm_backup();$ok=crm_write_all($data);if($ok){crm_journal_append('afrizon_returns_v1',$ms,$records);crm_journal_append('afrizon_orders_v5',$data['afrizon_orders_v5']['t'],array_values($orders));crm_journal_append('afrizon_return_audit_v1',$data['afrizon_return_audit_v1']['t'],$data['afrizon_return_audit_v1']['d']);}
  if($fh){@flock($fh,LOCK_UN);@fclose($fh);}crm_out(array('ok'=>(bool)$ok,'warehouse_status'=>$warehouse));
}
function crm_returns_restock($b) {
  global $LOCK_FILE;
  $id=(string)(isset($b['orderId'])?$b['orderId']:'');
  $fh=@fopen($LOCK_FILE,'c');if($fh)@flock($fh,LOCK_EX);
  $data=crm_read_raw(true);$actor=crm_returns_actor($b,$data,'restock');
  if(empty($actor['ok'])){if($fh){@flock($fh,LOCK_UN);@fclose($fh);}crm_out(array('ok'=>false,'err'=>$actor['err']),403);}
  list($records,$rm)=crm_returns_map($data);$ri=-1;
  foreach($records as $i=>$r){if(is_array($r)&&isset($r['order_id'])&&(string)$r['order_id']===$id){$ri=$i;break;}}
  if($ri<0||empty($records[$ri]['scanned_at'])||(isset($records[$ri]['product_condition'])?$records[$ri]['product_condition']:'')!=='Produit intact'){if($fh){@flock($fh,LOCK_UN);@fclose($fh);}crm_out(array('ok'=>false,'err'=>'intact-control-required'),409);}
  if(!empty($records[$ri]['stock_reintegrated_at'])){if($fh){@flock($fh,LOCK_UN);@fclose($fh);}crm_out(array('ok'=>false,'err'=>'already-restocked'),409);}
  $orders=isset($data['afrizon_orders_v5']['d'])?crm_unwrap($data['afrizon_orders_v5']['d']):array();$order=null;
  if(is_array($orders))foreach($orders as $o){if(is_array($o)&&isset($o['id'])&&(string)$o['id']===$id){$order=$o;break;}}
  if(!$order){if($fh){@flock($fh,LOCK_UN);@fclose($fh);}crm_out(array('ok'=>false,'err'=>'order-not-found'),404);}
  $cat=isset($data['afrizon_catalog_v1']['d'])?crm_unwrap($data['afrizon_catalog_v1']['d']):array();if(!is_array($cat))$cat=array();$pi=-1;
  foreach($cat as $i=>$p){if(is_array($p)&&crm_returns_norm(isset($p['nom'])?$p['nom']:'')===crm_returns_norm(isset($order['produit'])?$order['produit']:'')){$pi=$i;break;}}
  if($pi<0){if($fh){@flock($fh,LOCK_UN);@fclose($fh);}crm_out(array('ok'=>false,'err'=>'product-not-found-in-catalog'),404);}
  $q=max(1,(int)(isset($order['qte'])?$order['qte']:1));$oldStock=(int)(isset($cat[$pi]['stock'])?$cat[$pi]['stock']:0);$cat[$pi]['stock']=(string)($oldStock+$q);$now=crm_returns_iso();
  $records[$ri]['stock_reintegrated_at']=$now;$records[$ri]['stock_reintegrated_by']=$actor['name'];$records[$ri]['updated_at']=$now;
  $moves=isset($data['afrizon_stock_movements_v1']['d'])?crm_unwrap($data['afrizon_stock_movements_v1']['d']):array();if(!is_array($moves))$moves=array();
  array_unshift($moves,array('id'=>(string)(microtime(true)*1000000),'type'=>'RETURN_IN','order_id'=>$id,'tracking'=>$records[$ri]['tracking_number'],'product'=>$order['produit'],'quantity'=>$q,'at'=>$now,'by'=>$actor['name']));
  $ms=(int)(microtime(true)*1000);$data['afrizon_catalog_v1']=array('t'=>$ms,'d'=>$cat);$data['afrizon_returns_v1']=array('t'=>$ms,'d'=>$records);$data['afrizon_stock_movements_v1']=array('t'=>$ms,'d'=>$moves);
  crm_returns_audit($data,'return_restocked',$id,$records[$ri]['tracking_number'],$actor,array('product'=>$order['produit'],'quantity'=>$q));crm_backup();$ok=crm_write_all($data);
  if($ok){crm_journal_append('afrizon_catalog_v1',$ms,$cat);crm_journal_append('afrizon_returns_v1',$ms,$records);crm_journal_append('afrizon_stock_movements_v1',$ms,$moves);crm_journal_append('afrizon_return_audit_v1',$data['afrizon_return_audit_v1']['t'],$data['afrizon_return_audit_v1']['d']);}
  if($fh){@flock($fh,LOCK_UN);@fclose($fh);}crm_out(array('ok'=>(bool)$ok,'quantity'=>$q,'newStock'=>(int)$cat[$pi]['stock']));
}

/* One-time 2026-10 city delivery-price adjustment requested by admin.
 * Exact mapping from the pre-change value: 40->35, 45->40, 50->45.
 * The marker prevents a transformed 45->40 price from being discounted twice. */
function crm_city_delivery_price_cut_once() {
  global $LOCK_FILE,$DATA_DIR;
  $marker='afrizon_city_price_cut_20261028_v1';$stamp=rtrim((string)$DATA_DIR,'/').'/.city-price-cut-20261028.done';
  if(is_file($stamp))return;
  $fh=@fopen($LOCK_FILE,'c');if($fh)@flock($fh,LOCK_EX);
  $data=crm_read_raw(true);
  if(isset($data[$marker]['d']['done'])&&!empty($data[$marker]['d']['done'])){@touch($stamp);if($fh){@flock($fh,LOCK_UN);@fclose($fh);}return;}
  $rows=isset($data['afrizon_villes_v2']['d'])?crm_unwrap($data['afrizon_villes_v2']['d']):array();
  if(!is_array($rows)||count($rows)===0){if($fh){@flock($fh,LOCK_UN);@fclose($fh);}return;}
  $map=array('40'=>35,'45'=>40,'50'=>45);$counts=array('40_to_35'=>0,'45_to_40'=>0,'50_to_45'=>0);$changed=0;
  foreach($rows as $i=>$row){
    if(!is_array($row)||!isset($row['prix'])||!is_numeric($row['prix']))continue;$old=(float)$row['prix'];$key=(string)(int)$old;
    if($old!=(float)(int)$old||!isset($map[$key]))continue;$rows[$i]['prix']=$map[$key];$counts[$key.'_to_'.$map[$key]]++;$changed++;
  }
  $now=(int)(microtime(true)*1000);$prevT=isset($data['afrizon_villes_v2']['t'])?(int)$data['afrizon_villes_v2']['t']:0;
  $data['afrizon_villes_v2']=array('t'=>max($prevT+1,$now),'d'=>array_values($rows));
  $data[$marker]=array('t'=>$now,'d'=>array('done'=>1,'at'=>$now,'changed'=>$changed,'counts'=>$counts));
  crm_backup();$ok=crm_write_all($data);if($ok){crm_journal_append('afrizon_villes_v2',$data['afrizon_villes_v2']['t'],array_values($rows));@touch($stamp);}
  if($fh){@flock($fh,LOCK_UN);@fclose($fh);}crm_audit('city-price-cut | changed='.$changed.' | 40to35='.$counts['40_to_35'].' | 45to40='.$counts['45_to_40'].' | 50to45='.$counts['50_to_45'].' | ok='.($ok?1:0));
}
crm_city_delivery_price_cut_once();

/* One-time matching reduction for the existing CRM commission column.
 * Exact original values only: 40->35, 45->40, 50->45. */
function crm_order_commission_cut_once() {
  global $LOCK_FILE,$DATA_DIR;
  $marker='afrizon_commission_cut_20261028_v1';$stamp=rtrim((string)$DATA_DIR,'/').'/.commission-cut-20261028.done';
  if(is_file($stamp))return;
  $fh=@fopen($LOCK_FILE,'c');if($fh)@flock($fh,LOCK_EX);$data=crm_read_raw(true);
  if(isset($data[$marker]['d']['done'])&&!empty($data[$marker]['d']['done'])){@touch($stamp);if($fh){@flock($fh,LOCK_UN);@fclose($fh);}return;}
  $rows=isset($data['afrizon_orders_v5']['d'])?crm_unwrap($data['afrizon_orders_v5']['d']):array();
  if(!is_array($rows)||count($rows)===0){if($fh){@flock($fh,LOCK_UN);@fclose($fh);}return;}
  $map=array('40'=>35,'45'=>40,'50'=>45);$counts=array('40_to_35'=>0,'45_to_40'=>0,'50_to_45'=>0);$changed=0;$now=(int)(microtime(true)*1000);
  foreach($rows as $i=>$row){
    if(!is_array($row)||!empty($row['_del'])||!isset($row['commission'])||!is_numeric($row['commission']))continue;$old=(float)$row['commission'];$key=(string)(int)$old;
    if($old!=(float)(int)$old||!isset($map[$key]))continue;
    $row['commission']=$map[$key];if(!isset($row['_f'])||!is_array($row['_f']))$row['_f']=array();$row['_f']['commission']=$now;$row['_u']=$now;$rows[$i]=$row;$counts[$key.'_to_'.$map[$key]]++;$changed++;
  }
  $prevT=isset($data['afrizon_orders_v5']['t'])?(int)$data['afrizon_orders_v5']['t']:0;$data['afrizon_orders_v5']=array('t'=>max($prevT+1,$now),'d'=>array_values($rows));
  $data[$marker]=array('t'=>$now,'d'=>array('done'=>1,'at'=>$now,'changed'=>$changed,'counts'=>$counts));
  crm_backup();$ok=crm_write_all($data);if($ok){crm_journal_append('afrizon_orders_v5',$data['afrizon_orders_v5']['t'],array_values($rows));@touch($stamp);}
  if($fh){@flock($fh,LOCK_UN);@fclose($fh);}crm_audit('commission-cut | changed='.$changed.' | 40to35='.$counts['40_to_35'].' | 45to40='.$counts['45_to_40'].' | 50to45='.$counts['50_to_45'].' | ok='.($ok?1:0));
}
crm_order_commission_cut_once();

$m = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/* ---------- GET: full snapshot ---------- */
if ($m === 'GET') {
  // v3.101: cron ديال Digylog: api.php?action=digylog_sync&token=...
  if (isset($_GET['action']) && $_GET['action'] === 'digylog_sync') {
    if (!hash_equals($SECRET, crm_token())) crm_out(array('ok'=>false, 'err'=>'token'), 403);
    crm_out(crm_dl_run(false));
  }
  // v3.41: la lecture exige aussi le token (avant: n'importe qui avec l'URL téléchargeait tous les clients)
  if (!hash_equals($SECRET, crm_token())) crm_out(array('ok'=>false, 'err'=>'token'), 403);

  /* Guaranteed automatic fallback: every CRM snapshot can start one throttled
     status batch, even when an old cached index lacks the heartbeat script. */
  if (!isset($_GET['export']) && !isset($_GET['pushkey'])) { crm_dl_send_guarded(); crm_delivery_auto_guarded(); }

  /* PERF v3.90 — أهم إصلاح فالسيرفر.
   * قبل: كل جهاز كيدير GET كل 5 ثواني وكيحمّل 1.1MB حتى إلا ما تبدل والو.
   *      = 720 طلب/ساعة × 1.1MB = ~800MB فالساعة لكل مستخدم.
   * دابا: بصمة رخيصة (stat) → إلا ما تبدل والو كنرجعو 304 بلا ما نقراو حتى ملف
   *      وبلا ما نصيفطو حتى بايت. المتصفح ما كيديرش parse ولا localStorage ولا
   *      إعادة رسم — وهادا هو لي كان كيبلوكي الشاشة كل 5 ثواني. */
  /* ================================================================
   * v3.91 — واجهة خفيفة لـ Google Sheets
   *   api.php?export=adspend&token=...  → المصاريف الخام (للتحرير فالشيت)
   *   api.php?export=perf&token=...     → جدول الأداء محسوب (للقراءة فقط)
   * كترجع غير لي محتاج الشيت (بضع كيلوبايتات) عوض 1.1 ميغا.
   * ================================================================ */
  if (isset($_GET['pushkey'])) {   // v3.98: المفتاح العام ديال Web Push
    $__pk = crm_push_keys();
    crm_out($__pk ? array('ok'=>true, 'key'=>$__pk['pub']) : array('ok'=>false, 'err'=>'no-vapid-keys'), $__pk ? 200 : 500);
  }
  if (isset($_GET['export'])) {
    $__what = (string)$_GET['export'];
    $__d    = crm_read_raw();
    if ($__what === 'version') {
      /* نسخة السيرفر + تشخيص المرسل. بلا بيانات زبائن وبلا توكنات. */
      crm_out(array('ok'=>true,'apiVersion'=>CRM_API_VERSION,'senderGen'=>CRM_DL_SENDER_GEN,'built'=>'2026-10-29','diag'=>crm_dl_diag_build(array())));
    }
    $__ads  = isset($__d['afrizon_adspend_v1']['d']) ? crm_unwrap($__d['afrizon_adspend_v1']['d']) : array();
    if (!is_array($__ads)) $__ads = array();
    $__adsT = isset($__d['afrizon_adspend_v1']['t']) ? (int)$__d['afrizon_adspend_v1']['t'] : 0;
    $__rs   = isset($__d['afrizon_reset_v1']['t'])   ? (int)$__d['afrizon_reset_v1']['t']   : 0;

    if ($__what === 'adspend') {
      crm_out(array('ok'=>true, 't'=>$__adsT, 'rs'=>$__rs, 'rows'=>array_values($__ads)));
    }

    if ($__what === 'perf') {
      $__ord = isset($__d['afrizon_orders_v5']['d']) ? crm_unwrap($__d['afrizon_orders_v5']['d']) : array();
      if (!is_array($__ord)) $__ord = array();
      /* فهرسة الطلبيات بالتاريخ — بلا هادشي كنديرو 109 × 1400 مقارنة */
      $__byDate = array();
      foreach ($__ord as $o) {
        if (!is_array($o) || !empty($o['_del'])) continue;
        $dt = isset($o['dateCreation']) ? (string)$o['dateCreation'] : '';
        if ($dt === '') continue;
        $__byDate[$dt][] = $o;
      }
      $__norm = function($v) { return strtolower(trim((string)$v)); };
      $__rows = array();
      foreach ($__ads as $a) {
        if (!is_array($a)) continue;
        $aDate = isset($a['date'])    ? (string)$a['date']    : '';
        $aAg   = isset($a['agent'])   ? (string)$a['agent']   : '';
        $aPr   = isset($a['produit']) ? (string)$a['produit'] : '';
        $aSrc  = isset($a['source'])  ? (string)$a['source']  : '';
        $amt   = isset($a['amount'])  ? (float)$a['amount']   : 0;

        $cnt = 0; $conf = 0; $livre = 0; $retour = 0; $ca = 0;
        $pool = isset($__byDate[$aDate]) ? $__byDate[$aDate] : array();
        foreach ($pool as $o) {
          /* نفس منطق المطابقة ديال صفحة Dashboard performance بالضبط */
          if ($aAg !== '' && $__norm(isset($o['agent']) ? $o['agent'] : '') !== $__norm($aAg)) continue;
          if ($aPr !== '' && $__norm(isset($o['produit']) ? $o['produit'] : '') !== $__norm($aPr)) continue;
          if ($aAg === '' && $aSrc !== ''
              && $__norm(isset($o['originLead']) ? $o['originLead'] : '') !== $__norm($aSrc)) continue;

          $cnt++;
          $st = isset($o['statut'])    ? (string)$o['statut']    : '';
          $lv = isset($o['livraison']) ? (string)$o['livraison'] : '';
          if ($st === 'Confirmé') $conf++;
          if ($lv === 'Livrée' || $lv === 'Rechange')   { $livre++;  $ca += isset($o['prix']) ? (float)$o['prix'] : 0; }
          if ($lv === 'Retour' || $lv === 'Remboursé')  { $retour++; }
        }
        $__rows[] = array(
          'id'      => isset($a['id']) ? $a['id'] : '',
          'date'    => $aDate,
          'agent'   => $aAg,
          'produit' => $aPr,
          'source'  => $aSrc,
          'amount'  => $amt,
          'count'   => $cnt,
          'conf'    => $conf,
          'livre'   => $livre,
          'retour'  => $retour,
          'ca'      => $ca,
          'cpl'     => $cnt > 0 ? round($amt / $cnt, 2) : '',
        );
      }
      crm_out(array('ok'=>true, 't'=>$__adsT, 'rs'=>$__rs, 'rows'=>$__rows));
    }

    if ($__what === 'orders') {
      /* v3.92: الطلبيات الحية للـ Google Sheet.
       * كنرجعو صفوف (arrays) ماشي objects — الحجم كينقص ~60% وكيدخل
       * مباشرة لـ setValues() بلا تحويل. */
      $__cols = array('id','dateCreation','dateConfirmation','statut','remarques','idCmd',
                      'nom','telephone','ville','adresse','qte','prix','produit','livraison',
                      'upsell','carousell','agent','link','carosellFlag','originLead',
                      'commission','fees','livreur','tracking','dateExp','dateLiv','motif');
      $__since = isset($_GET['since']) ? trim((string)$_GET['since']) : '';
      $__ord = isset($__d['afrizon_orders_v5']['d']) ? crm_unwrap($__d['afrizon_orders_v5']['d']) : array();
      if (!is_array($__ord)) $__ord = array();
      $__out = array();
      foreach ($__ord as $o) {
        if (!is_array($o) || !empty($o['_del'])) continue;              // الممسوحة ما كتبانش
        if ($__since !== '' && (string)(isset($o['dateCreation']) ? $o['dateCreation'] : '') < $__since) continue;
        $row = array();
        foreach ($__cols as $c) {
          $v = isset($o[$c]) ? $o[$c] : '';
          if (is_array($v) || is_object($v)) $v = '';
          $row[] = $v;
        }
        $__out[] = $row;
      }
      /* الأحدث فاللول (نفس ترتيب الـCRM) */
      usort($__out, function($a, $b) {
        if ($a[1] === $b[1]) return ((float)$b[0] < (float)$a[0]) ? -1 : (((float)$b[0] > (float)$a[0]) ? 1 : 0);
        return strcmp((string)$b[1], (string)$a[1]);
      });
      crm_out(array(
        'ok'   => true,
        't'    => isset($__d['afrizon_orders_v5']['t']) ? (int)$__d['afrizon_orders_v5']['t'] : 0,
        'cols' => $__cols,
        'rows' => $__out,
      ));
    }

    if ($__what === 'stats') {
      /* v3.93: تشخيص — كيعطي الأرقام ديال الطلبيات بلا ما يحمّل الداتا كاملة */
      $__ord = isset($__d['afrizon_orders_v5']['d']) ? crm_unwrap($__d['afrizon_orders_v5']['d']) : array();
      if (!is_array($__ord)) $__ord = array();
      $live = 0; $del = 0; $nodate = 0; $bymonth = array(); $min = ''; $max = '';
      foreach ($__ord as $o) {
        if (!is_array($o)) continue;
        if (!empty($o['_del'])) { $del++; continue; }
        $live++;
        $dt = isset($o['dateCreation']) ? (string)$o['dateCreation'] : '';
        if ($dt === '') { $nodate++; continue; }
        $m = substr($dt, 0, 7);
        $bymonth[$m] = (isset($bymonth[$m]) ? $bymonth[$m] : 0) + 1;
        if ($min === '' || $dt < $min) $min = $dt;
        if ($max === '' || $dt > $max) $max = $dt;
      }
      ksort($bymonth);
      crm_out(array(
        'ok'            => true,
        'apiVersion'    => CRM_API_VERSION,
        'senderGen'     => CRM_DL_SENDER_GEN,
        'total_rows'    => count($__ord),
        'live_orders'   => $live,
        'deleted'       => $del,
        'without_date'  => $nodate,
        'oldest'        => $min,
        'newest'        => $max,
        'by_month'      => $bymonth,
        'data_file_kb'  => round(@filesize($DATA_FILE) / 1024),
        'journal_kb'    => round(@filesize($JOURNAL) / 1024),
        'orders_t'      => isset($__d['afrizon_orders_v5']['t']) ? (int)$__d['afrizon_orders_v5']['t'] : 0,
      ));
    }

    if ($__what === 'csv') {
      /* v3.95: CSV مباشر لـ Google Sheets عبر IMPORTDATA() — بلا Apps Script.
       * الأعمدة بنفس ترتيب ورقة COMONDES: A حتى M. بلا رأس. */
      $__ord = isset($__d['afrizon_orders_v5']['d']) ? crm_unwrap($__d['afrizon_orders_v5']['d']) : array();
      if (!is_array($__ord)) $__ord = array();
      $__c = array('dateCreation','dateConfirmation','statut','remarques','idCmd','nom',
                   'telephone','ville','adresse','qte','prix','produit','livraison');
      /* v3.96: &cols=upsell  ولا  &cols=agent,link  → كيرجع غير داك/هادوك الأعمدة،
       * بنفس ترتيب السطور. مفيد للأعمدة لي جايين بعد M فالشيت. */
      if (!empty($_GET['cols'])) {
        $__allf = array('id','dateCreation','dateConfirmation','statut','remarques','idCmd','nom',
                        'telephone','ville','adresse','qte','prix','produit','livraison','upsell',
                        'carousell','agent','link','carosellFlag','originLead','commission','fees',
                        'livreur','tracking','dateExp','dateLiv','motif');
        $__sel = array();
        foreach (explode(',', (string)$_GET['cols']) as $__r) {
          $__r = trim($__r);
          if ($__r === '') continue;
          foreach ($__allf as $__f) { if (strcasecmp($__f, $__r) === 0) { $__sel[] = $__f; break; } }
        }
        if (!$__sel) crm_out(array('ok'=>false,'err'=>'bad-cols','allowed'=>$__allf), 400);
        $__c = $__sel;
      }
      $__rows = array();
      foreach ($__ord as $o) {
        if (!is_array($o) || !empty($o['_del'])) continue;
        $__rows[] = $o;
      }
      usort($__rows, function($a, $b) {
        $x = isset($a['dateCreation']) ? (string)$a['dateCreation'] : '';
        $y = isset($b['dateCreation']) ? (string)$b['dateCreation'] : '';
        if ($x === $y) {
          $ai = isset($a['id']) ? (float)$a['id'] : 0; $bi = isset($b['id']) ? (float)$b['id'] : 0;
          return ($bi < $ai) ? -1 : (($bi > $ai) ? 1 : 0);
        }
        return strcmp($y, $x);
      });
      $out = '';
      foreach ($__rows as $o) {
        $line = array();
        foreach ($__c as $c) {
          $v = isset($o[$c]) ? $o[$c] : '';
          if (is_array($v) || is_object($v)) $v = '';
          $v = (string)$v;
          /* الهاتف: فراغ رفيع فاللول باش Google Sheets ما يحيدش الصفر */
          /* &plain=1 كيحيد الحماية إلا كان عمود الهاتف معمول "نص عادي" فالشيت */
          if ($c === 'telephone' && $v !== '' && $v[0] === '0' && empty($_GET['plain']))
            $v = "\xE2\x80\x8B" . $v;
          $line[] = '"' . str_replace('"', '""', $v) . '"';
        }
        $out .= implode(',', $line) . "\r\n";
      }
      if (!headers_sent()) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: inline; filename="commandes.csv"');
      }
      echo $out;
      exit;
    }

    crm_out(array('ok'=>false, 'err'=>'unknown-export'), 400);
  }

  $__sig  = crm_data_sig();
  $__inm  = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
  $__hit  = crm_etag_cached($__sig);
  if ($__hit !== null && $__inm !== '' && ($__inm === $__hit || $__inm === 'W/' . $__hit)) {
    header('ETag: ' . $__hit);
    http_response_code(304);
    exit;
  }

  $data = crm_read_raw();
  // normalise on the way out so old corrupted entries never reach the browser
  foreach ($data as $k => $v) {
    if (is_array($v) && array_key_exists('d', $v)) { $data[$k]['d'] = crm_unwrap($v['d']); }
  }
  // v3.83: حصر السجل فـ 400 عملية الأخيرة باش أجهزة المتصفح ما تعمرش وتضرب QuotaExceededError
  if (isset($data['afrizon_history_v1']['d']) && is_array($data['afrizon_history_v1']['d'])) {
    if (count($data['afrizon_history_v1']['d']) > 400) {
      $data['afrizon_history_v1']['d'] = array_slice($data['afrizon_history_v1']['d'], 0, 400);
    }
  }
  unset($data['afrizon_backup_v1']);
  unset($data['afrizon_backup_v1_agents']);

  $__body = json_encode($data, JSON_UNESCAPED_UNICODE);
  $__etag = '"' . md5($__body) . '"';
  crm_etag_store($__sig, $__etag);
  header('ETag: ' . $__etag);
  if ($__inm !== '' && ($__inm === $__etag || $__inm === 'W/' . $__etag)) { http_response_code(304); exit; }

  /* PERF v3.90: gzip — الداتا JSON كتضغط ~85%. 1.1MB → ~150KB. */
  if (!headers_sent() && !ini_get('zlib.output_compression')
      && strpos((string)($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''), 'gzip') !== false
      && function_exists('gzencode')) {
    $__gz = @gzencode($__body, 5);
    if ($__gz !== false) {
      header('Content-Encoding: gzip');
      header('Vary: Accept-Encoding');
      header('Content-Length: ' . strlen($__gz));
      echo $__gz;
      exit;
    }
  }
  header('Content-Length: ' . strlen($__body));
  echo $__body;
  exit;
}

/* ---------- POST ---------- */
if ($m === 'POST') {
  if (!hash_equals($SECRET, crm_token())) crm_out(array('ok'=>false, 'err'=>'token'), 403);

  $raw = file_get_contents('php://input');
  if (strlen($raw) > $MAX_BODY) crm_out(array('ok'=>false, 'err'=>'too-large'), 413);
  $b = json_decode($raw, true);
  if (!is_array($b)) crm_out(array('ok'=>false, 'err'=>'bad-json'), 400);

  /* -- actions (Digylog etc.) -- */
  if (isset($b['action'])) {
    $a = (string)$b['action'];
    if ($a === 'ping') crm_out(array('ok'=>true, 'v'=>'3.83'));
    if ($a === 'restore') crm_out(array('ok'=>false, 'err'=>'restore-not-implemented', 'msg'=>'الاسترجاع كيدار يدوياً من مجلد backups'), 501);
    if ($a === 'sheet_orders') crm_sheet_orders($b);   // v3.97: الشيت → CRM
    if ($a === 'push_subscribe') crm_push_subscribe($b);   // v3.98
    if ($a === 'push_test') crm_push_test($b);             // v3.98
    if ($a === 'quick_order') crm_quick_order($b);         // v3.100: طلبية سريعة من البنت
    if ($a === 'digylog_order_status') crm_dl_order_status($b); // read-only exact Tracking lookup
    if ($a === 'digylog_tracking_auto') crm_out(crm_tracking_auto_guarded());
    if ($a === 'digylog_delivery_auto') crm_out(crm_delivery_auto_guarded());
    if ($a === 'digylog_send_auto') crm_out(crm_dl_send_guarded());
    if ($a === 'digylog_admin') crm_dl_admin($b);          // v3.101: إعدادات Digylog (أدمين)
    if ($a === 'returns_list') crm_returns_list($b);
    if ($a === 'returns_scan') crm_returns_scan($b);
    if ($a === 'returns_control') crm_returns_control($b);
    if ($a === 'returns_restock') crm_returns_restock($b);
    if (strpos($a, 'digylog') === 0) crm_out(array('ok'=>false, 'err'=>'digylog-removed', 'msg'=>'الربط مع Digylog تحيد فـ v3.41'), 410);
    crm_out(array('ok'=>false, 'err'=>'unknown-action'), 400);
  }

  /* -- normal key write -- */
  if (!isset($b['key']) || !array_key_exists('d', $b)) crm_out(array('ok'=>false, 'err'=>'bad-body'), 400);
  $k = (string)$b['key'];
  if (!in_array($k, $ALLOWED_KEYS, true)) { crm_audit("reject | key=$k | not-allowed"); crm_out(array('ok'=>false, 'err'=>'key-not-allowed'), 400); }
  // v3.83: afrizon_backup_v1 ملغية لحماية كاش المتصفح من الامتلاء
  if ($k === 'afrizon_backup_v1' || $k === 'afrizon_backup_v1_agents') {
    // FIX v3.90: $t كانت كتستعمل قبل ما تتعرّف (notice + t=null فالجواب)
    crm_out(array('ok'=>true, 'noop'=>'backup_deprecated', 't'=>(int)(microtime(true) * 1000)));
  }

  $d = crm_unwrap($b['d']);
  $t = isset($b['t']) ? (int)$b['t'] : (int)(microtime(true) * 1000);
  $now = (int)(microtime(true) * 1000);
  if ($t > $now + 60000) $t = $now; // clock skew guard

  // v3.62: reset epoch — writes of wiped keys carrying data older than the reset are ignored
  // v3.67: EXCEPT for reset-aware clients — the body now carries rs (the client's seen reset
  // stamp). A device that acknowledged the reset can never silently lose its NEW orders again,
  // even if its clock is behind (client _u < RESET_T was wrongly discarding genuine new rows).
  $__cur0 = crm_read_raw();
  $RESET_T = isset($__cur0['afrizon_reset_v1']['t']) ? (int)$__cur0['afrizon_reset_v1']['t'] : 0;
  $RS = isset($b['rs']) ? (int)$b['rs'] : 0;
  $RESET_AWARE = ($RESET_T > 0 && $RS >= $RESET_T);
  $RESET_KEYS = array('afrizon_catalog_v1','sheet_pièce','afrizon_history_v1','afrizon_adspend_v1','afrizon_perfrows_v1','afrizon_backup_v1');
  if ($RESET_T > 0 && !$RESET_AWARE && in_array($k, $RESET_KEYS, true) && $t < $RESET_T) { crm_audit("reset-stale | key=$k | t=$t < reset=$RESET_T"); crm_out(array('ok'=>true, 'noop'=>'reset-stale', 'reset'=>$RESET_T, 't'=>$RESET_T)); }
  if ($k === 'afrizon_orders_v5' && $RESET_T > 0 && !$RESET_AWARE && is_array($d)) {
    $__f = array(); foreach ($d as $o) { if (is_array($o) && isset($o['_u']) && (float)$o['_u'] >= $RESET_T) $__f[] = $o; }
    $d = $__f;
  }
  // ghost guard: never let a client wipe orders/users with an empty array while server has data
  /* v3.91: zidt afrizon_adspend_v1 — دابا Google Sheet كيقدر يكتب هنا،
   * وإلا تمسحات الورقة بالغلط (ولا سكريبت طاح فنص الطريق) ما خاصش
   * المصاريف كاملين يطيرو من الـCRM. */
  if (($k === 'afrizon_orders_v5' || $k === 'afrizon_users_v1' || $k === 'afrizon_villes_v2' || $k === 'afrizon_chat_v1' || $k === 'afrizon_catalog_v1' || $k === 'afrizon_adspend_v1' || $k === 'afrizon_backup_v1' || $k === 'afrizon_backup_v1_agents') && is_array($d) && count($d) === 0) {
    $cur = $__cur0;   // PERF v3.90: كنعاودو نستعملو القراية ديال فوق عوض قراية جديدة ديال 2.2MB
    if (isset($cur[$k]['d']) && is_array($cur[$k]['d']) && count($cur[$k]['d']) > 0) {
      crm_audit("ghost | key=$k | empty write blocked");
      crm_out(array('ok'=>true, 'noop'=>'ghost', 't'=>(int)$cur[$k]['t']));
    }
  }

  $fh = @fopen($LOCK_FILE, 'c');
  if ($fh) @flock($fh, LOCK_EX);

  // PERF v3.90: هادي الوحيدة لي خاصها تكون طرية — ممكن شي request آخر كتب
  // قبل ما ناخدو القفل. الجوج القرايات ديال فوق كيتشاركو نفس الـ cache.
  $data = crm_read_raw(true);
  $prevT = isset($data[$k]['t']) ? (int)$data[$k]['t'] : 0;

  $__NOMERGE = array('afrizon_orders_v5','afrizon_chat_v1','afrizon_users_v1','afrizon_agent_names_v1','afrizon_villes_v2','afrizon_worktimes_v1','afrizon_remarques_v1','afrizon_avances_v1','afrizon_adspend_v1','afrizon_perfrows_v1','afrizon_livraison_v1','afrizon_history_v1','afrizon_catalog_v1','tabs_list_v1','sheet_pièce');
  if ($t < $prevT && !in_array($k, $__NOMERGE, true)) { // stale write — غير على ليستة ما كيندمجوش
    if ($fh) { @flock($fh, LOCK_UN); @fclose($fh); }
    crm_audit("stale | key=$k | t=$t < $prevT");
    crm_out(array('ok'=>true, 'noop'=>'stale', 't'=>$prevT));
  }
  $prevJson = isset($data[$k]['d']) ? json_encode($data[$k]['d'], JSON_UNESCAPED_UNICODE) : null;
  $newJson  = json_encode($d, JSON_UNESCAPED_UNICODE);
  if ($prevJson === $newJson) {          // nothing changed: touch time only, no backup churn
    /* v3.81: t ما كينقصش عمرو — قبل، جهاز ساعتو متأخرة كان كيرجّع t للور
       → الأجهزة الأخرى كيوقفو يشوفو التبدلات (i.t > c كتفشل ديما). */
    $effT = max($prevT, $t);
    $data[$k]['t'] = $effT;
    crm_write_all($data);
    if ($fh) { @flock($fh, LOCK_UN); @fclose($fh); }
    crm_out(array('ok'=>true, 'noop'=>'same', 't'=>$effT));
  }

  crm_backup();
  // v3.45: orders are merged row-by-row (newest _u wins, rows never dropped) so
  // several agents/admins writing at the same time never erase each other.
  if ($k === 'afrizon_orders_v5' && is_array($d)) {
    $__storedO = (isset($data[$k]['d']) && is_array($data[$k]['d'])) ? crm_unwrap($data[$k]['d']) : null;   // v3.81: unwrap قبل الدمج
    if (is_array($__storedO)) $d = crm_merge_orders($__storedO, $d, $RESET_T);
    // PERF v3.90: تنقية الطلبيات الممسوحة القديمة (أكثر من 30 يوم) — كانو 42% من الداتا
    $d = crm_purge_tombstones($d, $TOMBSTONE_TTL, $TOMBSTONE_MAX);
  }
  // v3.72: الرسائل كيتدمجو union بالـ id (ما كاينش مسح فالشات) — كاتب متزامنين ما يضيعوش رسائل
  if ($k === 'afrizon_chat_v1' && is_array($d)) {
    $__storedC = (isset($data[$k]['d']) && is_array($data[$k]['d'])) ? crm_unwrap($data[$k]['d']) : null;   // v3.81: unwrap قبل الدمج
    if (!is_array($__storedC)) $__storedC = array();
    $byId = array();
    foreach ($__storedC as $m) { if (is_array($m) && isset($m['id'])) $byId[(string)$m['id']] = $m; }
    foreach ($d as $m) {
      if (!is_array($m) || !isset($m['id'])) continue;
      $id = (string)$m['id'];
      if (isset($byId[$id])) { $byId[$id]['read'] = !empty($byId[$id]['read']) || !empty($m['read']); }
      else $byId[$id] = $m;
    }
    usort($byId, function($x, $y) { $a = (float)$x['id']; $b = (float)$y['id']; return $a == $b ? 0 : ($a < $b ? -1 : 1); });
    $d = array_values($byId);
  }
  // v3.79: اليوزرز — دمج بالصفوف (نفس حل الطلبيات): _u الأحدث كيربح، الحذف _del ناعم.
  // حتى دفع قديم ولا ساعة متقدمة ما بقاش يقدر يمسح يوزر مضاف.
  // v3.81: unwrap على الداتا المخزنة قبل الدمج — غلاف مزدوج قديم كان كيخلي
  // الدمج كيبدا من ليستة خاوية → اليوزرز كلهم كيتمسحو بلا ما حد يحس.
  if ($k === 'afrizon_users_v1' && is_array($d)) {
    $__storedU = (isset($data[$k]['d']) && is_array($data[$k]['d'])) ? crm_unwrap($data[$k]['d']) : null;
    if (is_array($__storedU)) {
      $uById = array();
      foreach ($__storedU as $u) { if (is_array($u) && isset($u['id'])) $uById[(string)$u['id']] = $u; }
      $nowms2 = (int)(microtime(true) * 1000);
      foreach ($d as $u) {
        if (!is_array($u) || !isset($u['id'])) continue;
        if (isset($u['_u']) && (float)$u['_u'] > $nowms2) $u['_u'] = $nowms2;
        $uid = (string)$u['id'];
        if (!isset($uById[$uid])) {
          /* v3.81: نفس الاسم ما كيتزادش مرتين بجوج ids — جوج أجهزة زادو نفس
             اليوزر فنفس الوقت كانو كيديرو دوبلون. اللي كاين فالسيرفر كيبقى. */
          $un = (isset($u['username']) && is_string($u['username'])) ? strtolower(trim($u['username'])) : '';
          $dup = false;
          if ($un !== '') { foreach ($uById as $su) { if (empty($su['_del']) && isset($su['username']) && is_string($su['username']) && strtolower(trim((string)$su['username'])) === $un) { $dup = true; break; } } }
          if (!$dup) $uById[$uid] = $u;
          continue;
        }
        /* v3.82: الحذف نهائي (من الجوج جيهات): نسخة فيها _del كتربح دايماً،
           حتى إلا كانت _u ديالها أقدم — يوزر ممسوح ما كيرجعش. */
        $sd = !empty($uById[$uid]['_del']); $id2 = !empty($u['_del']);
        if ($sd || $id2) {
          $cu = isset($uById[$uid]['_u']) ? (float)$uById[$uid]['_u'] : 0;
          $nu = isset($u['_u']) ? (float)$u['_u'] : 0;
          if (!$sd || ($id2 && $nu > $cu)) $uById[$uid] = $u;
          continue;
        }
        $cu = isset($uById[$uid]['_u']) ? (float)$uById[$uid]['_u'] : 0;
        $nu = isset($u['_u']) ? (float)$u['_u'] : 0;
        if ($nu > $cu) $uById[$uid] = $u;
      }
      $d = array_values($uById);
    }
  }
  // v3.78: دمج موحد — الوكلاء/المدن/غيرهم
  if (in_array($k, $__NOMERGE, true) && $k !== 'afrizon_orders_v5' && $k !== 'afrizon_chat_v1' && $k !== 'afrizon_users_v1'
      && is_array($d)) {
    $__storedL = (isset($data[$k]['d']) && is_array($data[$k]['d'])) ? crm_unwrap($data[$k]['d']) : null;    // v3.81: unwrap قبل الدمج
    if (is_array($__storedL)) $d = crm_merge_list($__storedL, $d, $prevT, $t);
  }
  // v3.83: حصر السجل فـ 400 عملية الأخيرة كحد أقصى
  if ($k === 'afrizon_history_v1' && is_array($d)) {
    usort($d, function($x, $y) {
      $atX = (is_array($x) && isset($x['at'])) ? (string)$x['at'] : '';
      $atY = (is_array($y) && isset($y['at'])) ? (string)$y['at'] : '';
      $c = strcmp($atY, $atX);
      if ($c !== 0) return $c;
      $idX = (is_array($x) && isset($x['id'])) ? (float)$x['id'] : 0;
      $idY = (is_array($y) && isset($y['id'])) ? (float)$y['id'] : 0;
      return $idX == $idY ? 0 : ($idX < $idY ? 1 : -1);
    });
    if (count($d) > 400) {
      $d = array_slice($d, 0, 400);
    }
  }
  $pushBy = ($k === 'afrizon_orders_v5' && is_array($d)) ? crm_push_collect_changes(isset($data[$k]['d']) ? $data[$k]['d'] : array(), $d, $now) : array();
  $data[$k] = array('t' => max($prevT + 1, $t), 'd' => $d);   // v3.81: t رتيب ديما — كتابة جديدة كيشوفها كلشي حتى من جهاز ساعتو متأخرة
  $effT = $data[$k]['t'];
  $ok = crm_write_all($data);
  if ($ok && is_array($d)) crm_journal_append($k, $effT, $d);
  if ($fh) { @flock($fh, LOCK_UN); @fclose($fh); }

  if (!$ok) crm_out(array('ok'=>false, 'err'=>'write-failed'), 500);
  crm_audit("write | $k | t=$effT | bytes=" . strlen($newJson));
  if (!empty($pushBy)) { try { crm_push_batch($pushBy); } catch (Throwable $__e) { crm_audit('push-error | ' . $__e->getMessage()); } }   // v3.99
  crm_out(array('ok'=>true, 't'=>$effT));
}

http_response_code(405);
echo '{"ok":false,"err":"method-not-allowed"}';
exit;