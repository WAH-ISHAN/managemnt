internal sealed partial class LifeWindow
{
    private bool pendingQuickCapture;
    // Called by the message-only hotkey owner, including while hidden in the tray.
    // The server applies the selected profile's PIN and child policy as usual.
    private void ShowQuickCapture()
    {
        if(IsDisposed)return;
        ShowMainWindow();
        if(!appReady || web.CoreWebView2 is null) { pendingQuickCapture=true; return; }
        pendingQuickCapture=false;
        GoRoute("/planner/capture");
    }
}
