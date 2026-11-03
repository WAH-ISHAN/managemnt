using Microsoft.Win32;

internal sealed partial class LifeWindow
{
    private bool platformLockPending;
    private void InitializeSessionLifecycle()
    {
        if(diagnostic)return;
        SystemEvents.SessionSwitch+=SessionChanged;
        SystemEvents.PowerModeChanged+=PowerChanged;
    }
    private void DisposeSessionLifecycle()
    {
        SystemEvents.SessionSwitch-=SessionChanged;
        SystemEvents.PowerModeChanged-=PowerChanged;
    }
    private void SessionChanged(object sender,SessionSwitchEventArgs e)
    {
        if(e.Reason is SessionSwitchReason.SessionLock or SessionSwitchReason.SessionLogoff) QueuePlatformLock();
    }
    private void PowerChanged(object sender,PowerModeChangedEventArgs e)
    {
        if(e.Mode is PowerModes.Suspend or PowerModes.Resume) QueuePlatformLock();
    }
    private void ClosePrivateWindows()
    {
        PauseNibmCalendar();nibmWindow?.Shutdown();nibmWindow=null;
        desktopPanel?.Dispose();desktopPanel=null;
        assistantPanel?.Dispose();assistantPanel=null;
        foreach(var request in nibmLocalRequests.Values)request.TrySetCanceled();
        nibmLocalRequests.Clear();
    }
    private void QueuePlatformLock()
    {
        if(IsDisposed || !IsHandleCreated || lifetime.IsCancellationRequested)return;
        try { BeginInvoke(new Action(()=>{
            if(IsDisposed || web.CoreWebView2 is null)return;
            if(switchingBrowser){platformLockPending=true;return;}
            platformLockPending=false;
            ClosePrivateWindows();
            GoRoute("/idle-lock");
        })); } catch(InvalidOperationException) { }
    }
}
