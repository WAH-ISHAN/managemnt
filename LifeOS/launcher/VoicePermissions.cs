using Microsoft.Web.WebView2.Core;

internal sealed partial class LifeWindow
{
    private void RequestVoicePermission(object? sender, CoreWebView2PermissionRequestedEventArgs request)
    {
        request.SavesInProfile=false;
        request.State=CoreWebView2PermissionState.Deny;
        if(request.PermissionKind!=CoreWebView2PermissionKind.Microphone || !appReady || !Visible || WindowState==FormWindowState.Minimized)return;
        if(VoiceCapturePermission.Allows(home,web.Source?.AbsoluteUri,request.Uri,request.IsUserInitiated))request.State=CoreWebView2PermissionState.Allow;
    }
    private void StopVoiceCapture()
    {
        if(web.IsDisposed || web.CoreWebView2 is null)return;
        try { web.CoreWebView2.PostWebMessageAsJson("{\"type\":\"lifeos-voice-stop\"}"); } catch(InvalidOperationException) { }
    }
}

// The main application's local recording page is the only permitted capture surface.
internal static class VoiceCapturePermission
{
    internal static bool Allows(Uri? home,string? document,string? origin,bool userInitiated)
    {
        if(!userInitiated || home is null || !Uri.TryCreate(document,UriKind.Absolute,out var page) || !Uri.TryCreate(origin,UriKind.Absolute,out var requester))return false;
        if(home.Scheme!="http" || home.Host!="127.0.0.1" || page.AbsolutePath!="/intelligence")return false;
        string authority=home.GetLeftPart(UriPartial.Authority);
        return page.GetLeftPart(UriPartial.Authority)==authority && requester.GetLeftPart(UriPartial.Authority)==authority;
    }
}
