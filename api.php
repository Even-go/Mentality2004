<?php
/**
 * 微电子2204 虚拟心理班会 - API 后端
 * 
 * 部署方式：
 *   1. 用 phpMyAdmin 导入 schema.sql 建表
 *   2. 修改下方 $DB_* 配置为你的数据库连接信息
 *   3. 将本文件与 index.html 放到同一网站目录
 *   4. 确保 PHP 已开启 pdo_mysql 扩展
 */

// 先输出缓冲，防止 InfinityFree 注入广告导致 header 失效
ob_start();

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Max-Age: 86400');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); ob_end_clean(); exit; }

// ═══════════ 数据库配置（InfinityFree） ═══════════
$DB_HOST = 'sql308.infinityfree.com';
$DB_NAME = 'if0_41966469_mentality_db';
$DB_USER = 'if0_41966469';
$DB_PASS = 'SIK1bpSUbTOUClG';
$DB_CHARSET = 'utf8mb4';

// ═══════════ 连接数据库 ═══════════
function getDB() {
    global $DB_HOST, $DB_NAME, $DB_USER, $DB_PASS, $DB_CHARSET;
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=$DB_CHARSET";
        $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}

// ═══════════ 路由 ═══════════
$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'record':
            handleRecord();
            break;
        case 'records':
            handleGetRecords();
            break;
        case 'import':
            handleImport();
            break;
        case 'clear':
            handleClear();
            break;
        case 'ping':
            jsonOk(['pong' => true, 'db' => 'connected']);
            break;
        default:
            jsonError('Unknown action. Available: record, records, import, clear, ping');
    }
} catch (Exception $e) {
    jsonError('Server error: ' . $e->getMessage());
}

// ═══════════ 处理函数 ═══════════

/** POST 提交一条记录 */
function handleRecord() {
    $input = getJsonInput();
    $required = ['type', 'question', 'answer', 'userId'];
    foreach ($required as $f) {
        if (empty($input[$f])) jsonError("缺少字段: $f");
    }

    $db = getDB();
    $stmt = $db->prepare(
        'INSERT INTO records (type, question, answer, opt_idx, user_id) VALUES (:t, :q, :a, :oi, :uid)'
    );
    $stmt->execute([
        ':t'   => $input['type'],
        ':q'   => $input['question'],
        ':a'   => $input['answer'],
        ':oi'  => $input['optIdx'] ?? null,
        ':uid' => $input['userId'],
    ]);

    jsonOk(['id' => (int)$db->lastInsertId()]);
}

/** GET 获取所有记录（管理员用） */
function handleGetRecords() {
    $pwd = $_GET['pwd'] ?? '';
    verifyAdmin($pwd);

    $db = getDB();
    $stmt = $db->query('SELECT id, type, question, answer, opt_idx, user_id, created_at FROM records ORDER BY created_at DESC LIMIT 5000');
    $rows = $stmt->fetchAll();

    // 转换字段名为 JS 风格
    $records = array_map(function($r) {
        return [
            'type'   => $r['type'],
            'q'      => $r['question'],
            'text'   => $r['answer'],
            'optIdx' => $r['opt_idx'] !== null ? (int)$r['opt_idx'] : null,
            'userId' => $r['user_id'],
            'ts'     => strtotime($r['created_at']) * 1000, // JS timestamp (ms)
        ];
    }, $rows);

    jsonOk(['records' => $records, 'total' => count($records)]);
}

/** POST 批量导入 JSON（管理员用，合并而不是覆盖） */
function handleImport() {
    $input = getJsonInput();
    $pwd = $input['pwd'] ?? '';
    verifyAdmin($pwd);

    $data = $input['data'] ?? [];
    if (!is_array($data)) jsonError('data 必须是数组');
    if (count($data) === 0) jsonOk(['imported' => 0, 'total' => 0]);

    $db = getDB();
    $stmt = $db->prepare(
        'INSERT INTO records (type, question, answer, opt_idx, user_id, created_at) VALUES (:t, :q, :a, :oi, :uid, FROM_UNIXTIME(:ts))'
    );

    $imported = 0;
    foreach ($data as $r) {
        if (empty($r['type']) || empty($r['q'])) continue;
        $ts = !empty($r['ts']) ? intval($r['ts'] / 1000) : time();
        $stmt->execute([
            ':t'   => $r['type'],
            ':q'   => $r['q'],
            ':a'   => $r['text'] ?? $r['optText'] ?? '',
            ':oi'  => $r['optIdx'] ?? null,
            ':uid' => $r['userId'] ?? 'imported',
            ':ts'  => $ts,
        ]);
        $imported++;
    }

    $total = (int)$db->query('SELECT COUNT(*) FROM records')->fetchColumn();
    jsonOk(['imported' => $imported, 'total' => $total]);
}

/** POST 清空数据（管理员用） */
function handleClear() {
    $input = getJsonInput();
    verifyAdmin($input['pwd'] ?? '');
    $db = getDB();
    $db->exec('DELETE FROM records');
    jsonOk(['cleared' => true]);
}

// ═══════════ 辅助函数 ═══════════

function verifyAdmin($pwd) {
    $db = getDB();
    $stmt = $db->prepare('SELECT `value` FROM config WHERE `key` = ?');
    $stmt->execute(['admin_password']);
    $row = $stmt->fetch();
    $correct = $row ? $row['value'] : '2204admin';
    if ($pwd !== $correct) {
        jsonError('密码错误或未提供 ?pwd= 参数', 403);
    }
}

function getJsonInput() {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) jsonError('请求体必须是 JSON');
    return $data;
}

function jsonOk($data, $code = 200) {
    http_response_code($code);
    echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonError($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}
