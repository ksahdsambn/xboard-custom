param([Parameter(Mandatory)][string]$OutputPath)
$ErrorActionPreference = 'Stop'
$temporary = Join-Path ([IO.Path]::GetTempPath()) ('xboard-runner-review-' + [guid]::NewGuid().ToString('N'))
$tests = [Collections.Generic.List[object]]::new()
function Save-Json($value, [string]$path) { [IO.File]::WriteAllText($path, (($value | ConvertTo-Json -Depth 8) -replace "`r`n", "`n") + "`n", [Text.UTF8Encoding]::new($false)) }
try {
    $copiedDirectory = Join-Path $temporary 'custom/tests/task-005'
    New-Item -ItemType Directory -Path $copiedDirectory -Force | Out-Null
    $runner = Join-Path $copiedDirectory 'run.ps1'
    Copy-Item -LiteralPath (Join-Path $PSScriptRoot 'task-005/run.ps1') -Destination $runner
    $missingRepo = Join-Path $temporary 'missing-official'
    $resultPath = Join-Path $temporary 'old-success.json'
    Save-Json @{taskId='TASK-005';status='通过';tests=@(@{name='old';passed=$true})} $resultPath
    & pwsh -NoProfile -File $runner -OfficialRepo $missingRepo -OutputPath $resultPath 2>$null | Out-Null
    $code = $LASTEXITCODE
    $result = Get-Content $resultPath -Raw | ConvertFrom-Json
    $tests.Add([ordered]@{name='setup_failure_invalidates_old_success';passed=($code -ne 0 -and $result.status -eq '失败')})
    $official = Join-Path $temporary 'official'
    New-Item -ItemType Directory -Path $official | Out-Null
    foreach ($case in @(@{name='official_source_output_rejected';path=(Join-Path $official 'sentinel.json')}, @{name='custom_source_output_rejected';path=(Join-Path $copiedDirectory 'sentinel.json')}, @{name='non_json_output_rejected';path=(Join-Path $temporary 'sentinel.txt')})) {
        Save-Json @{sentinel='must-remain-unchanged'} $case.path
        $before = (Get-FileHash $case.path).Hash
        & pwsh -NoProfile -File $runner -OfficialRepo $official -OutputPath $case.path 2>$null | Out-Null
        $tests.Add([ordered]@{name=$case.name;passed=($LASTEXITCODE -ne 0 -and (Get-FileHash $case.path).Hash -eq $before)})
    }
} finally {
    $resolved = [IO.Path]::GetFullPath($temporary)
    if (-not $resolved.StartsWith([IO.Path]::GetFullPath([IO.Path]::GetTempPath()),[StringComparison]::OrdinalIgnoreCase) -or (Split-Path $resolved -Leaf) -notmatch '^xboard-runner-review-[a-f0-9]{32}$') { throw 'Unsafe test cleanup path' }
    if (Test-Path -LiteralPath $resolved) { Remove-Item -LiteralPath $resolved -Recurse -Force }
}
$passed = $tests.Count -eq 4 -and @($tests | Where-Object { -not $_.passed }).Count -eq 0
$report = [ordered]@{schemaVersion=1;taskId='TASK-005';generatedAt=(Get-Date).ToString('o');status=if($passed){'通过'}else{'失败'};tests=$tests;runnerSha256=(Get-FileHash (Join-Path $PSScriptRoot 'task-005/run.ps1')).Hash}
$OutputPath = [IO.Path]::GetFullPath($OutputPath)
New-Item -ItemType Directory -Force -Path (Split-Path $OutputPath -Parent) | Out-Null
Save-Json $report $OutputPath
"Runner failure/protection tests: $(@($tests | Where-Object passed).Count)/$($tests.Count)"
if (-not $passed) { exit 1 }
exit 0
