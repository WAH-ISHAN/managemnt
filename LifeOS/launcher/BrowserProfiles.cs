using Microsoft.Web.WebView2.Core;
using Microsoft.Web.WebView2.WinForms;

internal sealed partial class LifeWindow
{
    private string browserProfile="default";
    private bool switchingBrowser;
    private sealed record SessionCookie(string Name,string Value,string Domain,string Path,bool HttpOnly,bool Secure,CoreWebView2CookieSameSiteKind SameSite);
    private readonly Dictionary<string,List<SessionCookie>> profileSessionCookies=new();
    private void RestoreExternalSessionCookies(CoreWebView2CookieManager manager)
    {
        if(!profileSessionCookies.TryGetValue(browserProfile,out var stored))return;
        foreach(var saved in stored) {
            var cookie=manager.CreateCookie(saved.Name,saved.Value,saved.Domain,saved.Path);
            cookie.IsHttpOnly=saved.HttpOnly;cookie.IsSecure=saved.Secure;cookie.SameSite=saved.SameSite;
            manager.AddOrUpdateCookie(cookie);
        }
    }
    private static string ValidBrowserProfile(string? id) => id=="default" || (id is {Length:32} && id.All(c=>c is >= '0' and <= '9' or >= 'a' and <= 'f')) ? id : throw new InvalidOperationException("Invalid workspace browser profile.");
    internal static CoreWebView2ControllerOptions BrowserOptions(CoreWebView2Environment env,string id)
    {
        var options=env.CreateCoreWebView2ControllerOptions();
        options.ProfileName=id=="default" ? "Default" : "LifeOS_"+ValidBrowserProfile(id);
        return options;
    }
    private async Task<bool> SwitchBrowserIfNeededAsync()
    {
        if(switchingBrowser || home is null || webEnvironment is null || web.CoreWebView2 is null) return switchingBrowser;
        var cookies=await web.CoreWebView2.CookieManager.GetCookiesAsync(home.ToString());
        string id=ValidBrowserProfile(cookies.FirstOrDefault(c=>c.Name=="lifeos_profile")?.Value ?? "default");
        if(id==browserProfile) return false;
        switchingBrowser=true;
        CancelAiAccountSignIn();
        try {
            appReady=false; web.Visible=false;
            PauseNibmCalendar(); nibmWindow?.Shutdown(); nibmWindow=null;
            foreach(var request in nibmLocalRequests.Values) request.TrySetCanceled();
            nibmLocalRequests.Clear();
            desktopPanel?.Dispose(); desktopPanel=null;
            assistantPanel?.Dispose();assistantPanel=null;
            delivered.Clear(); panelAlertIds.Clear(); lastAutoPanelDate=DateOnly.MinValue;
            // Carry only the freshly authenticated target PHP session. External
            // cookies, storage, caches and sign-ins stay in their named profile.
            string session=id=="default" ? "lifeos" : "lifeos_"+id;
            var transfer=cookies.Where(c=>c.Name==session).ToList();
            profileSessionCookies[browserProfile]=(await web.CoreWebView2.CookieManager.GetCookiesAsync(null))
                .Where(c=>c.IsSession && c.Domain.TrimStart('.')!="127.0.0.1")
                .Select(c=>new SessionCookie(c.Name,c.Value,c.Domain,c.Path,c.IsHttpOnly,c.IsSecure,c.SameSite)).ToList();
            var old=web;
            web=new WebView2 { Dock=DockStyle.Fill,Visible=false };
            Controls.Add(web); web.BringToFront(); old.Dispose();
            browserProfile=id;
            await InitializeMainBrowserAsync(webEnvironment,transfer);
            return true;
        } catch(Exception error) {
            LogStartup("profile-switch",error);
            allowExitForMaintenance=true;
            MessageBox.Show(this,NativeLocale.Text("The workspace browser could not reopen. Restart LifeOS. Your records are preserved."),"LifeOS",MessageBoxButtons.OK,MessageBoxIcon.Error);
            Close(); return true;
        } finally { switchingBrowser=false;if(platformLockPending)QueuePlatformLock(); }
    }
}
