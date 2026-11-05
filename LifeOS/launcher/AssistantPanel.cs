using System.Runtime.InteropServices;
using System.Text.Json;
using Microsoft.Web.WebView2.Core;
using Microsoft.Web.WebView2.WinForms;

internal sealed class AssistantPanelWindow : Form
{
    private readonly WebView2 browser=new(){Dock=DockStyle.Fill,DefaultBackgroundColor=Color.Transparent};
    private readonly Uri home;
    private readonly CoreWebView2Environment environment;
    private readonly string profile;
    private bool initialized;
    private PanelGlassResult glass;
    private int headerHeight=80;
    internal event Action<string>? AiJobRequested;
    internal event Action<string>? OpenRouteRequested;
    internal event Action? UnlockRequested;
    internal string ProfileName=>browser.CoreWebView2?.Profile.ProfileName??"";
    internal PanelGlassResult GlassResult=>glass;
    internal bool ContentFillsClient=>browser.Bounds==ClientRectangle;
    internal int HeaderHeight=>LogicalToDeviceUnits(headerHeight);
    internal Task<string> EvaluateAsync(string expression)=>browser.CoreWebView2?.ExecuteScriptAsync(expression)??Task.FromResult("");
    internal async Task CaptureAsync(string path){if(browser.CoreWebView2 is null)return;using var stream=File.Create(path);await browser.CoreWebView2.CapturePreviewAsync(CoreWebView2CapturePreviewImageFormat.Png,stream);}
    internal AssistantPanelWindow(Uri home,CoreWebView2Environment environment,string profile)
    {
        this.home=home;this.environment=environment;this.profile=profile;
        Text="LifeOS AI";FormBorderStyle=FormBorderStyle.None;Padding=Padding.Empty;ShowInTaskbar=false;TopMost=true;
        StartPosition=FormStartPosition.Manual;MinimumSize=new Size(360,460);Size=new Size(450,720);BackColor=Color.FromArgb(246,249,254);
        var work=Screen.FromPoint(Cursor.Position).WorkingArea;Size=new Size(Math.Min(Width,work.Width),Math.Min(Height,work.Height-24));Location=new Point(work.Right-Width-18,work.Top+Math.Max(12,(work.Height-Height)/2));
        Controls.Add(browser);Load+=async(_,_)=>await InitializeAsync();
        FormClosing+=(_,e)=>{if(e.CloseReason==CloseReason.UserClosing){e.Cancel=true;Hide();}};
        VisibleChanged+=async(_,_)=>{if(browser.CoreWebView2 is null)return;try{if(Visible){browser.CoreWebView2.Resume();await browser.CoreWebView2.ExecuteScriptAsync("window.dispatchEvent(new Event('lifeos:assistant-show'))");}else{await browser.CoreWebView2.ExecuteScriptAsync("window.dispatchEvent(new Event('lifeos:assistant-hide'))");await browser.CoreWebView2.TrySuspendAsync();}}catch(InvalidOperationException){}};
        SizeChanged+=(_,_)=>{if(IsHandleCreated&&glass.RoundedClipApplied)PanelGlass.UpdateRoundedClip(this);};
        DpiChanged+=(_,_)=>{if(glass.RoundedClipApplied)PanelGlass.UpdateRoundedClip(this);};
    }
    private async Task InitializeAsync()
    {
        if(initialized||IsDisposed)return;initialized=true;
        try{
            await browser.EnsureCoreWebView2Async(environment,LifeWindow.BrowserOptions(environment,profile));
            var core=browser.CoreWebView2;core.Settings.AreDevToolsEnabled=false;core.Settings.IsStatusBarEnabled=false;core.Settings.AreDefaultContextMenusEnabled=false;
            // The native region supplies the outer curve. Mark the document as
            // soon as its root exists so CSS does not cut a second curve into it.
            await core.AddScriptToExecuteOnDocumentCreatedAsync("(()=>{const clip="+(glass.RoundedClipApplied?"'true'":"'false'")+";const apply=()=>{if(!document.documentElement)return false;document.documentElement.dataset.nativeClip=clip;return true;};if(!apply()){const observer=new MutationObserver(()=>{if(apply())observer.disconnect()});observer.observe(document,{childList:true})}})()");
            core.NavigationCompleted+=async(_,_)=>{try{await core.ExecuteScriptAsync("document.documentElement.dataset.nativeClip="+(glass.RoundedClipApplied?"'true'":"'false'"));}catch(InvalidOperationException){}};
            core.NewWindowRequested+=(_,e)=>e.Handled=true;core.PermissionRequested+=(_,e)=>e.State=CoreWebView2PermissionState.Deny;
            core.NavigationStarting+=(_,e)=>{
                if(!Uri.TryCreate(e.Uri,UriKind.Absolute,out var uri)||uri.GetLeftPart(UriPartial.Authority)!=home.GetLeftPart(UriPartial.Authority)){e.Cancel=true;return;}
                if(uri.AbsolutePath=="/unlock"){e.Cancel=true;Hide();UnlockRequested?.Invoke();return;}
                if(!uri.AbsolutePath.StartsWith("/assistant"))e.Cancel=true;
            };
            core.WebMessageReceived+=(_,e)=>{
                if(!Uri.TryCreate(e.Source,UriKind.Absolute,out var source)||source.GetLeftPart(UriPartial.Authority)!=home.GetLeftPart(UriPartial.Authority)||source.AbsolutePath!="/assistant")return;
                try{using var document=JsonDocument.Parse(e.WebMessageAsJson);var value=document.RootElement;string type=value.GetProperty("type").GetString()??"";
                    if(type=="lifeos-ai-job"){AiJobRequested?.Invoke(value.GetProperty("id").GetString()??"");return;}
                    if(type=="lifeos-assistant-locked"){Hide();UnlockRequested?.Invoke();return;}
                    if(type=="lifeos-assistant-layout"&&value.TryGetProperty("headerHeight",out var height)&&height.ValueKind==JsonValueKind.Number&&height.TryGetInt32(out int pixels)){headerHeight=Math.Clamp(pixels,0,ClientSize.Height);return;}
                    if(type=="lifeos-assistant-theme"){glass=PanelGlass.Apply(this,value.GetProperty("dark").GetBoolean(),true);return;}
                    if(type=="lifeos-assistant-open"){string path=value.GetProperty("route").GetString()??"";if(path.StartsWith('/')&&!path.StartsWith("//")&&!path.Contains('\\'))OpenRouteRequested?.Invoke(path);return;}
                    if(type!="lifeos-assistant-window")return;
                    string action=value.GetProperty("action").GetString()??"";if(action=="hide"){Hide();return;}
                    if(Control.MouseButtons!=MouseButtons.Left||action is not("drag" or "resize"))return;
                    BeginInvoke(new Action(()=>{if(IsDisposed||Control.MouseButtons!=MouseButtons.Left)return;ReleaseCapture();SendMessage(Handle,0x0112,(IntPtr)(action=="drag"?0xF012:0xF008),IntPtr.Zero);}));
                }catch(JsonException){}catch(KeyNotFoundException){}catch(InvalidOperationException){}
            };
            core.Navigate(new Uri(home,"/assistant").ToString());
        }catch(InvalidOperationException){Hide();}
    }
    internal void Reveal(Form? companion=null,Rectangle? protectedHeader=null)=>FloatingPanelLayout.Reveal(this,companion,protectedHeader);
    protected override CreateParams CreateParams{get{var value=base.CreateParams;value.Style|=0x00040000;return value;}}
    protected override void OnHandleCreated(EventArgs e){base.OnHandleCreated(e);glass=PanelGlass.Apply(this,forceRoundedClip:true);}
    protected override void WndProc(ref Message message){if(message.Msg==PanelGlass.NcCalcSizeMessage){message.Result=IntPtr.Zero;return;}base.WndProc(ref message);}
    [DllImport("user32.dll")]private static extern bool ReleaseCapture();
    [DllImport("user32.dll")]private static extern IntPtr SendMessage(IntPtr handle,int message,IntPtr wParam,IntPtr lParam);
}

internal sealed partial class LifeWindow
{
    private AssistantPanelWindow? assistantPanel;
    private bool pendingAssistant;
    private void ShowAssistantPanel()
    {
        if(IsDisposed)return;if(!appReady||home is null||webEnvironment is null||switchingBrowser){pendingAssistant=true;return;}
        pendingAssistant=false;
        if(assistantPanel is null||assistantPanel.IsDisposed){assistantPanel=new AssistantPanelWindow(home,webEnvironment,browserProfile);assistantPanel.AiJobRequested+=StartAiJob;assistantPanel.OpenRouteRequested+=path=>{ShowMainWindow();GoRoute(path);};assistantPanel.UnlockRequested+=()=>{ShowMainWindow();GoRoute("/unlock");};}
        assistantPanel.Reveal(desktopPanel,VisibleChromeBounds);
    }
    private void ToggleAssistantPanel(){if(assistantPanel?.Visible==true)assistantPanel.Hide();else ShowAssistantPanel();}
}
