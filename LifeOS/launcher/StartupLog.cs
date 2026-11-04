internal sealed partial class LifeWindow
{
    private void LogStartup(string stage,Exception? error=null)
    {
        try {
            string data=Environment.GetEnvironmentVariable("LIFEOS_DATA_DIR") ?? Path.Combine(root,"data");
            Directory.CreateDirectory(data);
            string path=Path.Combine(data,"startup.log");
            if(File.Exists(path) && new FileInfo(path).Length>256*1024) File.Move(path,path+".previous",true);
            File.AppendAllText(path,DateTimeOffset.Now.ToString("O")+" "+stage+(error is null ? "" : Environment.NewLine+error)+Environment.NewLine);
        } catch(IOException) { } catch(UnauthorizedAccessException) { }
    }
}
