using System.Runtime.InteropServices;
using System.Text.Json;

internal sealed partial class LifeWindow
{
    private bool customChrome;
    private bool chromeCloseRequested;
    private int lastChromeResizeHit;
    private int ResizeRing => Math.Max(6,(int)Math.Round(6*DeviceDpi/96d));

    private void InitializeWindowChrome()
    {
        SetCustomChrome(true);
        Resize += (_, _) => PublishWindowState();
        FormClosed += (_, _) => {
            if (smoke) {
                string data=Environment.GetEnvironmentVariable("LIFEOS_DATA_DIR")!;
                File.WriteAllText(Path.Combine(data,"window-chrome-close.json"),JsonSerializer.Serialize(new { closedByControl=chromeCloseRequested }));
            }
        };
    }

    protected override CreateParams CreateParams
    {
        get {
            var cp=base.CreateParams;
            if (customChrome) cp.Style |= 0x00040000 | 0x00020000 | 0x00010000 | 0x00080000; // Resize, minimize, maximize and system menu.
            return cp;
        }
    }

    protected override void OnHandleCreated(EventArgs e)
    {
        base.OnHandleCreated(e);
        int rounded=2;
        DwmSetWindowAttribute(Handle,33,ref rounded,sizeof(int));
        int border=unchecked((int)0xFFFFFFFE);DwmSetWindowAttribute(Handle,34,ref border,sizeof(int));
    }

    private void SetCustomChrome(bool enabled)
    {
        if (customChrome!=enabled) {
            customChrome=enabled;
            FormBorderStyle=enabled ? FormBorderStyle.None : FormBorderStyle.Sizable;
            UpdateStyles();
        }
        // The WebView paints all the way to the native edge. Reserving a resize
        // ring here exposes the form background as a pale outline in light mode.
        Padding=Padding.Empty;
    }

    private int ChromeHitTest(Point point)
    {
        if (!customChrome || WindowState==FormWindowState.Maximized) return 1;
        int edge=ResizeRing;
        bool left=point.X<edge, right=point.X>=ClientSize.Width-edge;
        bool top=point.Y<edge, bottom=point.Y>=ClientSize.Height-edge;
        if (top) return left ? 13 : right ? 14 : 12;
        if (bottom) return left ? 16 : right ? 17 : 15;
        return left ? 10 : right ? 11 : 1;
    }

    protected override void WndProc(ref Message m)
    {
        if(HandleResidentMessage(ref m))return;
        if (customChrome && m.Msg==0x0083) { m.Result=IntPtr.Zero; return; } // Native frame is drawn by the glass header.
        if (customChrome && m.Msg==0x0084) {
            long packed=m.LParam.ToInt64();
            var point=PointToClient(new Point(unchecked((short)(packed & 0xffff)),unchecked((short)((packed>>16)&0xffff))));
            int hit=ChromeHitTest(point);
            if (hit!=1) { m.Result=(IntPtr)hit; return; }
        }
        base.WndProc(ref m);
        if (customChrome && m.Msg==0x0024 && m.LParam!=IntPtr.Zero) {
            var limits=Marshal.PtrToStructure<ChromeMinMax>(m.LParam);
            var screen=Screen.FromHandle(Handle); var work=screen.WorkingArea; var monitor=screen.Bounds;
            limits.MaxPosition=new Point(work.Left-monitor.Left,work.Top-monitor.Top);
            limits.MaxSize=new Point(work.Width,work.Height);
            limits.MinTrackSize=new Point(MinimumSize.Width,MinimumSize.Height);
            Marshal.StructureToPtr(limits,m.LParam,false);
        }
    }

    protected override bool ProcessCmdKey(ref Message msg,Keys keyData)
    {
        if (customChrome && keyData==(Keys.Alt | Keys.Space)) { ShowWindowMenu(); return true; }
        if (keyData==(Keys.Alt | Keys.F4)) { Close(); return true; }
        return base.ProcessCmdKey(ref msg,keyData);
    }

    private void HandleWindowMessage(JsonElement value)
    {
        if (!value.TryGetProperty("action",out var action) || action.ValueKind!=JsonValueKind.String) return;
        switch (action.GetString()) {
            case "state":
                SetCustomChrome(true);
                bool dark=value.TryGetProperty("dark",out var darkValue) && darkValue.ValueKind==JsonValueKind.True;
                BackColor=dark ? Color.FromArgb(16,24,39) : Color.FromArgb(237,242,250);
                PublishWindowState();
                break;
            case "close":
                chromeCloseRequested=true;
                BeginInvoke(new Action(Close));
                break;
            case "minimize": WindowState=FormWindowState.Minimized; break;
            case "maximize":
                WindowState=WindowState==FormWindowState.Maximized ? FormWindowState.Normal : FormWindowState.Maximized;
                break;
            case "drag":
                if (Control.MouseButtons!=MouseButtons.Left) return;
                BeginInvoke(new Action(() => {
                    if (IsDisposed || Control.MouseButtons!=MouseButtons.Left) return;
                    ReleaseCapture();
                    SendMessage(Handle,0x00A1,(IntPtr)2,IntPtr.Zero);
                }));
                break;
            case "resize":
                if (WindowState!=FormWindowState.Normal || !value.TryGetProperty("edge",out var edge) || edge.ValueKind!=JsonValueKind.String) return;
                int hit=edge.GetString() switch { "w"=>10,"e"=>11,"n"=>12,"nw"=>13,"ne"=>14,"s"=>15,"sw"=>16,"se"=>17,_=>0 };
                if (hit==0 || Control.MouseButtons!=MouseButtons.Left) return;
                BeginInvoke(new Action(() => {
                    if (IsDisposed || WindowState!=FormWindowState.Normal || Control.MouseButtons!=MouseButtons.Left) return;
                    lastChromeResizeHit=hit;
                    ReleaseCapture();
                    var cursor=Cursor.Position;
                    long position=((long)(ushort)cursor.Y<<16)|(ushort)cursor.X;
                    SendMessage(Handle,0x00A1,(IntPtr)hit,(IntPtr)position);
                }));
                break;
            case "menu": BeginInvoke(new Action(ShowWindowMenu)); break;
        }
    }

    private void PublishWindowState()
    {
        if (IsDisposed || web.CoreWebView2 is null) return;
        web.CoreWebView2.PostWebMessageAsJson(JsonSerializer.Serialize(new { type="lifeos-window-state",maximized=WindowState==FormWindowState.Maximized,resident=!diagnostic }));
    }

    private void ShowWindowMenu()
    {
        var menu=GetSystemMenu(Handle,false);
        bool maximized=WindowState==FormWindowState.Maximized;
        EnableMenuItem(menu,0xF120,(uint)(maximized ? 0 : 1));
        EnableMenuItem(menu,0xF030,(uint)(maximized ? 1 : 0));
        var point=PointToScreen(new Point(ResizeRing,LogicalToDeviceUnits(48)));
        uint command=TrackPopupMenuEx(menu,0x0102,point.X,point.Y,Handle,IntPtr.Zero);
        if (command!=0) SendMessage(Handle,0x0112,(IntPtr)command,IntPtr.Zero);
    }

    private async Task VerifyWindowChrome(Dictionary<string,bool> checks)
    {
        if (!await WaitForExpression("!!document.querySelector('#desktop-titlebar [data-window-action=close]')")) throw new InvalidOperationException("Desktop glass header missing.");
        if (!customChrome || FormBorderStyle!=FormBorderStyle.None) throw new InvalidOperationException("Native caption was not replaced by the glass header.");
        checks["glass-titlebar"]=true;
        if (Padding!=Padding.Empty || web.Bounds!=ClientRectangle) throw new InvalidOperationException("A visible frame remains around the WebView.");
        checks["window-content-to-edge"]=true;
        const string resizeGrips="(()=>{const grips=[...document.querySelectorAll('[data-resize-edge]')];return grips.length===8 && grips.every(e=>{const s=getComputedStyle(e),r=e.getBoundingClientRect();return s.backgroundColor==='rgba(0, 0, 0, 0)' && s.borderTopWidth==='0px' && s.boxShadow==='none' && e.contains(document.elementFromPoint(r.x+r.width/2,r.y+r.height/2))})})()";
        if (!await WaitForExpression(resizeGrips)) throw new InvalidOperationException("Invisible resize edges are missing or blocked.");
        checks["invisible-resize-grips"]=true;
        await VerifyResizeGesture(checks,"native-resize-through-webview");
        foreach (var (point,expected) in new[] {
            (new Point(1,1),13),(new Point(ClientSize.Width-2,1),14),
            (new Point(1,ClientSize.Height-2),16),(new Point(ClientSize.Width-2,ClientSize.Height-2),17),
            (new Point(1,ClientSize.Height/2),10),(new Point(ClientSize.Width-2,ClientSize.Height/2),11),
            (new Point(ClientSize.Width/2,1),12),(new Point(ClientSize.Width/2,ClientSize.Height-2),15)
        }) {
            Point screen=PointToScreen(point);
            long packed=((long)(ushort)screen.Y<<16)|(ushort)screen.X;
            if (SendMessage(Handle,0x0084,IntPtr.Zero,(IntPtr)packed).ToInt32()!=expected) throw new InvalidOperationException("Native resize ring hit-test failed.");
        }
        checks["native-resize-edges"]=true;
        async Task WaitState(FormWindowState expected) {
            for(int n=0;n<50;n++) { if(WindowState==expected) return; await Task.Delay(50); }
            throw new InvalidOperationException("Window control failed: "+expected);
        }
        await web.CoreWebView2.ExecuteScriptAsync("document.querySelector('[data-window-action=maximize]').click()");
        await WaitState(FormWindowState.Maximized);
        var work=Screen.FromHandle(Handle).WorkingArea;
        if (Math.Abs(Bounds.Width-work.Width)>2 || Math.Abs(Bounds.Height-work.Height)>2 || Padding!=Padding.Empty) throw new InvalidOperationException("Maximized window exceeds the monitor working area.");
        checks["window-maximize"]=true;
        if (!await WaitForExpression("[...document.querySelectorAll('[data-resize-edge]')].every(e=>getComputedStyle(e).display==='none')")) throw new InvalidOperationException("Resize edges cover a maximized window.");
        checks["maximized-resize-grips-hidden"]=true;
        await web.CoreWebView2.ExecuteScriptAsync("document.querySelector('[data-window-action=maximize]').click()");
        await WaitState(FormWindowState.Normal);
        if(Padding!=Padding.Empty || web.Bounds!=ClientRectangle || !await WaitForExpression(resizeGrips)) throw new InvalidOperationException("Borderless resize edges were not restored.");
        checks["window-restore"]=true;
        await web.CoreWebView2.ExecuteScriptAsync("document.querySelector('[data-window-action=minimize]').click()");
        await WaitState(FormWindowState.Minimized);
        checks["window-minimize"]=true;
        WindowState=FormWindowState.Normal;
        await Task.Delay(200);
        string frame=Path.Combine(Environment.GetEnvironmentVariable("LIFEOS_DATA_DIR")!,"smoke-screenshots","main-native-frame.png");Directory.CreateDirectory(Path.GetDirectoryName(frame)!);
        using(var bitmap=new Bitmap(Width,Height)){using(var graphics=Graphics.FromImage(bitmap))graphics.CopyFromScreen(Location,Point.Empty,Size);bitmap.Save(frame,System.Drawing.Imaging.ImageFormat.Png);}
    }

    private async Task VerifyModalWindowChrome(Dictionary<string,bool> checks)
    {
        if (!await WaitForExpression("(()=>{const b=document.querySelector('#desktop-titlebar [data-window-action=minimize]');if(!b?.closest('dialog')?.open)return false;const r=b.getBoundingClientRect();return b.contains(document.elementFromPoint(r.x+r.width/2,r.y+r.height/2))})()")) throw new InvalidOperationException("Window controls are blocked by a modal dialog.");
        await web.CoreWebView2.ExecuteScriptAsync("document.querySelector('#desktop-titlebar [data-window-action=minimize]').click()");
        for (int n=0;n<50 && WindowState!=FormWindowState.Minimized;n++) await Task.Delay(50);
        if(WindowState!=FormWindowState.Minimized) throw new InvalidOperationException("Minimize failed while a modal was open.");
        WindowState=FormWindowState.Normal;
        checks["window-controls-during-modal"]=true;
        if (!await WaitForExpression("(()=>{const e=document.querySelector('[data-resize-edge=e]');if(!e?.closest('dialog')?.open)return false;const r=e.getBoundingClientRect();return e.contains(document.elementFromPoint(r.x+r.width/2,r.y+r.height/2))})()")) throw new InvalidOperationException("Resize edges are blocked by a modal dialog.");
        checks["resize-grips-during-modal"]=true;
        await VerifyResizeGesture(checks,"native-resize-during-modal");
    }

    // Exercise the browser pointer event and the actual Windows sizing loop,
    // rather than only testing the parent's WM_NCHITTEST implementation.
    private async Task VerifyResizeGesture(Dictionary<string,bool> checks,string name)
    {
        var originalBounds=Bounds;
        var originalCursor=Cursor.Position;
        Activate();
        await Task.Delay(150,lifetime.Token);
        var start=PointToScreen(new Point(ClientSize.Width-3,ClientSize.Height/2));
        lastChromeResizeHit=0;
        try {
            await Task.Run(async () => {
                Cursor.Position=start;
                await Task.Delay(150);
                try {
                    ChromeMouseEvent(0x0002,0,0,0,UIntPtr.Zero);
                    await Task.Delay(300);
                    Cursor.Position=new Point(start.X-32,start.Y);
                    await Task.Delay(200);
                } finally { ChromeMouseEvent(0x0004,0,0,0,UIntPtr.Zero); }
            });
            await Task.Delay(100,lifetime.Token);
            if (lastChromeResizeHit!=11 || Math.Abs(Width-(originalBounds.Width-32))>3 || Height!=originalBounds.Height)
                throw new InvalidOperationException("The WebView edge did not perform a native resize: "+name);
            checks[name]=true;
        } finally { Bounds=originalBounds; Cursor.Position=originalCursor; }
    }

    [StructLayout(LayoutKind.Sequential)] private struct ChromeMinMax { public Point Reserved,MaxSize,MaxPosition,MinTrackSize,MaxTrackSize; }
    [DllImport("user32.dll")] private static extern bool ReleaseCapture();
    [DllImport("user32.dll",EntryPoint="mouse_event")] private static extern void ChromeMouseEvent(uint flags,uint x,uint y,uint data,UIntPtr extra);
    [DllImport("user32.dll")] private static extern IntPtr SendMessage(IntPtr handle,int message,IntPtr wParam,IntPtr lParam);
    [DllImport("user32.dll")] private static extern IntPtr GetSystemMenu(IntPtr handle,bool revert);
    [DllImport("user32.dll")] private static extern uint EnableMenuItem(IntPtr menu,uint item,uint enable);
    [DllImport("user32.dll")] private static extern uint TrackPopupMenuEx(IntPtr menu,uint flags,int x,int y,IntPtr handle,IntPtr parameters);
    [DllImport("dwmapi.dll")] private static extern int DwmSetWindowAttribute(IntPtr handle,int attribute,ref int value,int size);
}
