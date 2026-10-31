using System.Runtime.InteropServices;

internal sealed partial class LifeWindow
{
    private static readonly uint ActivateResidentMessage=RegisterWindowMessage("LifeOS.ShowMain.E89C8D98DC124820A52B1334E5D06A19");
    private static readonly uint ShutdownResidentMessage=RegisterWindowMessage("LifeOS.UpdateShutdown.E89C8D98DC124820A52B1334E5D06A19");
    private bool allowExitForMaintenance,cleanupDone;
    private ContextMenuStrip? trayMenu;

    internal static void NotifyExistingInstance(bool shutdown=false)=>PostMessage(new IntPtr(0xffff),shutdown?ShutdownResidentMessage:ActivateResidentMessage,IntPtr.Zero,IntPtr.Zero);

    private void InitializeResidency()
    {
        if(diagnostic)return;
        trayMenu=new ContextMenuStrip();
        trayMenu.Items.Add("Open LifeOS",null,(_,_)=>ShowMainWindow());
        trayMenu.Items.Add("Show / hide panel  (Win + \\)",null,(_,_)=>ToggleDesktopPanel());
        trayMenu.Items.Add(new ToolStripSeparator());
        trayMenu.Items.Add(new ToolStripMenuItem("LifeOS is running in the background"){Enabled=false});
        reminders.ContextMenuStrip=trayMenu;
        reminders.DoubleClick+=(_,_)=>ShowMainWindow();
        reminders.Visible=true;
        FormClosing+=(_,e)=>{
            if(e.CloseReason!=CloseReason.UserClosing||allowExitForMaintenance)return;
            e.Cancel=true;HideToTray();
        };
    }
    private void ShowMainWindow()
    {
        if(IsDisposed)return;
        ShowInTaskbar=true;Show();WindowState=FormWindowState.Normal;Activate();WriteResidentState();
    }
    private void HideToTray()
    {
        desktopPanel?.Hide();Hide();ShowInTaskbar=false;reminders.Visible=true;WriteResidentState();
    }
    private void WriteResidentState()
    {
        if(diagnostic||IsDisposed)return;
        try{string data=Environment.GetEnvironmentVariable("LIFEOS_DATA_DIR")??Path.Combine(root,"data");if(!Directory.Exists(data))return;File.WriteAllText(Path.Combine(data,"resident-state.json"),System.Text.Json.JsonSerializer.Serialize(new{processId=Environment.ProcessId,mainVisible=Visible,trayVisible=reminders.Visible,phpPid=php is {HasExited:false}?php.Id:(int?)null,hotkeyRegistered=panelHotkeys?.AnyWindowsRegistered??false}));}catch(IOException){}catch(UnauthorizedAccessException){}
    }
    private bool HandleResidentMessage(ref Message message)
    {
        if(diagnostic)return false;
        if(message.Msg==ActivateResidentMessage){ShowMainWindow();return true;}
        if(message.Msg==ShutdownResidentMessage){allowExitForMaintenance=true;Close();return true;}
        return false;
    }
    [DllImport("user32.dll",CharSet=CharSet.Unicode)]private static extern uint RegisterWindowMessage(string message);
    [DllImport("user32.dll",SetLastError=true)]private static extern bool PostMessage(IntPtr window,uint message,IntPtr wParam,IntPtr lParam);
}
