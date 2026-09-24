$ErrorActionPreference = 'Stop'
$pidFile = Join-Path $PSScriptRoot 'server.pid'
if (!(Test-Path -LiteralPath $pidFile)) { exit }
$testPid = [int](Get-Content -LiteralPath $pidFile)
$testProcess = Get-CimInstance Win32_Process -Filter "ProcessId = $testPid"
if ($testProcess) {
    if ($testProcess.Name -ne 'php.exe' -or $testProcess.CommandLine -notlike '*127.0.0.1:8091*' -or $testProcess.CommandLine -notlike '*tests/router.php*') { throw 'PID no longer identifies this test server; refusing to stop it.' }
    Stop-Process -Id $testPid
}
Remove-Item -LiteralPath $pidFile
Write-Output 'Isolated test server stopped.'
