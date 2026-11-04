// The panels are separate top-level windows, so CSS z-index cannot arrange
// them. Place only the window being revealed; dragging remains unrestricted.
internal static class FloatingPanelLayout
{
    internal static Rectangle Arrange(Rectangle preferred, Rectangle work, Rectangle? companion, Size minimum, int gap, int companionHeaderHeight, Rectangle? protectedHeader=null)
    {
        int width=Math.Min(preferred.Width,work.Width),height=Math.Min(preferred.Height,work.Height);
        Rectangle Clamp(Rectangle bounds)=>new(Math.Clamp(bounds.X,work.Left,work.Right-bounds.Width),Math.Clamp(bounds.Y,work.Top,work.Bottom-bounds.Height),bounds.Width,bounds.Height);
        Rectangle ClearHeader(Rectangle bounds)
        {
            if(protectedHeader is not Rectangle header || !bounds.IntersectsWith(Rectangle.Inflate(header,0,gap)))return bounds;
            int top=header.Bottom+gap,available=work.Bottom-top-gap;
            return available>=Math.Min(minimum.Height,work.Height)
                ? Clamp(new Rectangle(bounds.X,top,bounds.Width,Math.Min(bounds.Height,available))) : bounds;
        }
        var current=ClearHeader(Clamp(new Rectangle(preferred.Location,new Size(width,height))));
        width=current.Width;height=current.Height;
        if(companion is not Rectangle other || !current.IntersectsWith(Rectangle.Inflate(other,gap,gap)))return current;

        var options=new[]{
            new Rectangle(other.Left-gap-width,current.Top,width,height),
            new Rectangle(other.Right+gap,current.Top,width,height),
            new Rectangle(current.Left,other.Bottom+gap,width,height),
            new Rectangle(current.Left,other.Top-gap-height,width,height)
        };
        var free=options.Where(work.Contains).Where(r=>!r.IntersectsWith(Rectangle.Inflate(other,gap,gap)))
            .Where(r=>protectedHeader is not Rectangle header || !r.IntersectsWith(Rectangle.Inflate(header,0,gap)))
            .OrderBy(r=>Math.Abs((long)r.X-current.X)+Math.Abs((long)r.Y-current.Y)).ToArray();
        if(free.Length>0)return free[0];

        // On short/narrow displays, use an aligned cascade and reduce only the
        // newly opened window's height enough to expose the companion caption.
        int top=other.Top+companionHeaderHeight+gap;
        int available=work.Bottom-top-gap;
        if(available>=Math.Min(minimum.Height,work.Height))
            return ClearHeader(Clamp(new Rectangle(other.Left,top,width,Math.Min(height,available))));
        return ClearHeader(Clamp(new Rectangle(other.Left,current.Top,width,height)));
    }

    internal static void Reveal(Form panel,Form? companion,Rectangle? protectedHeader=null)
    {
        var work=Screen.FromRectangle(panel.Bounds).WorkingArea;
        int gap=panel.LogicalToDeviceUnits(12);
        int headerHeight=companion switch {
            AssistantPanelWindow assistant=>assistant.HeaderHeight,
            DesktopPanelWindow desktop=>desktop.HeaderHeight,
            _=>0
        };
        panel.Bounds=Arrange(panel.Bounds,work,companion is {Visible:true,IsDisposed:false}?companion.Bounds:null,
            panel.MinimumSize,gap,headerHeight,protectedHeader);
        panel.Show();panel.Activate();
    }
}
