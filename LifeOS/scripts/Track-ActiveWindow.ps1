param(
    [string]$BaseUrl = 'http://127.0.0.1:8787',
    [string]$ProfileId = '',
    [string]$Category = 'Uncategorized',
    [ValidateRange(5,60)][int]$IntervalSeconds = 15
)
$ErrorActionPreference = 'Stop'
$uri = [uri]$BaseUrl
if ($uri.Scheme -ne 'http' -or $uri.Host -ne '127.0.0.1' -or $uri.UserInfo) { throw 'Use a loopback LifeOS URL.' }
if (-not $env:LIFEOS_API_TOKEN) { throw 'Set LIFEOS_API_TOKEN to a time.write token from Connections.' }
Add-Type @'
using System;
using System.Runtime.InteropServices;
public static class LifeOSWindowCapture {
    [DllImport("user32.dll")] public static extern IntPtr GetForegroundWindow();
    [DllImport("user32.dll")] public static extern uint GetWindowThreadProcessId(IntPtr handle, out uint pid);
    [DllImport("user32.dll")] public static extern bool GetLastInputInfo(ref LASTINPUTINFO info);
    public struct LASTINPUTINFO { public uint cbSize; public uint dwTime; }
    public static bool Active() { var info = new LASTINPUTINFO(); info.cbSize=(uint)Marshal.SizeOf(info); return GetLastInputInfo(ref info) && unchecked((uint)Environment.TickCount-info.dwTime)<60000; }
}
'@
$headers = @{ Authorization = "Bearer $env:LIFEOS_API_TOKEN" }
if ($ProfileId) { $headers['X-LifeOS-Profile'] = $ProfileId }
Write-Host 'LifeOS application time capture is ON. Process names only. Idle periods over 60 seconds are skipped. Press Ctrl+C to pause.'
try {
    while ($true) {
        $watch = [System.Diagnostics.Stopwatch]::StartNew()
        $handle = [LifeOSWindowCapture]::GetForegroundWindow()
        [uint32]$processId = 0
        [void][LifeOSWindowCapture]::GetWindowThreadProcessId($handle,[ref]$processId)
        $process = Get-Process -Id $processId -ErrorAction SilentlyContinue
        $active = [LifeOSWindowCapture]::Active()
        Start-Sleep -Seconds $IntervalSeconds
        if (-not $process -or -not $active -or -not [LifeOSWindowCapture]::Active() -or [LifeOSWindowCapture]::GetForegroundWindow() -ne $handle) { continue }
        $seconds = [int][Math]::Floor($watch.Elapsed.TotalSeconds)
        if ($seconds -lt 1 -or $seconds -gt 300) { continue }
        $body = @{ process = $process.ProcessName; category = $Category; seconds = $seconds; identity = [guid]::NewGuid().ToString() } | ConvertTo-Json -Compress
        [void](Invoke-RestMethod -Uri ($BaseUrl.TrimEnd('/')+'/api/v1/window-time') -Method Post -Headers $headers -Body $body -ContentType 'application/json' -TimeoutSec 10 -MaximumRedirection 0)
    }
} finally { Write-Host 'LifeOS application time capture is OFF.' }
