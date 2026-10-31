using System.Text.Json.Nodes;

// Parse the actual package builder output with the desktop app's parser.
string json = File.ReadAllText(args[0]);
var release = UpdateRelease.Parse(json);
if (!release.IsNewerThan(new Version(1, 7, 2), 2))
    throw new Exception("Generated release should upgrade the previous installation.");
if (release.IsNewerThan(new Version(release.Version), release.BuildNumber))
    throw new Exception("Installed release must not update itself.");
var manifest = JsonNode.Parse(json)!;
manifest["package"]!["url"] = release.Package.Url.Replace("github.com/", "example.com/");
try {
    UpdateRelease.Parse(manifest.ToJsonString());
    throw new Exception("A foreign download URL was accepted.");
} catch (InvalidDataException) { }
Console.WriteLine("Generated manifest accepted; installed identity and foreign URL checks passed.");
