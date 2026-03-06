<?php
declare(strict_types=1);

require_once __DIR__ . '/config/db.php';

// ── Constants ─────────────────────────────────────────────────────────────────

const PER_PAGE = 50;

const VERIFY_LABELS = [
    0  => ['icon' => '🔑', 'label' => 'Password'],
    1  => ['icon' => '👆', 'label' => 'Fingerprint'],
    2  => ['icon' => '💳', 'label' => 'Card'],
    3  => ['icon' => '👤', 'label' => 'Face'],
    4  => ['icon' => '👤', 'label' => 'Face+FP'],
    5  => ['icon' => '👆', 'label' => 'FP+PW'],
    15 => ['icon' => '👤', 'label' => 'Face'],
];

const INOUT_LABELS = [
    0 => ['label' => 'Check In',   'class' => 'badge-in'],
    1 => ['label' => 'Check Out',  'class' => 'badge-out'],
    2 => ['label' => 'Break Out',  'class' => 'badge-break'],
    3 => ['label' => 'Break In',   'class' => 'badge-break'],
    4 => ['label' => 'OT In',      'class' => 'badge-ot'],
    5 => ['label' => 'OT Out',     'class' => 'badge-ot'],
];

// ── Input ─────────────────────────────────────────────────────────────────────

$today  = date('Y-m-d');
$from   = (isset($_GET['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'])) ? $_GET['from'] : $today;
$to     = (isset($_GET['to'])   && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to']))   ? $_GET['to']   : $today;
$uid    = trim($_GET['uid'] ?? '');
$page   = max(1, (int) ($_GET['page'] ?? 1));
$export = ($_GET['export'] ?? '') === 'csv';

// ── Database ──────────────────────────────────────────────────────────────────

$db = getDB();

$fromDt = $from . ' 00:00:00';
$toDt   = $to   . ' 23:59:59';

$baseParams  = [$fromDt, $toDt];
$uidFragment = '';
if ($uid !== '') {
    $uidFragment  = ' AND a.user_id LIKE ?';
    $baseParams[] = "%{$uid}%";
}

// Stats (always today, not affected by filter)
$todayPunches = (int) $db->query("SELECT COUNT(*) FROM attendance_logs WHERE DATE(punch_time) = CURDATE()")->fetchColumn();
$todayUsers   = (int) $db->query("SELECT COUNT(DISTINCT user_id) FROM attendance_logs WHERE DATE(punch_time) = CURDATE()")->fetchColumn();
$totalRecords = (int) $db->query("SELECT COUNT(*) FROM attendance_logs")->fetchColumn();
$devices      = $db->query("SELECT serial_number, ip_address, last_activity FROM devices ORDER BY last_activity DESC")->fetchAll();

// Filtered count
$countStmt = $db->prepare("SELECT COUNT(*) FROM attendance_logs a WHERE a.punch_time BETWEEN ? AND ?{$uidFragment}");
$countStmt->execute($baseParams);
$totalFiltered = (int) $countStmt->fetchColumn();
$totalPages    = max(1, (int) ceil($totalFiltered / PER_PAGE));
$page          = min($page, $totalPages);

// ── CSV Export ────────────────────────────────────────────────────────────────

if ($export) {
    $stmt = $db->prepare("
        SELECT
            a.user_id,
            COALESCE(NULLIF(u.name,''), a.user_id) AS user_name,
            DATE_FORMAT(a.punch_time,'%Y-%m-%d')   AS punch_date,
            DATE_FORMAT(a.punch_time,'%H:%i:%s')   AS punch_time_fmt,
            a.inout_type,
            a.verify_type,
            a.device_sn
        FROM attendance_logs a
        LEFT JOIN device_users u ON u.device_sn = a.device_sn AND u.user_id = a.user_id
        WHERE a.punch_time BETWEEN ? AND ?{$uidFragment}
        ORDER BY a.punch_time DESC
    ");
    $stmt->execute($baseParams);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="attendance_' . $from . '_to_' . $to . '.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['User ID', 'Name', 'Date', 'Time', 'Type', 'Verify Method', 'Device SN']);
    while ($row = $stmt->fetch()) {
        $inout  = INOUT_LABELS[$row['inout_type']]  ?? ['label' => 'Unknown'];
        $verify = VERIFY_LABELS[$row['verify_type']] ?? ['label' => 'Unknown'];
        fputcsv($out, [
            $row['user_id'],
            $row['user_name'],
            $row['punch_date'],
            $row['punch_time_fmt'],
            $inout['label'],
            $verify['label'],
            $row['device_sn'],
        ]);
    }
    fclose($out);
    exit;
}

// ── Paginated Records ─────────────────────────────────────────────────────────

$offset   = ($page - 1) * PER_PAGE;
$rowStmt  = $db->prepare("
    SELECT
        a.user_id,
        COALESCE(NULLIF(u.name,''), '') AS user_name,
        a.punch_time,
        a.inout_type,
        a.verify_type,
        a.device_sn
    FROM attendance_logs a
    LEFT JOIN device_users u ON u.device_sn = a.device_sn AND u.user_id = a.user_id
    WHERE a.punch_time BETWEEN ? AND ?{$uidFragment}
    ORDER BY a.punch_time DESC
    LIMIT ? OFFSET ?
");
$rowParams = array_merge($baseParams, [PER_PAGE, $offset]);
$rowStmt->execute($rowParams);
$records = $rowStmt->fetchAll();

// ── Helpers ───────────────────────────────────────────────────────────────────

function esc(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function deviceStatus(?string $lastActivity): array
{
    if ($lastActivity === null) return ['Offline', 'status-offline'];
    $diff = time() - (int) strtotime($lastActivity);
    if ($diff < 120)  return ['Online',  'status-online'];
    if ($diff < 600)  return ['Idle',    'status-idle'];
    return ['Offline', 'status-offline'];
}

function buildUrl(array $overrides = []): string
{
    global $from, $to, $uid, $page;
    $q = array_filter(array_merge(
        ['from' => $from, 'to' => $to, 'uid' => $uid, 'page' => $page],
        $overrides
    ), fn($v) => $v !== '' && $v !== null && $v !== 1 || ($v === 1 && isset($overrides['page'])));
    return '?' . http_build_query($q);
}

function pageUrl(int $p): string { return buildUrl(['page' => $p]); }

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Attendance Dashboard</title>
<style>
:root {
    --bg:        #F0F4F8;
    --card:      #FFFFFF;
    --border:    #DDE3EC;
    --primary:   #1A56DB;
    --primary-h: #1648C1;
    --text:      #111827;
    --muted:     #6B7280;
    --shadow:    0 1px 4px rgba(0,0,0,.08), 0 0 0 1px rgba(0,0,0,.04);
    --radius:    10px;
    --in:        #16A34A;
    --out:       #DC2626;
    --break:     #D97706;
    --ot:        #7C3AED;
}
*,*::before,*::after { box-sizing: border-box; margin: 0; padding: 0; }
html { font-size: 14px; }
body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif;
    background: var(--bg);
    color: var(--text);
    line-height: 1.5;
    min-height: 100vh;
}

/* ── Top bar ── */
.topbar {
    background: var(--primary);
    color: #fff;
    padding: 0 24px;
    height: 56px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: sticky;
    top: 0;
    z-index: 100;
    box-shadow: 0 2px 8px rgba(0,0,0,.15);
}
.topbar-brand {
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 700;
    font-size: 1.05rem;
    letter-spacing: .01em;
}
.topbar-brand svg { flex-shrink: 0; }
.topbar-devices {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}
.device-chip {
    display: flex;
    align-items: center;
    gap: 6px;
    background: rgba(255,255,255,.15);
    border: 1px solid rgba(255,255,255,.25);
    border-radius: 20px;
    padding: 3px 10px 3px 8px;
    font-size: .78rem;
    font-weight: 500;
}
.dot {
    width: 8px; height: 8px;
    border-radius: 50%;
    flex-shrink: 0;
}
.status-online  .dot { background: #4ADE80; box-shadow: 0 0 6px #4ADE80; }
.status-idle    .dot { background: #FCD34D; }
.status-offline .dot { background: #F87171; }

/* ── Layout ── */
.wrap {
    max-width: 1280px;
    margin: 0 auto;
    padding: 24px 20px 40px;
}

/* ── Stats row ── */
.stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 14px;
    margin-bottom: 20px;
}
.stat-card {
    background: var(--card);
    border-radius: var(--radius);
    box-shadow: var(--shadow);
    padding: 18px 20px;
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.stat-label { font-size: .75rem; font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: .06em; }
.stat-value { font-size: 2rem; font-weight: 700; color: var(--text); line-height: 1.1; }
.stat-sub   { font-size: .75rem; color: var(--muted); }

/* ── Filter bar ── */
.filter-bar {
    background: var(--card);
    border-radius: var(--radius);
    box-shadow: var(--shadow);
    padding: 14px 18px;
    margin-bottom: 16px;
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: flex-end;
}
.filter-group {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.filter-group label {
    font-size: .7rem;
    font-weight: 600;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: .06em;
}
.filter-group input[type=date],
.filter-group input[type=text] {
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 6px 10px;
    font-size: .875rem;
    color: var(--text);
    background: #FAFBFC;
    outline: none;
    height: 34px;
}
.filter-group input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(26,86,219,.12); }
.filter-actions { display: flex; gap: 8px; align-items: flex-end; }
.btn {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 7px 14px;
    border-radius: 6px;
    border: none;
    font-size: .8rem;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    height: 34px;
    transition: background .15s, box-shadow .15s;
}
.btn-primary { background: var(--primary); color: #fff; }
.btn-primary:hover { background: var(--primary-h); }
.btn-ghost { background: transparent; color: var(--muted); border: 1px solid var(--border); }
.btn-ghost:hover { background: var(--bg); color: var(--text); }
.btn-export { background: #F0FDF4; color: #15803D; border: 1px solid #BBF7D0; }
.btn-export:hover { background: #DCFCE7; }

/* ── Table card ── */
.table-card {
    background: var(--card);
    border-radius: var(--radius);
    box-shadow: var(--shadow);
    overflow: hidden;
}
.table-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 18px;
    border-bottom: 1px solid var(--border);
    flex-wrap: wrap;
    gap: 8px;
}
.table-title { font-weight: 700; font-size: .9rem; }
.result-count { font-size: .8rem; color: var(--muted); }
.table-wrap { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; }
thead th {
    padding: 10px 14px;
    text-align: left;
    font-size: .7rem;
    font-weight: 700;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: .06em;
    border-bottom: 1px solid var(--border);
    background: #FAFBFC;
    white-space: nowrap;
}
tbody tr { border-bottom: 1px solid var(--border); }
tbody tr:last-child { border-bottom: none; }
tbody tr:hover { background: #F8FAFC; }
tbody td {
    padding: 10px 14px;
    font-size: .85rem;
    vertical-align: middle;
    white-space: nowrap;
}
.user-id   { font-weight: 600; }
.user-name { color: var(--muted); font-size: .8rem; }
.time-main { font-weight: 600; }
.time-date { color: var(--muted); font-size: .78rem; }
.verify-label { color: var(--muted); font-size: .8rem; }

/* ── Badges ── */
.badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: .7rem;
    font-weight: 700;
    letter-spacing: .04em;
    text-transform: uppercase;
}
.badge-in    { background: #DCFCE7; color: #15803D; }
.badge-out   { background: #FEE2E2; color: #B91C1C; }
.badge-break { background: #FEF3C7; color: #92400E; }
.badge-ot    { background: #EDE9FE; color: #6D28D9; }

/* ── Empty state ── */
.empty {
    text-align: center;
    padding: 60px 20px;
    color: var(--muted);
}
.empty-icon { font-size: 2.5rem; margin-bottom: 10px; }
.empty-msg  { font-size: .9rem; }

/* ── Pagination ── */
.pagination {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 18px;
    border-top: 1px solid var(--border);
    flex-wrap: wrap;
    gap: 8px;
}
.pagination-info { font-size: .8rem; color: var(--muted); }
.page-links { display: flex; gap: 4px; }
.page-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 32px;
    height: 32px;
    padding: 0 8px;
    border-radius: 6px;
    border: 1px solid var(--border);
    background: var(--card);
    color: var(--text);
    font-size: .8rem;
    font-weight: 500;
    text-decoration: none;
    cursor: pointer;
    transition: background .1s;
}
.page-btn:hover { background: var(--bg); }
.page-btn.active { background: var(--primary); color: #fff; border-color: var(--primary); font-weight: 700; }
.page-btn.disabled { opacity: .4; pointer-events: none; }

/* ── Responsive ── */
@media (max-width: 600px) {
    .topbar { height: auto; padding: 10px 16px; flex-direction: column; align-items: flex-start; gap: 8px; }
    .wrap { padding: 16px 12px 32px; }
    .stat-value { font-size: 1.6rem; }
}
</style>
</head>
<body>

<!-- ── Top Bar ──────────────────────────────────────────────────────────────── -->
<header class="topbar">
    <div class="topbar-brand">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
            <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
        </svg>
        Attendance Middleware
    </div>
    <div class="topbar-devices">
        <?php if (empty($devices)): ?>
            <span style="font-size:.78rem;opacity:.7">No device connected yet</span>
        <?php else: ?>
            <?php foreach ($devices as $dev): ?>
                <?php [$statusLabel, $statusClass] = deviceStatus($dev['last_activity']); ?>
                <div class="device-chip <?= $statusClass ?>">
                    <span class="dot"></span>
                    <?= esc($dev['serial_number']) ?>
                    <span style="opacity:.7">· <?= $statusLabel ?></span>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</header>

<!-- ── Main ─────────────────────────────────────────────────────────────────── -->
<div class="wrap">

    <!-- Stats -->
    <div class="stats">
        <div class="stat-card">
            <span class="stat-label">Today's Punches</span>
            <span class="stat-value"><?= number_format($todayPunches) ?></span>
            <span class="stat-sub"><?= $today ?></span>
        </div>
        <div class="stat-card">
            <span class="stat-label">Active Users Today</span>
            <span class="stat-value"><?= number_format($todayUsers) ?></span>
            <span class="stat-sub">unique employees</span>
        </div>
        <div class="stat-card">
            <span class="stat-label">Total Records</span>
            <span class="stat-value"><?= number_format($totalRecords) ?></span>
            <span class="stat-sub">all time</span>
        </div>
        <div class="stat-card">
            <span class="stat-label">Device<?= count($devices) !== 1 ? 's' : '' ?></span>
            <span class="stat-value"><?= count($devices) ?></span>
            <span class="stat-sub">registered</span>
        </div>
    </div>

    <!-- Filter Bar -->
    <form method="GET" action="">
        <div class="filter-bar">
            <div class="filter-group">
                <label>From</label>
                <input type="date" name="from" value="<?= esc($from) ?>">
            </div>
            <div class="filter-group">
                <label>To</label>
                <input type="date" name="to" value="<?= esc($to) ?>">
            </div>
            <div class="filter-group">
                <label>User ID</label>
                <input type="text" name="uid" value="<?= esc($uid) ?>" placeholder="All users" style="width:120px">
            </div>
            <div class="filter-actions">
                <button type="submit" class="btn btn-primary">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    Filter
                </button>
                <a href="?" class="btn btn-ghost">Reset</a>
                <a href="<?= esc(buildUrl(['export' => 'csv', 'page' => ''])) ?>" class="btn btn-export">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    Export CSV
                </a>
            </div>
        </div>
    </form>

    <!-- Table -->
    <div class="table-card">
        <div class="table-header">
            <span class="table-title">Attendance Records</span>
            <span class="result-count">
                <?= number_format($totalFiltered) ?> record<?= $totalFiltered !== 1 ? 's' : '' ?>
                <?php if ($from !== $to): ?>
                    · <?= esc($from) ?> → <?= esc($to) ?>
                <?php else: ?>
                    · <?= esc($from) ?>
                <?php endif; ?>
                <?php if ($uid !== ''): ?>
                    · user "<?= esc($uid) ?>"
                <?php endif; ?>
            </span>
        </div>

        <?php if (empty($records)): ?>
            <div class="empty">
                <div class="empty-icon">📋</div>
                <div class="empty-msg">No attendance records found for this period.</div>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Date &amp; Time</th>
                            <th>Type</th>
                            <th>Verify</th>
                            <th>Device</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($records as $row): ?>
                        <?php
                            $dt      = new DateTimeImmutable($row['punch_time']);
                            $inout   = INOUT_LABELS[$row['inout_type']]  ?? ['label' => 'Unknown', 'class' => 'badge-in'];
                            $verify  = VERIFY_LABELS[$row['verify_type']] ?? ['icon' => '?', 'label' => 'Unknown'];
                        ?>
                        <tr>
                            <td>
                                <span class="user-id"><?= esc($row['user_id']) ?></span>
                                <?php if ($row['user_name'] !== ''): ?>
                                    <br><span class="user-name"><?= esc($row['user_name']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="time-main"><?= $dt->format('H:i:s') ?></span>
                                <br><span class="time-date"><?= $dt->format('D, d M Y') ?></span>
                            </td>
                            <td><span class="badge <?= $inout['class'] ?>"><?= $inout['label'] ?></span></td>
                            <td><span class="verify-label"><?= $verify['icon'] ?> <?= $verify['label'] ?></span></td>
                            <td style="color:var(--muted);font-size:.78rem"><?= esc($row['device_sn']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <span class="pagination-info">
                        Page <?= $page ?> of <?= $totalPages ?>
                        · showing <?= number_format(($page - 1) * PER_PAGE + 1) ?>–<?= number_format(min($page * PER_PAGE, $totalFiltered)) ?>
                    </span>
                    <div class="page-links">
                        <a href="<?= esc(pageUrl(1)) ?>"
                           class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>">«</a>
                        <a href="<?= esc(pageUrl(max(1, $page - 1))) ?>"
                           class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>">‹</a>
                        <?php
                            $start = max(1, $page - 2);
                            $end   = min($totalPages, $page + 2);
                            for ($p = $start; $p <= $end; $p++):
                        ?>
                            <a href="<?= esc(pageUrl($p)) ?>"
                               class="page-btn <?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
                        <?php endfor; ?>
                        <a href="<?= esc(pageUrl(min($totalPages, $page + 1))) ?>"
                           class="page-btn <?= $page >= $totalPages ? 'disabled' : '' ?>">›</a>
                        <a href="<?= esc(pageUrl($totalPages)) ?>"
                           class="page-btn <?= $page >= $totalPages ? 'disabled' : '' ?>">»</a>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div><!-- /table-card -->

</div><!-- /wrap -->
</body>
</html>
