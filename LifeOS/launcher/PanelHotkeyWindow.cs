using System.ComponentModel;
using System.Runtime.InteropServices;

// A message-only window keeps global shortcuts alive when the main Form hides
// or recreates its chrome/taskbar handle. Create and dispose on the UI thread.
internal sealed class PanelHotkeyWindow : NativeWindow, IDisposable
{
    private const int HotkeyMessage=0x0312;
    private const int WindowsId=0x1A63, AlternateWindowsId=0x1A64, FallbackId=0x1A62;
    private const uint ModControl=0x0002, ModShift=0x0004, ModWindows=0x0008, ModNoRepeat=0x4000;
    private readonly Action toggle;
    private bool disposed;

    internal bool WindowsRegistered { get; private set; }
    internal int WindowsError { get; private set; }
    internal bool AlternateWindowsRegistered { get; private set; }
    internal int AlternateWindowsError { get; private set; }
    internal bool AnyWindowsRegistered => WindowsRegistered || AlternateWindowsRegistered;
    internal bool FallbackRegistered { get; private set; }
    internal int FallbackError { get; private set; }
    internal IntPtr OwnerHandle => Handle;

    internal PanelHotkeyWindow(Action toggle)
    {
        ArgumentNullException.ThrowIfNull(toggle);
        this.toggle=toggle;
        try
        {
            CreateHandle(new CreateParams { Caption="LifeOS panel hotkeys", Parent=new IntPtr(-3) }); // HWND_MESSAGE
            if(Handle==IntPtr.Zero)throw new Win32Exception(Marshal.GetLastWin32Error(),"LifeOS could not create its hotkey owner.");
            WindowsRegistered=RegisterHotKey(Handle,WindowsId,ModWindows|ModNoRepeat,0xDC); // VK_OEM_5: US backslash
            WindowsError=WindowsRegistered?0:Marshal.GetLastWin32Error();
            AlternateWindowsRegistered=RegisterHotKey(Handle,AlternateWindowsId,ModWindows|ModNoRepeat,0xE2); // VK_OEM_102: ISO backslash
            AlternateWindowsError=AlternateWindowsRegistered?0:Marshal.GetLastWin32Error();
            FallbackRegistered=RegisterHotKey(Handle,FallbackId,ModControl|ModShift|ModNoRepeat,(uint)Keys.G);
            FallbackError=FallbackRegistered?0:Marshal.GetLastWin32Error();
        }
        catch
        {
            Dispose();
            throw;
        }
    }

    protected override void WndProc(ref Message message)
    {
        if(!disposed&&message.Msg==HotkeyMessage)
        {
            int id=message.WParam.ToInt32();
            if(id is WindowsId or AlternateWindowsId or FallbackId)
            {
                toggle();
                return;
            }
        }
        base.WndProc(ref message);
    }

    public void Dispose()
    {
        if(disposed)return;
        disposed=true;
        if(Handle!=IntPtr.Zero)
        {
            UnregisterHotKey(Handle,WindowsId);
            UnregisterHotKey(Handle,AlternateWindowsId);
            UnregisterHotKey(Handle,FallbackId);
            DestroyHandle();
        }
        WindowsRegistered=false;AlternateWindowsRegistered=false;FallbackRegistered=false;
        GC.SuppressFinalize(this);
    }

    [DllImport("user32.dll",SetLastError=true)]
    [return:MarshalAs(UnmanagedType.Bool)]
    private static extern bool RegisterHotKey(IntPtr window,int id,uint modifiers,uint key);
    [DllImport("user32.dll",SetLastError=true)]
    [return:MarshalAs(UnmanagedType.Bool)]
    private static extern bool UnregisterHotKey(IntPtr window,int id);
}
