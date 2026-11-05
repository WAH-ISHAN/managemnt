using System.Diagnostics;
using System.Reflection;
using System.Runtime.InteropServices;
using System.Text.Json;

internal static class ResidentLifecycle
{
    private static readonly List<string> checks = new();
    private static Type windowType = null!;
    private static readonly BindingFlags privateInstance = BindingFlags.Instance | BindingFlags.NonPublic;
    [STAThread]
    private static int Main(string[] args)
    {
        if (args[0] == "--probe") return ProbeInstalled(int.Parse(args[1]), Path.GetFullPath(args[2]));
        string data = Path.GetFullPath(args[0]);
        windowType = (args.Length > 1 ? Assembly.LoadFrom(Path.GetFullPath(args[1])) : Assembly.Load("LifeOS")).GetType("LifeWindow", true)!;
        var microphonePolicy=windowType.Assembly.GetType("VoiceCapturePermission",true)!.GetMethod("Allows",BindingFlags.Static|BindingFlags.NonPublic)!;
        bool Microphone(string page,string origin,bool gesture=true)=>(bool)microphonePolicy.Invoke(null,new object?[]{new Uri("http://127.0.0.1:17422"),page,origin,gesture})!;
        Check(Microphone("http://127.0.0.1:17422/intelligence","http://127.0.0.1:17422"),"Microphone permitted for a clicked local recording action");
        Check(!Microphone("http://127.0.0.1:17422/intelligence","http://127.0.0.1:17422",false),"Microphone refused without a user gesture");
        Check(!Microphone("http://127.0.0.1:17422/unlock","http://127.0.0.1:17422"),"Microphone refused on unlock page");
        Check(!Microphone("http://127.0.0.1:17422/assistant","http://127.0.0.1:17422"),"Microphone refused in online conversation");
        Check(!Microphone("https://example.com/intelligence","http://127.0.0.1:17422"),"Microphone refused for an external document");
        Check(!Microphone("http://127.0.0.1:17422/intelligence","https://example.com"),"Microphone refused for an external requester");
        Check(!Microphone("http://127.0.0.1:17422/intelligence","http://127.0.0.1:17423"),"Microphone refused for a different local server");
        Check(!Microphone("invalid","null"),"Microphone refused for invalid origins");
        Directory.CreateDirectory(data);
        Environment.SetEnvironmentVariable("LIFEOS_DATA_DIR", data);
        foreach (string mode in new[] { "LIFEOS_SMOKE_TEST", "LIFEOS_PROFILE_TEST", "LIFEOS_BROWSER_TEST", "LIFEOS_ACTIVATION_TEST" })
            Environment.SetEnvironmentVariable(mode, null);
        Application.EnableVisualStyles();
        Application.SetCompatibleTextRenderingDefault(false);
        using var window = (Form)Activator.CreateInstance(windowType, new object?[] { null, null })!;
        // Exercise the production window and residency handlers without opening
        // any personal database, starting PHP/AI, or claiming global shortcuts.
        window.Load -= (EventHandler)windowType.GetMethod("Start", privateInstance)!.CreateDelegate(typeof(EventHandler), window);
        windowType.GetField("startupPageReady", privateInstance)!.SetValue(window, true);
        try
        {
            window.Show();
            Pump(1800);
            nint originalHandle = window.Handle;
            Check(IsWindowVisible(originalHandle), "Main window starts visible");
            using var portal = (Form)Activator.CreateInstance(windowType.Assembly.GetType("NibmCalendarWindow", true)!, true)!;
            windowType.GetField("nibmWindow", privateInstance)!.SetValue(window, portal);
            for (int cycle = 0; cycle < 3; cycle++)
            {
                if (!portal.Visible) portal.Show(window);
                if (cycle == 1) window.WindowState = FormWindowState.Minimized;
                if (cycle == 2) window.WindowState = FormWindowState.Maximized;
                Pump(100);
                SendMessage(window.Handle, 0x0112, 0xF060, 0); // Native system-menu / Alt+F4 close.
                Pump(250);
                Check(!window.IsDisposed && !window.Visible && !IsWindowVisible(window.Handle), $"Close hides native window ({cycle})");
                Check(!portal.Visible && !portal.IsDisposed, $"Close hides and preserves the university window ({cycle})");
                Check((GetWindowLongPtr(window.Handle, -20).ToInt64() & 0x40080) == 0x80, $"Closed window has no taskbar style ({cycle})");
                Check(!HasVisibleAppWindow(), $"No visible application window remains ({cycle})");
                Check(window.Handle == originalHandle, $"Close preserves native window handle ({cycle})");
                Invoke(window, "ShowMainWindow");
                Pump(250);
                Check(window.Visible && IsWindowVisible(window.Handle) && (GetWindowLongPtr(window.Handle, -20).ToInt64() & 0x40080) == 0x40000, $"Tray reopen restores native taskbar window ({cycle})");
                Check(window.WindowState != FormWindowState.Minimized, $"Reopen restores minimized window ({cycle})");
                Check(window.Handle == originalHandle, $"Reopen preserves native window handle ({cycle})");
            }
            using (var command = JsonDocument.Parse("{\"action\":\"close\"}"))
                Invoke(window, "HandleWindowMessage", command.RootElement);
            Pump(250);
            Check(!window.Visible && !HasVisibleAppWindow(), "Glass close control hides native window");
            Invoke(window, "ShowMainWindow");
            Pump(150);
            var menu = (ContextMenuStrip)windowType.GetField("trayMenu", privateInstance)!.GetValue(window)!;
            menu.Items[menu.Items.Count - 1].PerformClick();
            Pump(150);
            Check(window.IsDisposed, "Exit LifeOS disposes the main window");
            Check(portal.IsDisposed, "Exit LifeOS disposes the university window");
            Check(!HasVisibleAppWindow(), "Exit leaves no visible application window");
            Console.WriteLine($"{checks.Count} native residency checks passed.");
            File.WriteAllText(Path.Combine(data, "result.json"), JsonSerializer.Serialize(new { success = true, checks }));
            return 0;
        }
        catch (Exception error)
        {
            File.WriteAllText(Path.Combine(data, "result.json"), JsonSerializer.Serialize(new { success = false, checks, error = error.ToString() }));
            Console.Error.WriteLine(error);
            return 1;
        }
        finally
        {
            if (!window.IsDisposed)
            {
                windowType.GetField("allowExitForMaintenance", privateInstance)!.SetValue(window, true);
                window.Close();
            }
        }
    }
    private static void Invoke(Form window, string method, params object[] args) => windowType.GetMethod(method, privateInstance)!.Invoke(window, args);
    private static void Check(bool condition, string label)
    {
        if (!condition) throw new InvalidOperationException(label);
        checks.Add(label);
    }
    private static void Pump(int milliseconds)
    {
        var timer = Stopwatch.StartNew();
        while (timer.ElapsedMilliseconds < milliseconds) { Application.DoEvents(); Thread.Sleep(10); }
    }
    private static int ProbeInstalled(int processId, string evidence)
    {
        Directory.CreateDirectory(evidence);
        try
        {
            using var process = Process.GetProcessById(processId);
            nint main = 0;
            for (int i = 0; i < 200 && main == 0; i++) { process.Refresh(); main = process.MainWindowHandle; Thread.Sleep(100); }
            Check(main != 0 && IsWindowVisible(main), "Installed main window opens");
            SendMessage(main, 0x0112, 0xF060, 0);
            Thread.Sleep(500);
            Check(!process.HasExited && !IsWindowVisible(main), "Installed Close hides the main window and keeps shortcuts available");
            Check(!HasVisibleAppWindow(processId), "Installed Close leaves no application taskbar window");
            Check((GetWindowLongPtr(main, -20).ToInt64() & 0x40080) == 0x80, "Installed hidden window uses tool-window style");
            using (var activation = Process.Start(new ProcessStartInfo(process.MainModule!.FileName!) { UseShellExecute = false, CreateNoWindow = true }))
                activation!.WaitForExit(5000);
            for (int i = 0; i < 100 && !IsWindowVisible(main); i++) Thread.Sleep(50);
            Check(IsWindowVisible(main), "Installed second launch reopens the same native window");
            Check((GetWindowLongPtr(main, -20).ToInt64() & 0x40080) == 0x40000, "Installed reopen restores taskbar style");
            SendMessage(main, 0x0112, 0xF060, 0);
            Thread.Sleep(500);
            Check(!HasVisibleAppWindow(processId), "Installed window hides again after reopening");
            File.WriteAllText(Path.Combine(evidence, "installed-result.json"), JsonSerializer.Serialize(new { success = true, processId, checks }));
            Console.WriteLine($"{checks.Count} installed native residency checks passed.");
            return 0;
        }
        catch (Exception error)
        {
            File.WriteAllText(Path.Combine(evidence, "installed-result.json"), JsonSerializer.Serialize(new { success = false, processId, checks, error = error.ToString() }));
            Console.Error.WriteLine(error);
            return 1;
        }
    }
    private static bool HasVisibleAppWindow(int? targetProcess = null)
    {
        bool found = false;
        EnumWindows((handle, _) => {
            GetWindowThreadProcessId(handle, out uint pid);
            long style = GetWindowLongPtr(handle, -20).ToInt64();
            if (pid == (targetProcess ?? Environment.ProcessId) && IsWindowVisible(handle) && (style & 0x80) == 0 && (GetWindow(handle, 4) == 0 || (style & 0x40000) != 0)) found = true;
            return true;
        }, 0);
        return found;
    }
    private delegate bool EnumWindow(nint handle, nint value);
    [DllImport("user32.dll")] private static extern bool EnumWindows(EnumWindow callback, nint value);
    [DllImport("user32.dll")] private static extern bool IsWindowVisible(nint handle);
    [DllImport("user32.dll")] private static extern uint GetWindowThreadProcessId(nint handle, out uint processId);
    [DllImport("user32.dll")] private static extern nint GetWindow(nint handle, uint command);
    [DllImport("user32.dll", EntryPoint = "GetWindowLongPtrW")] private static extern nint GetWindowLongPtr(nint handle, int index);
    [DllImport("user32.dll")] private static extern nint SendMessage(nint handle, uint message, nint wParam, nint lParam);
}
