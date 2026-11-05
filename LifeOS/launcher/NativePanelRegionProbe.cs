using System.Runtime.InteropServices;

// Read the actual HWND region in native diagnostics; CSS screenshots cannot
// detect an exposed nonclient frame or a mismatched Windows corner cut-out.
internal static class NativePanelRegionProbe
{
    internal static PanelRegionProbeResult Inspect(Form window)
    {
        IntPtr region=CreateRectRgn(0,0,0,0);
        if(region==IntPtr.Zero)return default;
        try {
            if(GetWindowRgn(window.Handle,region)==0)return default;
            GetRgnBox(region,out var bounds);
            int width=window.Width,height=window.Height;
            bool fits=bounds.Left==0&&bounds.Top==0&&bounds.Right>=width&&bounds.Right<=width+1
                &&bounds.Bottom>=height&&bounds.Bottom<=height+1;
            bool Included(int x,int y)=>PtInRegion(region,x,y);
            bool corners=!Included(0,0)&&!Included(width-1,0)&&!Included(0,height-1)&&!Included(width-1,height-1);
            bool edges=Included(width/2,0)&&Included(width/2,height-1)&&Included(0,height/2)&&Included(width-1,height/2);
            int depth=Math.Min((int)Math.Ceiling(32d*window.DeviceDpi/96d),Math.Min(width,height)/2);
            var previous=new[]{depth,depth,depth,depth};
            var levels=new[]{new HashSet<int>(),new HashSet<int>(),new HashSet<int>(),new HashSet<int>()};
            bool symmetric=depth>=4;
            for(int y=0;y<depth;y++) {
                var insets=new int[4];
                for(int corner=0;corner<4;corner++) {
                    int inset=0;
                    while(inset<depth&&!Included(corner%2==0?inset:width-1-inset,corner<2?y:height-1-y))inset++;
                    insets[corner]=inset;
                    levels[corner].Add(inset);
                    // A rounded corner continuously opens onto the straight edge.
                    if(inset>previous[corner])symmetric=false;
                    previous[corner]=inset;
                }
                // GDI's integer rasterization can differ by one pixel at the
                // mirrored boundary; a two-pixel tolerance also covers scaling.
                if(insets.Max()-insets.Min()>2)symmetric=false;
            }
            symmetric&=previous.All(value=>value<=1)&&levels.All(values=>values.Count>=4);
            return new(true,fits,corners,edges,symmetric,bounds.Right-bounds.Left,bounds.Bottom-bounds.Top);
        } finally {DeleteObject(region);}
    }

    [StructLayout(LayoutKind.Sequential)] private struct RegionBounds { internal int Left,Top,Right,Bottom; }
    [DllImport("gdi32.dll")] private static extern IntPtr CreateRectRgn(int left,int top,int right,int bottom);
    [DllImport("user32.dll")] private static extern int GetWindowRgn(IntPtr window,IntPtr region);
    [DllImport("gdi32.dll")] private static extern int GetRgnBox(IntPtr region,out RegionBounds bounds);
    [DllImport("gdi32.dll")] [return:MarshalAs(UnmanagedType.Bool)] private static extern bool PtInRegion(IntPtr region,int x,int y);
    [DllImport("gdi32.dll")] [return:MarshalAs(UnmanagedType.Bool)] private static extern bool DeleteObject(IntPtr handle);
}

internal readonly record struct PanelRegionProbeResult(bool Exists,bool FitsWindow,bool CornersExcluded,bool EdgesIncluded,bool SymmetricCurves,int RegionWidth,int RegionHeight);
