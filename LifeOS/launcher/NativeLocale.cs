using System.Text.Json;

internal static class NativeLocale
{
    private static string language="en";
    private static Dictionary<string,string> translations=new();
    internal static void Select(string? value)
    {
        string selected=value is "si" or "ta" ? value : "en";
        if(selected==language)return;
        language=selected;translations=new();
        if(selected=="en")return;
        try {
            var file=Path.Combine(AppContext.BaseDirectory,"public","assets","locale-"+selected+".js");
            string source=File.ReadAllText(file);
            const string prefix="window.lifeosLocale=";
            if(source.StartsWith(prefix,StringComparison.Ordinal))
                translations=JsonSerializer.Deserialize<Dictionary<string,string>>(source[prefix.Length..].Trim().TrimEnd(';'))??new();
        } catch(IOException) { } catch(UnauthorizedAccessException) { } catch(JsonException) { }
    }
    internal static string Text(string key)=>translations.TryGetValue(key,out var text)?text:key;
}

internal sealed partial class LifeWindow
{
    private async Task RefreshNativeLocaleAsync()
    {
        if(web.CoreWebView2 is null)return;
        string? selected=JsonSerializer.Deserialize<string>(await web.CoreWebView2.ExecuteScriptAsync("document.documentElement.lang"));
        NativeLocale.Select(selected);
        Text=NativeLocale.Text("LifeOS — Your life. Your freedom.");
        if(desktopPanel is not null)desktopPanel.Text=NativeLocale.Text("LifeOS desktop panel");
        if(nibmWindow is not null)nibmWindow.Text=NativeLocale.Text("NIBM calendar — Sign in securely");
        if(trayMenu is not null)foreach(ToolStripItem item in trayMenu.Items){
            if(item is ToolStripSeparator)continue;
            string key=item.Tag as string ?? item.Text ?? "";item.Tag=key;item.Text=NativeLocale.Text(key);
        }
    }
}
