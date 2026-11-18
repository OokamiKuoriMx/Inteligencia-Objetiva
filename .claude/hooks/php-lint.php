<?php
// PostToolUse (Edit|Write): valida la sintaxis de cada .php editado. Si falla, se lo informa a Claude.
$in = json_decode(stream_get_contents(STDIN), true) ?: [];
$f = $in['tool_input']['file_path'] ?? ($in['tool_response']['filePath'] ?? '');
if (!$f || strtolower(pathinfo($f, PATHINFO_EXTENSION)) !== 'php' || !is_file($f)) { exit(0); }
exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($f) . ' 2>&1', $out, $code);
if ($code !== 0) {
    echo json_encode([
        'decision' => 'block',
        'reason' => "php -l falló en $f:\n" . implode("\n", $out) . "\nCorrige la sintaxis antes de continuar.",
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
exit(0);
