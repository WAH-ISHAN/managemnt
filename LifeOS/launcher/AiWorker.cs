using System.Diagnostics;
using System.Text.Json;

internal sealed partial class LifeWindow
{
    private readonly Dictionary<string,Process> aiWorkers=new();
    private static string? FindNativeTool(string name)
    {
        var candidates=new List<string>();
        if(name=="opencode")candidates.Add(Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData),"npm","node_modules","opencode-ai","bin","opencode.exe"));
        foreach(string directory in (Environment.GetEnvironmentVariable("PATH")??"").Split(Path.PathSeparator)){
            if(!string.IsNullOrWhiteSpace(directory))candidates.Add(Path.Combine(directory.Trim('"'),name+".exe"));
        }
        return candidates.FirstOrDefault(File.Exists);
    }
    private void StartAiJob(string id)
    {
        if(id.Length!=32 || !id.All(c=>c is >= '0' and <= '9' or >= 'a' and <= 'f') || IsDisposed)return;
        foreach(var key in aiWorkers.Keys.ToArray())if(aiWorkers[key].HasExited){aiWorkers[key].Dispose();aiWorkers.Remove(key);}
        if(aiWorkers.Count>0)return;
        string data=Environment.GetEnvironmentVariable("LIFEOS_DATA_DIR")??Path.Combine(root,"data");
        if(browserProfile!="default")data=Path.Combine(data,"profiles",browserProfile);
        string file=Path.Combine(data,"ai","jobs",id+".json");
        if(!File.Exists(file)||new FileInfo(file).Length>40000)return;
        try {
            using var doc=JsonDocument.Parse(File.ReadAllText(file));
            if(doc.RootElement.GetProperty("status").GetString()!="queued")return;
            string? python=FindNativeTool("python"),executable=FindNativeTool("opencode");
            if(python is null || executable is null) {
                var state=JsonSerializer.Deserialize<Dictionary<string,object>>(File.ReadAllText(file))!;
                state["status"]="failed";state["error"]="The installed AI runtime could not be found. Check Python and the AI runtime installation.";
                File.WriteAllText(file,JsonSerializer.Serialize(state));return;
            }
            var start=new ProcessStartInfo(python){UseShellExecute=false,CreateNoWindow=true,WorkingDirectory=Path.Combine(data,"ai"),RedirectStandardError=true,RedirectStandardOutput=true};
            foreach(string argument in new[]{Path.Combine(root,"scripts","opencode_worker.py"),"--job",file,"--executable",executable})start.ArgumentList.Add(argument);
            var process=Process.Start(start)??throw new InvalidOperationException("AI worker did not start.");
            process.BeginErrorReadLine();process.BeginOutputReadLine();
            if(!process.HasExited)job.Attach(process);
            aiWorkers[id]=process;
        } catch(Exception error) when(error is IOException or JsonException or InvalidOperationException or System.ComponentModel.Win32Exception) { LogStartup("AI worker failed to start",error); }
    }
}
