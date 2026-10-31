using System.Diagnostics;
using System.Net;
using System.Reflection;
using System.Security.Cryptography;
using System.Text.Json;
using System.Text.RegularExpressions;

internal static class AppUpdateFiles
{
    internal static (Version Version,long Build) Installed(string root)
    {
        var fallback=(Assembly.GetExecutingAssembly().GetName().Version ?? new Version(1,8,0),0L);
        try {
            string path=Path.Combine(root,"lifeos-release.json");
            if(!File.Exists(path) || new FileInfo(path).Length>65536) return fallback;
            using var document=JsonDocument.Parse(File.ReadAllText(path));
            var identity=UpdateRelease.Identity(document.RootElement);
            // A stale metadata file must not make an older executable look newer.
            return identity.Version==fallback.Item1 ? identity : fallback;
        } catch(Exception error) when(error is IOException or JsonException or InvalidDataException or KeyNotFoundException or InvalidOperationException or FormatException or UnauthorizedAccessException) { return fallback; }
    }

    internal static bool StartPending(string root,string data,int? phpPid=null)
    {
        string updates=Path.Combine(data,"updates"),pending=Path.Combine(updates,"pending.json");
        if(!File.Exists(pending)) return false;
        try {
            if(new FileInfo(pending).Length>4096) throw new InvalidDataException("Invalid pending update.");
            using var pointer=JsonDocument.Parse(File.ReadAllText(pending));
            string stage=pointer.RootElement.GetProperty("stage").GetString() ?? "";
            if(!Regex.IsMatch(stage,@"^[a-f0-9]{32}$")) throw new InvalidDataException("Invalid update staging directory.");
            string directory=Path.Combine(updates,stage),manifest=Path.Combine(directory,"lifeos-update.json"),package=Path.Combine(directory,"LifeOS-Update.zip");
            var release=UpdateRelease.Parse(File.ReadAllText(manifest));
            var installed=Installed(root);
            if(!release.IsNewerThan(installed.Version,installed.Build)) { File.Delete(pending); return false; }
            if(!File.Exists(package) || new FileInfo(package).Length!=release.Package.Size) throw new InvalidDataException("The downloaded update is incomplete.");
            string helper=Path.Combine(directory,"Apply-LifeOSUpdate.ps1");
            File.Copy(Path.Combine(root,"scripts","Apply-LifeOSUpdate.ps1"),helper,true);
            var start=new ProcessStartInfo("powershell.exe") { UseShellExecute=false,CreateNoWindow=true,WindowStyle=ProcessWindowStyle.Hidden,WorkingDirectory=root };
            foreach(string argument in new[]{"-NoProfile","-ExecutionPolicy","Bypass","-File",helper,"-InstallDirectory",root,"-DataDirectory",data,"-PackagePath",package,"-ManifestPath",manifest,"-ParentProcessId",Environment.ProcessId.ToString(),"-PendingPath",pending,"-Restart"}) start.ArgumentList.Add(argument);
            if(phpPid is int pid) { start.ArgumentList.Add("-PhpProcessId");start.ArgumentList.Add(pid.ToString()); }
            start.Environment["LIFEOS_DATA_DIR"]=data;
            using var process=Process.Start(start) ?? throw new IOException("The update helper could not start.");
            return true;
        } catch(Exception error) when(error is IOException or UnauthorizedAccessException or JsonException or InvalidDataException or KeyNotFoundException or InvalidOperationException or FormatException or System.ComponentModel.Win32Exception) {
            try { Directory.CreateDirectory(updates);File.WriteAllText(Path.Combine(updates,"last-error.txt"),error.Message);File.Delete(pending); } catch(IOException) {} catch(UnauthorizedAccessException) {}
            return false;
        }
    }
}

internal sealed partial class LifeWindow
{
    private readonly System.Windows.Forms.Timer updateTimer=new(){Interval=3_600_000};
    private bool updateChecking,updateReady;
    private string updateMessage="Automatic updates are enabled.",availableVersion="";
    private string AppDataDirectory=>Path.GetFullPath(Environment.GetEnvironmentVariable("LIFEOS_DATA_DIR") ?? Path.Combine(root,"data"));

    private void InitializeAppUpdates()
    {
        if(diagnostic) return;
        updateTimer.Tick+=async(_,_)=>await CheckAppUpdateAsync();
        updateTimer.Start();
        _=StartUpdateChecksAsync();
    }

    private async Task StartUpdateChecksAsync()
    {
        try { await Task.Delay(20_000,lifetime.Token);await CheckAppUpdateAsync(); }
        catch(OperationCanceledException) {}
    }

    private void PublishUpdateState()
    {
        if(IsDisposed || web.CoreWebView2 is null) return;
        var installed=AppUpdateFiles.Installed(root);
        web.CoreWebView2.PostWebMessageAsJson(JsonSerializer.Serialize(new {type="lifeos-update-state",version=installed.Version.ToString(3),build=installed.Build,ready=updateReady,busy=updateChecking,message=updateMessage,availableVersion}));
    }

    private void HandleUpdateMessage(JsonElement value)
    {
        if(!value.TryGetProperty("action",out var action)) return;
        switch(action.GetString()) {
            case "state": PublishUpdateState();break;
            case "check": if(!diagnostic) _=CheckAppUpdateAsync();else PublishUpdateState();break;
            case "apply":
                if(diagnostic || !updateReady || updateChecking) return;
                if(AppUpdateFiles.StartPending(root,AppDataDirectory,php is {HasExited:false}?php.Id:null)) {
                    allowExitForMaintenance=true;Close();
                } else { updateReady=false;updateMessage="The update could not start. Check again to download it.";PublishUpdateState(); }
                break;
        }
    }

    private async Task CheckAppUpdateAsync()
    {
        if(updateChecking || updateReady || IsDisposed || lifetime.IsCancellationRequested) { PublishUpdateState();return; }
        updateChecking=true;updateMessage="Checking for updates…";PublishUpdateState();
        string? downloadDirectory=null;
        try {
            using var client=new HttpClient {Timeout=TimeSpan.FromMinutes(10)};
            client.DefaultRequestHeaders.UserAgent.ParseAdd("LifeOS/1.8");
            using var manifestTimeout=CancellationTokenSource.CreateLinkedTokenSource(lifetime.Token);
            manifestTimeout.CancelAfter(TimeSpan.FromSeconds(20));
            using var response=await client.GetAsync(UpdateRelease.Feed,HttpCompletionOption.ResponseHeadersRead,manifestTimeout.Token);
            if(response.StatusCode==HttpStatusCode.NotFound) { updateMessage="No LifeOS release has been published yet.";return; }
            response.EnsureSuccessStatusCode();
            if(response.Content.Headers.ContentLength>65536) throw new InvalidDataException("Update information is too large.");
            await using var manifestStream=await response.Content.ReadAsStreamAsync(manifestTimeout.Token);
            using var manifestBuffer=new MemoryStream();
            byte[] buffer=new byte[65536];int count;
            while((count=await manifestStream.ReadAsync(buffer,manifestTimeout.Token))>0) {
                if(manifestBuffer.Length+count>65536) throw new InvalidDataException("Update information is too large.");
                manifestBuffer.Write(buffer,0,count);
            }
            string json=System.Text.Encoding.UTF8.GetString(manifestBuffer.ToArray());
            var release=UpdateRelease.Parse(json);
            var installed=AppUpdateFiles.Installed(root);
            if(!release.IsNewerThan(installed.Version,installed.Build)) { updateMessage="LifeOS is up to date.";return; }
            string updates=Path.Combine(AppDataDirectory,"updates"),stage=Guid.NewGuid().ToString("N");
            downloadDirectory=Path.Combine(updates,stage);Directory.CreateDirectory(downloadDirectory);
            string package=Path.Combine(downloadDirectory,"LifeOS-Update.zip");
            updateMessage="Downloading LifeOS "+release.Version+"…";PublishUpdateState();
            using var download=await client.GetAsync(release.Package.Url,HttpCompletionOption.ResponseHeadersRead,lifetime.Token);
            download.EnsureSuccessStatusCode();
            if(download.Content.Headers.ContentLength is long length && length!=release.Package.Size) throw new InvalidDataException("Update package size does not match the release.");
            await using(var source=await download.Content.ReadAsStreamAsync(lifetime.Token))
            await using(var destination=new FileStream(package,FileMode.CreateNew,FileAccess.Write,FileShare.None,65536,true))
            using(var hash=IncrementalHash.CreateHash(HashAlgorithmName.SHA256)) {
                long total=0;
                while((count=await source.ReadAsync(buffer,lifetime.Token))>0) {
                    total+=count;
                    if(total>release.Package.Size) throw new InvalidDataException("Update package exceeded its declared size.");
                    hash.AppendData(buffer,0,count);await destination.WriteAsync(buffer.AsMemory(0,count),lifetime.Token);
                }
                if(total!=release.Package.Size || !Convert.ToHexString(hash.GetHashAndReset()).Equals(release.Package.Sha256,StringComparison.OrdinalIgnoreCase)) throw new InvalidDataException("Update package verification failed.");
            }
            await File.WriteAllTextAsync(Path.Combine(downloadDirectory,"lifeos-update.json"),json,lifetime.Token);
            string pending=Path.Combine(updates,"pending.json"),temporary=pending+".tmp";
            await File.WriteAllTextAsync(temporary,JsonSerializer.Serialize(new{stage}),lifetime.Token);File.Move(temporary,pending,true);
            availableVersion=release.Version;updateReady=true;downloadDirectory=null;
            updateMessage="LifeOS "+release.Version+" is ready. It will install the next time you open the app.";
        } catch(OperationCanceledException) { updateMessage="Update check paused. It will retry automatically."; }
        catch(Exception error) when(error is HttpRequestException or IOException or JsonException or InvalidDataException or InvalidOperationException or KeyNotFoundException or FormatException or UnauthorizedAccessException) {
            updateMessage="Could not check for updates. Your current app is ready to use.";
            try { string updates=Path.Combine(AppDataDirectory,"updates");Directory.CreateDirectory(updates);File.WriteAllText(Path.Combine(updates,"last-error.txt"),error.Message); } catch(IOException) {} catch(UnauthorizedAccessException) {}
        } finally {
            // A failed partial download is never promoted to a pending update.
            if(downloadDirectory is not null) { try { File.Delete(Path.Combine(downloadDirectory,"LifeOS-Update.zip")); } catch(IOException) {} catch(UnauthorizedAccessException) {} }
            updateChecking=false;PublishUpdateState();
        }
    }
}
