using System;
using System.Diagnostics;
using System.IO;
using System.IO.Compression;
using System.Reflection;
using System.Windows.Forms;

internal static class CrabSetup
{
    [STAThread]
    private static void Main()
    {
        string folder = Path.Combine(Path.GetTempPath(), "CrabSetup-" + Guid.NewGuid().ToString("N"));
        try
        {
            Directory.CreateDirectory(folder);
            string archive = Path.Combine(folder, "crab-windows.zip");
            using (Stream resource = Assembly.GetExecutingAssembly().GetManifestResourceStream("crab-package"))
            using (FileStream file = File.Create(archive))
            {
                if (resource == null) throw new InvalidOperationException("Installation package is missing.");
                resource.CopyTo(file);
            }
            string package = Path.Combine(folder, "package");
            ZipFile.ExtractToDirectory(archive, package);
            string installer = Path.Combine(package, "Install-Crab.ps1");
            var info = new ProcessStartInfo("powershell.exe", "-NoProfile -ExecutionPolicy Bypass -File \"" + installer + "\"");
            info.UseShellExecute = false;
            info.CreateNoWindow = true;
            info.RedirectStandardError = true;
            info.RedirectStandardOutput = true;
            using (Process process = Process.Start(info))
            {
                string output = process.StandardOutput.ReadToEnd();
                string error = process.StandardError.ReadToEnd();
                process.WaitForExit();
                if (process.ExitCode != 0) throw new InvalidOperationException(error.Length > 0 ? error : output);
            }
            MessageBox.Show("Crab is installed. Open it from the Start menu.", "Crab", MessageBoxButtons.OK, MessageBoxIcon.Information);
        }
        catch (Exception error)
        {
            MessageBox.Show(error.Message, "Crab setup", MessageBoxButtons.OK, MessageBoxIcon.Error);
        }
        finally
        {
            try { Directory.Delete(folder, true); } catch { }
        }
    }
}
