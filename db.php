<?php
declare(strict_types=1);

function envDbHost(): string { return getenv('DB_HOST') ?: '127.0.0.1'; }
function envDbUser(): string { return getenv('DB_USER') ?: 'root'; }
function envDbPass(): string { return getenv('DB_PASS') ?: ''; }
function envDbName(): string { return getenv('DB_NAME') ?: 'wilayah'; }

function ensureDatabaseExists(PDO $pdoServer, string $dbName): void {
    $pdoServer->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
}

function connectServer(): PDO {
    $dsn = "mysql:host=" . envDbHost() . ";charset=utf8mb4";
    $pdo = new PDO($dsn, envDbUser(), envDbPass(), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}

function connectDb(): PDO {
    $dbName = envDbName();
    try {
        $dsn = "mysql:host=" . envDbHost() . ";dbname={$dbName};charset=utf8mb4";
        return new PDO($dsn, envDbUser(), envDbPass(), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $e) {
        $server = connectServer();
        ensureDatabaseExists($server, $dbName);
        $dsn = "mysql:host=" . envDbHost() . ";dbname={$dbName};charset=utf8mb4";
        return new PDO($dsn, envDbUser(), envDbPass(), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
}

function ensureRegionsSchema(PDO $pdo): void {
    $sql = <<<SQL
    CREATE TABLE IF NOT EXISTS indonesia_regions (
        code VARCHAR(32) PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        postal_code VARCHAR(16) NULL,
        latitude DOUBLE NULL,
        longitude DOUBLE NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    SQL;
    $pdo->exec($sql);
}

function importRegionsIfEmpty(PDO $pdo, string $sqlFilePath): void {
    $count = (int)$pdo->query("SELECT COUNT(*) AS c FROM indonesia_regions")->fetch()['c'] ?? 0;
    if ($count > 0) {
        return;
    }
    if (!is_file($sqlFilePath)) {
        return;
    }
    $sql = file_get_contents($sqlFilePath);
    if ($sql === false || $sql === '') {
        return;
    }
    $pdo->exec($sql);
}

function getDb(): PDO {
    $pdo = connectDb();
    ensureRegionsSchema($pdo);
    importRegionsIfEmpty($pdo, __DIR__ . DIRECTORY_SEPARATOR . 'indonesia_regions.sql');
    return $pdo;
}

function countDots(string $code): int {
    return substr_count($code, '.');
}

function fetchRegions(PDO $pdo, int $level, ?string $parent, string $q, int $page, int $pageSize): array {
    $offset = max(0, ($page - 1) * $pageSize);
    $dotCount = $level;
    $where = " (LENGTH(code) - LENGTH(REPLACE(code,'.',''))) = :dots ";
    $params = [':dots' => $dotCount];
    if ($parent !== null && $parent !== '') {
        $where .= " AND code LIKE :parentPrefix ";
        $params[':parentPrefix'] = $parent . '.%';
    }
    if ($q !== '') {
        $where .= " AND name LIKE :q ";
        $params[':q'] = '%' . $q . '%';
    }
    $sql = "SELECT code, name, latitude, longitude FROM indonesia_regions WHERE {$where} ORDER BY name ASC LIMIT :limit OFFSET :offset";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $stmtCount = $pdo->prepare("SELECT COUNT(*) AS c FROM indonesia_regions WHERE {$where}");
    foreach ($params as $k => $v) {
        $stmtCount->bindValue($k, $v);
    }
    $stmtCount->execute();
    $total = (int)$stmtCount->fetch()['c'];

    $results = array_map(function ($r) {
        return [
            'id' => $r['code'],
            'text' => $r['name'],
            'latitude' => $r['latitude'],
            'longitude' => $r['longitude'],
        ];
    }, $rows);

    return [
        'results' => $results,
        'pagination' => ['more' => ($offset + $pageSize) < $total],
        'total' => $total,
    ];
}
 
function splitCodes(string $code): array {
    $parts = explode('.', $code);
    $prov = $parts[0] ?? null;
    $kab = isset($parts[1]) ? $parts[0] . '.' . $parts[1] : null;
    $kec = isset($parts[2]) ? $parts[0] . '.' . $parts[1] . '.' . $parts[2] : null;
    return [$prov, $kab, $kec];
}

function getRegionByCode(PDO $pdo, string $code): ?array {
    $stmt = $pdo->prepare("SELECT code, name, postal_code, latitude, longitude FROM indonesia_regions WHERE code = :code LIMIT 1");
    $stmt->execute([':code' => $code]);
    $r = $stmt->fetch();
    if (!$r) return null;
    return [
        'id' => $r['code'],
        'text' => $r['name'],
        'postal_code' => $r['postal_code'],
        'latitude' => $r['latitude'],
        'longitude' => $r['longitude'],
    ];
}

function getChainByCode(PDO $pdo, string $code): array {
    $desa = getRegionByCode($pdo, $code);
    [$provCode, $kabCode, $kecCode] = splitCodes($code);
    $prov = $provCode ? getRegionByCode($pdo, $provCode) : null;
    $kab = $kabCode ? getRegionByCode($pdo, $kabCode) : null;
    $kec = $kecCode ? getRegionByCode($pdo, $kecCode) : null;
    return ['prov' => $prov, 'kab' => $kab, 'kec' => $kec, 'desa' => $desa];
}

function searchByPostal(PDO $pdo, string $q, int $page, int $pageSize): array {
    $offset = max(0, ($page - 1) * $pageSize);
    $where = " postal_code IS NOT NULL ";
    $params = [];
    if ($q !== '') {
        $where .= " AND postal_code LIKE :q ";
        $params[':q'] = '%' . $q . '%';
    }
    $sql = "SELECT code, name, postal_code, latitude, longitude FROM indonesia_regions WHERE {$where} ORDER BY postal_code ASC, name ASC LIMIT :limit OFFSET :offset";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
    $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $stmtCount = $pdo->prepare("SELECT COUNT(*) AS c FROM indonesia_regions WHERE {$where}");
    foreach ($params as $k => $v) { $stmtCount->bindValue($k, $v); }
    $stmtCount->execute();
    $total = (int)$stmtCount->fetch()['c'];

    $results = array_map(function ($r) use ($pdo) {
        [$provCode, $kabCode, $kecCode] = splitCodes($r['code']);
        $prov = $provCode ? getRegionByCode($pdo, $provCode) : null;
        $kab = $kabCode ? getRegionByCode($pdo, $kabCode) : null;
        $kec = $kecCode ? getRegionByCode($pdo, $kecCode) : null;
        $line = $r['postal_code'] . ' - ' . $r['name'];
        if ($kec && $kab && $prov) {
            $line .= ' / ' . $kec['text'] . ' / ' . $kab['text'] . ' / ' . $prov['text'];
        }
        return [
            'id' => $r['code'],
            'text' => $line,
            'postal_code' => $r['postal_code'],
            'latitude' => $r['latitude'],
            'longitude' => $r['longitude'],
        ];
    }, $rows);

    return [
        'results' => $results,
        'pagination' => ['more' => ($offset + $pageSize) < $total],
        'total' => $total,
    ];
}

function getNearestDesaByLatLng(PDO $pdo, float $lat, float $lng): ?array {
    $sql = "SELECT code, name, latitude, longitude
            FROM indonesia_regions
            WHERE (LENGTH(code) - LENGTH(REPLACE(code,'.',''))) = 3
              AND latitude IS NOT NULL AND longitude IS NOT NULL
            ORDER BY ((latitude - :lat) * (latitude - :lat) + (longitude - :lng) * (longitude - :lng)) ASC
            LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':lat', $lat);
    $stmt->bindValue(':lng', $lng);
    $stmt->execute();
    $r = $stmt->fetch();
    if (!$r) return null;
    return [
        'id' => $r['code'],
        'text' => $r['name'],
        'latitude' => $r['latitude'],
        'longitude' => $r['longitude'],
    ];
}

function getChainByNearestLatLng(PDO $pdo, float $lat, float $lng): array {
    $desa = getNearestDesaByLatLng($pdo, $lat, $lng);
    if (!$desa) return ['prov' => null, 'kab' => null, 'kec' => null, 'desa' => null];
    return getChainByCode($pdo, $desa['id']);
}
?>
