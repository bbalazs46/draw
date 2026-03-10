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

    return ['status' => 200, 'body' => ['ok' => true, 'name' => $name, 'project' => $project]];
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

    return ['status' => 200, 'body' => ['ok' => true, 'name' => $name]];
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

function projectPath(string $dataDir, string $name): string
{
    return $dataDir . DIRECTORY_SEPARATOR . $name . '.json';
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
