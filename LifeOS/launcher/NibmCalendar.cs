using System.Globalization;
using System.Text.Json;
using Microsoft.Web.WebView2.Core;
using Microsoft.Web.WebView2.WinForms;

internal sealed partial class LifeWindow
{
    private CoreWebView2Environment? nibmEnvironment;
    private NibmCalendarWindow? nibmWindow;
    private readonly System.Windows.Forms.Timer nibmTimer = new() { Interval = 5 * 60 * 1000 };
    private readonly Dictionary<string, TaskCompletionSource<JsonElement>> nibmLocalRequests = new();
    private bool nibmEnabled;
    private bool nibmBusy;
    private string nibmBatch = "";
    private int nibmGeneration;

    private void InitializeNibmCalendar(CoreWebView2Environment environment)
    {
        nibmEnvironment = environment;
        nibmTimer.Tick += async (_, _) => await SyncNibmCalendarAsync();
    }

    private async Task HandleNibmMessageAsync(JsonElement message)
    {
        try {
            if (!message.TryGetProperty("action", out var action) || action.ValueKind != JsonValueKind.String) return;
            string kind = action.GetString() ?? "";
            if (kind is "pause" or "disconnect") { PauseNibmCalendar(); PublishNibmState("paused", "Calendar sync is paused."); return; }
            if (kind == "configure" && (!message.TryGetProperty("enabled", out var enabled) || enabled.ValueKind != JsonValueKind.True)) {
                PauseNibmCalendar(); return;
            }
            if (kind is not ("configure" or "open" or "sync")) return;
            if (!message.TryGetProperty("batchId", out var batchValue) || batchValue.ValueKind != JsonValueKind.String || !Guid.TryParse(batchValue.GetString(), out var batch)) {
                PublishNibmState("error", "Save a valid NIBM batch before connecting."); return;
            }
            string selectedBatch = batch.ToString();
            if (kind == "configure" && nibmEnabled && nibmBatch == selectedBatch) return;
            if (!nibmEnabled || nibmBatch != selectedBatch) { nibmGeneration++; nibmBatch = selectedBatch; }
            nibmEnabled = true;
            nibmTimer.Start();
            if (kind == "open") {
                int generation = nibmGeneration;
                var portal = await GetNibmWindowAsync();
                if (!nibmEnabled || nibmGeneration != generation || nibmBatch != selectedBatch || lifetime.IsCancellationRequested || IsDisposed || portal.IsDisposed) return;
                portal.Show(this);
                portal.WindowState = FormWindowState.Normal;
                portal.Activate();
            }
            await SyncNibmCalendarAsync();
        } catch (OperationCanceledException) { }
        catch (Exception) { if (!IsDisposed && nibmEnabled) PublishNibmState("error", "NIBM could not connect. Check your connection and try again."); }
    }

    private async Task<NibmCalendarWindow> GetNibmWindowAsync()
    {
        if (nibmEnvironment is null) throw new InvalidOperationException("LifeOS is still opening.");
        if (nibmWindow is null || nibmWindow.IsDisposed) {
            nibmWindow = new NibmCalendarWindow();
            nibmWindow.PortalChanged += async (_, _) => { if (nibmEnabled && !nibmBusy) await SyncNibmCalendarAsync(); };
        }
        var window = nibmWindow;
        try { await window.InitializeAsync(nibmEnvironment); return window; }
        catch {
            window.Shutdown();
            if (nibmWindow == window) nibmWindow = null;
            throw;
        }
    }

    private void PauseNibmCalendar()
    {
        nibmEnabled = false;
        nibmGeneration++;
        nibmTimer.Stop();
        if (nibmWindow is { IsDisposed: false }) nibmWindow.Hide();
    }

    private void PublishNibmState(string status, string message)
    {
        if (IsDisposed || web.CoreWebView2 is null) return;
        web.CoreWebView2.PostWebMessageAsJson(JsonSerializer.Serialize(new { type = "lifeos-nibm-state", status, message }));
    }

    private async Task SyncNibmCalendarAsync()
    {
        if (!nibmEnabled || nibmBusy || lifetime.IsCancellationRequested || web.CoreWebView2 is null) return;
        nibmBusy = true;
        int generation = nibmGeneration;
        string selectedBatch = nibmBatch;
        string? csrf = null;
        bool Current() => nibmEnabled && nibmGeneration == generation && !lifetime.IsCancellationRequested;
        try {
            // The local authenticated configuration is authoritative, even when a page message supplies a batch.
            var config = await RunNibmLocalAsync("(async()=>{const r=await fetch('/uni/calendar/config',{cache:'no-store',redirect:'error'});if(!r.ok)throw Error('config');return await r.json()})()");
            if (!Current()) return;
            if (!config.TryGetProperty("enabled", out var enabled) || enabled.ValueKind != JsonValueKind.True ||
                !config.TryGetProperty("batchId", out var serverBatch) || !string.Equals(serverBatch.GetString(), selectedBatch, StringComparison.OrdinalIgnoreCase)) {
                PauseNibmCalendar(); return;
            }
            if (!config.TryGetProperty("csrf", out var token) || token.ValueKind != JsonValueKind.String || string.IsNullOrEmpty(token.GetString())) return;
            csrf = token.GetString();
            PublishNibmState("syncing", "Checking the NIBM lecture calendar...");
            var portal = await GetNibmWindowAsync();
            if (!Current()) return;
            var colomboNow = DateTimeOffset.UtcNow.ToOffset(TimeSpan.FromMinutes(330));
            var month = new DateTime(colomboNow.Year, colomboNow.Month, 1);
            var fromDate = month.AddMonths(-1);
            var throughDate = month.AddMonths(7).AddDays(-1);
            var start = new DateTimeOffset(fromDate, TimeSpan.FromMinutes(330));
            var end = new DateTimeOffset(throughDate.AddDays(1).AddMilliseconds(-1), TimeSpan.FromMinutes(330));
            var result = await portal.ReadLecturesAsync(selectedBatch, start, end, lifetime.Token);
            if (!Current()) return;
            if (!result.TryGetProperty("ok", out var ok) || ok.ValueKind != JsonValueKind.True) {
                string code = result.TryGetProperty("code", out var reason) && reason.ValueKind == JsonValueKind.String ? reason.GetString() ?? "" : "";
                string error = code == "signin" ? "Sign in to NIBM to continue syncing." : code == "response" ?
                    "NIBM returned an unsupported calendar response. Your saved lectures are unchanged." : "NIBM could not be reached. Your saved lectures are unchanged.";
                PublishNibmState(code == "signin" ? "signin" : "error", error);
                await RecordNibmErrorAsync(csrf!, error);
                return;
            }
            if (!result.TryGetProperty("lectures", out var lectures) || lectures.ValueKind != JsonValueKind.Array || lectures.GetArrayLength() > 2000) throw new InvalidOperationException("Invalid NIBM response.");
            var form = new Dictionary<string, string> {
                ["_csrf"] = csrf!, ["batch_id"] = selectedBatch,
                ["window_start"] = fromDate.ToString("yyyy-MM-dd", CultureInfo.InvariantCulture),
                ["window_end"] = throughDate.ToString("yyyy-MM-dd", CultureInfo.InvariantCulture),
                ["lectures"] = lectures.GetRawText()
            };
            if (!Current()) return;
            var imported = await RunNibmLocalAsync("(async()=>{const r=await fetch('/uni/calendar/import',{method:'POST',redirect:'error',body:new URLSearchParams(" + JsonSerializer.Serialize(form) + ")});return {ok:r.ok,status:r.status}})()");
            if (!Current()) return;
            if (imported.TryGetProperty("ok", out var saved) && saved.ValueKind == JsonValueKind.True) PublishNibmState("synced", "Lecture calendar synced.");
            else {
                const string error = "NIBM returned an unsupported calendar response. Your saved lectures are unchanged.";
                PublishNibmState("error", error);
                await RecordNibmErrorAsync(csrf!, error);
            }
        } catch (OperationCanceledException) { }
        catch (Exception) {
            if (Current()) {
                const string error = "NIBM could not be reached. Your saved lectures are unchanged.";
                PublishNibmState("error", error);
                if (csrf is not null) { try { await RecordNibmErrorAsync(csrf, error); } catch { } }
            }
        } finally { nibmBusy = false; }
    }

    private Task<JsonElement> RecordNibmErrorAsync(string csrf, string message)
    {
        var form = new Dictionary<string, string> { ["_csrf"] = csrf, ["message"] = message };
        return RunNibmLocalAsync("(async()=>{const r=await fetch('/uni/calendar/error',{method:'POST',redirect:'error',body:new URLSearchParams(" + JsonSerializer.Serialize(form) + ")});if(!r.ok)throw Error('status');return {ok:true}})()");
    }

    private async Task<JsonElement> RunNibmLocalAsync(string expression)
    {
        string request = Guid.NewGuid().ToString("N");
        var completion = new TaskCompletionSource<JsonElement>(TaskCreationOptions.RunContinuationsAsynchronously);
        nibmLocalRequests.Add(request, completion);
        try {
            // ExecuteScriptAsync returns before a JS Promise resolves; results return through the origin-checked bridge.
            await web.CoreWebView2.ExecuteScriptAsync("(async()=>{try{const result=await " + expression + ";window.chrome.webview.postMessage({type:'lifeos-nibm-local',request:" + JsonSerializer.Serialize(request) + ",ok:true,result})}catch{window.chrome.webview.postMessage({type:'lifeos-nibm-local',request:" + JsonSerializer.Serialize(request) + ",ok:false})}})()");
            return await completion.Task.WaitAsync(TimeSpan.FromSeconds(25), lifetime.Token);
        } finally { nibmLocalRequests.Remove(request); }
    }

    private void HandleNibmLocalResult(JsonElement value)
    {
        if (!value.TryGetProperty("request", out var request) || request.ValueKind != JsonValueKind.String ||
            !nibmLocalRequests.TryGetValue(request.GetString() ?? "", out var completion)) return;
        if (value.TryGetProperty("ok", out var ok) && ok.ValueKind == JsonValueKind.True && value.TryGetProperty("result", out var result)) completion.TrySetResult(result.Clone());
        else completion.TrySetException(new InvalidOperationException("The local calendar request failed."));
    }

    private void DisposeNibmCalendar()
    {
        nibmEnabled = false;
        nibmGeneration++;
        nibmTimer.Stop();
        nibmTimer.Dispose();
        nibmWindow?.Shutdown();
        nibmWindow = null;
        foreach (var request in nibmLocalRequests.Values) request.TrySetCanceled();
        nibmLocalRequests.Clear();
    }

    private async Task VerifyNibmCalendarAsync(Dictionary<string, bool> checks)
    {
        foreach (var (source, expected) in new[] {
            ("https://aca.mynibm.com/", true), ("https://aca.mynibm.com/dashboard?batch=test", true),
            ("http://aca.mynibm.com/", false), ("https://aca.mynibm.com:8443/", false),
            ("https://aca.mynibm.com.evil.example/", false), ("https://user@aca.mynibm.com/", false),
            ("http://127.0.0.1:8787/", false), ("file:///C:/data/lifeos.db", false)
        }) {
            if (NibmCalendarWindow.IsPortal(source) != expected) throw new InvalidOperationException("NIBM origin boundary failed.");
        }
        checks["nibm-origin-boundary"] = true;
        var hidden = new NibmCalendarWindow();
        try {
            await hidden.InitializeDiagnosticAsync(nibmEnvironment!);
            if (hidden.Visible || !hidden.BrowserReady) throw new InvalidOperationException("Hidden NIBM browser did not initialize.");
            checks["nibm-hidden-browser"] = true;
        } finally { hidden.Shutdown(); }
        string batch = Guid.NewGuid().ToString();
        string day = DateTimeOffset.UtcNow.ToOffset(TimeSpan.FromMinutes(330)).ToString("yyyy-MM-dd", CultureInfo.InvariantCulture);
        var fixture = new {
            batch, day,
            lecture = new { lectureId = Guid.NewGuid().ToString(), batchId = batch, moduleName = "NIBM native smoke lecture", lectureType = "LECTURE", status = "APPROVED", startAt = day + "T09:00:00+05:30", endAt = day + "T11:00:00+05:30", facilityName = "Native fixture room" }
        };
        var result = await RunNibmLocalAsync("(async()=>{const p=" + JsonSerializer.Serialize(fixture) + ";const c=await(await fetch('/uni/calendar/config')).json();if(c.enabled)throw Error('Expected isolated empty calendar');const post=async(path,body)=>{const r=await fetch(path,{method:'POST',body:new URLSearchParams({_csrf:c.csrf,...body})});if(!r.ok)throw Error('Fixture request failed');return r};try{await post('/uni/calendar/connect',{portal_url:'https://aca.mynibm.com/dashboard?view=month&batch='+p.batch});const body={batch_id:p.batch,window_start:p.day,window_end:p.day,lectures:JSON.stringify([p.lecture])};const first=await(await post('/uni/calendar/import',body)).json();p.lecture.startAt=p.day+'T10:00:00+05:30';body.lectures=JSON.stringify([p.lecture]);const second=await(await post('/uni/calendar/import',body)).json();return{created:first.counts.created,updated:second.counts.updated}}finally{await post('/uni/calendar/pause',{})}})()");
        if (result.GetProperty("created").GetInt32() != 1 || result.GetProperty("updated").GetInt32() != 1) throw new InvalidOperationException("Native calendar import did not create/update exactly one fixture lecture.");
        checks["nibm-native-local-import"] = true;
        var config = await RunNibmLocalAsync("fetch('/uni/calendar/config').then(r=>r.json())");
        if (config.GetProperty("enabled").GetBoolean()) throw new InvalidOperationException("Calendar fixture did not pause sync.");
        checks["nibm-synthetic-import-paused"] = true;
    }
}

// University credentials remain in the university origin's WebView storage.
// The only information this view sends to LifeOS is bounded lecture metadata.
internal sealed class NibmCalendarWindow : Form
{
    private const string Origin = "https://aca.mynibm.com";
    private readonly WebView2 portal = new() { Dock = DockStyle.Fill };
    private Task? initialize;
    private TaskCompletionSource<JsonElement>? pending;
    private string pendingRequest = "";
    private bool shutdown;
    internal event EventHandler? PortalChanged;

    internal NibmCalendarWindow()
    {
        Text = "NIBM calendar — Sign in securely";
        Width = 1160; Height = 800; MinimumSize = new Size(720, 600);
        StartPosition = FormStartPosition.CenterParent;
        Controls.Add(portal);
        FormClosing += (_, e) => { if (!shutdown) { e.Cancel = true; Hide(); } };
    }

    internal bool BrowserReady => portal.CoreWebView2 is not null;
    internal Task InitializeAsync(CoreWebView2Environment environment) => initialize ??= InitializeCoreAsync(environment, true);
    internal Task InitializeDiagnosticAsync(CoreWebView2Environment environment) => initialize ??= InitializeCoreAsync(environment, false);

    private async Task InitializeCoreAsync(CoreWebView2Environment environment, bool navigate)
    {
        _ = Handle;
        _ = portal.Handle;
        await portal.EnsureCoreWebView2Async(environment);
        var core = portal.CoreWebView2;
        core.Settings.AreDevToolsEnabled = false;
        core.Settings.IsStatusBarEnabled = false;
        core.Settings.AreDefaultContextMenusEnabled = false;
        core.Settings.IsPasswordAutosaveEnabled = false;
        core.NavigationStarting += (_, e) => { if (!IsPortal(e.Uri)) e.Cancel = true; };
        core.NewWindowRequested += (_, e) => e.Handled = true;
        core.PermissionRequested += (_, e) => e.State = CoreWebView2PermissionState.Deny;
        core.DownloadStarting += (_, e) => e.Cancel = true;
        core.NavigationCompleted += (_, e) => { if (e.IsSuccess && IsPortal(core.Source)) PortalChanged?.Invoke(this, EventArgs.Empty); };
        await core.AddScriptToExecuteOnDocumentCreatedAsync("""
            (()=>{let last=localStorage.getItem('accessToken');setInterval(()=>{
              if(document.hidden)return;
              const token=localStorage.getItem('accessToken');
              if(token!==last){last=token;window.chrome.webview.postMessage({type:'lifeos-nibm-session-changed'});}
            },5000);})();
            """);
        core.WebMessageReceived += (_, e) => {
            if(IsPortal(e.Source) && e.WebMessageAsJson.Length<256) {
                try { using var notice=JsonDocument.Parse(e.WebMessageAsJson);
                    if(notice.RootElement.TryGetProperty("type",out var kind)&&kind.GetString()=="lifeos-nibm-session-changed") {PortalChanged?.Invoke(this,EventArgs.Empty);return;}
                } catch(JsonException){} catch(InvalidOperationException){}
            }
            if (!IsPortal(e.Source) || pending is null || e.WebMessageAsJson.Length > 4_000_000) return;
            try {
                using var document = JsonDocument.Parse(e.WebMessageAsJson);
                var value = document.RootElement;
                if (!value.TryGetProperty("type", out var type) || type.GetString() != "lifeos-nibm-result" ||
                    !value.TryGetProperty("request", out var request) || request.GetString() != pendingRequest) return;
                pending.TrySetResult(value.Clone());
            } catch (JsonException) { } catch (InvalidOperationException) { }
        };
        if (!navigate) return;
        var loaded = new TaskCompletionSource<bool>(TaskCreationOptions.RunContinuationsAsynchronously);
        void Loaded(object? sender, CoreWebView2NavigationCompletedEventArgs e) => loaded.TrySetResult(e.IsSuccess);
        core.NavigationCompleted += Loaded;
        try {
            core.Navigate(Origin + "/");
            if (!await loaded.Task.WaitAsync(TimeSpan.FromSeconds(30))) throw new InvalidOperationException("NIBM could not open.");
        } finally { core.NavigationCompleted -= Loaded; }
    }

    internal static bool IsPortal(string source) => Uri.TryCreate(source, UriKind.Absolute, out var uri) &&
        uri.Scheme == "https" && uri.Host == "aca.mynibm.com" && uri.IsDefaultPort && string.IsNullOrEmpty(uri.UserInfo);

    internal async Task<JsonElement> ReadLecturesAsync(string batchId, DateTimeOffset start, DateTimeOffset end, CancellationToken cancellation)
    {
        if (!IsPortal(portal.CoreWebView2.Source)) throw new InvalidOperationException("NIBM is not open.");
        pending = new TaskCompletionSource<JsonElement>(TaskCreationOptions.RunContinuationsAsynchronously);
        pendingRequest = Guid.NewGuid().ToString("N");
        string parameters = JsonSerializer.Serialize(new {
            request = pendingRequest, batchId,
            startAt = start.UtcDateTime.ToString("O", CultureInfo.InvariantCulture),
            endAt = end.UtcDateTime.ToString("O", CultureInfo.InvariantCulture)
        });
        try {
            await portal.CoreWebView2.ExecuteScriptAsync("(" + FetchScript + ")(" + parameters + ")");
            return await pending.Task.WaitAsync(TimeSpan.FromSeconds(45), cancellation);
        } finally { pending = null; pendingRequest = ""; }
    }

    internal void Shutdown()
    {
        if (shutdown) return;
        shutdown = true;
        pending?.TrySetCanceled();
        Close();
        Dispose();
    }

    private const string FetchScript = """
    async function(p) {
      const send = result => window.chrome.webview.postMessage({type:'lifeos-nibm-result',request:p.request,...result});
      if(location.origin !== 'https://aca.mynibm.com') return;
      const base = 'https://qoo15vk3ac.execute-api.ap-southeast-1.amazonaws.com/prod';
      const controller = new AbortController();
      const timeout = setTimeout(() => controller.abort(), 30000);
      try {
        const validToken = value => typeof value === 'string' && value.length > 0 && value.length <= 16384 && !/\s/.test(value);
        let token = localStorage.getItem('accessToken');
        if(token !== null && !validToken(token)) return send({ok:false,code:'signin'});
        const savedRefresh = localStorage.getItem('refreshToken');
        if(!validToken(token) && !validToken(savedRefresh)) return send({ok:false,code:'signin'});
        const query = new URLSearchParams({batchId:p.batchId,startAt:p.startAt,endAt:p.endAt});
        const request = () => fetch(base+'/schedule-service/lectures?'+query,{headers:{Authorization:'Bearer '+token},credentials:'omit',redirect:'error',cache:'no-store',signal:controller.signal});
        let response = validToken(token) ? await request() : null;
        if(response === null || [401,498].includes(response.status)) {
          const refreshToken = localStorage.getItem('refreshToken');
          if(!validToken(refreshToken)) return send({ok:false,code:'signin'});
          const refreshed = await fetch(base+'/identity-service/auth/refresh',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({refreshToken}),credentials:'omit',redirect:'error',signal:controller.signal});
          if(!refreshed.ok) return send({ok:false,code:[400,401,403,498].includes(refreshed.status)?'signin':'network'});
          const data = (await refreshed.json())?.data;
          if(!validToken(data?.accessToken) || (data.refreshToken != null && !validToken(data.refreshToken))) return send({ok:false,code:'signin'});
          // Some providers rotate only the access token. Keep the existing refresh
          // credential when no replacement is returned, including after reboot.
          if(data.refreshToken != null) localStorage.setItem('refreshToken',data.refreshToken);
          localStorage.setItem('accessToken',data.accessToken);
          token = data.accessToken;
          response = await request();
        }
        if([401,403,498].includes(response.status)) return send({ok:false,code:'signin'});
        if(!response.ok) return send({ok:false,code:'network'});
        const snapshot = await response.json();
        const rows = snapshot?.data?.lectures;
        if(!Array.isArray(rows) || rows.length > 2000) return send({ok:false,code:'response'});
        // Missing lectures cancel saved entries only for an explicitly complete snapshot.
        // Reject pagination/error markers instead of treating a partial page as a full calendar.
        for(const meta of [snapshot,snapshot?.data,snapshot?.pagination,snapshot?.data?.pagination]) {
          if(!meta || typeof meta !== 'object') continue;
          if(meta.success === false || meta.complete === false || meta.hasMore === true || meta.hasNextPage === true) return send({ok:false,code:'response'});
          for(const key of ['nextToken','nextPageToken','nextCursor','nextPage']) {
            const value = meta[key];
            if(value != null && value !== '' && value !== false && value !== 0) return send({ok:false,code:'response'});
          }
          for(const key of ['total','totalCount']) if(typeof meta[key] === 'number' && Number.isFinite(meta[key]) && meta[key] > rows.length) return send({ok:false,code:'response'});
        }
        const fields = ['lectureId','moduleName','moduleId','lectureType','startAt','endAt','status','batchId','batchName','facilityName','internalLecturerName','visitingLecturerName','demonstratorName','tutorName','instructorName','technicalAssistantName','updatedAt'];
        const lectures = rows.map(row => {
          if(!row || typeof row !== 'object' || Array.isArray(row)) throw Error('response');
          const value = {};
          for(const field of fields) {
            if(row[field] == null) continue;
            if(typeof row[field] !== 'string' || row[field].length > 4000) throw Error('response');
            value[field] = row[field];
          }
          return value;
        });
        if(new TextEncoder().encode(JSON.stringify(lectures)).length > 1500000) return send({ok:false,code:'response'});
        send({ok:true,lectures});
      } catch(error) { send({ok:false,code:error instanceof Error && error.message==='response' ? 'response' : 'network'}); }
      finally { clearTimeout(timeout); }
    }
    """;
}
