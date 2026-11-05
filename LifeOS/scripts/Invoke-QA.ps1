#requires -Version 5.1
[CmdletBinding()]
param(
    [switch]$Desktop,
    [ValidateRange(60,300)][int]$DesktopTimeoutSeconds=180,
    [string]$Php='php',
    [string]$Python='python',
    [string]$Node='node',
    [string]$Dotnet=''
)
$ErrorActionPreference='Stop'
$projectRoot=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$runId=[DateTime]::UtcNow.ToString('yyyyMMdd-HHmmss')+'-'+[guid]::NewGuid().ToString('N').Substring(0,8)
$evidence=Join-Path $projectRoot ('tests/.tmp/qa-'+$runId)
$logs=Join-Path $evidence 'logs'
New-Item -ItemType Directory -Path $logs -Force | Out-Null
$stages=[Collections.Generic.List[object]]::new()
$desktopPayload=$null
$script:qaFailed=$false
$started=[DateTime]::UtcNow
$utf8=[Text.UTF8Encoding]::new($false)
$previousData=$env:LIFEOS_DATA_DIR
$previousSmoke=$env:LIFEOS_SMOKE_TEST
$previousProfile=$env:LIFEOS_PROFILE_TEST
$previousQaPhp=$env:LIFEOS_QA_PHP
$originalLocation=Get-Location

function Resolve-Tool([string]$Tool) {
    try { return (Get-Command -Name $Tool -CommandType Application -ErrorAction Stop | Select-Object -First 1).Source }
    catch { Stop-QaBlocked ('Required tool was not found: '+$Tool) }
}
function Stop-QaBlocked([string]$Message) {
    $failure=[InvalidOperationException]::new($Message)
    $failure.Data['qaStatus']='blocked'
    throw $failure
}
function Write-QaLog([string]$Message) {
    [IO.File]::AppendAllText($script:activeLog,$Message+[Environment]::NewLine,$utf8)
}
function Invoke-CheckedNative([string]$Command,[string[]]$Arguments) {
    Write-QaLog ('> '+$Command+' '+($Arguments -join ' '))
    $savedPreference=$ErrorActionPreference
    try {
        # Windows PowerShell wraps native stderr as ErrorRecord; the exit code is
        # the process result. Keep stderr in the evidence, even for exit code 0.
        $ErrorActionPreference='Continue'
        $captured=@(& $Command @Arguments 2>&1)
        $code=$LASTEXITCODE
    } finally { $ErrorActionPreference=$savedPreference }
    foreach($line in $captured) { Write-QaLog $line.ToString() }
    Write-QaLog ('Exit code: '+$code)
    if ($code -ne 0) { throw ('Command exited '+$code+'. See '+$script:activeLog) }
}
function Invoke-QaStage([string]$Name,[scriptblock]$Action) {
    $script:activeLog=Join-Path $logs ($Name+'.log')
    [IO.File]::WriteAllText($script:activeLog,'',$utf8)
    $timer=[Diagnostics.Stopwatch]::StartNew()
    Write-Host ('Running '+$Name+'...')
    $status='passed'; $failure=$null
    try { & $Action } catch {
        $status=if($_.Exception.Data['qaStatus'] -eq 'blocked'){'blocked'}else{'failed'}
        $script:qaFailed=$true; $failure=$_.Exception.Message
        Write-QaLog ('FAIL: '+$failure)
    }
    $timer.Stop()
    # Strip Get-Content's extended PowerShell metadata before JSON serialization.
    $tail=@(Get-Content -LiteralPath $script:activeLog -Encoding UTF8 -Tail 8 | ForEach-Object { $_.ToString() })
    $stages.Add([ordered]@{name=$Name;status=$status;seconds=[Math]::Round($timer.Elapsed.TotalSeconds,2);log='logs/'+$Name+'.log';failure=$failure;outputTail=$tail})
    Write-Host ('['+$status.ToUpperInvariant()+'] '+$Name)
    if ($failure) {
        Write-Host $failure
        # Keep the actual traceback visible even when artifact upload is unavailable.
        Get-Content -LiteralPath $script:activeLog -Encoding UTF8 -Tail 60 | ForEach-Object { Write-Host $_ }
    }
}
function Get-SourceSnapshot {
    $files=@(Get-ChildItem -LiteralPath (Join-Path $projectRoot 'app'),(Join-Path $projectRoot 'public') -Recurse -File)
    foreach($folder in @('launcher','scripts','tests')) {
        $folderRoot=Join-Path $projectRoot $folder
        $files+=@(Get-ChildItem -LiteralPath $folderRoot -Recurse -File | Where-Object {
            $relative=$_.FullName.Substring($folderRoot.Length+1)
            $relative -notmatch '(^|[\\/])(\.tmp|bin|obj|__pycache__)([\\/]|$)' -and $_.Extension -In @('.cs','.csproj','.ps1','.php','.py','.js','.cjs','.mjs')
        })
    }
    foreach($name in @('composer.json','composer.lock','phpunit.xml','php.ini','lifeos.iss','build.ps1')) {
        if(Test-Path -LiteralPath (Join-Path $projectRoot $name)) { $files+=Get-Item -LiteralPath (Join-Path $projectRoot $name) }
    }
    return @($files | Sort-Object FullName -Unique | ForEach-Object {
        [ordered]@{path=$_.FullName.Substring($projectRoot.Length+1).Replace('\','/');sha256=(Get-FileHash -LiteralPath $_.FullName -Algorithm SHA256).Hash.ToLowerInvariant()}
    })
}

try {
    Set-Location -LiteralPath $projectRoot
    $env:LIFEOS_DATA_DIR=Join-Path $evidence 'isolated-data'
    Remove-Item Env:LIFEOS_SMOKE_TEST,Env:LIFEOS_PROFILE_TEST -ErrorAction SilentlyContinue
    $sourceBefore=Get-SourceSnapshot
    [IO.File]::WriteAllText((Join-Path $evidence 'source-before.json'),(ConvertTo-Json -InputObject $sourceBefore -Depth 5),$utf8)

    Invoke-QaStage 'prerequisites' {
        $script:phpCommand=Resolve-Tool $Php
        $env:LIFEOS_QA_PHP=$phpCommand
        $script:pythonCommand=Resolve-Tool $Python
        $script:nodeCommand=Resolve-Tool $Node
        Invoke-CheckedNative $phpCommand @('--version')
        Invoke-CheckedNative $pythonCommand @('--version')
        Invoke-CheckedNative $nodeCommand @('--version')
        Invoke-CheckedNative $phpCommand @('scripts/check-runtime.php')
        if(-not(Test-Path -LiteralPath 'vendor/bin/phpunit')) { Stop-QaBlocked 'PHPUnit is missing. Run composer install, then rerun QA.' }
    }
    if (-not $script:qaFailed) {
        Invoke-QaStage 'php-syntax' {
            $files=@(Get-ChildItem -LiteralPath app,public -Recurse -File -Filter '*.php')
            $files+=@(Get-ChildItem -LiteralPath tests,integrations -Recurse -File -Filter '*.php' | Where-Object {
                $_.FullName.Substring($projectRoot.Length+1) -notmatch '(^|[\\/])(\.tmp|node_modules|vendor)([\\/]|$)'
            })
            foreach($file in $files) { Invoke-CheckedNative $phpCommand @('-l',$file.FullName) }
            Write-QaLog ($files.Count.ToString()+' PHP files passed syntax checks.')
        }
        Invoke-QaStage 'javascript-syntax' {
            $files=@(Get-ChildItem -LiteralPath 'public/assets' -File -Filter '*.js' | ForEach-Object FullName)
            $files+=@('tests/ui_lifecycle.cjs','tests/nibm_bridge.cjs','tests/uni_calendar_ui.cjs')
            foreach($file in $files) {
                Invoke-CheckedNative $nodeCommand @('--check',$file)
            }
            Write-QaLog ($files.Count.ToString()+' JavaScript files passed syntax checks.')
        }
        Invoke-QaStage 'phpunit' { Invoke-CheckedNative $phpCommand @('vendor/bin/phpunit','--no-progress') }
        foreach($suite in @('module_loading','fresh_workspace','services','finance_planning','planner_growth_career','planner_search','documents_encryption','freedom','daily_progress','modern_tools','knowledge','life_planning','websites','website_accounts','website_costs','website_gateway','admin_studio','work_management','cloud_hub','wordpress_adapter','uni_calendar','uni_calendar_upgrade','persona','automation','expense_category_rules','sri_lanka_calendar','profiles_packs','capture_planning_trends','focus_intelligence','connectivity','calendar_completion','localization','work_roles','ai_provider','employee_study','platform','workspace_exchange')) {
            $suiteName=$suite
            Invoke-QaStage ('service-'+$suiteName) { Invoke-CheckedNative $phpCommand @('tests/'+$suiteName+'.php') }
        }
        Invoke-QaStage 'http-acceptance' { Invoke-CheckedNative $pythonCommand @('tests/http_acceptance.py') }
        Invoke-QaStage 'persona-http' { Invoke-CheckedNative $pythonCommand @('tests/persona_http.py') }
        Invoke-QaStage 'desktop-panel-http' { Invoke-CheckedNative $pythonCommand @('tests/desktop_panel_http.py') }
        Invoke-QaStage 'expense-rules-http' { Invoke-CheckedNative $pythonCommand @('tests/expense_rules_http.py') }
        foreach($extensionSuite in @('profiles_http','capture_planning_http','intelligence_http','connectivity_http','connectivity_worker','calendar_authorization')) {
            $extensionName=$extensionSuite
            Invoke-QaStage $extensionName { Invoke-CheckedNative $pythonCommand @('tests/'+$extensionName+'.py') }
        }
        Invoke-QaStage 'extensions-ui' { Invoke-CheckedNative $pythonCommand @('tests/extensions_ui.py') }
        Invoke-QaStage 'roles-ai-ui' { Invoke-CheckedNative $pythonCommand @('tests/roles_ai_ui.py') }
        Invoke-QaStage 'voice-recording-ui' { Invoke-CheckedNative $pythonCommand @('tests/voice_recording_ui.py') }
        Invoke-QaStage 'calendar-completion-ui' { Invoke-CheckedNative $pythonCommand @('tests/calendar_completion_ui.py') }
        Invoke-QaStage 'workspace-exchange-http' { Invoke-CheckedNative $pythonCommand @('tests/workspace_exchange_http.py') }
        Invoke-QaStage 'localization-ui' { Invoke-CheckedNative $pythonCommand @('tests/localization_ui.py') }
        Invoke-QaStage 'opencode-worker' { Invoke-CheckedNative $pythonCommand @('tests/opencode_worker.py') }
        Invoke-QaStage 'ai-account-worker' { Invoke-CheckedNative $pythonCommand @('tests/ai_accounts.py') }
        Invoke-QaStage 'ai-account-ui' { Invoke-CheckedNative $pythonCommand @('tests/ai_account_ui.py') }
        Invoke-QaStage 'persona-ui' { Invoke-CheckedNative $pythonCommand @('tests/persona_ui.py','--output',(Join-Path $evidence 'persona-ui')) }
        Invoke-QaStage 'uni-calendar-http' { Invoke-CheckedNative $pythonCommand @('tests/uni_calendar_http.py') }
        Invoke-QaStage 'admin-studio-http' { Invoke-CheckedNative $pythonCommand @('tests/admin_studio_http.py') }
        Invoke-QaStage 'work-management-http' { Invoke-CheckedNative $pythonCommand @('tests/work_management_http.py') }
        Invoke-QaStage 'website-connector' { Invoke-CheckedNative $pythonCommand @('tests/website_connector.py') }
        Invoke-QaStage 'node-website-adapters' { Invoke-CheckedNative $nodeCommand @('tests/website_adapters.mjs') }
        Invoke-QaStage 'ui-lifecycle' { Invoke-CheckedNative $nodeCommand @('tests/ui_lifecycle.cjs') }
        Invoke-QaStage 'nibm-bridge-fixture' { Invoke-CheckedNative $nodeCommand @('tests/nibm_bridge.cjs') }
        Invoke-QaStage 'uni-calendar-ui-lifecycle' { Invoke-CheckedNative $nodeCommand @('tests/uni_calendar_ui.cjs') }

        if($Desktop -and -not $script:qaFailed) {
            Invoke-QaStage 'desktop' {
                if(-not(Test-Path -LiteralPath 'dist/php/php.exe')) { Stop-QaBlocked 'Desktop runtime is missing. Prepare dist with build.ps1 first; this QA runner does not download or install prerequisites.' }
                $bundledPhp=Join-Path $projectRoot 'dist/php/php.exe'
                Invoke-CheckedNative $bundledPhp @('-c',(Join-Path $projectRoot 'dist/php/php.ini'),'-d',("extension_dir='"+(Join-Path $projectRoot 'dist/php/ext')+"'"),'scripts/check-runtime.php')
                $dotnetTool=$Dotnet
                if([string]::IsNullOrWhiteSpace($dotnetTool)) {
                    if(Test-Path -LiteralPath '.tools/dotnet/dotnet.exe') { $dotnetTool=Join-Path $projectRoot '.tools/dotnet/dotnet.exe' }
                    else { $dotnetTool='dotnet' }
                }
                $dotnetCommand=Resolve-Tool $dotnetTool
                Invoke-CheckedNative $dotnetCommand @('--version')
                Invoke-CheckedNative $dotnetCommand @('build','launcher/LifeOS.csproj','-c','Release')
                Invoke-CheckedNative $dotnetCommand @('run','--project','tests/resident-lifecycle/ResidentLifecycle.csproj','-c','Release','--',(Join-Path $evidence 'resident-lifecycle'))
                Invoke-CheckedNative 'powershell.exe' @('-NoProfile','-ExecutionPolicy','Bypass','-File','tests/BrowserProfiles-Smoke.ps1')
                Invoke-CheckedNative 'powershell.exe' @('-NoProfile','-ExecutionPolicy','Bypass','-File','tests/Activation-Smoke.ps1')
                Invoke-CheckedNative 'powershell.exe' @('-NoProfile','-ExecutionPolicy','Bypass','-File','tests/Startup-Welcome.ps1')
                Invoke-CheckedNative $dotnetCommand @('publish','launcher/LifeOS.csproj','-c','Release','-r','win-x64','--self-contained','true','-p:PublishSingleFile=true','-p:IncludeNativeLibrariesForSelfExtract=true','-o','dist')
                $script:desktopPayload=[ordered]@{path='dist/LifeOS.exe';sha256=(Get-FileHash -LiteralPath 'dist/LifeOS.exe' -Algorithm SHA256).Hash.ToLowerInvariant();version=(Get-Item -LiteralPath 'dist/LifeOS.exe').VersionInfo.ProductVersion}
                $powershellCommand=Resolve-Tool 'powershell.exe'
                $desktopResult=Join-Path $evidence 'desktop-result.json'
                Invoke-CheckedNative $powershellCommand @('-NoProfile','-ExecutionPolicy','Bypass','-File','tests/Ui-Preflight.ps1','-Runs','1','-TimeoutSeconds',([string]$DesktopTimeoutSeconds),'-EvidencePath',$desktopResult)
                $native=Get-Content -LiteralPath $desktopResult -Encoding UTF8 -Raw | ConvertFrom-Json
                if(-not $native.success) { throw 'Desktop evidence did not report success.' }
            }
            if(-not $script:qaFailed) {
                Invoke-QaStage 'windows-startup' {
                    $powershellCommand=Resolve-Tool 'powershell.exe'
                    Invoke-CheckedNative $powershellCommand @('-NoProfile','-ExecutionPolicy','Bypass','-File','tests/windows_startup.ps1')
                }
            }
        }
    }

    Invoke-QaStage 'source-stability' {
        $sourceAfter=Get-SourceSnapshot
        [IO.File]::WriteAllText((Join-Path $evidence 'source-after.json'),(ConvertTo-Json -InputObject $sourceAfter -Depth 5),$utf8)
        if((ConvertTo-Json -InputObject $sourceBefore -Depth 5 -Compress) -cne (ConvertTo-Json -InputObject $sourceAfter -Depth 5 -Compress)) {
            throw 'Source changed during QA. These results are stale; rerun after edits finish.'
        }
        Write-QaLog 'Source hashes match the files recorded at the start of this run.'
    }
} catch {
    $script:qaFailed=$true
    $stages.Add([ordered]@{name='runner';status='failed';failure=$_.Exception.Message})
    Write-Host $_.Exception.Message
} finally {
    $env:LIFEOS_DATA_DIR=$previousData
    $env:LIFEOS_SMOKE_TEST=$previousSmoke
    $env:LIFEOS_PROFILE_TEST=$previousProfile
    $env:LIFEOS_QA_PHP=$previousQaPhp
    Set-Location -LiteralPath $originalLocation.Path
    $desktopStage=@($stages | Where-Object { $_.name -eq 'desktop' })
    $overall=if(@($stages | Where-Object { $_.status -eq 'failed' }).Count){'failed'}elseif($script:qaFailed){'blocked'}else{'passed'}
    $report=[ordered]@{
        schemaVersion=1;runId=$runId;startedUtc=$started.ToString('o');finishedUtc=[DateTime]::UtcNow.ToString('o')
        projectRoot=$projectRoot;automatedStatus=$overall
        desktopRequested=[bool]$Desktop;desktopStatus=$(if($desktopStage.Count){$desktopStage[0].status}else{'not_run'})
        visualReviewStatus=$(if($desktopStage.Count -and $desktopStage[0].status -eq 'passed'){'pending'}else{'not_run'});releaseStatus='not_run';installationPerformed=$false
        environment=[ordered]@{os=[Environment]::OSVersion.VersionString;powershell=$PSVersionTable.PSVersion.ToString();is64BitProcess=[Environment]::Is64BitProcess}
        desktopPayload=$desktopPayload
        scope='LifeOS source checks and isolated test fixtures. Desktop stage is opt-in; screenshots still require visual review.'
        sourceManifest='source-before.json';stages=@($stages.ToArray())
    }
    [IO.File]::WriteAllText((Join-Path $evidence 'summary.json'),(ConvertTo-Json -InputObject $report -Depth 10),$utf8)
    Write-Host ('Evidence: '+$evidence)
    Write-Host ('Automated status: '+$report.automatedStatus+'. Visual review: '+$report.visualReviewStatus+'. Installation: not run.')
}
if($script:qaFailed){exit 1}
exit 0
