param(
    [string]$OfficialRepo = (Join-Path $PSScriptRoot '../../../Xboard'),
    [Parameter(Mandatory)][string]$OutputPath
)
$ErrorActionPreference = 'Stop'
$commit = '4f48e61a2cbc6db5338872b6bdb45ef954ec1256'
$image = 'ghcr.io/cedar2025/xboard@sha256:7edd660fd3dd686370dd0e663cb278b64be0c1403549348a381710b27f840bc5'
$OfficialRepo = [IO.Path]::GetFullPath($OfficialRepo)
$OutputPath = [IO.Path]::GetFullPath($OutputPath)
$customRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
if ($OutputPath -notmatch '\.json$' -or $OutputPath.StartsWith($OfficialRepo + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase) -or $OutputPath.StartsWith($customRoot + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)) { throw 'Output must be a JSON report outside official and custom source trees.' }
New-Item -ItemType Directory -Force -Path (Split-Path $OutputPath -Parent) | Out-Null
[IO.File]::WriteAllText($OutputPath, "{`"taskId`":`"TASK-025`",`"status`":`"失败`",`"tests`":[]}`n", [Text.UTF8Encoding]::new($false))
$temporaryRoot = [IO.Path]::GetFullPath([IO.Path]::GetTempPath())
$temporary = Join-Path $temporaryRoot ('xboard-task025-' + [guid]::NewGuid().ToString('N'))
$container = 'xboard-task025-' + [guid]::NewGuid().ToString('N').Substring(0, 12)
$created = $false
$createAttempted = $false
$result = [pscustomobject]@{ schemaVersion = 1; taskId = 'TASK-025'; evidenceClass = 'non-production-simulation'; formalAcceptanceClaimed = $false; deviceClaimed = $false; sourceCommit = $commit; generatedAt = (Get-Date).ToString('o'); status = '失败'; tests = @() }
function Invoke-Native([string]$Program, [string[]]$Arguments) {
    $output = & $Program @Arguments 2>&1
    if ($LASTEXITCODE -ne 0) { throw "$Program failed with exit $LASTEXITCODE (arguments/output omitted for safety)" }
    return ($output -join "`n")
}
function Add-Check([string]$Name, [bool]$Passed, [string]$Details = '') {
    $result.tests += [pscustomobject]@{ name = $Name; passed = $Passed; details = $Details }
}
try {
    $OfficialRepo = (Resolve-Path -LiteralPath $OfficialRepo).Path
    if (Invoke-Native git @('-C', $OfficialRepo, 'status', '--porcelain')) { throw 'Official worktree must be clean; no files were changed.' }
    New-Item -ItemType Directory -Path $temporary | Out-Null
    $archive = Join-Path $temporary 'source.tar'
    Invoke-Native git @('-C', $OfficialRepo, '-c', 'core.autocrlf=false', '-c', 'core.eol=lf', 'archive', '--format=tar', "--output=$archive", $commit) | Out-Null
    $pluginRoot = Join-Path $customRoot 'plugins/MobileApp'
    $createAttempted = $true
    Invoke-Native docker @('run', '-d', '--name', $container, '--label', 'xboard.task=TASK-025', $image, 'sleep', 'infinity') | Out-Null
    $created = $true
    Invoke-Native docker @('cp', $archive, "${container}:/tmp/source.tar") | Out-Null
    Invoke-Native docker @('exec', $container, 'sh', '-lc', 'mkdir /audit && tar -xf /tmp/source.tar -C /audit && cp -a /www/vendor /audit/vendor') | Out-Null
    Invoke-Native docker @('exec', '-w', '/audit', '-e', 'COMPOSER_ALLOW_SUPERUSER=1', $container, 'composer', 'install', '--no-dev', '--no-scripts', '--no-interaction', '--prefer-dist') | Out-Null
    Invoke-Native docker @('network', 'disconnect', 'bridge', $container) | Out-Null
    Invoke-Native docker @('cp', $pluginRoot, "${container}:/audit/plugins/MobileApp") | Out-Null
    Invoke-Native docker @('cp', (Join-Path $PSScriptRoot 'audit.php'), "${container}:/audit/task-025-audit.php") | Out-Null
    $phpLint = Invoke-Native docker @('exec', $container, 'sh', '-lc', 'find /audit/plugins/MobileApp/Adapters /audit/plugins/MobileApp/Support /audit/plugins/MobileApp/Controllers /audit/plugins/MobileApp/Services -name "*.php" -exec php -l {} \; && php -l /audit/task-025-audit.php')
    Add-Check 'all_profile_php_syntax_valid' ($phpLint -notmatch 'Errors parsing|Parse error' -and $phpLint -match 'No syntax errors detected')
    Invoke-Native docker @('exec', $container, 'sh', '-lc', 'mkdir -p /audit/database && touch /audit/database/task025.sqlite') | Out-Null
    $raw = & docker exec -w /audit $container sh -lc 'php task-025-audit.php'
    $runtimeExit = $LASTEXITCODE
    $audit = $null
    try { $audit = ($raw -join "`n") | ConvertFrom-Json } catch { $audit = $null }
    Add-Check 'runtime_process_exit_zero' ($runtimeExit -eq 0) "exit=$runtimeExit"
    Add-Check 'runtime_json_status_passed' ($null -ne $audit -and $audit.taskId -eq 'TASK-025' -and $audit.status -eq 'passed' -and $audit.formalAcceptanceClaimed -eq $false) "status=$($audit.status)"
    if ($null -ne $audit) {
        foreach ($item in @($audit.tests)) {
            Add-Check ([string]$item.name) ([bool]$item.passed) (($item.details | ConvertTo-Json -Compress -Depth 6))
        }
        $result | Add-Member -NotePropertyName runtime -NotePropertyValue $audit
    }
    Add-Check 'official_checkout_unchanged' ((Invoke-Native git @('-C', $OfficialRepo, 'status', '--porcelain')) -eq '')
} catch {
    Add-Check 'runner_completed_without_exception' $false $_.Exception.Message
} finally {
    $cleaned = $true
    if ($createAttempted -or $created) {
        docker rm -f $container 2>$null | Out-Null
        $cleaned = [string]::IsNullOrWhiteSpace((docker ps -aq --filter "name=$container"))
    }
    if (Test-Path -LiteralPath $temporary) {
        Get-ChildItem -LiteralPath $temporary -Recurse -Force -ErrorAction SilentlyContinue | ForEach-Object { try { $_.Attributes = 'Normal' } catch {} }
        Remove-Item -LiteralPath $temporary -Recurse -Force -ErrorAction SilentlyContinue
        $cleaned = $cleaned -and -not (Test-Path -LiteralPath $temporary)
    }
    Add-Check 'temporary_container_and_source_removed' $cleaned
    $result.status = if (@($result.tests).Count -gt 0 -and @($result.tests | Where-Object { $_.passed -isnot [bool] -or $_.passed -ne $true }).Count -eq 0) { '通过' } else { '失败' }
    [IO.File]::WriteAllText($OutputPath, (($result | ConvertTo-Json -Depth 16) -replace "`r`n", "`n") + "`n", [Text.UTF8Encoding]::new($false))
}
if ($result.status -ne '通过') { throw 'TASK-025 runtime audit failed; do not advance.' }
Write-Output "TASK-025: $(@($result.tests).Count) checks passed; isolated runtime cleaned."
exit 0
