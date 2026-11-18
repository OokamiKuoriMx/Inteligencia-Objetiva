<?php
// PreToolUse (Edit|Write): impide editar config.php (credenciales). Usar config.example.php.
$in = json_decode(stream_get_contents(STDIN), true) ?: [];
$f = str_replace('\\', '/', (string) ($in['tool_input']['file_path'] ?? ''));
if (preg_match('#(^|/)modulo-visualizacion/config\.php$#i', $f)) {
    echo json_encode(['hookSpecificOutput' => [
        'hookEventName' => 'PreToolUse',
        'permissionDecision' => 'deny',
        'permissionDecisionReason' => 'config.php contiene credenciales y no se edita desde Claude. Cambia config.example.php o pide al usuario que edite config.php.',
    ]], JSON_UNESCAPED_UNICODE);
}
exit(0);
