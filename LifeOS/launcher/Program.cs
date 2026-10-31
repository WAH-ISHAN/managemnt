using System.Diagnostics;
using System.Net;
using System.Net.Sockets;
using System.Runtime.InteropServices;
using System.Text.Json;
using Microsoft.Web.WebView2.Core;
using Microsoft.Web.WebView2.WinForms;

internal static class Program
{
    [STAThread]
    static void Main(string[] args)
    {
        if(args.Contains("--shutdown-for-update",StringComparer.OrdinalIgnoreCase)){LifeWindow.NotifyExistingInstance(true);return;}
        bool diagnostic=Environment.GetEnvironmentVariable("LIFEOS_SMOKE_TEST")=="1" || Environment.GetEnvironmentVariable("LIFEOS_PROFILE_TEST")=="1";
        if (diagnostic && string.IsNullOrWhiteSpace(Environment.GetEnvironmentVariable("LIFEOS_DATA_DIR"))) { Environment.ExitCode=2; return; }
        // Explicit diagnostic runs use their own data/profile and must not claim
        // the user's normal app instance. Ordinary launches remain single-instance.
        string mutexName=@"Local\LifeOS.SingleInstance";
        if (diagnostic) {
            string diagnosticData=Path.GetFullPath(Environment.GetEnvironmentVariable("LIFEOS_DATA_DIR")!);
            string identity=Convert.ToHexString(System.Security.Cryptography.SHA256.HashData(System.Text.Encoding.UTF8.GetBytes(diagnosticData.ToUpperInvariant())));
            mutexName=@"Local\LifeOS.Diagnostic."+identity;
        }
        using var mutex = new Mutex(true, mutexName, out bool first);
        if (!first) { if(!diagnostic)LifeWindow.NotifyExistingInstance(); return; }
        ApplicationConfiguration.Initialize();
        try { Application.Run(new LifeWindow()); }
        catch (Exception error) { MessageBox.Show(error.Message, "LifeOS", MessageBoxButtons.OK, MessageBoxIcon.Error); }
    }
}

internal sealed partial class LifeWindow : Form
{
    private PanelHotkeyWindow? panelHotkeys;
    private bool appReady,pendingPanelToggle;
    private readonly WebView2 web = new() { Dock = DockStyle.Fill };
    private Process? php;
    private readonly ProcessJob job = new();
    private readonly CancellationTokenSource lifetime = new();
    private readonly string root = AppContext.BaseDirectory;
    private readonly bool diagnostic=Environment.GetEnvironmentVariable("LIFEOS_SMOKE_TEST")=="1"||Environment.GetEnvironmentVariable("LIFEOS_PROFILE_TEST")=="1";
    private Uri? home;
    private CoreWebView2Environment? webEnvironment;
    private DesktopPanelWindow? desktopPanel;
    private readonly System.Windows.Forms.Timer todayPanelTimer=new(){Interval=60_000};
    private DateOnly lastAutoPanelDate=DateOnly.MinValue;
    private bool todayPanelChecking;
    private readonly bool smoke = Environment.GetEnvironmentVariable("LIFEOS_SMOKE_TEST")=="1";
    private readonly bool profile = Environment.GetEnvironmentVariable("LIFEOS_PROFILE_TEST")=="1";
    private readonly NotifyIcon reminders = new();
    private readonly HashSet<string> delivered = new();
    private readonly HashSet<string> panelAlertIds = new();
    private DateTime lastReminder = DateTime.MinValue;
    private string reminderRoute = "/insights#alerts";
    public LifeWindow()
    {
        Text = "LifeOS — Student Life Management"; Width = 1320; Height = 880; MinimumSize = new Size(720, 600);
        StartPosition = FormStartPosition.CenterScreen; BackColor = Color.FromArgb(245,245,247);
        InitializeWindowChrome();
        Icon=System.Drawing.Icon.ExtractAssociatedIcon(Application.ExecutablePath);
        reminders.Icon=Icon ?? SystemIcons.Application; reminders.Text="LifeOS · Student life reminders";
        reminders.BalloonTipClicked += (_, _) => { ShowMainWindow(); GoRoute(reminderRoute); };
        InitializeResidency();
        InitializeAppUpdates();
        Controls.Add(web); Load += Start;
        FormClosed += (_, _) => Cleanup();
        Application.ApplicationExit += (_, _) => Cleanup();
    }
    private async void Start(object? sender, EventArgs args)
    {
        string data = Environment.GetEnvironmentVariable("LIFEOS_DATA_DIR") ?? Path.Combine(root, "data");
        if (!diagnostic && AppUpdateFiles.StartPending(root, data))
        {
            allowExitForMaintenance = true;
            Close();
            return;
        }
        try
        {
            try { CoreWebView2Environment.GetAvailableBrowserVersionString(); }
            catch { throw new InvalidOperationException("WebView2 Runtime is missing. Run LifeOS-Setup.exe again to install the bundled runtime."); }
            Directory.CreateDirectory(Path.Combine(data,"sessions"));
            if(!diagnostic){panelHotkeys=new PanelHotkeyWindow(ToggleDesktopPanel);File.WriteAllText(Path.Combine(data,"desktop-panel-hotkey.json"),JsonSerializer.Serialize(new{shortcut="Win+\\",registered=panelHotkeys.AnyWindowsRegistered,nativeError=panelHotkeys.WindowsError,primary=panelHotkeys.WindowsRegistered,iso=panelHotkeys.AlternateWindowsRegistered,fallback=panelHotkeys.FallbackRegistered,processId=Environment.ProcessId,owner="message-only-window"}));}
            File.WriteAllText(Path.Combine(data,".write-check"), "ok"); File.Delete(Path.Combine(data,".write-check"));
            using var listener = new TcpListener(IPAddress.Loopback, 0);
            listener.Start(); int port = ((IPEndPoint)listener.LocalEndpoint).Port; listener.Stop();
            home = new Uri($"http://127.0.0.1:{port}/");
            string instance = Guid.NewGuid().ToString("N");
            var start = new ProcessStartInfo(Path.Combine(root,"php","php.exe")) { WorkingDirectory=root, UseShellExecute=false, CreateNoWindow=true };
            foreach (string value in new[] { "-c",Path.Combine(root,"php","php.ini"),"-d","extension_dir="+Path.Combine(root,"php","ext"),"-S",$"127.0.0.1:{port}","-t",Path.Combine(root,"public"),Path.Combine(root,"public","index.php") }) start.ArgumentList.Add(value);
            start.Environment["LIFEOS_INSTANCE"] = instance; start.Environment["LIFEOS_DATA_DIR"] = data;
            start.Environment["LIFEOS_WEBSITE_KEY"] = Convert.ToBase64String(System.Security.Cryptography.RandomNumberGenerator.GetBytes(32));
            php = Process.Start(start) ?? throw new InvalidOperationException("PHP could not start.");
            job.Attach(php);
            using var client = new HttpClient(new HttpClientHandler { UseProxy=false }) { Timeout=TimeSpan.FromSeconds(2) };
            bool ready = false;
            for (int attempt=0; attempt<75 && !lifetime.IsCancellationRequested; attempt++)
            {
                if (php.HasExited) throw new InvalidOperationException("PHP stopped during startup. Check data/php_error.log.");
                try { if (await client.GetStringAsync(new Uri(home,"health"),lifetime.Token)=="LifeOS:"+instance) { ready=true; break; } }
                catch (HttpRequestException) { } catch (TaskCanceledException) { lifetime.Token.ThrowIfCancellationRequested(); }
                await Task.Delay(100,lifetime.Token);
            }
            if (!ready) throw new InvalidOperationException("LifeOS did not start in time. Check disk permissions and data/php_error.log.");
            var env = await CoreWebView2Environment.CreateAsync(null,Path.Combine(data,"webview"));
            webEnvironment=env;
            if (lifetime.IsCancellationRequested) return;
            await web.EnsureCoreWebView2Async(env);
            InitializeNibmCalendar(env);
            web.CoreWebView2.Settings.AreDevToolsEnabled = false;
            web.CoreWebView2.Settings.IsStatusBarEnabled = false;
            web.CoreWebView2.Settings.AreDefaultContextMenusEnabled = false;
            web.CoreWebView2.NavigationStarting += (_, e) => {
                todayPanelChecking=false;
                if (!Uri.TryCreate(e.Uri,UriKind.Absolute,out var uri) || uri.GetLeftPart(UriPartial.Authority)!=home.GetLeftPart(UriPartial.Authority)) e.Cancel=true;
                else if (uri.AbsolutePath is "/lock" or "/unlock") PauseNibmCalendar();
            };
            web.CoreWebView2.NewWindowRequested += (_, e) => e.Handled=true;
            web.CoreWebView2.PermissionRequested += (_, e) => e.State=CoreWebView2PermissionState.Deny;
            web.CoreWebView2.NavigationCompleted += async (_, _) => {
                if (IsDisposed) return;
                try {
                    bool ready=await web.CoreWebView2.ExecuteScriptAsync("!!document.getElementById('desktop-titlebar')")=="true";
                    if (!IsDisposed) SetCustomChrome(ready);
                    appReady=ready;if(ready&&pendingPanelToggle){pendingPanelToggle=false;ToggleDesktopPanel();}if(ready)WriteResidentState();
                    if(ready&&!diagnostic)await CheckForTodayPanelAsync();
                    PublishUpdateState();
                } catch (InvalidOperationException) { if (!IsDisposed) SetCustomChrome(false); }
            };
            web.CoreWebView2.WebMessageReceived += (_, e) => {
                if (!Uri.TryCreate(e.Source,UriKind.Absolute,out var source) || source.GetLeftPart(UriPartial.Authority)!=home.GetLeftPart(UriPartial.Authority)) return;
                try {
                    using var message=JsonDocument.Parse(e.WebMessageAsJson); var value=message.RootElement;
                    if (!value.TryGetProperty("type",out var kind) || kind.ValueKind!=JsonValueKind.String) return;
                    if(kind.GetString()=="lifeos-today-panel") {
                        todayPanelChecking=false;
                        if(value.TryGetProperty("hasItems",out var hasItems)&&hasItems.ValueKind==JsonValueKind.True&&lastAutoPanelDate!=DateOnly.FromDateTime(DateTime.Today)) {
                            lastAutoPanelDate=DateOnly.FromDateTime(DateTime.Today);ShowDesktopPanel();
                        }
                        return;
                    }
                    if (kind.GetString()=="lifeos-nibm-local") { HandleNibmLocalResult(value); return; }
                    if (kind.GetString()=="lifeos-nibm") { _=HandleNibmMessageAsync(value.Clone()); return; }
                    if (kind.GetString()=="lifeos-window") { HandleWindowMessage(value); return; }
                    if (kind.GetString()=="lifeos-update") { HandleUpdateMessage(value); return; }
                    if (kind.GetString()=="lifeos-panel-close") { desktopPanel?.Hide(); return; }
                    if (kind.GetString()!="lifeos-alerts") return;
                    bool enabled=value.TryGetProperty("enabled",out var pref) && pref.ValueKind==JsonValueKind.True;
                    reminders.Visible=!diagnostic;
                    if (!enabled || !value.TryGetProperty("alerts",out var list) || list.ValueKind!=JsonValueKind.Array) return;
                    bool newAlert=false;
                    foreach(var alert in list.EnumerateArray())if(alert.TryGetProperty("id",out var alertId)&&alertId.ValueKind==JsonValueKind.String){string id=alertId.GetString()??"";if(id.Length==64&&panelAlertIds.Add(id))newAlert=true;}
                    if(newAlert&&!diagnostic)ShowDesktopPanel();
                    bool newCalendarUpdate=list.EnumerateArray().Any(alert=>alert.TryGetProperty("kind",out var alertKind) && alertKind.ValueKind==JsonValueKind.String && alertKind.GetString()=="calendar" && alert.TryGetProperty("id",out var alertId) && alertId.ValueKind==JsonValueKind.String && !delivered.Contains(alertId.GetString() ?? ""));
                    if (!newCalendarUpdate && DateTime.UtcNow-lastReminder<TimeSpan.FromSeconds(45)) return;
                    foreach (var alert in list.EnumerateArray()) {
                        if (alert.TryGetProperty("read",out var isRead) && isRead.ValueKind==JsonValueKind.True) continue;
                        string id=alert.GetProperty("id").GetString() ?? "";
                        if (id.Length!=64 || delivered.Contains(id)) continue;
                        string title=alert.GetProperty("title").GetString() ?? "LifeOS reminder";
                        string body=alert.GetProperty("body").GetString() ?? "";
                        string route=alert.GetProperty("route").GetString() ?? "/insights";
                        if (alert.TryGetProperty("kind",out var notificationKind) && notificationKind.ValueKind==JsonValueKind.String && notificationKind.GetString()=="calendar") title="LifeOS · "+title;
                        if (!route.StartsWith('/') || route.StartsWith("//")) continue;
                        reminders.BalloonTipTitle=title[..Math.Min(title.Length,60)]; reminders.BalloonTipText=body[..Math.Min(body.Length,220)]; reminders.BalloonTipIcon=ToolTipIcon.Info;
                        reminderRoute=route; reminders.ShowBalloonTip(7000); delivered.Add(id); lastReminder=DateTime.UtcNow; break;
                    }
                } catch (JsonException) { } catch (InvalidOperationException) { } catch (KeyNotFoundException) { }
            };
            if(!diagnostic){todayPanelTimer.Tick+=async(_,_)=>await CheckForTodayPanelAsync();todayPanelTimer.Start();}
            web.KeyDown += (_, e) => {
                if (!e.Control || e.Alt) return;
                string? script=null;
                if (e.KeyCode==Keys.K) script="window.LifeOS?.openCommands()";
                else if (e.KeyCode==Keys.N) script="window.LifeOS?.go('/planner?view=tasks#new-task')";
                else if (e.KeyCode==Keys.F && e.Shift) script="window.LifeOS?.go('/focus')";
                else if (e.KeyCode>=Keys.D1 && e.KeyCode<=Keys.D9) script=$"document.querySelectorAll('.sidebar .nav-link')[{e.KeyCode-Keys.D1}]?.click()";
                if (script is null) return;
                e.Handled=true; e.SuppressKeyPress=true; string action=script; BeginInvoke(new Action(async () => { if (!IsDisposed) await web.CoreWebView2.ExecuteScriptAsync(action); }));
            };
            web.CoreWebView2.DownloadStarting += (_, e) =>
            {
                string filename=Path.GetFileName(e.ResultFilePath);
                if (string.IsNullOrWhiteSpace(filename)) filename="lifeos-"+DateTime.Now.ToString("yyyy-MM-dd")+".db";
                string extension=Path.GetExtension(filename).ToLowerInvariant();
                string filter=extension switch { ".csv"=>"CSV table (*.csv)|*.csv", ".db"=>"LifeOS database (*.db)|*.db", ".lifeosbk"=>"Encrypted LifeOS backup (*.lifeosbk)|*.lifeosbk", _=>"Document (*"+extension+")|*"+extension+"|All files (*.*)|*.*" };
                using var save = new SaveFileDialog { FileName=filename,Filter=filter,OverwritePrompt=true };
                e.Handled=true;
                if (save.ShowDialog(this)==DialogResult.OK) e.ResultFilePath=save.FileName; else e.Cancel=true;
            };
            bool smokeStarted=false;
            if (profile) web.CoreWebView2.NavigationCompleted += async (_, e) =>
            {
                if (smokeStarted) return;
                smokeStarted=true;
                void Stage(string stage) => File.WriteAllText(Path.Combine(data,"profile-stage.json"),System.Text.Json.JsonSerializer.Serialize(new { stage,port,phpPid=php?.Id,success=e.IsSuccess }));
                try
                {
                    Stage("idle");
                    await Task.Delay(15000,lifetime.Token);
                    Stage("navigation");
                    foreach (string path in new[] { "/salary","/investments","/uni","/lessons","/review","/" })
                    {
                        await web.CoreWebView2.ExecuteScriptAsync("htmx.ajax('GET',"+System.Text.Json.JsonSerializer.Serialize(path)+",{target:'#main',swap:'outerHTML',select:'#main'})");
                        await Task.Delay(2000,lifetime.Token);
                    }
                    Stage("load");
                    while (!File.Exists(Path.Combine(data,"profile-stop"))) await Task.Delay(250,lifetime.Token);
                    Stage("complete");
                }
                catch (Exception error) { File.WriteAllText(Path.Combine(data,"profile-error.txt"),error.ToString()); }
                finally { Close(); }
            };
            if (smoke) web.CoreWebView2.NavigationCompleted += async (_, e) =>
            {
                if (smokeStarted) return;
                smokeStarted=true;
                bool completed=false;
                try
                {
                    await RunSmoke(data,e.IsSuccess);
                    completed=true;
                }
                catch (Exception error) {
                    string? detail=null;
                    try {
                        detail=await web.CoreWebView2.ExecuteScriptAsync("JSON.stringify({path:location.pathname,heading:document.querySelector('#main h1')?.textContent,errors:window.__lifeosErrors,ready:document.readyState,navigation:window.__lifeosSmokeNavigation,marker:window.__lifeosSmoke,active:document.querySelector('.sidebar [aria-current=page]')?.getAttribute('href'),invalid:[...document.querySelectorAll('input:invalid')].map(e=>e.name)})");
                        using var screenshot=File.Create(Path.Combine(data,"smoke-failure.png"));
                        await web.CoreWebView2.CapturePreviewAsync(CoreWebView2CapturePreviewImageFormat.Png,screenshot);
                    } catch { }
                    File.WriteAllText(Path.Combine(data,"launcher-smoke.json"),System.Text.Json.JsonSerializer.Serialize(new { success=false,error=error.Message,detail }));
                }
                finally {
                    if (completed) BeginInvoke(new Action(async () => {
                        try { await web.CoreWebView2.ExecuteScriptAsync("document.querySelector('[data-window-action=close]').click()"); } catch (InvalidOperationException) { }
                        await Task.Delay(1000);
                        if (!IsDisposed) Close();
                    }));
                    else Close();
                }
            };
            if (smoke)
            {
                await web.CoreWebView2.AddScriptToExecuteOnDocumentCreatedAsync("window.__lifeosErrors=[];addEventListener('error',e=>{if(e.message)window.__lifeosErrors.push(e.message)});addEventListener('unhandledrejection',e=>window.__lifeosErrors.push(String(e.reason)))");
                web.CoreWebView2.AddWebResourceRequestedFilter("*",CoreWebView2WebResourceContext.All);
                web.CoreWebView2.WebResourceRequested += (_, request) =>
                {
                    if (request.Request.Method!="POST") return;
                    string? origin=request.Request.Headers.Contains("Origin") ? request.Request.Headers.GetHeader("Origin") : null;
                    File.AppendAllText(Path.Combine(data,"smoke-post-origins.jsonl"),JsonSerializer.Serialize(new { url=request.Request.Uri,origin })+Environment.NewLine);
                };
            }
            web.Source=home;
        }
        catch (OperationCanceledException) { }
        catch (Exception error) {
            if (smoke) { Directory.CreateDirectory(data); File.WriteAllText(Path.Combine(data,"launcher-smoke.json"),System.Text.Json.JsonSerializer.Serialize(new { success=false,error=error.Message })); }
            else if (!IsDisposed) MessageBox.Show(this,error.Message,"LifeOS could not open",MessageBoxButtons.OK,MessageBoxIcon.Error);
            allowExitForMaintenance=true;if (!IsDisposed) Close();
        }
    }
    private async Task RunSmoke(string data,bool navigationSucceeded)
    {
        var checks=new Dictionary<string,bool>();
        async Task Check(string name,string expression) { if (!await WaitForExpression(expression)) throw new InvalidOperationException("Smoke check failed: "+name); checks[name]=true; }
        async Task Capture(string name)
        {
            string directory=Path.Combine(data,"smoke-screenshots"); Directory.CreateDirectory(directory);
            await Task.Delay(300,lifetime.Token);
            using var output=File.Create(Path.Combine(directory,name+".png"));
            await web.CoreWebView2.CapturePreviewAsync(CoreWebView2CapturePreviewImageFormat.Png,output);
        }
        async Task CaptureComfort(string name,string? focusSelector=null)
        {
            Size original=ClientSize;
            string originalTheme=JsonSerializer.Deserialize<string>(await web.CoreWebView2.ExecuteScriptAsync("document.documentElement.dataset.theme || 'system'")) ?? "system";
            var layouts=new Dictionary<string,JsonElement>();
            foreach (int width in new[] { 1280,1024,720 })
            {
                ClientSize=new Size(width,820);
                foreach (string theme in new[] { "light","dark" })
                {
                    await web.CoreWebView2.ExecuteScriptAsync("(()=>{const target="+JsonSerializer.Serialize(theme)+";for(let i=0;i<3 && document.documentElement.dataset.theme!==target;i++)document.querySelector('[data-theme-toggle]')?.click();scrollTo(0,0)})()");
                    await Task.Delay(150,lifetime.Token);
                    if (focusSelector is not null) await web.CoreWebView2.ExecuteScriptAsync("document.querySelector("+JsonSerializer.Serialize(focusSelector)+")?.scrollIntoView({block:'center',behavior:'instant'})");
                    string key=name+"-"+width+"-"+theme;
                    string metrics=await web.CoreWebView2.ExecuteScriptAsync("(()=>{const shown=e=>!!(e.getClientRects().length && getComputedStyle(e).visibility!=='hidden'),min=(selector,property)=>{const values=[...document.querySelectorAll(selector)].filter(shown).map(e=>parseFloat(getComputedStyle(e)[property]));return values.length ? Math.min(...values):null};return {width:innerWidth,documentWidth:document.documentElement.scrollWidth,bodyFont:parseFloat(getComputedStyle(document.body).fontSize),inputFont:min('input:not([type=hidden]),select,textarea','fontSize'),buttonFont:min('button,.btn','fontSize'),sidebarFont:min('.nav-link','fontSize'),theme:document.documentElement.dataset.theme}})()");
                    using var parsed=JsonDocument.Parse(metrics); layouts[key]=parsed.RootElement.Clone();
                    File.WriteAllText(Path.Combine(data,"comfort-"+name+".json"),JsonSerializer.Serialize(layouts));
                    await Capture("comfort-"+key);
                    if (name=="dashboard-empty" && width==1280) {
                        Activate();
                        await Task.Delay(150,lifetime.Token);
                        using var nativeFrame=new Bitmap(Width,Height);
                        using(var graphics=Graphics.FromImage(nativeFrame)) graphics.CopyFromScreen(Location,Point.Empty,Size);
                        nativeFrame.Save(Path.Combine(data,"smoke-screenshots","window-edge-"+theme+".png"),System.Drawing.Imaging.ImageFormat.Png);
                    }
                    await Check("comfort-overflow:"+key,"document.documentElement.scrollWidth<=innerWidth+1");
                }
            }
            ClientSize=original;
            await web.CoreWebView2.ExecuteScriptAsync("(()=>{const target="+JsonSerializer.Serialize(originalTheme)+";for(let i=0;i<3 && document.documentElement.dataset.theme!==target;i++)document.querySelector('[data-theme-toggle]')?.click();scrollTo(0,0)})()");
            File.WriteAllText(Path.Combine(data,"comfort-"+name+".json"),JsonSerializer.Serialize(layouts));
        }
        async Task Navigate(string path)
        {
            await Check("ready-to-navigate:"+path,"document.readyState==='complete'");
            string target=JsonSerializer.Serialize(path);
            string token=JsonSerializer.Serialize(Guid.NewGuid().ToString("N"));
            await web.CoreWebView2.ExecuteScriptAsync("window.__lifeosSmoke=1;window.__lifeosSmokeNavigation=null;(()=>{const path="+target+",token="+token+";if(location.pathname===path){window.__lifeosSmokeNavigation=token;return;}const done=e=>{if(e.detail.elt?.id==='main' && location.pathname===path){window.__lifeosSmokeNavigation=token;document.removeEventListener('htmx:afterSettle',done)}};document.addEventListener('htmx:afterSettle',done);const link=[...document.querySelectorAll('.sidebar .nav-link')].find(a=>a.getAttribute('href')===path),group=link?.closest('details');if(group && !group.open)group.querySelector('summary').click();link.click()})()");
            await Check("navigate:"+path,"window.__lifeosSmokeNavigation==="+token+" && location.pathname==="+target+" && !!document.querySelector('#main h1') && window.__lifeosSmoke===1 && document.querySelector('.sidebar [aria-current=page]')?.getAttribute('href')==="+target);
            await Check("scripts:"+path,"window.__lifeosErrors.length===0");
        }
        async Task Act(string javascript) {
            string error=await web.CoreWebView2.ExecuteScriptAsync("(()=>{try{document.getElementById('main').dataset.smokeSubmission='pending';"+javascript+";return null}catch(error){return String(error.stack || error)}})()");
            if(error!="null") throw new InvalidOperationException("Desktop action failed: "+error);
        }
        async Task Changed(string name,string expression) { await Check(name,"!document.querySelector('#main[data-smoke-submission]') && !!window.LifeOS && ("+expression+")"); }
        async Task StudioNavigate(string path) {
            string target=JsonSerializer.Serialize(path);
            await Act("(()=>{const a=document.createElement('a');a.href="+target+";document.getElementById('main').append(a);a.click()})()");
            await Changed("studio-navigate:"+path,"location.pathname+location.search==="+target+" && !!document.querySelector('#main h1')");
        }
        bool onboarding=await web.CoreWebView2.ExecuteScriptAsync("location.pathname==='/welcome'")=="true";
        await VerifyWindowChrome(checks);
        if (onboarding)
        {
            await Check("onboarding-form","!!document.querySelector('form[action=\"/welcome\"] input[name=name]')");
            await Check("onboarding-brand","document.querySelector('.lifeos-logo img')?.complete && document.querySelector('.lifeos-logo img').naturalWidth===256");
            await Capture("onboarding");
            await web.CoreWebView2.ExecuteScriptAsync("(()=>{const f=document.querySelector('form[action=\"/welcome\"]');f.elements.name.value='Windows smoke';f.elements.institution.value='Disposable test workspace';f.querySelector('[value=money]').checked=true;f.requestSubmit()})()");
            if (!await WaitForHeading("Your salary, with room to live.")) throw new InvalidOperationException("Onboarding did not open the salary planner.");
            checks["onboarding-submit"]=true;
        }
        await Check("application-shell","!!document.querySelector('.sidebar') && !!window.htmx && !!window.LifeOS");
        await Check("application-ready","document.readyState==='complete'");
        await Check("application-brand","document.querySelector('.sidebar .lifeos-logo img')?.complete && document.querySelector('.sidebar .lifeos-logo img').naturalWidth===256");
        await Navigate("/websites");
        await Check("website-manager","[...document.forms].some(f=>f.action.endsWith('/websites/profile') && f.elements.base_url) && document.querySelector('#main h1')?.textContent.includes('websites')");
        await Capture("website-manager");
        await Act("document.querySelector('#provider-login').open=true");
        await CaptureComfort("provider-gateway-login","#provider-login");
        await Act("document.querySelector('#provider-login').open=false");
        await web.CoreWebView2.ExecuteScriptAsync("(()=>{const f=[...document.forms].find(f=>f.action.endsWith('/websites/profile'));f.elements.name.value='Smoke website';f.elements.business_name.value='Business 0';f.elements.base_url.value='https://example.com';f.requestSubmit()})()");
        if (!await WaitForHeading("Smoke website")) throw new InvalidOperationException("Website profile did not save in the isolated smoke database.");
        checks["website-profile-save"]=true;
        await Capture("website-profile");
        await Act("(()=>{const f=document.querySelector('form[action$=\"/costs\"]');f.elements.kind.value='maintenance';f.elements.amount.value='250.50';f.elements.vendor.value='UI maintenance provider';f.requestSubmit()})()");
        await Changed("website-cost-offline-save","document.querySelector('#maintenance-costs')?.textContent.includes('UI maintenance provider') && document.querySelector('#maintenance-costs').textContent.includes('Website-only cost')");
        await CaptureComfort("website-costs",".website-cost-totals");

        await Check("website-password-toggle","!!document.querySelector('[data-website-password]')");
        await web.CoreWebView2.ExecuteScriptAsync("document.querySelector('[data-website-password]').click()");
        await Check("website-password-show","document.querySelector('input[name=password]').type==='text' && document.querySelector('[data-website-password]').getAttribute('aria-pressed')==='true'");
        await web.CoreWebView2.ExecuteScriptAsync("document.querySelector('[data-website-password]').click()");
        await Check("website-password-hide","document.querySelector('input[name=password]').type==='password'");
        await Navigate("/websites");
        await web.CoreWebView2.ExecuteScriptAsync("window.__websiteFixtures=false;(async()=>{const csrf=document.querySelector('input[name=_csrf]').value;for(let i=1;i<=9;i++){if([...document.querySelectorAll('[data-website-card]')].some(c=>c.dataset.siteName==='Workspace QA '+i))continue;const values={_csrf:csrf,name:'Workspace QA '+i,business_name:'Business '+i,base_url:'https://qa'+i+'.example.com',connector_url:i%2===0 ? 'https://qa'+i+'.example.com/api/lifeos-admin.php' : ''};const r=await fetch('/websites/profile',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(values)});if(!r.ok)throw Error('Website fixture failed');}window.__websiteFixtures=true;location.reload()})().catch(e=>window.__websiteFixtureError=e.message)");
        await Check("website-ten-cards","document.querySelectorAll('[data-website-card]').length>=10 && !document.querySelector('[data-website-controls]').hidden");
        await CaptureComfort("websites");
        await web.CoreWebView2.ExecuteScriptAsync("(()=>{const business=document.querySelector('[data-website-business]');business.value='business:business 9';business.dispatchEvent(new Event('change',{bubbles:true}))})()");
        await Check("website-business-filter","document.querySelectorAll('[data-website-card]:not([hidden])').length===1 && document.querySelector('[data-website-card]:not([hidden])').textContent.includes('Workspace QA 9')");
        await web.CoreWebView2.ExecuteScriptAsync("document.querySelector('[data-website-clear]').click()");
        await Check("website-business-reset","document.querySelector('[data-website-business]').value==='' && document.querySelectorAll('[data-website-card]:not([hidden])').length>=10");

        await web.CoreWebView2.ExecuteScriptAsync("(()=>{const input=document.querySelector('[data-website-search]');input.value='Workspace QA 9';input.dispatchEvent(new Event('input',{bubbles:true}))})()");
        await Check("website-live-search","[...document.querySelectorAll('[data-website-card]')].filter(c=>!c.hidden).length===1 && [...document.querySelectorAll('[data-website-card]')].find(c=>!c.hidden).textContent.includes('Workspace QA 9')");
        await web.CoreWebView2.ExecuteScriptAsync("document.querySelector('[data-website-filter=signed]').click()");
        await Check("website-empty-filter","!document.querySelector('[data-website-no-results]').hidden && document.querySelectorAll('[data-website-card]:not([hidden])').length===0");
        await web.CoreWebView2.ExecuteScriptAsync("document.querySelector('[data-website-clear]').click()");
        await Check("website-clear-filters","document.querySelectorAll('[data-website-card]:not([hidden])').length>=10 && document.querySelector('[data-website-search]').value===''");
        await web.CoreWebView2.ExecuteScriptAsync("document.querySelector('[data-website-filter=setup]').click()");
        await Check("website-setup-filter","[...document.querySelectorAll('[data-website-card]')].filter(c=>!c.hidden).every(c=>c.dataset.status==='setup') && document.querySelector('[data-website-filter=setup]').getAttribute('aria-pressed')==='true'");
        await web.CoreWebView2.ExecuteScriptAsync("document.querySelector('[data-website-filter=all]').click();(()=>{const sort=document.querySelector('[data-website-sort]');sort.value='setup';sort.dispatchEvent(new Event('change',{bubbles:true}))})()");
        await Check("website-sort-setup","document.querySelector('[data-website-card]').dataset.status==='setup'");
        await web.CoreWebView2.CallDevToolsProtocolMethodAsync("Emulation.setEmulatedMedia","{\"features\":[{\"name\":\"prefers-reduced-motion\",\"value\":\"reduce\"}]}");
        await Check("website-reduced-motion","getComputedStyle(document.querySelector('[data-website-card]')).animationName==='none'");
        await web.CoreWebView2.CallDevToolsProtocolMethodAsync("Emulation.setEmulatedMedia","{\"features\":[]}");
        await web.CoreWebView2.ExecuteScriptAsync("document.querySelector('[data-website-add]').click()");
        await Check("website-quick-add","document.querySelector('#new-website').open && document.activeElement.name==='name'");

        await web.CoreWebView2.ExecuteScriptAsync("location.href='/websites/setup'");
        if (!await WaitForHeading("Connect a website you own.")) throw new InvalidOperationException("Website connection guide did not render.");
        await Check("website-reference-inventory","document.querySelector('#references')?.textContent.includes('CreativeFX') && document.querySelector('#references')?.textContent.includes('TenderHub')");
        await Capture("website-setup-references");
        await Navigate("/web-admin");
        await Check("admin-studio-sidebar","document.querySelector('.sidebar a[href=\"/web-admin\"]') && document.querySelector('#main h1')?.textContent.includes('websites')");
        await StudioNavigate("/web-admin/1?section=pages");
        await Check("studio-combined-navigation","document.querySelector('#studio-navigation').textContent.includes('Works & projects') && document.querySelector('#studio-navigation').textContent.includes('Invoices & payments') && document.querySelector('form[action$=\"/records/pages\"]')");
        await Act("(()=>{const f=document.querySelector('form[action$=\"/records/pages\"]');f.elements.title.value='Studio UI page';f.elements['fields[slug]'].value='studio-ui-page';f.elements['fields[body]'].value='A real page saved in the isolated UI test workspace.';f.requestSubmit()})()");
        await Changed("studio-page-saved","document.querySelector('.studio-record-list')?.textContent.includes('Studio UI page')");
        await CaptureComfort("admin-studio-pages",".studio-section-heading");
        await Act("document.querySelector('.studio-record-title').click()");
        await Changed("studio-page-edit-loaded","document.querySelector('#studio-editor input[name=title]')?.value==='Studio UI page'");
        await Act("(()=>{const f=document.querySelector('#studio-editor form');f.elements.title.value='Updated Studio UI page';f.requestSubmit()})()");
        await Changed("studio-page-edited","document.querySelector('.studio-record-list')?.textContent.includes('Updated Studio UI page')");
        await StudioNavigate("/web-admin/1?section=tasks");
        await Act("(()=>{const f=document.querySelector('#studio-editor form');f.elements.title.value='Review website copy';f.elements.status.value='in_progress';f.requestSubmit()})()");
        await Changed("studio-task-saved","document.querySelector('.studio-record-list')?.textContent.includes('Review website copy')");
        await StudioNavigate("/web-admin/1");
        await Check("studio-dashboard-real-records","document.querySelector('.studio-recent') && document.querySelector('#main').textContent.includes('Updated Studio UI page') && [...document.querySelectorAll('canvas[data-chart]')].some(c=>!!window.Chart?.getChart(c))");
        await CaptureComfort("admin-studio-overview",".studio-section-heading");
        await StudioNavigate("/web-admin/1?section=media");
        await web.CoreWebView2.ExecuteScriptAsync("(()=>{const csrf=document.querySelector('input[name=_csrf]').value;const bytes=Uint8Array.from(atob('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lS8AAAAASUVORK5CYII='),c=>c.charCodeAt(0));const fd=new FormData();fd.append('_csrf',csrf);fd.append('file',new Blob([bytes],{type:'image/png'}),'studio-ui-asset.png');fetch('/web-admin/1/media',{method:'POST',body:fd}).then(r=>{if(!r.ok)throw Error('Upload failed');location.reload()})})()");
        await Check("studio-media-uploaded","document.querySelector('.studio-media-card')?.textContent.includes('studio-ui-asset.png') && document.querySelector('.studio-media-card img')?.complete");
        await CaptureComfort("admin-studio-media",".studio-section-heading");
        await StudioNavigate("/web-admin/1?section=connected");
        await Check("studio-no-fake-remote-save","document.querySelector('#main').textContent.includes('Sign in to your website first.') && !document.querySelector('form[action$=\"/remote/save\"]')");
        await Navigate("/work");
        await Check("work-module-available","document.querySelector('.sidebar a[href=\"/work\"]') && document.querySelector('form[action=\"/work/workspace\"]')");
        await Act("(()=>{const f=document.querySelector('form[action=\"/work/workspace\"]');f.elements.name.value='LifeOS work UI workspace';f.requestSubmit()})()");
        await Changed("work-workspace-created","document.querySelector('.work-workspace-bar')?.textContent.includes('LifeOS work UI workspace')");
        await StudioNavigate("/work?workspace=1&tab=projects");
        await Act("(()=>{const f=document.querySelector('form[action=\"/work/workspace/1/projects\"]');f.elements.name.value='LifeOS product work';f.elements.identifier.value='WORK';f.requestSubmit()})()");
        await Changed("work-project-created","document.querySelector('#main h1')?.textContent.includes('LifeOS product work') && document.querySelector('form[action=\"/work/project/1/issues\"]')");
        await Act("(()=>{const f=document.querySelector('form[action=\"/work/project/1/issues\"]');f.elements.title.value='Build the LifeOS workflow';f.elements.description.value='## Shipping plan\\nA real saved item in the isolated desktop fixture.';f.elements.priority.value='2';f.elements.start_date.value=document.querySelector('meta[name=lifeos-today]').content;f.elements.due_date.value=document.querySelector('meta[name=lifeos-today]').content;f.elements['assignee_ids[]'].options[0].selected=true;f.requestSubmit()})()");
        await Changed("work-issue-created","document.querySelector('.work-issue-header h1')?.textContent.includes('Build the LifeOS workflow') && document.querySelector('.work-issue-kicker')?.textContent.includes('WORK-1')");
        await CaptureComfort("work-issue",".work-issue-header");
        await StudioNavigate("/work/project/1?layout=board");
        await Act("(()=>{const card=document.querySelector('[data-work-card]');const target=[...document.querySelectorAll('[data-work-state]')].find(c=>c.querySelector('header strong').textContent==='In progress');window.__workTargetState=target.dataset.workState;const dt=new DataTransfer();card.dispatchEvent(new DragEvent('dragstart',{bubbles:true,dataTransfer:dt}));target.dispatchEvent(new DragEvent('dragover',{bubbles:true,dataTransfer:dt}));target.dispatchEvent(new DragEvent('drop',{bubbles:true,dataTransfer:dt}))})()");
        await Changed("work-kanban-saved","document.querySelector('[data-work-state=\"'+window.__workTargetState+'\"] [data-work-card]') && document.querySelector('[data-work-board-status]')?.textContent.includes('moved and saved')");
        await web.CoreWebView2.ExecuteScriptAsync("(()=>{const csrf=document.querySelector('input[name=_csrf]').value;const today=document.querySelector('meta[name=lifeos-today]').content;const post=async(path,values)=>{const r=await fetch(path,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({_csrf:csrf,...values})});if(!r.ok)throw Error('Work fixture failed')};(async()=>{await post('/work/project/1/cycle',{name:'Current build cycle',starts_on:today,ends_on:today,status:'active'});await post('/work/project/1/module',{name:'Client workspace',status:'in_progress'});await post('/work/project/1/label',{name:'Product',color:'#9f8bff'});for(const values of [{title:'Review client requests',priority:'3',state_id:'2'},{title:'Write project decisions',priority:'4',state_id:'4'}])await post('/work/project/1/issues',{...values,cycle_id:'1',module_id:'1','assignee_ids[]':'1','label_ids[]':'1',start_date:today,due_date:today});await post('/work/project/1/pages',{title:'Project decisions',body:'## Decisions\\nKeep work local and make each workflow action explicit.',pinned:'1'});location.reload()})().catch(e=>window.__workFixtureError=e.message)})()");
        await Check("work-populated-board","document.querySelectorAll('[data-work-card]').length===3 && !window.__workFixtureError");
        await Check("work-csp-colours","[...document.querySelectorAll('.work-state-dot[data-work-color]')].some(e=>e.dataset.workColor==='#3b82f6' && getComputedStyle(e).backgroundColor==='rgb(59, 130, 246)')");
        await CaptureComfort("work-board",".work-issue-controls");
        await StudioNavigate("/work/project/1?tab=analytics");
        await Check("work-real-analytics","[...document.querySelectorAll('canvas[data-chart]')].some(c=>!!window.Chart?.getChart(c)) && document.querySelector('.work-stats').textContent.includes('3')");
        await CaptureComfort("work-analytics");
        string workCalendarMonth=DateTime.Now.ToString("yyyy-MM");
        await StudioNavigate("/work/project/1?layout=calendar&month="+workCalendarMonth);
        await Check("work-calendar-real-items","document.querySelectorAll('.work-calendar-cell a').length===3");
        await CaptureComfort("work-calendar",".work-layout-switch");
        await StudioNavigate("/work/project/1?layout=gantt&month="+workCalendarMonth);
        await Check("work-timeline-actual-dates","document.querySelectorAll('.work-timeline-bar').length===3 && [...document.querySelectorAll('.work-timeline-bar')].every(e=>parseFloat(e.style.width)>0 && parseFloat(e.style.left)>=0)");
        await CaptureComfort("work-timeline",".work-layout-switch");
        await StudioNavigate("/work?workspace=1");
        await Check("work-personal-overview","document.querySelector('.work-stats') && document.querySelector('#main').textContent.includes('LifeOS product work')");
        await CaptureComfort("work-overview");


        await Navigate("/salary");
        await web.CoreWebView2.CallDevToolsProtocolMethodAsync("Emulation.setEmulatedMedia","{\"features\":[{\"name\":\"prefers-reduced-motion\",\"value\":\"reduce\"}]}");
        await Check("reduced-motion","matchMedia('(prefers-reduced-motion: reduce)').matches && getComputedStyle(document.getElementById('main')).animationName==='none'");
        await web.CoreWebView2.CallDevToolsProtocolMethodAsync("Emulation.setEmulatedMedia","{\"features\":[]}");
        await web.CoreWebView2.ExecuteScriptAsync("window.__lifeosSmoke=1");
        await Navigate("/");
        if (onboarding) { await Capture("dashboard-empty"); await CaptureComfort("dashboard-empty"); }
        await Navigate("/salary");
        await CaptureComfort("money");
        await Check("readable-controls","parseFloat(getComputedStyle(document.body).fontSize)>=16 && document.querySelector('#add-expense input[name=amount]').getBoundingClientRect().height>=44");
        await Check("date-shortcuts","document.querySelectorAll('#add-expense .date-shortcuts button').length>=2");
        await web.CoreWebView2.ExecuteScriptAsync("[...document.querySelectorAll('#add-expense .date-shortcuts button')].find(b=>b.textContent==='Yesterday').click()");
        await Check("date-yesterday","document.querySelector('#add-expense input[name=date]').value < document.querySelector('meta[name=lifeos-today]').content");
        await web.CoreWebView2.ExecuteScriptAsync("[...document.querySelectorAll('#add-expense .date-shortcuts button')].find(b=>b.textContent==='Today').click()");
        await Check("date-today","document.querySelector('#add-expense input[name=date]').value===document.querySelector('meta[name=lifeos-today]').content");
        await web.CoreWebView2.ExecuteScriptAsync("document.querySelector('[data-quick-add]').click()");
        await Check("quick-add-dialog","document.getElementById('quick-add-dialog').open && document.querySelectorAll('[data-quick-result]').length===8");
        await VerifyModalWindowChrome(checks);
        await Capture("glass-controls-over-modal");
        await Capture("quick-add");
        await web.CoreWebView2.ExecuteScriptAsync("document.querySelector('[data-quick-result][href=\"/salary#add-expense\"]').click()");
        await Check("quick-expense-destination","location.pathname==='/salary' && location.hash==='#add-expense' && document.activeElement===document.querySelector('#add-expense input[name=amount]') && !document.getElementById('quick-add-dialog').open");
        Size drawerSize=ClientSize; ClientSize=new Size(720,820); await Task.Delay(200,lifetime.Token);
        await web.CoreWebView2.ExecuteScriptAsync("document.querySelector('[data-menu-toggle]').click()");
        await Check("narrow-menu-open","document.documentElement.dataset.menuOpen==='true' && !document.querySelector('.sidebar').inert");
        await Capture("narrow-menu");
        await web.CoreWebView2.ExecuteScriptAsync("document.dispatchEvent(new KeyboardEvent('keydown',{key:'Escape',bubbles:true}))");
        await Check("narrow-menu-close","document.documentElement.dataset.menuOpen==='false' && document.querySelector('.sidebar').inert");
        ClientSize=drawerSize; await Task.Delay(200,lifetime.Token);
        await Act("(()=>{const f=document.querySelector('#add-income form');f.elements.source.value='Windows smoke income';f.elements.amount.value='1000.01';f.requestSubmit()})()");
        await Changed("income-submit","[...document.querySelectorAll('#main td')].some(e=>e.textContent.includes('Windows smoke income')) && [...document.querySelectorAll('canvas[data-chart]')].some(c=>!!window.Chart?.getChart(c))");
        // The native runner uses a disposable database; these records never ship.
        await web.CoreWebView2.ExecuteScriptAsync("(async()=>{const csrf=document.querySelector('input[name=_csrf]').value,f=document.querySelector('#add-expense form'),category=f.elements.category.value,account=f.elements.account.value;for(let i=0;i<6;i++){const d=new Date();d.setDate(15);d.setMonth(d.getMonth()-5+i);const date=d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-15';const send=async(path,fields)=>{const r=await fetch(path,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({_csrf:csrf,...fields})});if(!r.ok)throw Error('Cashflow fixture failed')};if(i<5)await send('/salary',{date,source:'Cashflow QA '+i,amount:String([700,860,920,1100,980][i]),type:'salary'});await send('/expenses',{date,category,account,amount:String([150,220,270,330,380,420][i]),note:'Cashflow QA expense'})}location.reload()})().catch(e=>window.__cashflowFixtureError=e.message)");
        await Check("cashflow-six-month-records","[...document.querySelectorAll('canvas[data-chart]')].some(c=>{const chart=window.Chart?.getChart(c);return chart && chart.data.datasets[0].data.filter(v=>v>0).length===6 && chart.data.datasets[1].data.filter(v=>v>0).length===6})");
        await web.CoreWebView2.ExecuteScriptAsync("(()=>{const chart=Chart.getChart(document.querySelector('canvas[data-chart]'));window.__cashflowValues=JSON.stringify(chart.data.datasets.map(d=>d.data));chart.setActiveElements([{datasetIndex:0,index:5}]);chart.tooltip.setActiveElements([{datasetIndex:0,index:5}],{x:chart.getDatasetMeta(0).data[5].x,y:chart.getDatasetMeta(0).data[5].y});chart.update('none')})()");
        await Check("cashflow-tooltip-values","(()=>{const chart=Chart.getChart(document.querySelector('canvas[data-chart]'));return chart.tooltip.dataPoints[0].raw===chart.data.datasets[0].data[5] && JSON.stringify(chart.data.datasets.map(d=>d.data))===window.__cashflowValues})()");
        await web.CoreWebView2.ExecuteScriptAsync("(()=>{const chart=Chart.getChart(document.querySelector('canvas[data-chart]'));chart.hide(1);chart.show(1);chart.setActiveElements([]);chart.tooltip.setActiveElements([],{x:0,y:0});chart.update('none')})()");
        await Check("cashflow-legend-values","(()=>{const chart=Chart.getChart(document.querySelector('canvas[data-chart]'));return chart.isDatasetVisible(1) && JSON.stringify(chart.data.datasets.map(d=>d.data))===window.__cashflowValues})()");
        await web.CoreWebView2.CallDevToolsProtocolMethodAsync("Emulation.setEmulatedMedia","{\"features\":[{\"name\":\"prefers-reduced-motion\",\"value\":\"reduce\"}]}");
        await Check("chart-reduced-motion","[...document.querySelectorAll('canvas[data-chart]')].every(c=>!window.Chart?.getChart(c)?.options.animation)");
        await web.CoreWebView2.CallDevToolsProtocolMethodAsync("Emulation.setEmulatedMedia","{\"features\":[]}");
        await Check("chart-animation-restored","[...document.querySelectorAll('canvas[data-chart]')].some(c=>window.Chart?.getChart(c)?.options.animation.duration===720)");
        await web.CoreWebView2.ExecuteScriptAsync("document.querySelector('canvas[data-chart]').closest('.card').scrollIntoView({block:'center',behavior:'instant'})");
        await Capture("modern-money-chart");
        await web.CoreWebView2.ExecuteScriptAsync("document.querySelector('.table-wrap').scrollIntoView({block:'center',behavior:'instant'})");
        await Capture("borderless-money-table");
        await web.CoreWebView2.ExecuteScriptAsync("scrollTo({top:0,behavior:'instant'})");
        await Navigate("/");
        string page=await web.CoreWebView2.ExecuteScriptAsync("JSON.stringify({title:document.title,main:!!document.querySelector('#main h1'),charts:[...document.querySelectorAll('canvas[data-chart]')].filter(c=>!!window.Chart?.getChart(c)).length})");
        await Check("dashboard-chart","[...document.querySelectorAll('canvas[data-chart]')].some(c=>!!window.Chart?.getChart(c))");
        await Check("daily-progress-panel","!!document.querySelector('.daily-progress') && document.getElementById('progress-date').value===document.querySelector('meta[name=lifeos-today]').content");
        await Act("document.querySelector('.day-links a').click()");
        await Changed("review-previous-day","document.getElementById('progress-date').value < document.querySelector('meta[name=lifeos-today]').content");
        await Act("document.querySelector('.day-links a[href=\"/\"]').click()");
        await Changed("review-today","document.getElementById('progress-date').value===document.querySelector('meta[name=lifeos-today]').content");
        await Capture("dashboard");
        await CaptureComfort("dashboard");
        string links=await web.CoreWebView2.ExecuteScriptAsync("[...document.querySelectorAll('.sidebar .nav-link')].map(a=>a.getAttribute('href'))");
        foreach (string path in JsonSerializer.Deserialize<string[]>(links) ?? [])
        {
            if (path=="/") continue;
            await Navigate(path);
            if (path is "/planner" or "/focus" or "/career" or "/documents" or "/freedom") await Capture(path[1..]);
        }
        await Navigate("/uni");
        await Check("university-independent-actions","['module','assignment','exam','class'].every(type=>!!document.querySelector('a[href=\"/uni/add/'+type+'\"]'))");
        await CaptureComfort("university-overview");
        await web.CoreWebView2.ExecuteScriptAsync("document.querySelector('a[href=\"/uni/add/assignment\"]').click()");
        await Check("university-assignment-form","!!document.querySelector('form[action=\"/uni/assignment\"] input[name=title]:invalid')");
        await web.CoreWebView2.ExecuteScriptAsync("document.querySelector('a[href=\"/uni/add/exam\"]').click()");
        await Check("university-exam-without-assignment","document.querySelector('#main h1')?.textContent==='Add an exam' && !!document.querySelector('form[action=\"/uni/exam\"]') && !document.querySelector('form[action=\"/uni/assignment\"]')");
        await Capture("university-exam-form");
        await Act("(()=>{const f=document.querySelector('form[action=\"/uni/exam\"]');f.elements.new_module_name.value='Independent UI exam module';f.elements.date.value=document.querySelector('meta[name=lifeos-today]').content;f.elements.venue.value='Independent exam venue';f.requestSubmit()})()");
        await Changed("university-exam-save-independent","location.search==='?view=exams' && document.querySelector('#main').textContent.includes('Independent exam venue')");
        await VerifyNibmCalendarAsync(checks);
        await StudioNavigate("/uni/calendar");
        await Check("nibm-calendar-synthetic-render","document.querySelector('#main').textContent.includes('NIBM native smoke lecture') && document.querySelectorAll('#main tbody tr').length===1 && document.querySelector('#main').textContent.includes('10:00')");
        await CaptureComfort("nibm-calendar-synthetic");
        await Act("document.querySelector('[data-notifications-open]').click()");
        await Check("calendar-notification-drawer","document.getElementById('notifications-drawer').open && document.querySelector('[data-notifications-list]').textContent.includes('NIBM native smoke lecture')");
        await Check("calendar-notification-controls","document.querySelector('[data-notifications-open]').getAttribute('aria-controls')==='notifications-drawer' && document.querySelector('[data-notifications-filter=calendar]').getAttribute('aria-pressed')==='false'");
        await VerifyModalWindowChrome(checks);
        await Check("notification-window-controls-cover-viewport","(()=>{const r=document.getElementById('desktop-titlebar').getBoundingClientRect();return Math.abs(r.x)<1 && Math.abs(r.y)<1 && Math.abs(r.width-innerWidth)<1})()");
        await CaptureComfort("notification-drawer");
        await web.CoreWebView2.ExecuteScriptAsync("document.querySelector('[data-notifications-filter=calendar]').click()");
        await Check("calendar-notification-filter","document.querySelector('[data-notifications-filter=calendar]').getAttribute('aria-pressed')==='true' && [...document.querySelectorAll('[data-notification-id]')].every(row=>row.querySelector('.notification-kind-icon'))");
        await web.CoreWebView2.ExecuteScriptAsync("document.querySelector('[data-notifications-close]').click()");
        await Check("calendar-notification-close","!document.getElementById('notifications-drawer').open");
        await VerifyDesktopPanelAsync(checks);
        await Navigate("/career");
        await Act("(()=>{const f=document.querySelector('form[action=\"/career/skills\"]');f.elements.name.value='Progress ring preview';f.elements.progress.value='50';f.requestSubmit()})()");
        await Changed("progress-ring-record","!!document.querySelector('[data-progress-donut][data-progress-value=\"50\"]')");
        await web.CoreWebView2.ExecuteScriptAsync("document.querySelector('[data-progress-donut]').scrollIntoView({block:'center',behavior:'instant'})");
        await Check("progress-ring-visible","document.querySelector('[data-progress-donut]').classList.contains('is-visible')");
        await CaptureComfort("progress-rings","[data-progress-donut]");
        await web.CoreWebView2.CallDevToolsProtocolMethodAsync("Emulation.setEmulatedMedia","{\"features\":[{\"name\":\"prefers-reduced-motion\",\"value\":\"reduce\"}]}");
        await Check("progress-ring-reduced-motion","[...document.querySelectorAll('.progress-ring-arc')].every(arc=>getComputedStyle(arc).animationName==='none')");
        await web.CoreWebView2.CallDevToolsProtocolMethodAsync("Emulation.setEmulatedMedia","{\"features\":[]}");
        await web.CoreWebView2.ExecuteScriptAsync("document.querySelector('.sidebar .nav-link[href=\"/\"]').click();document.querySelector('.sidebar .nav-link[href=\"/salary\"]').click()");
        await Check("latest-navigation-wins","location.pathname==='/salary' && document.querySelector('#main h1')?.textContent==='Money, in perspective.' && document.querySelector('.sidebar [aria-current=page]')?.getAttribute('href')==='/salary'");
        await Task.Delay(300,lifetime.Token);
        await Check("latest-navigation-wins","location.pathname==='/salary' && document.querySelector('#main h1')?.textContent==='Money, in perspective.'");
        await Check("money-chart","[...document.querySelectorAll('canvas[data-chart]')].some(c=>!!window.Chart?.getChart(c))");
        await Check("navigation-loading-cleared","!document.documentElement.hasAttribute('data-page-loading') && !document.getElementById('main').hasAttribute('aria-busy')");
        await web.CoreWebView2.ExecuteScriptAsync("document.querySelector('[data-command-open]').click();const q=document.getElementById('command-search');q.value='money';q.dispatchEvent(new Event('input',{bubbles:true}))");
        await Check("command-search","document.getElementById('command-dialog').open && [...document.querySelectorAll('[data-command-result]')].some(a=>!a.hidden && a.getAttribute('href')==='/salary')");
        await web.CoreWebView2.ExecuteScriptAsync("(()=>{const q=document.getElementById('command-search');q.value='expense';q.dispatchEvent(new Event('input',{bubbles:true}))})()");
        await Check("search-expense-action","document.querySelector('[data-command-result]:not([hidden])').getAttribute('href')==='/salary#add-expense'");
        await web.CoreWebView2.ExecuteScriptAsync("document.querySelector('[data-command-close]').click();document.dispatchEvent(new KeyboardEvent('keydown',{key:'k',ctrlKey:true,bubbles:true}))");
        await Check("command-shortcut","document.getElementById('command-dialog').open && document.activeElement.id==='command-search'");
        await web.CoreWebView2.ExecuteScriptAsync("document.querySelector('[data-command-close]').click();document.querySelector('[data-theme-toggle]').click();window.__lifeosTheme=document.documentElement.dataset.theme");
        await Check("theme-toggle","localStorage.getItem('lifeos-theme')===window.__lifeosTheme && [...document.querySelectorAll('canvas[data-chart]')].some(c=>!!window.Chart?.getChart(c))");
        string theme=await web.CoreWebView2.ExecuteScriptAsync("window.__lifeosTheme");
        web.CoreWebView2.Reload();
        await Check("theme-persistence","!!window.LifeOS && !window.__lifeosSmoke && document.documentElement.dataset.theme==="+theme);
        await web.CoreWebView2.ExecuteScriptAsync("window.__lifeosSmoke=1");
        await web.CoreWebView2.ExecuteScriptAsync("document.querySelector('#main a[href=\"/salary/plan\"]').click()");
        if (!await WaitForHeading("Your salary, with room to live.")) throw new InvalidOperationException("Salary plan did not render.");
        await web.CoreWebView2.ExecuteScriptAsync("(()=>{const f=document.querySelector('form[method=post][action=\"/salary/plan\"]');for(const [name,value] of Object.entries({income:'100000',bills:'10000',loan_payments:'5000',living:'20000',savings_percent:'20',investment_percent:'10'}))f.elements[name].value=value;f.elements.income.dispatchEvent(new Event('change',{bubbles:true}))})()");
        await Check("salary-preview","document.querySelector('#plan-preview .hero-number')?.textContent.includes('35,000.00')");
        await web.CoreWebView2.ExecuteScriptAsync("(()=>{const f=document.querySelector('form[method=post][action=\"/salary/plan\"]');f.elements.income.value='200000';f.elements.income.dispatchEvent(new Event('change',{bubbles:true}))})()");
        await Check("salary-preview-update","document.querySelector('#plan-preview .hero-number')?.textContent.includes('105,000.00')");
        await Capture("salary-plan");
        await Navigate("/focus");
        await Act("(()=>{const f=document.querySelector('form[action=\"/focus/start\"]');f.elements.subject.value='Windows smoke focus';f.elements.minutes.value='1';f.requestSubmit()})()");
        await Changed("timer-start","document.querySelector('[data-focus-timer]')?.dataset.status==='running'");
        await Capture("focus-running");
        string before=await web.CoreWebView2.ExecuteScriptAsync("document.querySelector('[data-focus-timer]').textContent");
        await Task.Delay(1300,lifetime.Token);
        await Check("timer-ticks","document.querySelector('[data-focus-timer]').textContent!=="+before);
        await Act("document.querySelector('button[name=action][value=pause]').click()");
        await Changed("timer-pause","document.querySelector('[data-focus-timer]')?.dataset.status==='paused'");
        string paused=await web.CoreWebView2.ExecuteScriptAsync("document.querySelector('[data-focus-timer]').textContent");
        await Task.Delay(1300,lifetime.Token);
        await Check("timer-paused-stable","document.querySelector('[data-focus-timer]').textContent==="+paused);
        await Act("document.querySelector('button[name=action][value=resume]').click()");
        await Changed("timer-resume","document.querySelector('[data-focus-timer]')?.dataset.status==='running'");
        await Act("document.querySelector('button[name=action][value=finish]').click()");
        await Changed("timer-finish","!!window.htmx && !document.querySelector('[data-focus-timer]') && [...document.querySelectorAll('#main .simple-row')].some(e=>e.textContent.includes('Windows smoke focus') && e.textContent.includes('complete'))");
        await Navigate("/");
        await web.CoreWebView2.ExecuteScriptAsync("document.querySelector('.dashboard-details')?.setAttribute('open','');document.querySelector('#main a[href=\"/review\"]').click()");
        if (!await WaitForHeading("A moment to reset.")) throw new InvalidOperationException("Weekly review did not render.");
        await Act("(()=>{const f=document.querySelector('form[action=\"/review\"]');f.elements.wins.value='Windows smoke review';f.elements.score.value='8';f.elements.focus.value='Disposable acceptance checks';f.requestSubmit()})()");
        await Changed("review-submit","[...document.querySelectorAll('canvas[data-chart]')].some(c=>!!window.Chart?.getChart(c))");
        await Navigate("/");
        await web.CoreWebView2.ExecuteScriptAsync("document.querySelector('.dashboard-details')?.setAttribute('open','');document.querySelector('#main a[href=\"/review\"]').click()");
        if (!await WaitForHeading("A moment to reset.")) throw new InvalidOperationException("Saved weekly review did not render.");
        await Check("review-chart","window.__lifeosSmoke===1 && [...document.querySelectorAll('canvas[data-chart]')].some(c=>!!window.Chart?.getChart(c))");
        await Capture("weekly-review");
        await Navigate("/planner");
        await Act("document.querySelector('.tabs a[href*=tasks]').click()");
        await Changed("planner-tasks-open","!!document.querySelector('#new-task form')");
        await CaptureComfort("planner-tasks");
        await Act("(()=>{const f=document.querySelector('#new-task form');f.elements.title.value='Double click smoke task';f.requestSubmit();f.requestSubmit()})()");
        await Changed("single-task-from-double-submit","[...document.querySelectorAll('.lesson-entry strong')].filter(e=>e.textContent==='Double click smoke task').length===1");
        await Check("submission-loading-cleared","!document.querySelector('[data-submitting]') && !document.documentElement.hasAttribute('data-page-loading') && !document.getElementById('main').hasAttribute('aria-busy')");
        await Capture("planner-task-created");
        await Navigate("/life-admin");
        await Check("personal-essentials","!!document.querySelector('#expiries') && !!document.querySelector('#subscriptions') && !!document.querySelector('#loans')");
        await CaptureComfort("personal-essentials");
        await Navigate("/small-business");
        await Check("optional-business","!!document.querySelector('form[action=\"/small-business/project\"]')");
        await CaptureComfort("small-business");
        await Navigate("/");
        await web.CoreWebView2.ExecuteScriptAsync("document.querySelector('a[href=\"/why/net-worth\"]').click()");
        if (!await WaitForHeading("Recorded net worth")) throw new InvalidOperationException("Net worth explanation did not render.");
        await Capture("net-worth-explanation");
        web.CoreWebView2.Navigate(new Uri(home!,"/unlock").ToString());
        await Check("unlock-window-controls","location.pathname==='/unlock' && !!document.querySelector('.lock-body #desktop-titlebar [data-window-action=close]') && !!document.querySelector('input[name=pin]')");
        await Capture("unlock-window-chrome");
        await Check("script-errors","window.__lifeosErrors.length===0");
        File.WriteAllText(Path.Combine(data,"launcher-smoke.json"),JsonSerializer.Serialize(new { success=navigationSucceeded,phpPid=php?.Id,page,onboarding,checks }));
    }
    private async Task<bool> WaitForExpression(string expression)
    {
        for (int i=0;i<100;i++)
        {
            if (await web.CoreWebView2.ExecuteScriptAsync("Boolean("+expression+")")=="true") return true;
            await Task.Delay(100,lifetime.Token);
        }
        return false;
    }
    private async Task<bool> WaitForHeading(string heading)
    {
        for (int i=0;i<100;i++)
        {
            string json=await web.CoreWebView2.ExecuteScriptAsync("document.querySelector('#main h1')?.textContent || ''");
            if (System.Text.Json.JsonSerializer.Deserialize<string>(json)==heading) { await Task.Delay(250,lifetime.Token); return true; }
            await Task.Delay(100,lifetime.Token);
        }
        return false;
    }
    private async Task VerifyDesktopPanelAsync(Dictionary<string,bool> checks)
    {
        if(home is null||webEnvironment is null)throw new InvalidOperationException("Desktop panel environment was not initialized.");
        desktopPanel=new DesktopPanelWindow(home,webEnvironment);desktopPanel.Show();
        try{
            bool ready=false;for(int i=0;i<100;i++){if(await desktopPanel.EvaluateAsync("Boolean(document.querySelector('[data-items]') && document.querySelector('[data-refresh]'))")=="true"){ready=true;break;}await Task.Delay(100,lifetime.Token);}
            if(!ready)throw new InvalidOperationException("Desktop side panel did not load.");checks["desktop-panel-native-window"]=true;
            bool hasLecture=false;for(int i=0;i<50;i++){if((await desktopPanel.EvaluateAsync("document.querySelector('[data-items]')?.textContent.includes('NIBM native smoke lecture')"))=="true"){hasLecture=true;break;}await Task.Delay(100,lifetime.Token);}
            if(!hasLecture)throw new InvalidOperationException("Desktop panel did not show today's synthetic NIBM lecture.");checks["desktop-panel-todays-lecture"]=true;
            if(await desktopPanel.EvaluateAsync("document.querySelector('h1')?.textContent==='Right now' && !!document.querySelector('[data-refresh]')")!="true")throw new InvalidOperationException("Desktop panel schedule controls did not render.");checks["desktop-panel-up-next"]=true;
            string screen=await desktopPanel.EvaluateAsync("JSON.stringify({width:innerWidth,height:innerHeight})");
            if(string.IsNullOrWhiteSpace(screen))throw new InvalidOperationException("Desktop side panel viewport was unavailable.");checks["desktop-panel-window-rendered"]=true;
            if(await desktopPanel.EvaluateAsync("document.documentElement.scrollWidth<=innerWidth && getComputedStyle(document.querySelector('.event')).gridTemplateColumns.split(' ').length===2")!="true")throw new InvalidOperationException("Desktop panel rows overflowed the compact viewport.");checks["desktop-panel-table-layout"]=true;
            if(!desktopPanel.GlassResult.RoundedClipApplied)throw new InvalidOperationException("Desktop panel native rounded clipping was not applied.");checks["desktop-panel-native-rounding"]=true;
            await desktopPanel.EvaluateAsync("(()=>{document.querySelector('[data-section-add=todo]').click();const f=document.querySelector('[data-add-form]');f.elements.title.value='Review lecture notes';f.requestSubmit()})()");
            for(int i=0;i<50;i++){if(await desktopPanel.EvaluateAsync("document.querySelector('[data-todos]').textContent.includes('Review lecture notes')")=="true")break;await Task.Delay(100,lifetime.Token);}
            await desktopPanel.EvaluateAsync("(()=>{document.querySelector('[data-section-add=note]').click();const f=document.querySelector('[data-add-form]');f.elements.title.value='A little reminder';f.elements.body.value='Bring your notebook to the afternoon lecture.';f.requestSubmit()})()");
            for(int i=0;i<50;i++){if(await desktopPanel.EvaluateAsync("document.querySelector('[data-notes]').textContent.includes('A little reminder')")=="true")break;await Task.Delay(100,lifetime.Token);}
            await desktopPanel.EvaluateAsync("(()=>{document.querySelector('[data-panel-width]').value=520;document.querySelector('[data-panel-height]').value=640;document.querySelector('[data-apply-size]').click()})()");
            for(int i=0;i<50&&desktopPanel.Width!=520;i++)await Task.Delay(100,lifetime.Token);
            var work=Screen.FromControl(desktopPanel).WorkingArea;
            if(desktopPanel.Width!=Math.Min(520,work.Width)||desktopPanel.Height!=Math.Min(640,work.Height))throw new InvalidOperationException("Panel size customization failed.");
            var customized=desktopPanel.Bounds;desktopPanel.Hide();desktopPanel.RepositionForCurrentScreen();desktopPanel.Show();
            if(desktopPanel.Bounds!=customized)throw new InvalidOperationException("Panel layout was reset when reopening.");
            checks["desktop-panel-size-persistence"]=true;
            using(var restored=new DesktopPanelWindow(home,webEnvironment)) {if(restored.Bounds!=customized)throw new InvalidOperationException("Saved panel layout was not restored from disk.");}
            checks["desktop-panel-layout-reload"]=true;
            desktopPanel.Activate();
            string screenshots=Path.Combine(Environment.GetEnvironmentVariable("LIFEOS_DATA_DIR")!,"smoke-screenshots");
            await desktopPanel.CaptureAsync(screenshots,false);await desktopPanel.CaptureAsync(screenshots,true);
        }finally{desktopPanel.Hide();desktopPanel.Dispose();desktopPanel=null;}
    }
    private void GoRoute(string path) { if (home is not null && path.StartsWith('/') && !path.StartsWith("//") && !IsDisposed) web.Source=new Uri(home,path); }
    private void ShowDesktopPanel()
    {
        if(home is null||webEnvironment is null||diagnostic||IsDisposed)return;
        try{if(desktopPanel is null){desktopPanel=new DesktopPanelWindow(home,webEnvironment);desktopPanel.OpenRouteRequested+=path=>{ShowMainWindow();GoRoute(path);};}if(!desktopPanel.Visible){desktopPanel.RepositionForCurrentScreen();desktopPanel.Show();}}
        catch(InvalidOperationException){}
    }
    private void ToggleDesktopPanel(){if(!appReady){pendingPanelToggle=!pendingPanelToggle;return;}if(desktopPanel?.Visible==true)desktopPanel.Hide();else ShowDesktopPanel();}
    private async Task CheckForTodayPanelAsync()
    {
        if(home is null||web.CoreWebView2 is null||diagnostic||IsDisposed||todayPanelChecking)return;
        var today=DateOnly.FromDateTime(DateTime.Today);if(lastAutoPanelDate==today)return;
        todayPanelChecking=true;
        try{await web.CoreWebView2.ExecuteScriptAsync("(async()=>{let hasItems=false;try{const r=await fetch('/desktop/panel/data',{cache:'no-store',signal:AbortSignal.timeout(10000)});if(r.ok){const data=await r.json();hasItems=Array.isArray(data.items)&&data.items.length>0;}}catch{}window.chrome.webview.postMessage({type:'lifeos-today-panel',hasItems});})()");}
        catch(InvalidOperationException){todayPanelChecking=false;}
    }
    private void Cleanup()
    {
        if(cleanupDone)return;cleanupDone=true;
        lifetime.Cancel();
        panelHotkeys?.Dispose();panelHotkeys=null;
        todayPanelTimer.Stop();todayPanelTimer.Dispose();desktopPanel?.Dispose();desktopPanel=null;
        DisposeNibmCalendar();
        try { if (php is { HasExited:false }) { php.Kill(true); php.WaitForExit(3000); } } catch { }
        php?.Dispose(); php=null; job.Dispose(); reminders.Visible=false; reminders.Dispose();trayMenu?.Dispose();
    }
}

// Windows closes the PHP process even if the launcher crashes or is terminated.
internal sealed class ProcessJob : IDisposable
{
    private IntPtr handle = CreateJobObject(IntPtr.Zero,null);
    public ProcessJob()
    {
        if (handle==IntPtr.Zero) throw new System.ComponentModel.Win32Exception();
        var info = new ExtendedLimitInformation(); info.Basic.LimitFlags=0x2000;
        int size=Marshal.SizeOf<ExtendedLimitInformation>(); IntPtr memory=Marshal.AllocHGlobal(size);
        try { Marshal.StructureToPtr(info,memory,false); if (!SetInformationJobObject(handle,9,memory,(uint)size)) throw new System.ComponentModel.Win32Exception(); }
        finally { Marshal.FreeHGlobal(memory); }
    }
    public void Attach(Process process) { if (!AssignProcessToJobObject(handle,process.Handle)) { process.Kill(true); throw new System.ComponentModel.Win32Exception(); } }
    public void Dispose() { if (handle!=IntPtr.Zero) { CloseHandle(handle); handle=IntPtr.Zero; } }
    [StructLayout(LayoutKind.Sequential)] private struct BasicLimitInformation { public long PerProcess, PerJob; public uint LimitFlags; public UIntPtr MinWorking,MaxWorking; public uint ActiveLimit; public UIntPtr Affinity; public uint Priority,Scheduling; }
    [StructLayout(LayoutKind.Sequential)] private struct IoCounters { public ulong ReadOps,WriteOps,OtherOps,ReadBytes,WriteBytes,OtherBytes; }
    [StructLayout(LayoutKind.Sequential)] private struct ExtendedLimitInformation { public BasicLimitInformation Basic; public IoCounters Io; public UIntPtr ProcessMemory,JobMemory,PeakProcessMemory,PeakJobMemory; }
    [DllImport("kernel32.dll",CharSet=CharSet.Unicode,SetLastError=true)] private static extern IntPtr CreateJobObject(IntPtr attributes,string? name);
    [DllImport("kernel32.dll",SetLastError=true)] private static extern bool SetInformationJobObject(IntPtr job,int info,IntPtr data,uint size);
    [DllImport("kernel32.dll",SetLastError=true)] private static extern bool AssignProcessToJobObject(IntPtr job,IntPtr process);
    [DllImport("kernel32.dll")] private static extern bool CloseHandle(IntPtr handle);
}
