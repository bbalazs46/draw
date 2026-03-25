<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$dataDir = __DIR__ . DIRECTORY_SEPARATOR . 'draw5_store_data';
$maxRequestBytes = 25 * 1024 * 1024;

respond(handleRequest($dataDir, $maxRequestBytes));

function handleRequest(string $dataDir, int $maxRequestBytes): array
{
    $action = $_GET['action'] ?? 'list';
    if (!is_dir($dataDir) && !mkdir($dataDir, 0700, true) && !is_dir($dataDir)) {
        return errorResponse('Nem sikerült létrehozni a mentési mappát.', 500);
    }

    return match ($action) {
        'list' => listProjects($dataDir),
        'load' => loadProject($dataDir),
        'save' => saveProject($dataDir, $maxRequestBytes),
        'presence' => updatePresence($dataDir, $maxRequestBytes),
        default => errorResponse('Ismeretlen művelet.', 400),
    };
}

function listProjects(string $dataDir): array
{
    $files = glob($dataDir . DIRECTORY_SEPARATOR . '*.json');
    if ($files === false) {
        return errorResponse('Nem sikerült beolvasni az online rajzokat.', 500);
    }

    $projects = [];
    foreach ($files as $file) {
        $projects[] = pathinfo($file, PATHINFO_FILENAME);
    }
    natcasesort($projects);

    return ['status' => 200, 'body' => ['ok' => true, 'files' => array_values($projects)]];
}

function loadProject(string $dataDir): array
{
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        return errorResponse('A megnyitáshoz GET kérés szükséges.', 405);
    }

    $name = sanitizeProjectName($_GET['name'] ?? '');
    if ($name === null) {
        return errorResponse('Hiányzó vagy hibás fájlnév.', 400);
    }

    $path = projectPath($dataDir, $name);
    if (!is_file($path)) {
        return errorResponse('Az online rajz nem található.', 404);
    }

    $content = file_get_contents($path);
    if ($content === false) {
        return errorResponse('Nem sikerült beolvasni az online rajzot.', 500);
    }

    $project = json_decode($content, true);
    if (!is_array($project)) {
        return errorResponse('A mentett online rajz sérült.', 500);
    }

    return ['status' => 200, 'body' => ['ok' => true, 'name' => $name, 'project' => $project, 'revision' => loadProjectRevision($dataDir, $name)]];
}

function saveProject(string $dataDir, int $maxRequestBytes): array
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return errorResponse('A mentéshez POST kérés szükséges.', 405);
    }

    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return errorResponse('Hiányzó kérés törzs.', 400);
    }
    if (strlen($raw) > $maxRequestBytes) {
        return errorResponse('A rajz túl nagy az online mentéshez.', 413);
    }

    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        return errorResponse('Hibás JSON kérés.', 400);
    }

    $name = sanitizeProjectName($payload['name'] ?? '');
    if ($name === null) {
        return errorResponse('Adj meg egy érvényes online fájlnevet.', 400);
    }

    $project = $payload['project'] ?? null;
    if (!is_array($project)) {
        return errorResponse('Hiányzik a mentendő rajz tartalma.', 400);
    }

    $encoded = json_encode($project, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        return errorResponse('Nem sikerült feldolgozni a rajzot.', 400);
    }

    $path = projectPath($dataDir, $name);
    if (file_put_contents($path, $encoded, LOCK_EX) === false) {
        return errorResponse('Nem sikerült elmenteni az online rajzot.', 500);
    }

    $revision = bumpProjectRevision($dataDir, $name);
    if ($revision <= 0) {
        return errorResponse('Nem sikerült frissíteni a rajz verzióját.', 500);
    }

    return ['status' => 200, 'body' => ['ok' => true, 'name' => $name, 'revision' => $revision]];
}

function updatePresence(string $dataDir, int $maxRequestBytes): array
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return errorResponse('A jelenléthez POST kérés szükséges.', 405);
    }

    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return errorResponse('Hiányzó kérés törzs.', 400);
    }
    if (strlen($raw) > $maxRequestBytes) {
        return errorResponse('A jelenléti kérés túl nagy.', 413);
    }

    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        return errorResponse('Hibás JSON kérés.', 400);
    }

    $name = sanitizeProjectName($payload['name'] ?? '');
    if ($name === null) {
        return errorResponse('Hiányzó vagy hibás fájlnév.', 400);
    }

    $clientId = sanitizeClientId($payload['clientId'] ?? '');
    if ($clientId === null) {
        return errorResponse('Hiányzó vagy hibás kliensazonosító.', 400);
    }

    $state = sanitizePresenceState($payload['state'] ?? null);
    if ($state === null) {
        return errorResponse('Hibás jelenléti állapot.', 400);
    }

    $presenceDir = presenceDir($dataDir);
    if (!is_dir($presenceDir) && !mkdir($presenceDir, 0700, true) && !is_dir($presenceDir)) {
        return errorResponse('Nem sikerült létrehozni a jelenléti mappát.', 500);
    }

    $path = presencePath($dataDir, $name);
    $entries = loadJsonFile($path);
    if (!is_array($entries)) {
        $entries = [];
    }

    $now = time();
    $ttl = 15;
    $sanitizedEntries = [];
    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $entryClientId = sanitizeClientId($entry['clientId'] ?? '');
        $entryState = sanitizePresenceState($entry['state'] ?? null);
        $updatedAt = (int) ($entry['updatedAt'] ?? 0);
        if ($entryClientId === null || $entryState === null || $updatedAt < ($now - $ttl)) {
            continue;
        }
        $sanitizedEntries[$entryClientId] = [
            'clientId' => $entryClientId,
            'state' => $entryState,
            'updatedAt' => $updatedAt,
        ];
    }

    $sanitizedEntries[$clientId] = [
        'clientId' => $clientId,
        'state' => $state,
        'updatedAt' => $now,
    ];

    $stored = array_values($sanitizedEntries);
    if (file_put_contents($path, json_encode($stored, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
        return errorResponse('Nem sikerült frissíteni a jelenléti adatokat.', 500);
    }

    $clients = [];
    foreach ($stored as $entry) {
        $clients[] = array_merge(['clientId' => $entry['clientId']], $entry['state']);
    }

    return [
        'status' => 200,
        'body' => [
            'ok' => true,
            'clients' => $clients,
            'revision' => loadProjectRevision($dataDir, $name),
        ],
    ];
}

function sanitizeProjectName(mixed $value): ?string
{
    if (!is_string($value)) {
        return null;
    }

    $name = trim($value);
    if ($name === '') {
        return null;
    }
    if (str_contains($name, '..')) {
        return null;
    }

    $name = preg_replace('/[^\pL\pN._ -]+/u', '-', $name);
    if ($name === null) {
        return null;
    }
    $name = preg_replace('/\s+/u', ' ', $name);
    if ($name === null) {
        return null;
    }
    $name = trim((string) $name, " .\t\n\r\0\x0B-");

    if ($name === '') {
        return null;
    }

    preg_match_all('/./us', $name, $chars);
    $name = implode('', array_slice($chars[0] ?? [], 0, 80));
    return $name !== '' ? $name : null;
}

function sanitizeClientId(mixed $value): ?string
{
    if (!is_string($value)) {
        return null;
    }

    $value = trim($value);
    if ($value === '' || strlen($value) > 120) {
        return null;
    }

    return preg_match('/^[A-Za-z0-9_-]+$/', $value) === 1 ? $value : null;
}

function sanitizePresenceState(mixed $value): ?array
{
    if (!is_array($value)) {
        return null;
    }

    $tool = $value['tool'] ?? 'pencil';
    if (!is_string($tool) || !in_array($tool, ['pencil', 'eraser', 'picker', 'select', 'pin'], true)) {
        $tool = 'pencil';
    }

    $state = [
        'x' => sanitizeFiniteNumber($value['x'] ?? null),
        'y' => sanitizeFiniteNumber($value['y'] ?? null),
        'tool' => $tool,
        'drawing' => !empty($value['drawing']),
        'color' => sanitizeHexColor($value['color'] ?? null) ?? '#7c7cff',
        'alpha' => clampFloat(sanitizeFiniteNumber($value['alpha'] ?? 1.0) ?? 1.0, 0.0, 1.0),
        'useWorld' => !empty($value['useWorld']),
        'sizeWorld' => sanitizeFiniteNumber($value['sizeWorld'] ?? null),
        'sizeScreenPx' => sanitizeFiniteNumber($value['sizeScreenPx'] ?? null),
        'eraserWorldPx' => sanitizeFiniteNumber($value['eraserWorldPx'] ?? null),
        'eraserScreenPx' => sanitizeFiniteNumber($value['eraserScreenPx'] ?? null),
    ];

    $stroke = sanitizePresenceStroke($value['stroke'] ?? null);
    if ($stroke !== null) {
        $state['stroke'] = $stroke;
    }

    return $state;
}

function sanitizePresenceStroke(mixed $value): ?array
{
    if (!is_array($value)) {
        return null;
    }

    $rawPoints = is_array($value['points'] ?? null) ? $value['points'] : [];
    $points = [];
    foreach (array_slice($rawPoints, -160) as $point) {
        if (!is_array($point)) {
            continue;
        }
        $x = sanitizeFiniteNumber($point['x'] ?? null);
        $y = sanitizeFiniteNumber($point['y'] ?? null);
        if ($x === null || $y === null) {
            continue;
        }
        $points[] = ['x' => $x, 'y' => $y];
    }

    if ($points === []) {
        return null;
    }

    return [
        'color' => sanitizeHexColor($value['color'] ?? null) ?? '#7c7cff',
        'alpha' => clampFloat(sanitizeFiniteNumber($value['alpha'] ?? 1.0) ?? 1.0, 0.0, 1.0),
        'useWorld' => !empty($value['useWorld']),
        'sizeWorld' => sanitizeFiniteNumber($value['sizeWorld'] ?? null),
        'sizeScreenPx' => sanitizeFiniteNumber($value['sizeScreenPx'] ?? null),
        'points' => $points,
    ];
}

function projectPath(string $dataDir, string $name): string
{
    return $dataDir . DIRECTORY_SEPARATOR . $name . '.json';
}

function metaDir(string $dataDir): string
{
    return $dataDir . DIRECTORY_SEPARATOR . '_meta';
}

function presenceDir(string $dataDir): string
{
    return $dataDir . DIRECTORY_SEPARATOR . '_presence';
}

function metaPath(string $dataDir, string $name): string
{
    return metaDir($dataDir) . DIRECTORY_SEPARATOR . $name . '.json';
}

function presencePath(string $dataDir, string $name): string
{
    return presenceDir($dataDir) . DIRECTORY_SEPARATOR . $name . '.json';
}

function loadProjectRevision(string $dataDir, string $name): int
{
    $meta = loadJsonFile(metaPath($dataDir, $name));
    $revision = is_array($meta) ? (int) ($meta['revision'] ?? 0) : 0;
    return max(1, $revision);
}

function bumpProjectRevision(string $dataDir, string $name): int
{
    $dir = metaDir($dataDir);
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        return 0;
    }

    $path = metaPath($dataDir, $name);
    $meta = loadJsonFile($path);
    $revision = is_array($meta) ? (int) ($meta['revision'] ?? 0) : 0;
    $revision = max(0, $revision) + 1;
    $encoded = json_encode(['revision' => $revision, 'updatedAt' => time()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded === false || file_put_contents($path, $encoded, LOCK_EX) === false) {
        return 0;
    }

    return $revision;
}

function loadJsonFile(string $path): mixed
{
    if (!is_file($path)) {
        return null;
    }

    $content = file_get_contents($path);
    if ($content === false || $content === '') {
        return null;
    }

    return json_decode($content, true);
}

function sanitizeFiniteNumber(mixed $value): ?float
{
    if (!is_int($value) && !is_float($value)) {
        return null;
    }
    if (!is_finite((float) $value)) {
        return null;
    }
    return (float) $value;
}

function sanitizeHexColor(mixed $value): ?string
{
    if (!is_string($value)) {
        return null;
    }
    return preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1 ? strtolower($value) : null;
}

function clampFloat(float $value, float $min, float $max): float
{
    return max($min, min($max, $value));
}

function errorResponse(string $message, int $status): array
{
    return ['status' => $status, 'body' => ['ok' => false, 'error' => $message]];
}

function respond(array $response): void
{
    http_response_code((int) ($response['status'] ?? 200));
    echo json_encode($response['body'] ?? ['ok' => false, 'error' => 'Ismeretlen hiba.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
