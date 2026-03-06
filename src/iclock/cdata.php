<?php
/**
 * ADMS Protocol — Main endpoint
 *
 * GET  /iclock/cdata  — Device registration / heartbeat → returns device options
 * POST /iclock/cdata  — Device pushes attendance or operation logs
 *
 * ZKTeco ADMS reference:
 *   table=ATTLOG  : attendance punches
 *   table=OPERLOG : user/admin operation records (includes USER entries)
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/db.php';

// ── Helpers ───────────────────────────────────────────────────────────────────

function plainOk(): void
{
    header('Content-Type: text/plain');
    echo "OK";
}

function admsLog(string $msg): void
{
    error_log("[ADMS] {$msg}");
}

// ── Main ──────────────────────────────────────────────────────────────────────

$sn     = trim($_GET['SN']    ?? '');
$table  = strtoupper(trim($_GET['table'] ?? ''));
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($sn === '') {
    http_response_code(400);
    exit("Bad Request: missing SN");
}

try {
    $db = getDB();
    upsertDevice($db, $sn);

    if ($method === 'GET') {
        handleInit($db, $sn);
    } elseif ($method === 'POST') {
        $body = (string) file_get_contents('php://input');
        match ($table) {
            'ATTLOG'  => handleAttlog($db, $sn, $body),
            'OPERLOG' => handleOperlog($db, $sn, $body),
            default   => plainOk(),
        };
    } else {
        plainOk();
    }
} catch (Throwable $e) {
    admsLog("Error SN={$sn}: " . $e->getMessage());
    http_response_code(500);
    exit("ERROR");
}

// ── Handlers ──────────────────────────────────────────────────────────────────

function upsertDevice(PDO $db, string $sn): void
{
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    $stmt = $db->prepare('
        INSERT INTO devices (serial_number, ip_address, last_activity)
        VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            ip_address    = VALUES(ip_address),
            last_activity = NOW()
    ');
    $stmt->execute([$sn, $ip]);
}

/**
 * Respond to the device's initial GET with configuration options.
 *
 * ATTLOGStamp=0 tells the device to send *all* stored records.
 * INSERT IGNORE on the attendance table prevents duplicates, so
 * returning 0 is safe and ensures no records are ever missed.
 */
function handleInit(PDO $db, string $sn): void
{
    admsLog("INIT SN={$sn}");
    header('Content-Type: text/plain');
    echo implode("\n", [
        "GET OPTION FROM: {$sn}",
        "ATTLOGStamp=0",
        "OPERLOGStamp=9999",
        "ATTPHOTOStamp=None",
        "ErrorDelay=30",
        "Delay=10",
        "TransTimes=00:00;23:59",
        "TransInterval=1",
        "TransFlag=1111000000",
        "TimeZone=1",   // UTC+1 (West Africa Time)
        "Realtime=1",   // push punches immediately as they happen
        "Encrypt=None",
    ]);
}

/**
 * Parse and store attendance punch records.
 *
 * Line format (tab-separated):
 *   UserID  DateTime  VerifyType  InOutType  WorkCode  Reserved
 */
function handleAttlog(PDO $db, string $sn, string $body): void
{
    $lines = preg_split('/\r\n|\r|\n/', trim($body)) ?: [];
    $stmt  = $db->prepare('
        INSERT IGNORE INTO attendance_logs
            (device_sn, user_id, punch_time, verify_type, inout_type, work_code, raw_data)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ');

    $count = 0;
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;

        $parts = preg_split('/\t/', $line);
        if (count($parts) < 2) continue;

        $userId    = trim($parts[0]);
        $punchTime = trim($parts[1]);

        if ($userId === '' || !strtotime($punchTime)) continue;

        $verifyType = isset($parts[2]) ? (int) trim($parts[2]) : 0;
        $inoutType  = isset($parts[3]) ? (int) trim($parts[3]) : 0;
        $workCode   = (isset($parts[4]) && trim($parts[4]) !== '') ? trim($parts[4]) : null;

        $stmt->execute([$sn, $userId, $punchTime, $verifyType, $inoutType, $workCode, $line]);
        $count++;
    }

    admsLog("ATTLOG SN={$sn} lines={$count}");
    plainOk();
}

/**
 * Parse OPERLOG — contains USER records and admin operations.
 *
 * USER line format:
 *   USER  PIN=1  Name=John  Pri=0  Passwd=  Card=0  Grp=1  ...
 */
function handleOperlog(PDO $db, string $sn, string $body): void
{
    $lines = preg_split('/\r\n|\r|\n/', trim($body)) ?: [];
    $stmt  = $db->prepare('
        INSERT INTO device_users (device_sn, user_id, name, privilege, card_number)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            name        = VALUES(name),
            privilege   = VALUES(privilege),
            card_number = VALUES(card_number),
            updated_at  = NOW()
    ');

    foreach ($lines as $line) {
        $line = trim($line);
        if (!str_starts_with($line, 'USER')) continue;

        $attrs = [];
        preg_match_all('/(\w+)=([^\t\s]*)/', $line, $m, PREG_SET_ORDER);
        foreach ($m as $match) {
            $attrs[$match[1]] = $match[2];
        }

        if (!isset($attrs['PIN'])) continue;

        $name = isset($attrs['Name']) ? urldecode($attrs['Name']) : null;
        $stmt->execute([
            $sn,
            $attrs['PIN'],
            ($name !== '' ? $name : null),
            (int) ($attrs['Pri'] ?? 0),
            ($attrs['Card'] ?? '0') !== '0' ? ($attrs['Card'] ?? null) : null,
        ]);
    }

    admsLog("OPERLOG SN={$sn}");
    plainOk();
}
