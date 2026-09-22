<?php
function storage_dir(): string {
    $dir = dirname(__DIR__, 2) . '/storage';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir;
}

function transaction_file(string $transactionId): string {
    return storage_dir() . '/txn_' . hash('sha256', $transactionId) . '.json';
}

function token_index_file(string $token): string {
    return storage_dir() . '/tok_' . hash('sha256', $token) . '.json';
}

function save_json_file(string $path, array $data): bool {
    $tmp = $path . '.tmp';
    $ok = @file_put_contents(
        $tmp,
        json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        LOCK_EX
    );
    if ($ok === false) return false;
    @chmod($tmp, 0600);
    return @rename($tmp, $path);
}

function load_json_file(string $path): ?array {
    if (!is_file($path)) return null;
    $raw = @file_get_contents($path);
    if ($raw === false) return null;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function current_base_url(): string {
    $proto = 'http';
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $candidate = strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'])[0]));
        if (in_array($candidate, ['http','https'], true)) $proto = $candidate;
    } elseif (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $proto = 'https';
    }
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $host = preg_replace('/[^A-Za-z0-9.:-]/', '', $host);
    if ($host === '') return '';
    return $proto . '://' . $host;
}
