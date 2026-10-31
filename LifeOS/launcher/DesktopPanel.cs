using System.Text.Json;
using System.Runtime.InteropServices;
using Microsoft.Web.WebView2.Core;
using Microsoft.Web.WebView2.WinForms;

internal sealed class DesktopPanelWindow : Form
{
    private readonly WebView2 browser = new() { Dock=DockStyle.Fill };
    private readonly Uri home;
    private readonly CoreWebView2Environment environment;
    private readonly System.Windows.Forms.Timer autoHideTimer=new(){Interval=1000};
    private bool autoHideEnabled=true;
    private DateTime autoHideGraceUntil;
    private bool initialized, restoringBounds;
    private readonly string layoutPath=Path.Combine(Path.GetFullPath(Environment.GetEnvironmentVariable("LIFEOS_DATA_DIR") ?? Path.Combine(AppContext.BaseDirectory,"data")),"desktop-panel-layout.json");
    private Rectangle? savedBounds;
    private PanelGlassResult glassResult;
    internal event Action<string>? OpenRouteRequested;
    internal bool IsPanelVisible => Visible;
    internal PanelGlassResult GlassResult=>glassResult;
    internal async Task<string> EvaluateAsync(string script){if(browser.CoreWebView2 is null)return "";return await browser.CoreWebView2.ExecuteScriptAsync(script);}

    internal DesktopPanelWindow(Uri home,CoreWebView2Environment environment)
    {
        this.home=home; this.environment=environment;
        FormBorderStyle=FormBorderStyle.None; MinimumSize=new Size(320,360); ShowInTaskbar=false; TopMost=true;
        Text="LifeOS desktop panel";
        BackColor=Color.Black; Width=420;
        StartPosition=FormStartPosition.Manual;
        LoadLayout();
        RepositionForCurrentScreen();
        Controls.Add(browser); KeyPreview=true;
        KeyDown+=(_,e)=>{if(e.KeyCode==Keys.Escape){e.Handled=true;Hide();}};
        FormClosing+=(_,e)=>{if(e.CloseReason==CloseReason.UserClosing){e.Cancel=true;Hide();}};
        VisibleChanged+=async(_,_)=>{if(Visible){browser.CoreWebView2?.Resume();autoHideGraceUntil=DateTime.UtcNow.AddSeconds(12);if(autoHideEnabled)autoHideTimer.Start();}else{autoHideTimer.Stop();if(browser.CoreWebView2 is not null)try{await browser.CoreWebView2.TrySuspendAsync();}catch(InvalidOperationException){}}};
        SizeChanged+=(_,_)=>{if(glassResult.RoundedClipApplied)PanelGlass.UpdateRoundedClip(this);};
        ResizeEnd+=(_,_)=>SaveLayout();
        LocationChanged+=(_,_)=>{if(Visible&&!restoringBounds) savedBounds=Bounds;};
        autoHideTimer.Tick+=(_,_)=>{
            if(!Visible||!autoHideEnabled||DateTime.UtcNow<autoHideGraceUntil)return;
            GetWindowThreadProcessId(GetForegroundWindow(),out uint foregroundPid);
            if(foregroundPid!=(uint)Environment.ProcessId)Hide();
        };
        Load+=async (_,_)=>await InitializeAsync();
    }
    private void LoadLayout()
    {
        try {
            if(!File.Exists(layoutPath)||new FileInfo(layoutPath).Length>4096)return;
            using var doc=JsonDocument.Parse(File.ReadAllText(layoutPath));var v=doc.RootElement;
            savedBounds=new Rectangle(v.GetProperty("x").GetInt32(),v.GetProperty("y").GetInt32(),v.GetProperty("width").GetInt32(),v.GetProperty("height").GetInt32());
        } catch(Exception e) when(e is IOException or UnauthorizedAccessException or JsonException or KeyNotFoundException or InvalidOperationException or FormatException) { }
    }
    private void SaveLayout()
    {
        if(restoringBounds)return;
        savedBounds=Bounds;
        try {
            Directory.CreateDirectory(Path.GetDirectoryName(layoutPath)!);
            string temp=layoutPath+".tmp";
            File.WriteAllText(temp,JsonSerializer.Serialize(new{x=Left,y=Top,width=Width,height=Height}));
            File.Move(temp,layoutPath,true);
        } catch(IOException){} catch(UnauthorizedAccessException){}
        PublishLayout();
    }
    private void PublishLayout()
    {
        if(IsDisposed||browser.IsDisposed)return;
        try{browser.CoreWebView2?.PostWebMessageAsJson(JsonSerializer.Serialize(new{type="lifeos-panel-layout",width=Width,height=Height}));}catch(InvalidOperationException){}
    }
    internal void RepositionForCurrentScreen()
    {
        var desired=savedBounds;
        var work=desired is Rectangle r ? Screen.FromRectangle(r).WorkingArea : Screen.FromPoint(Cursor.Position).WorkingArea;
        int width=Math.Clamp(desired?.Width??440,Math.Min(320,work.Width),work.Width);
        int height=Math.Clamp(desired?.Height??760,Math.Min(360,work.Height),work.Height);
        int x=Math.Clamp(desired?.X??(work.Right-width-18),work.Left,work.Right-width);
        int y=Math.Clamp(desired?.Y??(work.Top+18),work.Top,work.Bottom-height);
        restoringBounds=true;Bounds=new Rectangle(x,y,width,height);restoringBounds=false;
    }
    private void HandleLayoutMessage(JsonElement message)
    {
        string kind=message.GetProperty("type").GetString()??"";
        if(kind=="lifeos-panel-drag"||kind=="lifeos-panel-resize") {
            ReleaseCapture();SendMessage(Handle,0x0112,new IntPtr(kind=="lifeos-panel-drag"?0xF012:0xF008),IntPtr.Zero);SaveLayout();
        } else if(kind=="lifeos-panel-size"&&message.TryGetProperty("width",out var w)&&w.ValueKind==JsonValueKind.Number&&w.TryGetInt32(out int width)&&message.TryGetProperty("height",out var h)&&h.ValueKind==JsonValueKind.Number&&h.TryGetInt32(out int height)) {
            savedBounds=new Rectangle(Left,Top,width,height);RepositionForCurrentScreen();SaveLayout();
        } else if(kind=="lifeos-panel-reset") {savedBounds=null;RepositionForCurrentScreen();SaveLayout();}
    }
    private async Task InitializeAsync()
    {
        if(initialized||IsDisposed)return; initialized=true;
        try {
            await browser.EnsureCoreWebView2Async(environment);
            browser.DefaultBackgroundColor=Color.Transparent;
            browser.CoreWebView2.Settings.AreDevToolsEnabled=false;
            browser.CoreWebView2.Settings.AreDefaultContextMenusEnabled=false;
            browser.CoreWebView2.NewWindowRequested+=(_,e)=>e.Handled=true;
            browser.CoreWebView2.PermissionRequested+=(_,e)=>e.State=CoreWebView2PermissionState.Deny;
            browser.CoreWebView2.NavigationCompleted+=async(_,_)=>{PublishLayout();try{await browser.CoreWebView2.ExecuteScriptAsync("document.documentElement.dataset.nativeGlass="+(glassResult.NativeBackdropConfigured?"'true'":"'false'"));}catch(InvalidOperationException){}};
            browser.CoreWebView2.NavigationStarting+=(_,e)=>{if(!Uri.TryCreate(e.Uri,UriKind.Absolute,out var uri)||uri.GetLeftPart(UriPartial.Authority)!=home.GetLeftPart(UriPartial.Authority))e.Cancel=true;};
            browser.CoreWebView2.WebMessageReceived+=(_,e)=>{try{if(!Uri.TryCreate(e.Source,UriKind.Absolute,out var source)||source.GetLeftPart(UriPartial.Authority)!=home.GetLeftPart(UriPartial.Authority))return;using var message=JsonDocument.Parse(e.WebMessageAsJson);if(!message.RootElement.TryGetProperty("type",out var kind)||kind.ValueKind!=JsonValueKind.String)return;if(kind.GetString() is "lifeos-panel-drag" or "lifeos-panel-resize" or "lifeos-panel-size" or "lifeos-panel-reset")HandleLayoutMessage(message.RootElement);else if(kind.GetString()=="lifeos-panel-close")Hide();else if(kind.GetString()=="lifeos-panel-autohide"&&message.RootElement.TryGetProperty("enabled",out var enabled)){autoHideEnabled=enabled.ValueKind==JsonValueKind.True;if(Visible&&autoHideEnabled)autoHideTimer.Start();else autoHideTimer.Stop();}else if(kind.GetString()=="lifeos-panel-open"&&message.RootElement.TryGetProperty("route",out var route)){string path=route.GetString()??"";if(path.StartsWith('/')&&!path.StartsWith("//")&&!path.Contains('\\')){OpenRouteRequested?.Invoke(path);Hide();}}}catch(JsonException){}};
            browser.CoreWebView2.Navigate(new Uri(home,"/desktop/panel").ToString());
        } catch(InvalidOperationException){Hide();}
    }
    protected override CreateParams CreateParams { get { var value=base.CreateParams;value.Style|=0x00040000;return value; } }
    protected override bool ShowWithoutActivation=>false;
    protected override void OnHandleCreated(EventArgs e){base.OnHandleCreated(e);glassResult=PanelGlass.Apply(this,forceRoundedClip:true);}
    protected override void WndProc(ref Message m){if(m.Msg==PanelGlass.NcCalcSizeMessage){m.Result=IntPtr.Zero;return;}base.WndProc(ref m);if(m.Msg is PanelGlass.CompositionChangedMessage or 0x031A)glassResult=PanelGlass.Apply(this,forceRoundedClip:true);}
    internal async Task CaptureAsync(string directory,bool dark)
    {
        Directory.CreateDirectory(directory);glassResult=PanelGlass.Apply(this,dark,forceRoundedClip:true);
        await browser.CoreWebView2.CallDevToolsProtocolMethodAsync("Emulation.setEmulatedMedia",JsonSerializer.Serialize(new{features=new[]{new{name="prefers-color-scheme",value=dark?"dark":"light"}}}));
        await Task.Delay(250);
        string suffix=dark?"dark":"light";
        using(var stream=File.Create(Path.Combine(directory,"desktop-panel-content-"+suffix+".png")))await browser.CoreWebView2.CapturePreviewAsync(CoreWebView2CapturePreviewImageFormat.Png,stream);
        using(var bitmap=new Bitmap(Width,Height)){using(var graphics=Graphics.FromImage(bitmap))graphics.CopyFromScreen(Location,Point.Empty,Size);bitmap.Save(Path.Combine(directory,"desktop-panel-native-"+suffix+".png"),System.Drawing.Imaging.ImageFormat.Png);}
        File.WriteAllText(Path.Combine(directory,"desktop-panel-glass-"+suffix+".json"),JsonSerializer.Serialize(glassResult));
    }
    protected override void Dispose(bool disposing){if(disposing){SaveLayout();autoHideTimer.Stop();autoHideTimer.Dispose();}base.Dispose(disposing);}
    [DllImport("user32.dll")]private static extern bool ReleaseCapture();
    [DllImport("user32.dll")]private static extern IntPtr SendMessage(IntPtr window,int message,IntPtr wParam,IntPtr lParam);
    [DllImport("user32.dll")]private static extern IntPtr GetForegroundWindow();
    [DllImport("user32.dll")]private static extern uint GetWindowThreadProcessId(IntPtr window,out uint processId);
}
