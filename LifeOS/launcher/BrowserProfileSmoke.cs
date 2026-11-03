using System.Text.Json;
using Microsoft.Web.WebView2.Core;
using Microsoft.Web.WebView2.WinForms;

internal sealed partial class LifeWindow
{
    private bool browserTestStarted;
    private async Task RunBrowserProfileSmokeAsync()
    {
        string data=Environment.GetEnvironmentVariable("LIFEOS_DATA_DIR")!;
        var checks=new Dictionary<string,bool>();
        void Check(bool valid,string label) { if(!valid)throw new InvalidOperationException(label);checks[label]=true; }
        async Task Wait(Func<bool> predicate,string label) { for(int i=0;i<200;i++){if(predicate())return;await Task.Delay(50);}throw new InvalidOperationException(label); }
        async Task Page(string route,string expression) { GoRoute(route); if(!await WaitForExpression($"location.pathname==={JsonSerializer.Serialize(route)} && ({expression})"))throw new InvalidOperationException("Page did not open: "+route); }
        async Task ExternalStorage(string? expected,string value) {
            string folder=Path.Combine(data,"external-probe");Directory.CreateDirectory(folder);File.WriteAllText(Path.Combine(folder,"index.html"),"<!doctype html><title>Isolated browser probe</title>");
            using var probe=new WebView2{Visible=false};Controls.Add(probe);_ =probe.Handle;
            await probe.EnsureCoreWebView2Async(webEnvironment!,BrowserOptions(webEnvironment!,browserProfile));
            probe.CoreWebView2.SetVirtualHostNameToFolderMapping("accounts.example.test",folder,CoreWebView2HostResourceAccessKind.DenyCors);
            var navigated=new TaskCompletionSource();probe.CoreWebView2.NavigationCompleted+=(_,e)=>{if(e.IsSuccess)navigated.TrySetResult();else navigated.TrySetException(new InvalidOperationException("External origin probe failed"));};
            probe.CoreWebView2.Navigate("https://accounts.example.test/index.html");await navigated.Task.WaitAsync(TimeSpan.FromSeconds(10));
            Check(await probe.CoreWebView2.ExecuteScriptAsync("localStorage.getItem('stable-profile-probe')")==JsonSerializer.Serialize(expected),"Stable external storage belongs to profile: "+browserProfile);
            await probe.CoreWebView2.ExecuteScriptAsync("localStorage.setItem('stable-profile-probe',"+JsonSerializer.Serialize(value)+")");
        }
        async Task Switch(string id,string pin) {
            await Page("/profiles","!!document.querySelector('form[action=\"/profiles/switch\"]')");
            await web.CoreWebView2.ExecuteScriptAsync("(()=>{const f=document.querySelector('form[action=\"/profiles/switch\"]');f.elements.profile.value="+JsonSerializer.Serialize(id)+";f.elements.pin.value="+JsonSerializer.Serialize(pin)+";f.requestSubmit()})()");
            await Wait(()=>browserProfile==id && !switchingBrowser && appReady,"Native profile switch timed out");
            Check(await WaitForExpression("location.pathname==='/'"),"Profile opens authenticated home: "+id);
        }
        try {
            bool reopened=File.Exists(Path.Combine(data,"browser-run-complete"));
            using var fixture=JsonDocument.Parse(File.ReadAllText(Path.Combine(data,"browser-fixture.json")));
            string child=fixture.RootElement.GetProperty("child").GetString()!;
            Check(await WaitForExpression("location.pathname==='/unlock'"),"PIN required on fresh launch");
            await web.CoreWebView2.ExecuteScriptAsync("(()=>{const f=document.querySelector('form[action=\"/unlock\"]');f.elements.pin.value='1234';f.requestSubmit()})()");
            Check(await WaitForExpression("location.pathname==='/' && !!document.querySelector('.app-shell')"),"Default PIN opens home");
            await ExternalStorage(reopened?"default":null,"default");
            if(reopened)Check((await web.CoreWebView2.CookieManager.GetCookiesAsync("https://accounts.example.test/")).Any(c=>c.Name=="synthetic-persistent"&&c.Value=="default"),"Persistent sign-in cookie survives process restart");
            await web.CoreWebView2.ExecuteScriptAsync("localStorage.setItem('lifeos-isolation-probe','default')");
            var cookie=web.CoreWebView2.CookieManager.CreateCookie("synthetic-signin","default","accounts.example.test","/");
            web.CoreWebView2.CookieManager.AddOrUpdateCookie(cookie);
            var persistent=web.CoreWebView2.CookieManager.CreateCookie("synthetic-persistent","default","accounts.example.test","/");persistent.Expires=DateTime.UtcNow.AddDays(1);web.CoreWebView2.CookieManager.AddOrUpdateCookie(persistent);
            string firstName=web.CoreWebView2.Profile.ProfileName;
            var firstView=web;
            await Switch(child,"5678");
            Check(firstView.IsDisposed,"Source main WebView disposed");
            Check(web.CoreWebView2.Profile.ProfileName!=firstName,"Distinct persisted browser profiles");
            if(!reopened)Check(await web.CoreWebView2.ExecuteScriptAsync("localStorage.getItem('lifeos-isolation-probe')")=="null","Local application storage stays isolated across switches");
            await ExternalStorage(reopened?"child":null,"child");
            Check(!(await web.CoreWebView2.CookieManager.GetCookiesAsync("https://accounts.example.test/")).Any(c=>c.Name=="synthetic-persistent"),"Persistent external cookies never cross profiles");
            Check(!(await web.CoreWebView2.CookieManager.GetCookiesAsync("https://accounts.example.test/")).Any(c=>c.Name=="synthetic-signin"),"External sign-in cookies not shared with child");
            await web.CoreWebView2.ExecuteScriptAsync("localStorage.setItem('lifeos-isolation-probe','child')");
            cookie=web.CoreWebView2.CookieManager.CreateCookie("synthetic-signin","child","accounts.example.test","/");
            web.CoreWebView2.CookieManager.AddOrUpdateCookie(cookie);
            desktopPanel=new DesktopPanelWindow(home!,webEnvironment!,browserProfile);desktopPanel.Show();
            await Wait(()=>desktopPanel.ProfileName!="","Child panel opens");
            Check(desktopPanel.ProfileName==web.CoreWebView2.Profile.ProfileName,"Panel uses selected browser profile");
            var childPanel=desktopPanel;
            using(var portal=new NibmCalendarWindow()) {
                await portal.InitializeDiagnosticAsync(webEnvironment!,browserProfile);
                Check(portal.ProfileName==web.CoreWebView2.Profile.ProfileName,"University browser uses selected profile");
                portal.Shutdown();
            }
            await Switch("default","1234");
            Check(childPanel.IsDisposed,"Old profile panel disposed on switch");
            Check(await web.CoreWebView2.ExecuteScriptAsync("localStorage.getItem('lifeos-isolation-probe')")=="\"default\"","Default local storage restored without child values");
            Check((await web.CoreWebView2.CookieManager.GetCookiesAsync("https://accounts.example.test/")).Single(c=>c.Name=="synthetic-signin").Value=="default","Default external sign-in restored");
            string probeId=new string('a',32),probeDirectory=Path.Combine(data,"ai","jobs");Directory.CreateDirectory(probeDirectory);
            string probePath=Path.Combine(probeDirectory,probeId+".json");
            File.WriteAllText(probePath,JsonSerializer.Serialize(new{id=probeId,kind="unsupported",status="queued",created_at=DateTimeOffset.UtcNow.ToUnixTimeSeconds()}));
            await Page("/intelligence","!!document.querySelector('form[action=\"/intelligence/models\"]')");
            await web.CoreWebView2.ExecuteScriptAsync("window.chrome.webview.postMessage({type:'lifeos-ai-job',id:'"+probeId+"'})");
            await Wait(()=>{try{using var result=JsonDocument.Parse(File.ReadAllText(probePath));return result.RootElement.GetProperty("status").GetString()=="failed";}catch(IOException){return false;}catch(JsonException){return false;}},"Native AI worker did not finish its local-only probe");
            using(var result=JsonDocument.Parse(File.ReadAllText(probePath)))Check(result.RootElement.GetProperty("error").GetString()=="Unsupported AI request.","Native AI bridge starts the bounded worker without model downloads");
            HideToTray();Check(!Visible&&!ShowInTaskbar,"Main window hides to tray");
            ShowMainWindow();Check(Visible&&ShowInTaskbar&&WindowState==FormWindowState.Normal,"Hidden window reopens");
            ShowDesktopPanel();
            await Wait(()=>desktopPanel is not null,"Default panel created");
            QueuePlatformLock();
            Check(await WaitForExpression("location.pathname==='/unlock'"),"Server lock redirects to PIN");
            Check(desktopPanel is null,"Panel disposed at lock");
        } catch(Exception error) {
                File.WriteAllText(Path.Combine(data,"browser-profile-result.json"),JsonSerializer.Serialize(new{success=false,error=error.ToString(),checks}));
                allowExitForMaintenance=true;Close();return;
        }
        File.WriteAllText(Path.Combine(data,"browser-profile-result.json"),JsonSerializer.Serialize(new{success=checks.Values.All(v=>v),checks}));
        File.WriteAllText(Path.Combine(data,"browser-run-complete"),"ok");
        allowExitForMaintenance=true;Close();
    }
}
