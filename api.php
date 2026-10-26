<?php
require_once 'config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$action = $_GET['action'] ?? '';

// ─────────────────────────────────────────
// HEALTH CHECK
// ─────────────────────────────────────────
if ($action === 'health') {
    echo json_encode([
        'status'       => 'ok',
        'hasClaudeKey' => CLAUDE_API_KEY !== 'your_claude_api_key_here',
        'hasGeminiKey' => GEMINI_API_KEY !== 'your_gemini_api_key_here',
    ]);
    exit;
}

// ─────────────────────────────────────────
// CLAUDE CHAT
// ─────────────────────────────────────────
if ($action === 'chat') {
    $body   = json_decode(file_get_contents('php://input'), true);
    $msgs   = $body['messages'] ?? [];
    $system = $body['system']   ?? 'You are Clamini, a helpful creative AI assistant.';

    $payload = json_encode([
        'model'      => CLAUDE_MODEL,
        'max_tokens' => 1024,
        'system'     => $system,
        'messages'   => $msgs
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . CLAUDE_API_KEY,
            'anthropic-version: 2023-06-01'
        ]
    ]);

    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($res, true);

    if ($code !== 200) {
        http_response_code($code);
        echo json_encode(['error' => $data['error']['message'] ?? 'Claude API error']);
        exit;
    }

    echo json_encode(['text' => $data['content'][0]['text'] ?? '']);
    exit;
}

// ─────────────────────────────────────────
// GEMINI CHAT
// ─────────────────────────────────────────
if ($action === 'gemini-chat') {
    $body   = json_decode(file_get_contents('php://input'), true);
    $msgs   = $body['messages'] ?? [];
    $system = $body['system']   ?? 'You are Clamini, a helpful creative AI assistant.';

    // Convert to Gemini format (user/model roles)
    $contents = [];
    foreach ($msgs as $m) {
        $contents[] = [
            'role'  => $m['role'] === 'assistant' ? 'model' : 'user',
            'parts' => [['text' => $m['content']]]
        ];
    }

    $payload = json_encode([
        'system_instruction' => ['parts' => [['text' => $system]]],
        'contents'           => $contents,
        'generationConfig'   => ['maxOutputTokens' => 1024]
    ]);

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . GEMINI_MODEL . ':generateContent?key=' . GEMINI_API_KEY;
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json']
    ]);

    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($res, true);

    if ($code !== 200) {
        http_response_code($code);
        echo json_encode(['error' => $data['error']['message'] ?? 'Gemini API error']);
        exit;
    }

    echo json_encode(['text' => $data['candidates'][0]['content']['parts'][0]['text'] ?? '']);
    exit;
}

// ─────────────────────────────────────────
// IMAGE ANALYSIS — Claude or Gemini Vision
// ─────────────────────────────────────────
if ($action === 'analyze') {
    if (empty($_FILES['image'])) {
        http_response_code(400);
        echo json_encode(['error' => 'No image uploaded']);
        exit;
    }

    $file   = $_FILES['image'];
    $mime   = $file['type'];
    $base64 = base64_encode(file_get_contents($file['tmp_name']));
    $prompt = $_POST['prompt'] ?? 'Describe and analyze this image in detail.';
    $model  = $_POST['model']  ?? 'claude';

    if ($model === 'gemini') {
        // ── Gemini Vision ──
        $payload = json_encode([
            'contents' => [[
                'parts' => [
                    ['inline_data' => ['mime_type' => $mime, 'data' => $base64]],
                    ['text' => $prompt]
                ]
            ]],
            'generationConfig' => ['maxOutputTokens' => 1024]
        ]);

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . GEMINI_MODEL . ':generateContent?key=' . GEMINI_API_KEY;
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json']
        ]);
        $res  = curl_exec($ch);
        curl_close($ch);
        $data = json_decode($res, true);
        echo json_encode(['text' => $data['candidates'][0]['content']['parts'][0]['text'] ?? $data['error']['message'] ?? 'No response']);
        exit;
    }

    // ── Claude Vision (default) ──
    $payload = json_encode([
        'model'      => CLAUDE_MODEL,
        'max_tokens' => 1024,
        'messages'   => [[
            'role'    => 'user',
            'content' => [
                ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $base64]],
                ['type' => 'text',  'text'   => $prompt]
            ]
        ]]
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . CLAUDE_API_KEY,
            'anthropic-version: 2023-06-01'
        ]
    ]);
    $res  = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($res, true);
    echo json_encode(['text' => $data['content'][0]['text'] ?? $data['error']['message'] ?? 'No response']);
    exit;
}

// ─────────────────────────────────────────
// LORE — GET
// ─────────────────────────────────────────
if ($action === 'lore' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $entries = file_exists(LORE_FILE) ? json_decode(file_get_contents(LORE_FILE), true) : [];
    echo json_encode($entries);
    exit;
}

// ─────────────────────────────────────────
// LORE — SAVE
// ─────────────────────────────────────────
if ($action === 'lore' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body    = json_decode(file_get_contents('php://input'), true);
    $entries = file_exists(LORE_FILE) ? json_decode(file_get_contents(LORE_FILE), true) : [];
    $entry   = [
        'id'        => time() . rand(100, 999),
        'title'     => htmlspecialchars($body['title']   ?? ''),
        'content'   => htmlspecialchars($body['content'] ?? ''),
        'createdAt' => date('c')
    ];
    $entries[] = $entry;
    file_put_contents(LORE_FILE, json_encode($entries, JSON_PRETTY_PRINT));
    echo json_encode($entry);
    exit;
}

// ─────────────────────────────────────────
// LORE — DELETE
// ─────────────────────────────────────────
if ($action === 'lore' && $_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $id      = $_GET['id'] ?? '';
    $entries = file_exists(LORE_FILE) ? json_decode(file_get_contents(LORE_FILE), true) : [];
    $entries = array_values(array_filter($entries, fn($e) => $e['id'] != $id));
    file_put_contents(LORE_FILE, json_encode($entries, JSON_PRETTY_PRINT));
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'Unknown action']);
