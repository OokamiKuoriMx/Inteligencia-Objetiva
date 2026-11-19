param(
    [ValidateRange(1024, 65535)][int]$Puerto = 8090,
    [switch]$ReiniciarDatos
)
$ErrorActionPreference = 'Stop'
$moduloIO = Split-Path $PSScriptRoot -Parent
if (-not (Get-Command php -ErrorAction SilentlyContinue)) { throw 'Instala PHP con pdo_mysql, curl y mbstring.' }
if (-not $env:LOCALAPPDATA) { throw 'No se encontró LOCALAPPDATA para guardar sesiones fuera de la nube.' }
$baseLocalIO = [IO.Path]::GetFullPath($env:LOCALAPPDATA)
# Rechaza carpetas redirigidas o sincronizadas antes de crear temporales.
if ($baseLocalIO -match '(?i)(OneDrive|Dropbox|Google Drive|iCloud)') { throw 'LOCALAPPDATA debe estar fuera de la nube.' }
$antecesorIO = Get-Item -LiteralPath $baseLocalIO -Force
while ($null -ne $antecesorIO) {
    if ($antecesorIO.LinkType) { throw "Ruta local redirigida: $($antecesorIO.FullName)" }
    $antecesorIO = $antecesorIO.Parent
}
$temporalIO = Join-Path $baseLocalIO 'Codex\Temp\io-demo'
$comprobarIO = $temporalIO
while (-not (Test-Path -LiteralPath $comprobarIO)) { $comprobarIO = Split-Path $comprobarIO -Parent }
if (Test-Path -LiteralPath $comprobarIO) {
    $antecesorIO = Get-Item -LiteralPath $comprobarIO -Force
    while ($antecesorIO.FullName -ne $baseLocalIO) {
        if ($antecesorIO.LinkType) { throw "Ruta temporal redirigida: $($antecesorIO.FullName)" }
        $antecesorIO = $antecesorIO.Parent
        if ($null -eq $antecesorIO) { throw 'No se pudo verificar la ruta temporal.' }
    }
}
$sesionesIO = Join-Path $temporalIO 'sesiones'
if ((Test-Path -LiteralPath $sesionesIO) -and (Get-Item -LiteralPath $sesionesIO -Force).LinkType) { throw 'La carpeta de sesiones está redirigida.' }
New-Item -ItemType Directory -Path $sesionesIO -Force | Out-Null
$previoTempIO = $env:TEMP; $previoTmpIO = $env:TMP
$previoLocalIO = $env:IO_LOCAL_TEMP; $previoPuertoIO = $env:IO_DEMO_PORT
try {
    $env:TEMP = $temporalIO; $env:TMP = $temporalIO
    $env:IO_LOCAL_TEMP = $sesionesIO; $env:IO_DEMO_PORT = [string]$Puerto
    $argumentosIO = @((Join-Path $moduloIO 'tests\sembrar-demo.php'))
    if ($ReiniciarDatos) { $argumentosIO += '--reiniciar' }
    & php @argumentosIO
    if ($LASTEXITCODE -ne 0) { throw 'No se pudo preparar la demo. Requiere MySQL local en 127.0.0.1:3307, usuario root sin contraseña.' }
    Write-Host "Demo local: http://127.0.0.1:$Puerto/ — no se hacen cargos. Ctrl+C para detener."
    & php -S "127.0.0.1:$Puerto" -t (Split-Path $moduloIO -Parent) (Join-Path $moduloIO 'tests\servidor-local.php')
} finally {
    $env:TEMP = $previoTempIO; $env:TMP = $previoTmpIO
    $env:IO_LOCAL_TEMP = $previoLocalIO; $env:IO_DEMO_PORT = $previoPuertoIO
}
