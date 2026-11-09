<?php
/**
 * AfrizoN CRM — recover.php (v3.67)
 * أداة استرجاع الطلبيات من الباكابات + معاينة شنو كاين دابا
 *
 * الاستعمال:  https://الموقع/public_html/recover.php?token=SECRET
 *   - صفحة عربية كتبين: شحال ديال الطلبيات دابا + كل الباكابات المتوفرين
 *   - زر "دمج" لكل باكاب: كيزيد غير الطلبيات الناقصة (ما كيتمسح والو، ما كيبدل والو)
 *   - زر "📱 من هاد الجهاز": كيجيب النسخة المخبية فمتصفح الجهاز (كيوجدو البنات!)
 *
 * الأمان: نفس السر ديال api.php + كل عملية كتسجل فـ audit.log
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');

$SECRET = '8c907fc0f4ffe0b9775a6b7c3c0fc7700e5724c0d78343df';

/* ---------- paths (نفس منطق api.php v3.67: بلايص رئيسية + مرايا) ---------- */
$DIR_EXT = __DIR__ . '/../crm-afrizon-data';
$DIR_LOC = __DIR__;
function rc_epoch_dir($d) {
  $m = 0;
  $s = @file_get_contents($d . '/crm_data.json');
  if ($s !== false && $s !== '') {
    $j = json_decode($s, true);
    if (is_array($j)) { foreach ($j as $v) { if (is_array($v) && isset($v['t']) && (int)$v['t'] > $m) $m = (int)$v['t']; } }
    else { $m = -1; }
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
if (!is_dir($DIR_EXT)) { $PRIMARY = $DIR_LOC; $MIRROR = null; }
else {
  $eE = rc_epoch_dir($DIR_EXT); $eL = rc_epoch_dir($DIR_LOC);
  if ($eL > $eE) { $PRIMARY = $DIR_LOC; $MIRROR = $DIR_EXT; }
}
$DATA_FILE   = $PRIMARY . '/crm_data.json';
$MIRROR_FILE = ($MIRROR !== null) ? $MIRROR . '/crm_data.json' : null;
$BACKUP_DIR  = $PRIMARY . '/backups';
$AUDIT_FILE  = $PRIMARY . '/audit.log';
$JOURNAL     = $PRIMARY . '/journal.log';
$JOURNAL_M   = ($MIRROR !== null) ? $MIRROR . '/journal.log' : null;

/* ---------- helpers ---------- */
function rc_token() {
  if (isset($_SERVER['HTTP_X_SYNC_TOKEN'])) return (string)$_SERVER['HTTP_X_SYNC_TOKEN'];
  if (isset($_GET['token'])) return (string)$_GET['token'];
  return '';
}
function rc_out($arr, $code = 200) {
  if ($code !== 200) http_response_code($code);
  echo json_encode($arr, JSON_UNESCAPED_UNICODE);
  exit;
}
function rc_audit($line) {
  global $AUDIT_FILE;
  $ip = $_SERVER['REMOTE_ADDR'] ?? '-';
  @file_put_contents($AUDIT_FILE, date('Y-m-d H:i:s') . " | $ip | recover | $line\n", FILE_APPEND | LOCK_EX);
}
function rc_unwrap($v) {
  $g = 0;
  while (is_array($v) && isset($v['t']) && array_key_exists('d', $v) && is_numeric($v['t']) && count($v) <= 2 && $g++ < 5) { $v = $v['d']; }
  return $v;
}
function rc_journal_replay($data) {
  global $JOURNAL, $JOURNAL_M;
  $files = array(($JOURNAL !== null ? $JOURNAL . '.1' : null), $JOURNAL, ($JOURNAL_M !== null ? $JOURNAL_M . '.1' : null), $JOURNAL_M);
  foreach ($files as $jf) {
    if (!$jf || !file_exists($jf)) continue;
    $fh = @fopen($jf, 'r'); if (!$fh) continue;
    while (($l = fgets($fh)) !== false) {
      $l = trim($l); if ($l === '') continue;
      $e = json_decode($l, true);
      if (!is_array($e) || !isset($e['k'], $e['t'], $e['d']) || !is_array($e['d'])) continue;
      $k = (string)$e['k']; $et = (int)$e['t'];
      if (!isset($data[$k]) || !is_array($data[$k]) || (int)$data[$k]['t'] < $et) $data[$k] = array('t' => $et, 'd' => $e['d']);
    }
    @fclose($fh);
  }
  return $data;
}
function rc_read() {
  global $DATA_FILE, $BACKUP_DIR, $MIRROR_FILE;
  $j = null;
  if (file_exists($DATA_FILE)) {
    $j = json_decode((string)@file_get_contents($DATA_FILE), true);
  }
  if (!is_array($j)) {
    $c = glob($BACKUP_DIR . '/b-*.json');
    if ($c) { rsort($c); foreach ($c as $f) { $j = json_decode((string)@file_get_contents($f), true); if (is_array($j)) break; } }
  }
  if (!is_array($j)) $j = array();
  /* v3.81: دمج الميرا مفتاح بمفتاح (الأحدث كيربح) — ملف قديم فـ الميرا
     ما بقاش كيخبي الطلبيات الجديدة (نفس إصلاح api.php). */
  if ($MIRROR_FILE && is_file($MIRROR_FILE)) {
    $m = json_decode((string)@file_get_contents($MIRROR_FILE), true);
    if (is_array($m)) {
      foreach ($m as $mk => $mv) {
        if (!is_array($mv) || !isset($mv['t'])) continue;
        if (!isset($j[$mk]) || !is_array($j[$mk]) || (int)$mv['t'] > (int)$j[$mk]['t']) $j[$mk] = $mv;
      }
    }
  }
  return rc_journal_replay($j);
}
function rc_write_both($data) {
  global $DATA_FILE, $MIRROR_FILE;
  $json = json_encode($data, JSON_UNESCAPED_UNICODE);
  if ($json === false) return false;
  $tmp = $DATA_FILE . '.tmp.rc.' . getmypid();
  $ok = (@file_put_contents($tmp, $json, LOCK_EX) !== false) && @rename($tmp, $DATA_FILE);
  if (!$ok && file_exists($tmp)) @unlink($tmp);
  if ($MIRROR_FILE) {
    $tmp2 = $MIRROR_FILE . '.tmp.rc.' . getmypid();
    if (@file_put_contents($tmp2, $json, LOCK_EX) !== false) { @rename($tmp2, $MIRROR_FILE); }
    elseif (file_exists($tmp2)) @unlink($tmp2);
  }
  return $ok;
}
function rc_journal_append($k, $t, $d) {
  global $JOURNAL, $JOURNAL_M;
  $line = json_encode(array('k' => $k, 't' => $t, 'd' => $d), JSON_UNESCAPED_UNICODE);
  if ($line === false) return;
  foreach (array($JOURNAL, $JOURNAL_M) as $jf) { if ($jf) @file_put_contents($jf, $line . "\n", FILE_APPEND | LOCK_EX); }
}
function rc_orders_of($data) {
  if (!is_array($data)) return array();
  if (!isset($data['afrizon_orders_v5'])) return array();
  $d = rc_unwrap($data['afrizon_orders_v5']);
  return is_array($d) ? $d : array();
}
function rc_stats($orders) {
  $total = 0; $byMonth = array(); $minD = ''; $maxD = ''; $lastU = 0;
  foreach ($orders as $o) {
    if (!is_array($o) || !empty($o['_del'])) continue;
    $total++;
    $dc = substr((string)($o['dateCreation'] ?? ''), 0, 7);
    if ($dc !== '') { $byMonth[$dc] = (isset($byMonth[$dc]) ? $byMonth[$dc] : 0) + 1; }
    $d10 = substr((string)($o['dateCreation'] ?? ''), 0, 10);
    if ($d10 !== '') { if ($minD === '' || $d10 < $minD) $minD = $d10; if ($maxD === '' || $d10 > $maxD) $maxD = $d10; }
    $u = isset($o['_u']) ? (float)$o['_u'] : 0;
    if ($u > $lastU) $lastU = $u;
  }
  krsort($byMonth);
  return array('total' => $total, 'byMonth' => $byMonth, 'minDate' => $minD, 'maxDate' => $maxD,
               'lastActivity' => $lastU > 0 ? date('Y-m-d H:i', (int)round($lastU / 1000)) : '');
}
/* الطلبيات الناقصة لي غادي تزاد من مصدر (باكاب ولا جهاز) */
function rc_missing($curOrders, $srcOrders) {
  $have = array();
  foreach ($curOrders as $o) { if (is_array($o) && isset($o['id'])) $have[(string)$o['id']] = 1; }
  $seen = array(); $out = array();
  foreach ($srcOrders as $o) {
    if (!is_array($o) || !isset($o['id'])) continue;
    $id = (string)$o['id'];
    if (isset($have[$id]) || isset($seen[$id])) continue;
    if (!empty($o['_del'])) continue; // اللي مسحتهم يدويا ما كنرجعوهمش
    $seen[$id] = 1; $out[] = $o;
  }
  return $out;
}
/* دمج إضافي آمن: كيزيد غير الناقص، كيختم التواريخ باش يتحميو، كيكتب الرئيسي + المرايا + journal */
function rc_restore_missing($srcOrders, $label) {
  $data = rc_read();
  $cur = rc_orders_of($data);
  $missing = rc_missing($cur, $srcOrders);
  if (!count($missing)) return array('added' => 0, 'total' => count(array_filter($cur, function($o){ return is_array($o) && empty($o['_del']); })));
  $now = (int)(microtime(true) * 1000);
  $i = 0;
  foreach ($missing as $o) {
    $i++;
    $r = $o;
    $r['_u'] = $now + $i; // ختم طازج → كيدوز فلاتر reset وكيربح أي نسخة قديمة
    $f = (isset($r['_f']) && is_array($r['_f'])) ? $r['_f'] : array();
    foreach (array('dateCreation','dateConfirmation','dateExp','dateLiv') as $dk) {
      if (!empty($r[$dk])) $f[$dk] = $now + $i; // قفل التاريخ: ما يتبدلش من بعد بالكاش القديم
    }
    if (count($f)) $r['_f'] = $f;
    unset($r['_d']);
    $cur[] = $r;
  }
  usort($cur, function($x, $y) { $a = (float)$x['id']; $b = (float)$y['id']; return $a == $b ? 0 : ($a < $b ? 1 : -1); });
  $data['afrizon_orders_v5'] = array('t' => $now, 'd' => $cur);
  if (!rc_write_both($data)) rc_out(array('ok' => false, 'err' => 'write-failed'), 500);
  rc_journal_append('afrizon_orders_v5', $now, $cur);
  rc_audit("restore-additive | $label | added=" . count($missing));
  $alive = array_filter($cur, function($o){ return is_array($o) && empty($o['_del']); });
  return array('added' => count($missing), 'total' => count($alive));
}

/* ---------- auth ---------- */
$TOK = rc_token();
if (!hash_equals($SECRET, $TOK)) {
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') rc_out(array('ok' => false, 'err' => 'token'), 403);
  http_response_code(403);
  echo '<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><body style="font-family:sans-serif;text-align:center;padding:40px">🔒 زيد <code>?token=...</code> فـ الرابط (نفس السر ديال api.php)</body></html>';
  exit;
}

/* ---------- download ---------- */
if (isset($_GET['download'])) {
  $f = basename((string)$_GET['download']);
  if (!preg_match('/^(b|d)-[\w.-]+\.json$/', $f)) rc_out(array('ok' => false, 'err' => 'bad-name'), 400);
  $p = $BACKUP_DIR . '/' . $f;
  if (!file_exists($p)) rc_out(array('ok' => false, 'err' => 'not-found'), 404);
  header('Content-Type: application/json; charset=utf-8');
  header('Content-Disposition: attachment; filename="' . $f . '"');
  echo (string)@file_get_contents($p);
  exit;
}

/* ---------- API (POST) ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  $raw = file_get_contents('php://input');
  $b = json_decode($raw, true);
  if (!is_array($b)) rc_out(array('ok' => false, 'err' => 'bad-json'), 400);
  $a = (string)($b['action'] ?? '');

  if ($a === 'stats') {
    $data = rc_read();
    $cur = rc_orders_of($data);
    $backups = array();
    if (is_dir($BACKUP_DIR)) {
      $files = array_merge(glob($BACKUP_DIR . '/b-*.json') ?: array(), glob($BACKUP_DIR . '/d-*.json') ?: array());
      rsort($files);
      foreach (array_slice($files, 0, 40) as $f) {
        $j = json_decode((string)@file_get_contents($f), true);
        $o = is_array($j) ? rc_orders_of($j) : array();
        $st = rc_stats($o);
        $backups[] = array('file' => basename($f), 'orders' => $st['total'],
          'range' => trim($st['minDate'] . ' → ' . $st['maxDate'], ' →'),
          'mtime' => date('Y-m-d H:i', (int)@filemtime($f)), 'size' => (int)@filesize($f));
      }
    }
    rc_out(array('ok' => true, 'stats' => rc_stats($cur), 'backups' => $backups, 'primary' => basename(dirname($DATA_FILE))));
  }

  if ($a === 'restore' && isset($b['file'])) {
    $f = basename((string)$b['file']);
    if (!preg_match('/^(b|d)-[\w.-]+\.json$/', $f)) rc_out(array('ok' => false, 'err' => 'bad-name'), 400);
    $p = $BACKUP_DIR . '/' . $f;
    if (!file_exists($p)) rc_out(array('ok' => false, 'err' => 'not-found'), 404);
    $j = json_decode((string)@file_get_contents($p), true);
    $src = is_array($j) ? rc_orders_of($j) : array();
    rc_out(array('ok' => true) + rc_restore_missing($src, 'backup:' . $f));
  }

  if ($a === 'device-push' && isset($b['orders']) && is_array($b['orders'])) {
    rc_out(array('ok' => true) + rc_restore_missing($b['orders'], 'device:' . ($_SERVER['REMOTE_ADDR'] ?? '-')));
  }

  rc_out(array('ok' => false, 'err' => 'unknown-action'), 400);
}
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>استرجاع الطلبيات — AfrizoN CRM</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',Tahoma,sans-serif;background:#0f172a;color:#e2e8f0;min-height:100vh;padding:20px}
.wrap{max-width:820px;margin:0 auto}
h1{font-size:22px;margin-bottom:4px}
.sub{color:#94a3b8;font-size:13px;margin-bottom:18px}
.card{background:#1e293b;border:1px solid #334155;border-radius:14px;padding:16px;margin-bottom:14px}
.big{font-size:34px;font-weight:800;color:#34d399}
.row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.months{display:flex;gap:6px;flex-wrap:wrap;margin-top:10px}
.chip{background:#334155;border-radius:8px;padding:4px 10px;font-size:12px}
.bk{display:flex;justify-content:space-between;gap:10px;align-items:center;border-top:1px solid #334155;padding:10px 0;flex-wrap:wrap}
.bk:first-of-type{border-top:0}
button{cursor:pointer;border:0;border-radius:10px;padding:9px 16px;font-weight:700;font-size:13px;font-family:inherit}
.go{background:#059669;color:#fff}
.go:hover{background:#047857}
.dev{background:#7c3aed;color:#fff;width:100%;padding:14px;font-size:15px}
.dl{background:#334155;color:#cbd5e1;text-decoration:none;padding:8px 12px;border-radius:10px;font-size:12px}
.mut{color:#94a3b8;font-size:12px}
#msg{position:fixed;bottom:14px;left:14px;right:14px;background:#059669;color:#fff;padding:12px 16px;border-radius:12px;font-weight:700;text-align:center;display:none;z-index:9}
.warn{background:#7c2d12;border:1px solid #ea580c;border-radius:10px;padding:10px 14px;font-size:13px;margin-bottom:14px}
</style>
</head>
<body>
<div class="wrap">
  <h1>🛟 استرجاع الطلبيات — AfrizoN CRM</h1>
  <div class="sub">الأداة كتزيد غير الطلبيات الناقصة — عمرها ما كتمسح ولا كتبدل اللي كاينين دابا</div>
  <div class="warn">⚠️ أهم خطوة: كل جهاز ديال البنات خاصو يفتح هاد الصفحة مرة وحدة ويضغط الزر البنفسجي — كل جهاز مخبي نسخة ديال الطلبيات فمتصفحو!</div>

  <div class="card">
    <div class="row" style="justify-content:space-between">
      <div><div class="big" id="total">…</div><div class="mut">الطلبيات كاينين دابا</div></div>
      <div style="text-align:left" class="mut" id="meta"></div>
    </div>
    <div class="months" id="months"></div>
  </div>

  <div class="card">
    <div style="margin-bottom:8px;font-weight:700">📱 رجّع الطلبيات من هاد الجهاز</div>
    <div class="mut" style="margin-bottom:10px">المتصفح ديال كل جهاز مخبي نسخة أوتوماتيك (حتى الطلبيات لي "تمسحو") — هاد الزر كيرجعهم للسيرفر بضغطة وحدة.</div>
    <button class="dev" onclick="devicePush()">📤 رجّع نسخة هاد الجهاز للسيرفر</button>
    <div class="mut" id="devInfo" style="margin-top:8px"></div>
  </div>

  <div class="card">
    <div style="margin-bottom:8px;font-weight:700">💾 الباكابات ديال السيرفر (الأحدث فوق)</div>
    <div id="bks" class="mut">جاري التحميل…</div>
  </div>
</div>
<div id="msg"></div>
<script>
const TOK = new URLSearchParams(location.search).get('token') || '';
const api = (body) => fetch('recover.php?token=' + encodeURIComponent(TOK), {
  method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body)
}).then(r => r.json());
function msg(t, bad){ const m = document.getElementById('msg'); m.textContent = t; m.style.display = 'block';
  m.style.background = bad ? '#b91c1c' : '#059669'; setTimeout(() => m.style.display = 'none', 6000); }
function refresh(){
  api({action:'stats'}).then(r => {
    if (!r.ok) return msg('خطأ فالتحميل', true);
    document.getElementById('total').textContent = r.stats.total;
    document.getElementById('meta').innerHTML = 'أقدم: ' + (r.stats.minDate || '—') + '<br>أحدث: ' + (r.stats.maxDate || '—') + '<br>آخر نشاط: ' + (r.stats.lastActivity || '—');
    const mo = document.getElementById('months');
    mo.innerHTML = Object.entries(r.stats.byMonth).slice(0, 12).map(([m, c]) => `<span class="chip">${m}: <b>${c}</b></span>`).join('') || '<span class="chip">ما كاين والو</span>';
    const el = document.getElementById('bks');
    if (!r.backups.length) { el.textContent = 'ما كاينش باكابات فالسيرفر (backups/)'; return; }
    el.innerHTML = r.backups.map(b =>
      `<div class="bk"><div><b>${b.file}</b><br><span class="mut">${b.mtime} · ${b.orders} طلبية · ${(b.size/1024).toFixed(0)}KB · ${b.range || '—'}</span></div>
       <div class="row"><a class="dl" href="recover.php?token=${encodeURIComponent(TOK)}&download=${encodeURIComponent(b.file)}">⬇️ تحميل</a>
       <button class="go" onclick="restore('${b.file}')">♻️ دمج الناقص</button></div></div>`).join('');
  }).catch(() => msg('السيرفر ما جاوبش', true));
}
function restore(f){
  if (!confirm('نزيد الطلبيات الناقصة من ' + f + ' ؟\n(ما كيتمسح والو — غير الزيادة)')) return;
  api({action:'restore', file:f}).then(r => {
    if (!r.ok) return msg('فشل: ' + (r.err || '?'), true);
    msg(r.added ? '✅ تزادت ' + r.added + ' طلبية — المجموع دابا ' + r.total : 'ما كاين حتى طلبية ناقصة فهاد النسخة');
    refresh();
  }).catch(() => msg('السيرفر ما جاوبش', true));
}
function devicePush(){
  let rows = [];
  try { const a = JSON.parse(localStorage.getItem('afrizon_backup_v1') || 'null'); if (Array.isArray(a)) rows = rows.concat(a); } catch {}
  try { const b = JSON.parse(localStorage.getItem('afrizon_orders_v5') || 'null'); if (Array.isArray(b)) rows = rows.concat(b); } catch {}
  document.getElementById('devInfo').textContent = 'لقيت فهاد الجهاز: ' + rows.length + ' سجل — جاري الإرسال…';
  if (!rows.length) return msg('هاد الجهاز ما عندوش نسخة فالمتصفح', true);
  api({action:'device-push', orders: rows}).then(r => {
    if (!r.ok) return msg('فشل: ' + (r.err || '?'), true);
    document.getElementById('devInfo').textContent = 'تزادو ' + r.added + ' طلبية جديدة من هاد الجهاز';
    msg(r.added ? '✅ تزادت ' + r.added + ' طلبية من هاد الجهاز — المجموع ' + r.total : 'كولشي ديجا كاين فالسيرفر');
    refresh();
  }).catch(() => msg('السيرفر ما جاوبش', true));
}
refresh();
</script>
</body>
</html>
