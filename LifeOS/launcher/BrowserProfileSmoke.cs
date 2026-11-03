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
            ShowAssistantPanel();
            await Wait(()=>assistantPanel is not null&&assistantPanel.ProfileName!="","Child assistant opens");
            var childAssistant=assistantPanel!;
            Check(childAssistant.ProfileName==web.CoreWebView2.Profile.ProfileName,"Assistant uses selected browser profile");
            using(var portal=new NibmCalendarWindow()) {
                await portal.InitializeDiagnosticAsync(webEnvironment!,browserProfile);
                Check(portal.ProfileName==web.CoreWebView2.Profile.ProfileName,"University browser uses selected profile");
                portal.Shutdown();
            }
            await Switch("default","1234");
            Check(childPanel.IsDisposed,"Old profile panel disposed on switch");
            Check(childAssistant.IsDisposed,"Old profile assistant disposed on switch");
            Check(await web.CoreWebView2.ExecuteScriptAsync("localStorage.getItem('lifeos-isolation-probe')")=="\"default\"","Default local storage restored without child values");
            Check((await web.CoreWebView2.CookieManager.GetCookiesAsync("https://accounts.example.test/")).Single(c=>c.Name=="synthetic-signin").Value=="default","Default external sign-in restored");
            string probeId=new string('a',32),probeDirectory=Path.Combine(data,"ai","jobs");Directory.CreateDirectory(probeDirectory);
            string probePath=Path.Combine(probeDirectory,probeId+".json");
            File.WriteAllText(probePath,JsonSerializer.Serialize(new{id=probeId,kind="unsupported",status="queued",created_at=DateTimeOffset.UtcNow.ToUnixTimeSeconds()}));
            await Page("/intelligence","!!document.querySelector('[data-assistant-open]')");
            await web.CoreWebView2.ExecuteScriptAsync("window.chrome.webview.postMessage({type:'lifeos-ai-job',id:'"+probeId+"'})");
            await Wait(()=>{try{using var result=JsonDocument.Parse(File.ReadAllText(probePath));return result.RootElement.GetProperty("status").GetString()=="failed";}catch(IOException){return false;}catch(JsonException){return false;}},"Native AI worker did not finish its local-only probe");
            using(var result=JsonDocument.Parse(File.ReadAllText(probePath)))Check(result.RootElement.GetProperty("error").GetString()=="Unsupported AI request.","Native AI bridge starts the bounded worker without model downloads");
            ShowAssistantPanel();
            await Wait(()=>assistantPanel is not null&&assistantPanel.ProfileName!="","Default assistant created");
            var activeAssistant=assistantPanel!;
            async Task AssistantReady(){for(int i=0;i<200;i++){if(await activeAssistant.EvaluateAsync("!!document.querySelector('[data-assistant-composer]')")=="true")return;await Task.Delay(50);}throw new InvalidOperationException("Assistant page did not render");}
            await AssistantReady();
            Check(activeAssistant.Visible&&!activeAssistant.ShowInTaskbar,"Assistant opens in a separate compact window");
            Check(await activeAssistant.EvaluateAsync("!document.querySelector('select,input[type=checkbox]')")=="true","Assistant hides provider controls");
            await activeAssistant.CaptureAsync(Path.Combine(data,"assistant-native.png"));
            if(!reopened&&Environment.GetEnvironmentVariable("LIFEOS_AI_LIVE_TEST")=="1") {
                string liveData=Path.GetFullPath(data);
                Check(Path.GetFileName(liveData).StartsWith("browser-profiles-",StringComparison.OrdinalIgnoreCase)&&Path.GetFileName(Path.GetDirectoryName(liveData))==".tmp","Live conversation uses disposable browser-profile data");
                const string liveQuestion="Reply with exactly OK.";
                var liveTimer=System.Diagnostics.Stopwatch.StartNew();
                await activeAssistant.EvaluateAsync("(()=>{const f=document.querySelector('[data-assistant-composer]');f.elements.message.value="+JsonSerializer.Serialize(liveQuestion)+";f.elements.message.dispatchEvent(new Event('input',{bubbles:true}));f.requestSubmit()})()");
                string liveAnswer="",liveError="";
                while(liveTimer.Elapsed<TimeSpan.FromSeconds(110)) {
                    using var visible=JsonDocument.Parse(await activeAssistant.EvaluateAsync("({answer:document.querySelector('.chat-reply-content')?.innerText.trim()||'',error:document.querySelector('.chat-error')?.innerText.trim()||''})"));
                    liveAnswer=visible.RootElement.GetProperty("answer").GetString()??"";
                    liveError=visible.RootElement.GetProperty("error").GetString()??"";
                    if(liveAnswer.Length>0||liveError.Length>0)break;
                    await Task.Delay(200);
                }
                liveTimer.Stop();
                var liveJobs=new List<Dictionary<string,JsonElement>>();
                foreach(string liveJobPath in Directory.EnumerateFiles(probeDirectory,"*.json")) {
                    try {
                        using var liveJob=JsonDocument.Parse(File.ReadAllText(liveJobPath));
                        if(!liveJob.RootElement.TryGetProperty("kind",out var kind)||kind.GetString()!="chat")continue;
                        var details=new Dictionary<string,JsonElement>();
                        foreach(string key in new[]{"id","status","question","context","conversation","error","model_used","attempted_models","cached"})
                            if(liveJob.RootElement.TryGetProperty(key,out var value))details[key]=value.Clone();
                        liveJobs.Add(details);
                    }catch(IOException){}catch(JsonException){}
                }
                var liveEvidence=new{synthetic_prompt_only=true,question=liveQuestion,answer=liveAnswer,error=liveError,elapsed_seconds=Math.Round(liveTimer.Elapsed.TotalSeconds,2),jobs=liveJobs};
                File.WriteAllText(Path.Combine(data,"assistant-live-result.json"),JsonSerializer.Serialize(liveEvidence));
                await activeAssistant.CaptureAsync(Path.Combine(data,"assistant-live.png"));
                string liveDiagnostics=JsonSerializer.Serialize(liveEvidence);
                bool readable=liveError.Length==0&&liveAnswer.Length>0&&liveAnswer.Length<=30000&&liveAnswer.Any(char.IsLetterOrDigit)&&!liveAnswer.Contains('\uFFFD');
                if(!readable)throw new InvalidOperationException("Native assistant did not return a readable live answer: "+liveDiagnostics);
                Check(readable,"Native assistant returns a readable live answer");
                bool syntheticOnly=liveJobs.Count==1&&liveJobs[0].TryGetValue("status",out var liveStatus)&&liveStatus.GetString()=="complete"
                    &&liveJobs[0].TryGetValue("question",out var sentQuestion)&&sentQuestion.GetString()==liveQuestion
                    &&liveJobs[0].TryGetValue("context",out var sentContext)&&((sentContext.ValueKind==JsonValueKind.Array&&sentContext.GetArrayLength()==0)||(sentContext.ValueKind==JsonValueKind.Object&&!sentContext.EnumerateObject().Any()))
                    &&liveJobs[0].TryGetValue("conversation",out var sentHistory)&&sentHistory.ValueKind==JsonValueKind.Array&&sentHistory.GetArrayLength()==0;
                if(!syntheticOnly)throw new InvalidOperationException("Unexpected live request contents: "+liveDiagnostics);
                Check(syntheticOnly,"Live request sends only the synthetic question");
            }
            int hotkeyCalls=0;
            using(var keys=new PanelHotkeyWindow(()=>{},null,()=>hotkeyCalls++)){
                Check(keys.AssistantRegistered||keys.AssistantIsoRegistered,"Windows registers the global assistant shortcut");
                SendMessage(keys.OwnerHandle,0x0312,(IntPtr)(keys.AssistantRegistered?0x1A66:0x1A67),IntPtr.Zero);
                Check(hotkeyCalls==1,"Registered shortcut dispatch invokes assistant callback");
            }
            activeAssistant.Hide();Check(!activeAssistant.Visible,"Assistant hides without closing the app");
            ToggleAssistantPanel();await AssistantReady();Check(activeAssistant.Visible,"Assistant shortcut callback reopens existing window");
            activeAssistant.Size=new Size(390,530);Check(activeAssistant.Width==390&&activeAssistant.Height==530,"Assistant compact resize persists");
            HideToTray();Check(!Visible&&!ShowInTaskbar,"Main window hides to tray");
            Check(!activeAssistant.Visible,"Tray hiding also hides assistant");
            ShowMainWindow();Check(Visible&&ShowInTaskbar&&WindowState==FormWindowState.Normal,"Hidden window reopens");
            ShowDesktopPanel();
            ShowAssistantPanel();
            await Wait(()=>desktopPanel is not null,"Default panel created");
            QueuePlatformLock();
            Check(await WaitForExpression("location.pathname==='/unlock'"),"Server lock redirects to PIN");
            Check(desktopPanel is null,"Panel disposed at lock");
            Check(assistantPanel is null&&activeAssistant.IsDisposed,"Assistant private view disposed at lock");
        } catch(Exception error) {
                File.WriteAllText(Path.Combine(data,"browser-profile-result.json"),JsonSerializer.Serialize(new{success=false,error=error.ToString(),checks}));
                allowExitForMaintenance=true;Close();return;
        }
        File.WriteAllText(Path.Combine(data,"browser-profile-result.json"),JsonSerializer.Serialize(new{success=checks.Values.All(v=>v),checks}));
        File.WriteAllText(Path.Combine(data,"browser-run-complete"),"ok");
        allowExitForMaintenance=true;Close();
    }
}
