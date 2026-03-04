<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require __DIR__ . '/db.php';
$pdo = getDb();

$mode = isset($_GET['mode']) ? (string)$_GET['mode'] : null;
$level = isset($_GET['level']) ? (int)$_GET['level'] : 0;
$parent = isset($_GET['parent']) ? (string)$_GET['parent'] : null;
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$pageSize = isset($_GET['pageSize']) ? max(1, min(100, (int)$_GET['pageSize'])) : 50;

try {
    if ($mode === 'postal') {
        $data = searchByPostal($pdo, $q, $page, $pageSize);
    } elseif ($mode === 'chain') {
        $code = isset($_GET['code']) ? (string)$_GET['code'] : '';
        $data = getChainByCode($pdo, $code);
    } elseif ($mode === 'nearest') {
        $lat = isset($_GET['lat']) ? (float)$_GET['lat'] : 0.0;
        $lng = isset($_GET['lng']) ? (float)$_GET['lng'] : 0.0;
        $data = getChainByNearestLatLng($pdo, $lat, $lng);
    } else {
        $data = fetchRegions($pdo, $level, $parent, $q, $page, $pageSize);
    }
    echo json_encode($data);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error', 'message' => $e->getMessage()]);
}
?>
