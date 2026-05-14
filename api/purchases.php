<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$file = __DIR__ . '/data/purchases.json';
if (!file_exists(dirname($file))) mkdir(dirname($file), 0777, true);
if (!file_exists($file)) file_put_contents($file, '{}');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    readfile($file);
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['id'], $input['user'], $input['purchased'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing fields']);
        exit;
    }
    $lock = fopen($file, 'c+');
    flock($lock, LOCK_EX);
    $raw = stream_get_contents($lock);
    $purchases = $raw ? json_decode($raw, true) : [];
    if (!is_array($purchases)) $purchases = [];
    $id = $input['id'];
    $user = $input['user'];
    $val = intval($input['purchased']);
    if (!isset($purchases[$id])) $purchases[$id] = [];
    if ($val === 0) {
        unset($purchases[$id][$user]);
        if (empty($purchases[$id])) unset($purchases[$id]);
    } else {
        $purchases[$id][$user] = $val;
    }
    ftruncate($lock, 0);
    rewind($lock);
    fwrite($lock, json_encode($purchases, JSON_UNESCAPED_UNICODE));
    flock($lock, LOCK_UN);
    fclose($lock);
    echo json_encode(['ok' => true]);
}
