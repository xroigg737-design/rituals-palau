<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$file = __DIR__ . '/data/log.jsonl';
if (!file_exists(dirname($file))) mkdir(dirname($file), 0777, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['ev'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing ev field']);
        exit;
    }
    $entry = [
        'ts' => gmdate('c'),
        'ev' => substr($input['ev'], 0, 30),
    ];
    if (isset($input['u'])) $entry['u'] = substr($input['u'], 0, 20);
    if (isset($input['cid'])) $entry['cid'] = substr($input['cid'], 0, 20);
    if (isset($input['sec'])) $entry['sec'] = substr($input['sec'], 0, 30);
    if (isset($input['v'])) $entry['v'] = intval($input['v']);
    if (isset($input['q'])) $entry['q'] = substr($input['q'], 0, 100);

    $fp = fopen($file, 'a');
    flock($fp, LOCK_EX);
    fwrite($fp, json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n");
    flock($fp, LOCK_UN);
    fclose($fp);
    echo json_encode(['ok' => true]);

} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {

    if (isset($_GET['dashboard'])) {
        header('Content-Type: text/html; charset=utf-8');
        serveDashboard($file);
        exit;
    }

    header('Content-Type: application/json');
    if (!file_exists($file)) { echo '[]'; exit; }

    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $entries = array_map('json_decode', $lines);
    $entries = array_filter($entries);

    $days = isset($_GET['days']) ? intval($_GET['days']) : 30;
    $cutoff = gmdate('c', strtotime("-{$days} days"));
    $entries = array_values(array_filter($entries, fn($e) => ($e->ts ?? '') >= $cutoff));

    if (isset($_GET['raw'])) {
        echo json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    $stats = computeStats($entries);
    echo json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

function computeStats(array $entries): array {
    $byUser = [];
    $byEvent = [];
    $byDay = [];
    $byConcert = [];
    $bySection = [];
    $sessions = [];
    $searches = [];

    foreach ($entries as $e) {
        $ev = $e->ev ?? '';
        $u = $e->u ?? 'anon';
        $day = substr($e->ts ?? '', 0, 10);

        $byEvent[$ev] = ($byEvent[$ev] ?? 0) + 1;
        if ($day) $byDay[$day] = ($byDay[$day] ?? 0) + 1;

        if ($u !== 'anon') {
            if (!isset($byUser[$u])) $byUser[$u] = ['visits' => 0, 'votes' => 0, 'last' => ''];
            if ($ev === 'page_view') $byUser[$u]['visits']++;
            if ($ev === 'vote') $byUser[$u]['votes']++;
            if (($e->ts ?? '') > $byUser[$u]['last']) $byUser[$u]['last'] = $e->ts ?? '';
        }

        if ($ev === 'vote' && isset($e->cid)) {
            $byConcert[$e->cid] = ($byConcert[$e->cid] ?? 0) + 1;
        }
        if ($ev === 'section_view' && isset($e->sec)) {
            $bySection[$e->sec] = ($bySection[$e->sec] ?? 0) + 1;
        }
        if ($ev === 'search' && isset($e->q)) {
            $searches[] = $e->q;
        }
    }

    ksort($byDay);
    arsort($byEvent);
    arsort($byConcert);
    arsort($bySection);

    return [
        'total_events' => count($entries),
        'by_event' => $byEvent,
        'by_user' => $byUser,
        'by_day' => $byDay,
        'top_concerts' => array_slice($byConcert, 0, 20, true),
        'top_sections' => $bySection,
        'recent_searches' => array_slice(array_reverse($searches), 0, 20),
    ];
}

function serveDashboard(string $file): void {
    $entries = [];
    if (file_exists($file)) {
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $entries = array_values(array_filter(array_map('json_decode', $lines)));
    }
    $stats = computeStats($entries);
    $json = json_encode($stats, JSON_UNESCAPED_UNICODE);
    $totalEntries = count($entries);

    $recentActivity = [];
    $recent = array_slice(array_reverse($entries), 0, 50);
    foreach ($recent as $e) {
        $recentActivity[] = [
            'ts' => $e->ts ?? '',
            'ev' => $e->ev ?? '',
            'u' => $e->u ?? 'anon',
            'detail' => $e->cid ?? $e->sec ?? $e->q ?? '',
        ];
    }
    $recentJson = json_encode($recentActivity, JSON_UNESCAPED_UNICODE);

    $accessLog = [];
    foreach ($entries as $e) {
        if (($e->ev ?? '') === 'page_view') {
            $ts = $e->ts ?? '';
            $accessLog[] = ['ts' => $ts, 'u' => $e->u ?? 'anon'];
        }
    }
    usort($accessLog, fn($a, $b) => $b['ts'] <=> $a['ts']);
    $accessJson = json_encode($accessLog, JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="ca">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Rituals Palau – Analytics</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,system-ui,sans-serif;background:#0a0a0f;color:#e0ddd5;padding:1.5rem;max-width:1100px;margin:0 auto}
h1{font-size:1.4rem;color:#d4a853;margin-bottom:.3rem;letter-spacing:.05em}
.sub{font-size:.75rem;color:#888;margin-bottom:1.5rem}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;margin-bottom:1.5rem}
.card{background:#15151f;border:1px solid #252530;border-radius:10px;padding:1.2rem}
.card h3{font-size:.65rem;text-transform:uppercase;letter-spacing:.1em;color:#888;margin-bottom:.5rem}
.card .num{font-size:2rem;font-weight:700;color:#d4a853}
.card .detail{font-size:.75rem;color:#aaa;margin-top:.3rem}
table{width:100%;border-collapse:collapse;margin-bottom:1.5rem}
th{text-align:left;font-size:.65rem;text-transform:uppercase;letter-spacing:.1em;color:#888;padding:.5rem .8rem;border-bottom:1px solid #252530}
td{padding:.5rem .8rem;font-size:.8rem;border-bottom:1px solid #151520}
tr:hover{background:#151520}
.tag{display:inline-block;padding:.15rem .5rem;border-radius:4px;font-size:.65rem;font-weight:600}
.tag-pv{background:#1a3a2a;color:#4ade80}
.tag-vote{background:#3a2a1a;color:#d4a853}
.tag-sec{background:#1a2a3a;color:#60a5fa}
.tag-search{background:#2a1a3a;color:#c084fc}
.tag-login{background:#3a1a2a;color:#fb7185}
.tag-other{background:#252530;color:#aaa}
.chart{height:120px;display:flex;align-items:flex-end;gap:2px;margin-top:.5rem}
.bar{background:#d4a853;border-radius:2px 2px 0 0;min-width:4px;flex:1;position:relative;cursor:default}
.bar:hover::after{content:attr(data-tip);position:absolute;bottom:100%;left:50%;transform:translateX(-50%);background:#000;color:#fff;padding:2px 6px;border-radius:4px;font-size:.6rem;white-space:nowrap}
.section{margin-bottom:1.5rem}
.section h2{font-size:.9rem;color:#d4a853;margin-bottom:.8rem;padding-bottom:.4rem;border-bottom:1px solid #252530}
.empty{color:#555;font-style:italic;font-size:.8rem;padding:1rem}
a.back{color:#d4a853;text-decoration:none;font-size:.8rem}
a.back:hover{text-decoration:underline}
.refresh{float:right;background:#252530;color:#d4a853;border:none;padding:.4rem .8rem;border-radius:6px;cursor:pointer;font-size:.7rem}
.refresh:hover{background:#353540}
.day-group{margin-bottom:1rem}
.day-header{font-size:.75rem;font-weight:700;color:#d4a853;padding:.5rem .8rem;background:#12121a;border-radius:6px;margin-bottom:.3rem;display:flex;justify-content:space-between;align-items:center}
.day-header .count{font-size:.65rem;font-weight:400;color:#888;background:#1a1a25;padding:.15rem .5rem;border-radius:4px}
.access-row{display:flex;align-items:center;gap:.8rem;padding:.35rem .8rem;font-size:.78rem;border-bottom:1px solid #151520}
.access-row:hover{background:#151520}
.access-time{color:#d4a853;font-weight:600;font-family:monospace;font-size:.8rem;min-width:50px}
.access-user{color:#e0ddd5;font-weight:500;min-width:60px}
.access-dot{width:6px;height:6px;border-radius:50%;flex-shrink:0}
</style>
</head>
<body>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.3rem">
<h1>📊 Rituals Palau – Analytics</h1>
<button class="refresh" onclick="location.reload()">↻ Actualitzar</button>
</div>
<p class="sub"><?= $totalEntries ?> events registrats · <a class="back" href="../">← Tornar a l'app</a></p>

<div class="grid" id="cards"></div>

<div class="section">
<h2>Activitat diària</h2>
<div class="chart" id="dayChart"></div>
</div>

<div class="section">
<h2>Usuaris</h2>
<table id="userTable"><thead><tr><th>Usuari</th><th>Visites</th><th>Vots</th><th>Última activitat</th></tr></thead><tbody></tbody></table>
</div>

<div class="section">
<h2>Seccions més vistes</h2>
<table id="secTable"><thead><tr><th>Secció</th><th>Visualitzacions</th></tr></thead><tbody></tbody></table>
</div>

<div class="section">
<h2>Accessos per dia i hora</h2>
<div id="accessLog"></div>
</div>

<div class="section">
<h2>Activitat recent</h2>
<table id="recentTable"><thead><tr><th>Quan</th><th>Usuari</th><th>Event</th><th>Detall</th></tr></thead><tbody></tbody></table>
</div>

<script>
const S = <?= $json ?>;
const R = <?= $recentJson ?>;
const A = <?= $accessJson ?>;

const tagClass = ev => ({page_view:'pv',vote:'vote',section_view:'sec',search:'search',login:'login'}[ev]||'other');
const tagLabel = ev => ({page_view:'visita',vote:'vot',section_view:'secció',search:'cerca',login:'login'}[ev]||ev);

// Cards
const cards = document.getElementById('cards');
const totalVisits = S.by_event.page_view || 0;
const totalVotes = S.by_event.vote || 0;
const uniqueUsers = Object.keys(S.by_user).length;
const activeDays = Object.keys(S.by_day).length;
[
    {t:'Total visites',n:totalVisits},
    {t:'Total vots',n:totalVotes},
    {t:'Usuaris actius',n:uniqueUsers},
    {t:'Dies amb activitat',n:activeDays},
].forEach(c => {
    cards.innerHTML += '<div class="card"><h3>'+c.t+'</h3><div class="num">'+c.n+'</div></div>';
});

// Day chart
const dayChart = document.getElementById('dayChart');
const days = Object.entries(S.by_day);
if (days.length) {
    const max = Math.max(...days.map(d=>d[1]));
    days.forEach(([d,n]) => {
        const pct = Math.max(4, (n/max)*100);
        dayChart.innerHTML += '<div class="bar" style="height:'+pct+'%" data-tip="'+d+': '+n+'"></div>';
    });
} else {
    dayChart.innerHTML = '<div class="empty">Encara no hi ha dades</div>';
}

// Users table
const utb = document.querySelector('#userTable tbody');
Object.entries(S.by_user).sort((a,b)=>b[1].visits-a[1].visits).forEach(([u,d]) => {
    const ago = d.last ? timeAgo(d.last) : '-';
    utb.innerHTML += '<tr><td><strong>'+u+'</strong></td><td>'+d.visits+'</td><td>'+d.votes+'</td><td style="color:#888;font-size:.75rem">'+ago+'</td></tr>';
});
if (!Object.keys(S.by_user).length) utb.innerHTML = '<tr><td colspan=4 class="empty">Encara no hi ha dades</td></tr>';

// Sections table
const stb = document.querySelector('#secTable tbody');
Object.entries(S.top_sections).forEach(([s,n]) => {
    stb.innerHTML += '<tr><td>'+s+'</td><td>'+n+'</td></tr>';
});
if (!Object.keys(S.top_sections).length) stb.innerHTML = '<tr><td colspan=2 class="empty">Encara no hi ha dades</td></tr>';

// Access log by day & hour
const userColors = {xavi:'#d4a853',anna:'#e8747c',albert:'#60a5fa',elia:'#4ade80',montse:'#c084fc',roger:'#fb923c'};
const accessDiv = document.getElementById('accessLog');
if (A.length) {
    const grouped = {};
    A.forEach(a => {
        const d = new Date(a.ts);
        const dayKey = d.toLocaleDateString('ca-ES',{weekday:'long',day:'numeric',month:'long',year:'numeric'});
        if (!grouped[dayKey]) grouped[dayKey] = [];
        grouped[dayKey].push(a);
    });
    let html = '';
    for (const [day, items] of Object.entries(grouped)) {
        html += '<div class="day-group"><div class="day-header"><span>'+day.charAt(0).toUpperCase()+day.slice(1)+'</span><span class="count">'+items.length+' acces'+(items.length!==1?'os':'')+'</span></div>';
        items.forEach(a => {
            const d = new Date(a.ts);
            const hh = String(d.getHours()).padStart(2,'0');
            const mm = String(d.getMinutes()).padStart(2,'0');
            const color = userColors[a.u] || '#888';
            html += '<div class="access-row"><span class="access-time">'+hh+':'+mm+'</span><span class="access-dot" style="background:'+color+'"></span><span class="access-user">'+a.u+'</span></div>';
        });
        html += '</div>';
    }
    accessDiv.innerHTML = html;
} else {
    accessDiv.innerHTML = '<div class="empty">Encara no hi ha accessos registrats</div>';
}

// Recent activity
const rtb = document.querySelector('#recentTable tbody');
R.forEach(e => {
    const ago = timeAgo(e.ts);
    rtb.innerHTML += '<tr><td style="color:#888;font-size:.75rem;white-space:nowrap">'+ago+'</td><td>'+(e.u||'anon')+'</td><td><span class="tag tag-'+tagClass(e.ev)+'">'+tagLabel(e.ev)+'</span></td><td style="color:#aaa;font-size:.75rem">'+(e.detail||'')+'</td></tr>';
});
if (!R.length) rtb.innerHTML = '<tr><td colspan=4 class="empty">Encara no hi ha dades</td></tr>';

function timeAgo(ts) {
    const diff = (Date.now() - new Date(ts).getTime()) / 1000;
    if (diff < 60) return 'ara';
    if (diff < 3600) return Math.floor(diff/60) + ' min';
    if (diff < 86400) return Math.floor(diff/3600) + ' h';
    return Math.floor(diff/86400) + ' d';
}
</script>
</body>
</html>
<?php } ?>
