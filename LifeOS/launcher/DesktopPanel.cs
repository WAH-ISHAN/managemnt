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
    private bool initialized;
    private PanelGlassResult glassResult;
    internal event Action<string>? OpenRouteRequested;
    internal bool IsPanelVisible => Visible;
    internal PanelGlassResult GlassResult=>glassResult;
    internal async Task<string> EvaluateAsync(string script){if(browser.CoreWebView2 is null)return "";return await browser.CoreWebView2.ExecuteScriptAsync(script);}

    internal DesktopPanelWindow(Uri home,CoreWebView2Environment environment)
    {
        this.home=home; this.environment=environment;
        FormBorderStyle=FormBorderStyle.None; ShowInTaskbar=false; TopMost=true;
        Text="LifeOS desktop panel";
        BackColor=Color.Black; Width=420;
        StartPosition=FormStartPosition.Manual;
        RepositionForCurrentScreen();
        Controls.Add(browser); KeyPreview=true;
        KeyDown+=(_,e)=>{if(e.KeyCode==Keys.Escape){e.Handled=true;Hide();}};
        FormClosing+=(_,e)=>{if(e.CloseReason==CloseReason.UserClosing){e.Cancel=true;Hide();}};
        VisibleChanged+=async(_,_)=>{if(Visible){browser.CoreWebView2?.Resume();autoHideGraceUntil=DateTime.UtcNow.AddSeconds(12);autoHideTimer.Start();}else{autoHideTimer.Stop();if(browser.CoreWebView2 is not null)try{await browser.CoreWebView2.TrySuspendAsync();}catch(InvalidOperationException){}}};
        SizeChanged+=(_,_)=>{if(glassResult.RoundedClipApplied)PanelGlass.UpdateRoundedClip(this);};
        autoHideTimer.Tick+=(_,_)=>{
            if(!Visible||!autoHideEnabled||DateTime.UtcNow<autoHideGraceUntil)return;
            GetWindowThreadProcessId(GetForegroundWindow(),out uint foregroundPid);
            if(foregroundPid!=(uint)Environment.ProcessId)Hide();
        };
        Load+=async (_,_)=>await InitializeAsync();
    }
    internal void RepositionForCurrentScreen(){var work=Screen.FromPoint(Cursor.Position).WorkingArea;Width=Math.Min(420,work.Width-36);Height=Math.Min(760,work.Height-36);Location=new Point(work.Right-Width-18,work.Top+18);}
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
            browser.CoreWebView2.NavigationCompleted+=async(_,_)=>{try{await browser.CoreWebView2.ExecuteScriptAsync("document.documentElement.dataset.nativeGlass="+(glassResult.NativeBackdropConfigured?"'true'":"'false'"));}catch(InvalidOperationException){}};
            browser.CoreWebView2.NavigationStarting+=(_,e)=>{if(!Uri.TryCreate(e.Uri,UriKind.Absolute,out var uri)||uri.GetLeftPart(UriPartial.Authority)!=home.GetLeftPart(UriPartial.Authority))e.Cancel=true;};
            browser.CoreWebView2.WebMessageReceived+=(_,e)=>{try{if(!Uri.TryCreate(e.Source,UriKind.Absolute,out var source)||source.GetLeftPart(UriPartial.Authority)!=home.GetLeftPart(UriPartial.Authority))return;using var message=JsonDocument.Parse(e.WebMessageAsJson);if(!message.RootElement.TryGetProperty("type",out var kind)||kind.ValueKind!=JsonValueKind.String)return;if(kind.GetString()=="lifeos-panel-close")Hide();else if(kind.GetString()=="lifeos-panel-autohide"&&message.RootElement.TryGetProperty("enabled",out var enabled))autoHideEnabled=enabled.ValueKind==JsonValueKind.True;else if(kind.GetString()=="lifeos-panel-open"&&message.RootElement.TryGetProperty("route",out var route)){string path=route.GetString()??"";if(path.StartsWith('/')&&!path.StartsWith("//")&&!path.Contains('\\')){OpenRouteRequested?.Invoke(path);Hide();}}}catch(JsonException){}};
            browser.CoreWebView2.Navigate(new Uri(home,"/desktop/panel").ToString());
        } catch(InvalidOperationException){Hide();}
    }
    protected override bool ShowWithoutActivation=>true;
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
    protected override void Dispose(bool disposing){if(disposing){autoHideTimer.Stop();autoHideTimer.Dispose();}base.Dispose(disposing);}
    [DllImport("user32.dll")]private static extern IntPtr GetForegroundWindow();
    [DllImport("user32.dll")]private static extern uint GetWindowThreadProcessId(IntPtr window,out uint processId);
}
