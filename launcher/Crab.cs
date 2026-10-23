using System;
using System.Diagnostics;
using System.IO;
using System.Windows.Forms;

internal static class Crab
{
    [STAThread]
    private static void Main()
    {
        try
        {
            string root = AppDomain.CurrentDomain.BaseDirectory;
            string script = Path.Combine(root, "Start-Crab.ps1");
            if (!File.Exists(script)) throw new FileNotFoundException("Crab is not installed. Run Install-Crab.ps1 from the release package.");
            var info = new ProcessStartInfo("powershell.exe", "-NoProfile -ExecutionPolicy Bypass -File \"" + script + "\"");
            info.UseShellExecute = false;
            info.CreateNoWindow = true;
            Process.Start(info);
        }
        catch (Exception error)
        {
            MessageBox.Show(error.Message, "Crab", MessageBoxButtons.OK, MessageBoxIcon.Error);
        }
    }
}
