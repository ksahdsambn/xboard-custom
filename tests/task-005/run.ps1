param(
    [string]$OfficialRepo = (Join-Path $PSScriptRoot '../../../Xboard'),
    [Parameter(Mandatory)][string]$OutputPath
)
$ErrorActionPreference = 'Stop'
$commit = '4f48e61a2cbc6db5338872b6bdb45ef954ec1256'
$image = 'ghcr.io/cedar2025/xboard@sha256:7edd660fd3dd686370dd0e663cb278b64be0c1403549348a381710b27f840bc5'
$OfficialRepo = (Resolve-Path -LiteralPath $OfficialRepo).Path
$OutputPath = [IO.Path]::GetFullPath($OutputPath)
$temporaryRoot = [IO.Path]::GetFullPath([IO.Path]::GetTempPath())
$temporary = Join-Path $temporaryRoot ('xboard-task005-' + [guid]::NewGuid().ToString('N'))
$container = 'xboard-task005-' + [guid]::NewGuid().ToString('N').Substring(0, 12)
$created = $false
$result = [pscustomobject]@{ schemaVersion = 1; taskId = 'TASK-005'; evidenceClass = 'isolated-upstream-runtime'; sourceCommit = $commit; generatedAt = (Get-Date).ToString('o'); status = '失败'; tests = @() }
function Invoke-Native([string]$Program, [string[]]$Arguments) {
    $output = & $Program @Arguments 2>&1
    if ($LASTEXITCODE -ne 0) { throw "$Program failed with exit $LASTEXITCODE (arguments/output omitted for safety)" }
    return ($output -join "`n")
}
function Add-Check([string]$Name, [bool]$Passed) {
    $result.tests += [pscustomobject]@{ name = $Name; passed = $Passed; details = @{} }
}
try {
    $before = Invoke-Native git @('-C', $OfficialRepo, 'status', '--porcelain')
    if ($before) { throw 'Official worktree must be clean; no files were changed.' }
    $head = Invoke-Native git @('-C', $OfficialRepo, 'rev-parse', 'HEAD')
    New-Item -ItemType Directory -Path $temporary | Out-Null
    $archive = Join-Path $temporary 'source.tar'
    Invoke-Native git @('-C', $OfficialRepo, 'archive', '--format=tar', "--output=$archive", $commit) | Out-Null
    $sourceDirectory = Join-Path $temporary 'source'
    New-Item -ItemType Directory -Path $sourceDirectory | Out-Null
    Invoke-Native tar @('-xf', $archive, '-C', $sourceDirectory) | Out-Null
    $sourceFiles = @(
        'composer.json', 'composer.lock', 'app/Services/Plugin/PluginManager.php',
        'app/Services/Plugin/AbstractPlugin.php', 'app/Http/Middleware/InitializePlugins.php',
        'app/Providers/RouteServiceProvider.php', 'app/Http/Kernel.php', 'app/Console/Kernel.php',
        'app/Http/Middleware/User.php', 'app/Exceptions/Handler.php', 'app/Exceptions/ApiException.php',
        'app/Helpers/ApiResponse.php', 'app/Services/AuthService.php', 'app/Services/Auth/LoginService.php',
        'app/Services/Auth/RegisterService.php', 'app/Services/CaptchaService.php', 'app/Services/UserService.php',
        'app/Services/PlanService.php', 'app/Services/ServerService.php', 'app/Services/TicketService.php',
        'app/Http/Resources/NodeResource.php', 'app/Http/Controllers/V1/User/NoticeController.php',
        'app/Http/Controllers/V1/User/TicketController.php', 'app/Models/Server.php', 'app/Models/Plan.php',
        'app/Models/InviteCode.php', 'app/Models/User.php', 'app/Models/Ticket.php',
        'app/Models/TicketMessage.php', 'app/Helpers/Functions.php', 'app/Support/Setting.php', 'config/cache.php'
    )
    $sourceHashes = @($sourceFiles | ForEach-Object {
        [ordered]@{ path = $_; sha256 = (Get-FileHash -LiteralPath (Join-Path $sourceDirectory $_) -Algorithm SHA256).Hash }
    })
    $fixtureHashes = @(Get-ChildItem -LiteralPath $PSScriptRoot -Recurse -File | Sort-Object FullName | ForEach-Object {
        [ordered]@{ path = [IO.Path]::GetRelativePath($PSScriptRoot, $_.FullName).Replace('\', '/'); sha256 = (Get-FileHash -LiteralPath $_.FullName -Algorithm SHA256).Hash }
    })
    Invoke-Native docker @('run', '-d', '--name', $container, '--label', 'xboard.task=TASK-005', $image, 'sleep', 'infinity') | Out-Null
    $created = $true
    Invoke-Native docker @('cp', $archive, "${container}:/tmp/source.tar") | Out-Null
    Invoke-Native docker @('exec', $container, 'sh', '-lc', 'mkdir /audit && tar -xf /tmp/source.tar -C /audit && cp -a /www/vendor /audit/vendor') | Out-Null
    # The image is only the PHP runtime/cache. Resolve production packages from the frozen lock.
    $composerOutput = Invoke-Native docker @('exec', '-w', '/audit', '-e', 'COMPOSER_ALLOW_SUPERUSER=1', $container, 'composer', 'install', '--no-dev', '--no-scripts', '--no-interaction', '--prefer-dist')
    Invoke-Native docker @('network', 'disconnect', 'bridge', $container) | Out-Null
    Invoke-Native docker @('cp', (Join-Path $PSScriptRoot 'Task005Probe'), "${container}:/audit/plugins/Task005Probe") | Out-Null
    Invoke-Native docker @('cp', (Join-Path $PSScriptRoot 'audit.php'), "${container}:/audit/task005-audit.php") | Out-Null
    $phpLint = Invoke-Native docker @('exec', $container, 'sh', '-lc', 'find /audit/plugins/Task005Probe -name "*.php" -exec php -l {} \; && php -l /audit/task005-audit.php')
    $raw = & docker exec -w /audit $container sh -lc 'touch /audit/database/task005.sqlite && php task005-audit.php'
    $runtimeExit = $LASTEXITCODE
    $result = ($raw -join "`n") | ConvertFrom-Json
    Add-Check 'runtime_process_exit_zero' ($runtimeExit -eq 0)
    Add-Check 'all_probe_php_syntax_valid' ($phpLint -notmatch 'Errors parsing|Parse error' -and $phpLint -match 'No syntax errors detected')
    $isolated = (Invoke-Native docker @('inspect', $container) | ConvertFrom-Json)[0]
    Add-Check 'runtime_no_network_ports_or_host_mounts' (@($isolated.NetworkSettings.Networks.PSObject.Properties).Count -eq 0 -and @($isolated.Mounts).Count -eq 0 -and @($isolated.HostConfig.PortBindings.PSObject.Properties).Count -eq 0)
    $hashesMatch = $true
    foreach ($item in $sourceHashes) {
        $runtimeHash = Invoke-Native docker @('exec', $container, 'sha256sum', "/audit/$($item.path)")
        if (($runtimeHash -split '\s+')[0] -ne $item.sha256) { $hashesMatch = $false }
    }
    Add-Check 'audited_official_files_unmodified_in_runtime' $hashesMatch
    Add-Check 'official_checkout_unchanged' ((Invoke-Native git @('-C', $OfficialRepo, 'status', '--porcelain')) -eq '' -and (Invoke-Native git @('-C', $OfficialRepo, 'rev-parse', 'HEAD')) -eq $head)
    $result | Add-Member -NotePropertyName provenance -NotePropertyValue ([ordered]@{
        image = $image; sourceArchiveSha256 = (Get-FileHash -LiteralPath $archive -Algorithm SHA256).Hash
        sourceFiles = $sourceHashes; fixtureFiles = $fixtureHashes
        composerManifestLockWarning = ($composerOutput -match 'lock file is not up to date')
        dependencyPolicy = 'composer install --no-dev --no-scripts; no composer update; exact locked versions checked in PHP'
    })
} catch {
    Add-Check 'runner_completed_without_exception' $false
    throw
} finally {
    $cleaned = $true
    if ($created) {
        $identity = (Invoke-Native docker @('inspect', $container) | ConvertFrom-Json)[0]
        if ($identity.Config.Labels.'xboard.task' -ne 'TASK-005') { throw 'Refusing to remove unowned container' }
        Invoke-Native docker @('rm', '-f', $container) | Out-Null
        $cleaned = (Invoke-Native docker @('ps', '-aq', '--filter', "name=^/$container$")) -eq ''
    }
    if (Test-Path -LiteralPath $temporary) {
        $resolvedTemporary = (Resolve-Path -LiteralPath $temporary).Path
        if (-not $resolvedTemporary.StartsWith($temporaryRoot, [StringComparison]::OrdinalIgnoreCase) -or (Split-Path $resolvedTemporary -Leaf) -notmatch '^xboard-task005-[a-f0-9]{32}$') { throw 'Unsafe temporary cleanup path' }
        Remove-Item -LiteralPath $resolvedTemporary -Recurse -Force
        $cleaned = $cleaned -and -not (Test-Path -LiteralPath $resolvedTemporary)
    }
    if ($null -ne $result) {
        Add-Check 'temporary_container_and_source_removed' $cleaned
        $result.status = if (@($result.tests | Where-Object { $_.passed -ne $true }).Count -eq 0) { '通过' } else { '失败' }
        New-Item -ItemType Directory -Force -Path (Split-Path $OutputPath -Parent) | Out-Null
        [IO.File]::WriteAllText($OutputPath, (($result | ConvertTo-Json -Depth 12) -replace "`r`n", "`n") + "`n", [Text.UTF8Encoding]::new($false))
    }
}
if ($result.status -ne '通过') { throw 'TASK-005 runtime audit failed; do not advance.' }
Write-Output "TASK-005: $(@($result.tests).Count)/$(@($result.tests).Count) passed; isolated runtime cleaned."
exit 0
