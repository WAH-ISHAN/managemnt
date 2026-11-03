using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using Windows.Security.Credentials.UI;
internal sealed partial class LifeWindow {
    private readonly string helloBridgeKey=Convert.ToHexString(RandomNumberGenerator.GetBytes(32));
    private bool helloBusy;
    private async Task HandlePlatformHello(JsonElement message) {
        if(helloBusy||home is null||web.Source?.AbsolutePath!="/unlock")return;
        if(!message.TryGetProperty("challenge",out var challenge)||!message.TryGetProperty("profile",out var profile))return;
        string nonce=challenge.GetString()??"",id=profile.GetString()??"";if(nonce.Length!=64||id.Length>40)return;helloBusy=true;
        try {
            if(!OperatingSystem.IsWindowsVersionAtLeast(10,0,22000))throw new PlatformNotSupportedException("Use your LifeOS PIN on this Windows version.");
            if(await UserConsentVerifier.CheckAvailabilityAsync()!=UserConsentVerifierAvailability.Available)throw new InvalidOperationException("Windows Hello is unavailable. Use your LifeOS PIN.");
            if(await UserConsentVerifierInterop.RequestVerificationForWindowAsync(Handle,"Unlock LifeOS")!=UserConsentVerificationResult.Verified)throw new InvalidOperationException("Windows verification was cancelled or failed. Use your PIN.");
            string proof=Convert.ToHexString(HMACSHA256.HashData(Encoding.UTF8.GetBytes(helloBridgeKey),Encoding.UTF8.GetBytes(id+":"+nonce))).ToLowerInvariant();
            string form=JsonSerializer.Serialize(new {proof,challenge=nonce});
            await web.CoreWebView2.ExecuteScriptAsync("(()=>{const d="+form+";const f=document.createElement('form');f.method='post';f.action='/unlock/hello';d._csrf=document.querySelector('input[name=_csrf]').value;for(const [n,v] of Object.entries(d)){const i=document.createElement('input');i.type='hidden';i.name=n;i.value=v;f.append(i)}document.body.append(f);f.submit()})()");
        }catch(Exception error){await web.CoreWebView2.ExecuteScriptAsync("(()=>{const s=document.querySelector('[data-hello-status]');if(s)s.textContent="+JsonSerializer.Serialize(error.Message)+"})()");}finally{helloBusy=false;}
    }
}
