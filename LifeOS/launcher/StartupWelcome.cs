using System.Diagnostics;
using System.Drawing.Drawing2D;
using System.Drawing.Imaging;
using System.Runtime.InteropServices;

internal enum StartupStage { Initializing, LogoReady, AppReady, SplashExit, DashboardVisible }

internal sealed class StartupWelcome : Form
{
    private readonly System.Windows.Forms.Timer animation = new() { Interval=16 };
    private readonly Stopwatch elapsed = Stopwatch.StartNew();
    private readonly Image logo;
    private readonly bool motion;
    private double? fading;
    private bool appReady;
    private Form? parent;
    internal StartupStage Stage { get; private set; } = StartupStage.Initializing;
    internal event Action? TimedOut;

    internal StartupWelcome(string root)
    {
        FormBorderStyle=FormBorderStyle.None;
        ShowInTaskbar=false;
        StartPosition=FormStartPosition.Manual;
        ClientSize=new Size(460,380);
        BackColor=Color.FromArgb(7,18,37);
        DoubleBuffered=true;
        AccessibleName="LifeOS is opening";
        motion=!SystemParametersInfo(0x1042,0,out bool enabled,0) || enabled;
        var path=Path.Combine(root,"public","assets","brand","lifeos-mark.png");
        try { using var source=Image.FromFile(path); logo=new Bitmap(source); }
        catch (Exception error) when (error is IOException or ArgumentException or UnauthorizedAccessException) {
            using var icon=Icon.ExtractAssociatedIcon(Application.ExecutablePath) ?? SystemIcons.Application;
            logo=icon.ToBitmap();
        }
        animation.Tick+=(_,_)=>TickFrame();
        Shown+=(_,_)=>{
            if(Owner is Form owner) {
                parent=owner;
                Bounds=owner.Bounds;
                owner.Move+=FollowParent;
                owner.Resize+=FollowParent;
            }
            elapsed.Restart();
            animation.Start();
            Invalidate();
        };
    }

    internal void Ready()
    {
        appReady=true;
        if(Stage==StartupStage.LogoReady) Stage=StartupStage.AppReady;
    }

    private void FollowParent(object? sender,EventArgs args)
    {
        if(parent is { IsDisposed:false }) Bounds=parent.Bounds;
    }

    private void TickFrame()
    {
        double ms=elapsed.Elapsed.TotalMilliseconds;
        if(ms>=30_000 && !appReady) { animation.Stop(); TimedOut?.Invoke(); return; }
        if(appReady && Stage==StartupStage.LogoReady) Stage=StartupStage.AppReady;
        if(Stage==StartupStage.AppReady) { Stage=StartupStage.SplashExit; fading=ms; }
        if(fading is double start) {
            double fade=motion?Math.Clamp((ms-start)/220,0,1):1;
            Opacity=1-fade;
            if(fade>=1) { Stage=StartupStage.DashboardVisible; Close(); return; }
        }
        Invalidate();
    }

    protected override void OnPaint(PaintEventArgs e)
    {
        base.OnPaint(e);
        var g=e.Graphics;
        g.InterpolationMode=InterpolationMode.HighQualityBicubic;
        g.PixelOffsetMode=PixelOffsetMode.HighQuality;
        double entrance=motion?Math.Clamp(elapsed.Elapsed.TotalMilliseconds/320,0,1):1;
        double eased=1-Math.Pow(1-entrance,3);
        float size=(float)Math.Min(Math.Min(ClientSize.Width,ClientSize.Height)*0.34,180);
        size=Math.Max(56,size);
        size*=(float)(0.94+0.06*eased);
        float x=(ClientSize.Width-size)/2;
        float y=(ClientSize.Height-size)/2;
        using var attributes=new ImageAttributes();
        var matrix=new ColorMatrix { Matrix33=(float)eased };
        attributes.SetColorMatrix(matrix);
        g.DrawImage(logo,Rectangle.Round(new RectangleF(x,y,size,size)),0,0,logo.Width,logo.Height,GraphicsUnit.Pixel,attributes);
        if(Stage==StartupStage.Initializing) Stage=StartupStage.LogoReady;
    }

    protected override bool ShowWithoutActivation=>true;
    protected override void Dispose(bool disposing)
    {
        if(disposing) {
            animation.Dispose();
            logo.Dispose();
            if(parent is not null) { parent.Move-=FollowParent; parent.Resize-=FollowParent; }
        }
        base.Dispose(disposing);
    }
    [DllImport("user32.dll",SetLastError=true)] private static extern bool SystemParametersInfo(uint action,uint parameter,[MarshalAs(UnmanagedType.Bool)] out bool value,uint flags);
}
