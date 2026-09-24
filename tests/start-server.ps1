$ErrorActionPreference = 'Stop'
$workspace = Split-Path $PSScriptRoot -Parent
$pidFile = Join-Path $PSScriptRoot 'server.pid'
if (Test-Path -LiteralPath $pidFile) {
    $existing = Get-Process -Id ([int](Get-Content -LiteralPath $pidFile)) -ErrorAction SilentlyContinue
    if ($existing) { Write-Output 'A test server is already running; use stop-server.ps1 first.'; exit 1 }
}
$previous = $env:BARBER_DB_NAME
try {
    $env:BARBER_DB_NAME = 'barber_company_test'
    $testProcess = Start-Process -FilePath C:/xampp/php/php.exe -ArgumentList @('-d',('session.save_path="' + $env:TEMP + '"'),'-S','127.0.0.1:8091','tests/router.php') -WorkingDirectory $workspace -WindowStyle Hidden -RedirectStandardOutput (Join-Path $PSScriptRoot 'server-output.log') -RedirectStandardError (Join-Path $PSScriptRoot 'server-error.log') -PassThru
    $testProcess.Id | Set-Content -LiteralPath $pidFile
    Write-Output 'Test server: http://127.0.0.1:8091/Barber/'
} finally {
    if ($null -eq $previous) { Remove-Item Env:BARBER_DB_NAME -ErrorAction SilentlyContinue } else { $env:BARBER_DB_NAME = $previous }
}
