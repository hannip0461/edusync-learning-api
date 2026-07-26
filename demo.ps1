param()

$ErrorActionPreference = 'Stop'
Set-Location $PSScriptRoot

$failureReport = (@(
    '<!doctype html>',
    '<html lang="en"><head><meta charset="utf-8"><title>EduSync Learning API Demo Report</title></head>',
    '<body><h1>EduSync Learning API Demo Report</h1><p class="FAIL">FAIL: demo did not complete.</p></body></html>'
) -join "`n") + "`n"
[System.IO.File]::WriteAllText((Join-Path $PSScriptRoot 'demo-result.html'), $failureReport, [System.Text.UTF8Encoding]::new($false))

function Html([object]$value) {
    return [System.Net.WebUtility]::HtmlEncode([string]$value)
}

docker compose config --quiet
if ($LASTEXITCODE -ne 0) {
    throw 'docker compose config failed.'
}
docker compose build | Out-Null
if ($LASTEXITCODE -ne 0) {
    throw 'docker compose build failed.'
}
docker compose up -d --force-recreate app | Out-Null
if ($LASTEXITCODE -ne 0) {
    throw 'docker compose up failed.'
}

$runnerOutput = docker compose exec -T app php tests/demo_runner.php
$runnerExitCode = $LASTEXITCODE
$reportLine = @($runnerOutput -split "`r?`n" | Where-Object { $_.TrimStart().StartsWith('{') })[-1]
if ([string]::IsNullOrWhiteSpace($reportLine)) {
    throw 'Demo runner did not return a report.'
}
$report = $reportLine | ConvertFrom-Json
$generatedAtUtc = [DateTimeOffset]::Parse(
    [string] $report.generated_at_utc,
    [Globalization.CultureInfo]::InvariantCulture,
    [Globalization.DateTimeStyles]::AssumeUniversal
).ToUniversalTime().ToString(
    "yyyy-MM-dd'T'HH:mm:ss'Z'",
    [Globalization.CultureInfo]::InvariantCulture
)

$rows = foreach ($scenario in $report.scenarios) {
    $result = if ($scenario.passed) { 'PASS' } else { 'FAIL' }
    $request = Html (($scenario.request | ConvertTo-Json -Compress -Depth 8))
    $response = Html (($scenario.response | ConvertTo-Json -Compress -Depth 8))
    $details = Html (($scenario.details | ConvertTo-Json -Compress -Depth 8))
    "<tr class='$result'><td>$(Html $scenario.name)</td><td>$result</td><td>$(Html $scenario.status)</td><td><code>$request</code></td><td><code>$response</code></td><td><code>$details</code></td></tr>"
}

$summary = if ($report.summary.passed -and $runnerExitCode -eq 0) { 'PASS' } else { 'FAIL' }
$finalState = Html (($report.final_state | ConvertTo-Json -Depth 8))
$limits = ($report.limitations | ForEach-Object { "<li>$(Html $_)</li>" }) -join [Environment]::NewLine
$html = @"
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>EduSync Learning API Demo Report</title>
  <style>
    body { background: #f5f7fb; color: #172033; font: 15px/1.5 system-ui, sans-serif; margin: 0; }
    main { max-width: 1400px; margin: 36px auto; padding: 0 24px 48px; }
    h1, h2 { margin-bottom: 8px; } .card { background: #fff; border: 1px solid #d9e0eb; border-radius: 10px; padding: 20px; margin-top: 18px; }
    .PASS { color: #096b36; } .FAIL { color: #b42318; } table { border-collapse: collapse; width: 100%; } th, td { border-top: 1px solid #e5e9f0; padding: 10px; text-align: left; vertical-align: top; }
    th { background: #f8fafc; } code { display: block; max-width: 360px; overflow-wrap: anywhere; white-space: pre-wrap; font: 12px/1.4 ui-monospace, monospace; }
  </style>
</head>
<body><main>
  <h1>EduSync Learning API Demo Report</h1>
  <p>Verified at $(Html $generatedAtUtc) with Docker Compose, HTTP API, and SQL Server. Credentials, HMAC signatures, and database passwords are omitted.</p>
  <section class="card"><h2>Overall: <span class="$summary">$summary</span></h2><p>$(Html (($report.environment | ConvertTo-Json -Compress)))</p></section>
  <section class="card"><h2>Scenario results</h2><table><thead><tr><th>Scenario</th><th>Result</th><th>HTTP / test status</th><th>Request</th><th>Response</th><th>Details</th></tr></thead><tbody>$($rows -join [Environment]::NewLine)</tbody></table></section>
  <section class="card"><h2>Scenario state and cleanup</h2><code>$finalState</code></section>
  <section class="card"><h2>Scope and limitations</h2><ul>$limits</ul></section>
</main></body></html>
"@

$html = ($html -replace "`r`n", "`n") -replace "`r", "`n"
[System.IO.File]::WriteAllText((Join-Path $PSScriptRoot 'demo-result.html'), $html, [System.Text.UTF8Encoding]::new($false))

docker compose up -d --force-recreate app | Out-Null
if ($LASTEXITCODE -ne 0) {
    throw 'final docker compose up failed.'
}
$health = Invoke-RestMethod -Uri 'http://127.0.0.1:8080/health' -Method Get
if ($summary -ne 'PASS' -or $health.database -ne 'connected' -or $health.probe -ne 1 -or $runnerExitCode -ne 0) {
    exit 1
}
