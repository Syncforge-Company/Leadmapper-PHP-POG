$ErrorActionPreference = 'Stop'

$pidFile = Join-Path $env:TEMP 'leadmapper-dev.pid'

if (!(Test-Path -LiteralPath $pidFile)) {
  'LeadMapper dev server is not running.'
  exit 0
}

$storedPid = Get-Content -LiteralPath $pidFile -ErrorAction SilentlyContinue
if (!$storedPid) {
  Remove-Item -LiteralPath $pidFile -Force -ErrorAction SilentlyContinue
  'LeadMapper dev server is not running.'
  exit 0
}

$runningProcess = Get-Process -Id ([int] $storedPid) -ErrorAction SilentlyContinue
if ($runningProcess) {
  Stop-Process -Id ([int] $storedPid)
}

Remove-Item -LiteralPath $pidFile -Force -ErrorAction SilentlyContinue
"LeadMapper dev server stopped (PID $storedPid)."
