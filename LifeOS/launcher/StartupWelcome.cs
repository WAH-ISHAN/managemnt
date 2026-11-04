using System.Diagnostics;
using System.Drawing.Drawing2D;
using System.Runtime.InteropServices;

internal sealed class StartupWelcome : Form
{
    private readonly System.Windows.Forms.Timer animation = new() { Interval=25 };
    private readonly Stopwatch elapsed = Stopwatch.StartNew();
    private readonly Image? logo;
    private readonly bool motion;
    private bool ready;
    private double? fading;
    internal static string Greeting(int hour) => hour is >=5 and <12 ? "Good morning" : hour is >=12 and <17 ? "Good afternoon" : "Good evening";
    internal static DateTimeOffset LocalTime(DateTimeOffset utc) => utc.ToOffset(TimeSpan.FromMinutes(330));
    internal StartupWelcome(string root)
    {
        FormBorderStyle=FormBorderStyle.None; ShowInTaskbar=false; StartPosition=FormStartPosition.CenterParent;
        ClientSize=new Size(460,380); BackColor=Color.FromArgb(244,247,255); DoubleBuffered=true;
        AccessibleName="Welcome to LifeOS";
        motion=!SystemParametersInfo(0x1042,0,out bool enabled,0) || enabled;
        Opacity=motion?0:1;
        var path=Path.Combine(root,"public","assets","brand","lifeos-mark.png");
        if(File.Exists(path)) { using var source=Image.FromFile(path); logo=new Bitmap(source); }
        animation.Tick+=(_,_)=>TickFrame();
        Shown+=(_,_)=>{elapsed.Restart();animation.Start();};
    }
    internal void Ready() => ready=true;
    private void TickFrame()
    {
        double ms=elapsed.Elapsed.TotalMilliseconds;
        if(ready && ms>=(motion?1200:0)) fading??=ms;
        if(fading is double start) {
            double fade=motion?Math.Clamp((ms-start)/280,0,1):1;
            Opacity=1-fade;
            if(fade>=1) { Close();return; }
        } else Opacity=motion?Math.Clamp(ms/350,0,1):1;
        Invalidate();
    }
    protected override void OnPaint(PaintEventArgs e)
    {
        base.OnPaint(e);var g=e.Graphics;g.SmoothingMode=SmoothingMode.AntiAlias;
        float entrance=motion?(float)Math.Clamp(elapsed.Elapsed.TotalMilliseconds/700,0,1):1;
        float eased=1-(float)Math.Pow(1-entrance,3);float size=104+24*eased;
        if(logo is not null)g.DrawImage(logo,(Width-size)/2,62+(1-eased)*14,size,size);
        using var title=new Font("Segoe UI",23,FontStyle.Bold);using var subtitle=new Font("Segoe UI",15);
        using var ink=new SolidBrush(Color.FromArgb(30,43,68));using var muted=new SolidBrush(Color.FromArgb(86,103,129));
        using var centered=new StringFormat{Alignment=StringAlignment.Center};
        g.DrawString("Welcome to LifeOS",title,ink,new RectangleF(12,216,Width-24,45),centered);
        g.DrawString(Greeting(LocalTime(DateTimeOffset.UtcNow).Hour),subtitle,muted,new RectangleF(12,265,Width-24,34),centered);
        using var ring=new Pen(Color.FromArgb(35,111,225),3){StartCap=LineCap.Round,EndCap=LineCap.Round};
        float angle=motion?(float)(elapsed.Elapsed.TotalSeconds*180%360):0;
        g.DrawArc(ring,Width/2-11,322,22,22,angle,250);
    }
    protected override bool ShowWithoutActivation=>true;
    protected override void Dispose(bool disposing){if(disposing){animation.Dispose();logo?.Dispose();}base.Dispose(disposing);}
    [DllImport("user32.dll",SetLastError=true)] private static extern bool SystemParametersInfo(uint action,uint parameter,[MarshalAs(UnmanagedType.Bool)] out bool value,uint flags);
}
