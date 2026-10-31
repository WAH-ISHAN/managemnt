using System.Text.Json;
using System.Text.RegularExpressions;

internal sealed record UpdateAsset(string Url,string Sha256,long Size);
internal sealed record UpdateRelease(string Version,long BuildNumber,string Commit,UpdateAsset Package)
{
    internal const string Repository="WAH-ISHAN/managemnt";
    internal const string Feed="https://github.com/"+Repository+"/releases/latest/download/lifeos-update.json";
    internal const long MaxPackageBytes=512L*1024*1024;

    internal static (Version Version,long Build) Identity(JsonElement value)
    {
        if(value.GetProperty("schemaVersion").GetInt32()!=1 || value.GetProperty("product").GetString()!="LifeOS" ||
           value.GetProperty("repository").GetString()!=Repository || value.GetProperty("channel").GetString()!="stable")
            throw new InvalidDataException("This update is not a stable LifeOS release.");
        string version=value.GetProperty("version").GetString() ?? "";
        if(!Regex.IsMatch(version,@"^\d+\.\d+\.\d+(?:\.\d+)?$") || !System.Version.TryParse(version,out var parsed))
            throw new InvalidDataException("Invalid update version.");
        long build=value.GetProperty("buildNumber").GetInt64();
        if(build<0) throw new InvalidDataException("Invalid update build.");
        return (Normalize(parsed),build);
    }

    internal static UpdateRelease Parse(string json)
    {
        if(json.Length>65536) throw new InvalidDataException("Update information is too large.");
        using var document=JsonDocument.Parse(json);
        var value=document.RootElement;
        var identity=Identity(value);
        string commit=value.GetProperty("commit").GetString() ?? "";
        if(!Regex.IsMatch(commit,@"^[a-fA-F0-9]{40}$")) throw new InvalidDataException("Invalid update source revision.");
        if(!DateTimeOffset.TryParse(value.GetProperty("publishedAt").GetString(),out _)) throw new InvalidDataException("Invalid update date.");
        var package=value.GetProperty("package");
        string url=package.GetProperty("url").GetString() ?? "";
        string hash=package.GetProperty("sha256").GetString() ?? "";
        long size=package.GetProperty("size").GetInt64();
        if(!Uri.TryCreate(url,UriKind.Absolute,out var uri) || uri.Scheme!="https" || uri.Host!="github.com" || !uri.IsDefaultPort ||
           uri.UserInfo.Length!=0 || uri.Query.Length!=0 || uri.Fragment.Length!=0 ||
           !Regex.IsMatch(uri.AbsolutePath,@"^/WAH-ISHAN/managemnt/releases/download/lifeos-[A-Za-z0-9._-]+/LifeOS-update\.zip$"))
            throw new InvalidDataException("Update download is outside the LifeOS release repository.");
        if(!Regex.IsMatch(hash,@"^[a-fA-F0-9]{64}$") || size<=0 || size>MaxPackageBytes)
            throw new InvalidDataException("Invalid update package integrity information.");
        return new(value.GetProperty("version").GetString()!,identity.Build,commit,new(url,hash.ToLowerInvariant(),size));
    }

    internal bool IsNewerThan(Version installed,long installedBuild)
    {
        int comparison=Normalize(System.Version.Parse(Version)).CompareTo(Normalize(installed));
        return comparison>0 || comparison==0 && BuildNumber>installedBuild;
    }

    private static Version Normalize(Version version)=>new(version.Major,version.Minor,Math.Max(0,version.Build),Math.Max(0,version.Revision));
}
