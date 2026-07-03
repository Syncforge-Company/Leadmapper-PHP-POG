$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $PSScriptRoot
$pidFile = Join-Path $env:TEMP 'leadmapper-dev.pid'
$stdoutFile = Join-Path $env:TEMP 'leadmapper-dev.out.log'
$stderrFile = Join-Path $env:TEMP 'leadmapper-dev.err.log'
$hostName = '127.0.0.1'
$port = 8000

if (Test-Path -LiteralPath $pidFile) {
  $existingPid = Get-Content -LiteralPath $pidFile -ErrorAction SilentlyContinue
  if ($existingPid) {
    $runningProcess = Get-Process -Id ([int] $existingPid) -ErrorAction SilentlyContinue
    if ($runningProcess) {
      "LeadMapper dev server already running at http://${hostName}:${port}/index.php (PID $existingPid)"
      exit 0
    }
  }

  Remove-Item -LiteralPath $pidFile -Force -ErrorAction SilentlyContinue
}

$process = Start-Process `
  -FilePath 'php' `
  -ArgumentList @('-S', "${hostName}:${port}", '-t', $projectRoot) `
  -WorkingDirectory $projectRoot `
  -RedirectStandardOutput $stdoutFile `
  -RedirectStandardError $stderrFile `
  -PassThru

Start-Sleep -Seconds 1
$process.Refresh()

if ($process.HasExited) {
  if (Test-Path -LiteralPath $stderrFile) {
    Get-Content -LiteralPath $stderrFile
  }

  throw 'Failed to start LeadMapper dev server.'
}

Set-Content -LiteralPath $pidFile -Value $process.Id

"LeadMapper dev server started at http://${hostName}:${port}/index.php (PID $($process.Id))"
"Stdout log: $stdoutFile"
"Stderr log: $stderrFile"
