param([int]$Runs=1,[int]$TimeoutSeconds=180,[string]$EvidencePath='')
$ErrorActionPreference='Stop'
if ($Runs -lt 1 -or $Runs -gt 2) { throw 'Choose one fresh run or two runs to include reopening the same database.' }
if ($TimeoutSeconds -lt 60 -or $TimeoutSeconds -gt 300) { throw 'Choose a UI preflight timeout between 60 and 300 seconds.' }
# The diagnostic launcher has a mutex scoped to this fresh test-data directory.
$projectRoot=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$distribution=Join-Path $projectRoot 'dist'
$launcher=Join-Path $distribution 'LifeOS.exe'
if (-not (Test-Path -LiteralPath $launcher)) { throw 'Publish the launcher or build the distribution first.' }
$temporaryRoot=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '.tmp'))
$data=[IO.Path]::GetFullPath((Join-Path $temporaryRoot ('ui-comfort-'+[guid]::NewGuid().ToString('N'))))
if (-not $data.StartsWith($temporaryRoot+[IO.Path]::DirectorySeparatorChar,[StringComparison]::OrdinalIgnoreCase)) { throw 'Unsafe test data directory.' }
New-Item -ItemType Directory -Path $data -Force | Out-Null
Copy-Item -LiteralPath (Join-Path $projectRoot 'app'),(Join-Path $projectRoot 'public') -Destination $distribution -Recurse -Force
$previousData=$env:LIFEOS_DATA_DIR
$previousSmoke=$env:LIFEOS_SMOKE_TEST
$previousProfile=$env:LIFEOS_PROFILE_TEST
$env:LIFEOS_DATA_DIR=$data
$env:LIFEOS_SMOKE_TEST='1'
Remove-Item Env:LIFEOS_PROFILE_TEST -ErrorAction SilentlyContinue
$runEvidence=[Collections.Generic.List[object]]::new()
$uiSuccess=$false
$uiFailure=$null
Write-Host ('Isolated UI data: '+$data)
try {
    for ($run=1;$run -le $Runs;$run++) {
        $resultPath=Join-Path $data 'launcher-smoke.json'
        Remove-Item -LiteralPath $resultPath -ErrorAction SilentlyContinue
        Remove-Item -LiteralPath (Join-Path $data 'window-chrome-close.json') -ErrorAction SilentlyContinue
        $process=Start-Process -FilePath $launcher -WorkingDirectory $distribution -WindowStyle Hidden -PassThru
        $deadline=[DateTime]::UtcNow.AddSeconds($TimeoutSeconds)
        while (-not $process.WaitForExit(10000) -and [DateTime]::UtcNow -lt $deadline) { Write-Host 'UI preflight is capturing layouts and checking workflows...' }
        if (-not $process.HasExited) { $process.Kill(); throw 'UI preflight timed out; its isolated data was preserved.' }
        if (-not (Test-Path -LiteralPath $resultPath)) { throw 'The launcher did not write fresh UI evidence.' }
        $result=Get-Content -LiteralPath $resultPath -Raw | ConvertFrom-Json
        Copy-Item -LiteralPath $resultPath -Destination (Join-Path $data ('preflight-'+$run+'.json'))
        if (-not $result.success) { throw ('UI preflight failed: '+($result | ConvertTo-Json -Depth 8 -Compress)) }
        if (-not $result.checks -or @($result.checks.PSObject.Properties).Count -eq 0 -or @($result.checks.PSObject.Properties | Where-Object { $_.Value -ne $true }).Count -gt 0) { throw 'Desktop evidence contains an absent or failed check.' }
        $chrome=Get-Content -LiteralPath (Join-Path $data 'window-chrome-close.json') -Raw | ConvertFrom-Json
        if (-not $chrome.closedByControl) { throw 'The glass close control did not close the native window.' }
        if (Get-Process -Id $result.phpPid -ErrorAction SilentlyContinue) { throw 'The test PHP process survived window close.' }
        $runEvidence.Add([ordered]@{run=$run;checkCount=@($result.checks.PSObject.Properties).Count;resultFile=(Join-Path $data ('preflight-'+$run+'.json'));closedByControl=[bool]$chrome.closedByControl;phpExited=$true})
        Write-Host ('UI preflight '+$run+': '+@($result.checks.PSObject.Properties).Count+' checks passed; PHP exited.')
    }
    Write-Host ('Evidence and screenshots: '+$data)
    $uiSuccess=$true
} catch {
    $uiFailure=$_.Exception.Message
    throw
} finally {
    $env:LIFEOS_DATA_DIR=$previousData
    $env:LIFEOS_SMOKE_TEST=$previousSmoke
    $env:LIFEOS_PROFILE_TEST=$previousProfile
    if ($EvidencePath -ne '') {
        $evidenceFile=[IO.Path]::GetFullPath($EvidencePath)
        $parent=[IO.Path]::GetDirectoryName($evidenceFile)
        if (-not (Test-Path -LiteralPath $parent)) { New-Item -ItemType Directory -Path $parent -Force | Out-Null }
        $evidence=[ordered]@{success=$uiSuccess;failure=$uiFailure;dataDirectory=$data;screenshotDirectory=(Join-Path $data 'smoke-screenshots');visualReviewStatus='pending';runs=@($runEvidence.ToArray())}
        [IO.File]::WriteAllText($evidenceFile,($evidence | ConvertTo-Json -Depth 8),[Text.UTF8Encoding]::new($false))
    }
}
