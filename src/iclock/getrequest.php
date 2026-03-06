<?php
/**
 * ADMS Protocol — Command polling endpoint
 *
 * The device calls this every ~30 seconds to ask:
 * "Do you have any commands for me?"
 *
 * Responding with "OK" means no commands pending.
 * This also serves as a heartbeat — we update the device's last_activity.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/db.php';

$sn = trim($_GET['SN'] ?? '');

if ($sn === '') {
    http_response_code(400);
    exit("Bad Request: missing SN");
}

try {
    $db = getDB();
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';

    $db->prepare('
        INSERT INTO devices (serial_number, ip_address, last_activity)
        VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            ip_address    = VALUES(ip_address),
            last_activity = NOW()
    ')->execute([$sn, $ip]);

} catch (Throwable $e) {
    error_log("[ADMS] getrequest error SN={$sn}: " . $e->getMessage());
}

header('Content-Type: text/plain');
echo "OK";
