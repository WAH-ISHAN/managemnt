using System.Runtime.InteropServices;
using Microsoft.Win32;

// Native composition is configured only on handle/theme changes. The compositor
// owns the backdrop; this helper does not capture the desktop or run a sampler.
// https://learn.microsoft.com/windows/win32/api/dwmapi/ne-dwmapi-dwm_systembackdrop_type
// https://learn.microsoft.com/windows/win32/api/dwmapi/nf-dwmapi-dwmextendframeintoclientarea
internal static class PanelGlass
{
    internal const int NativeFrameStyles = 0x00C00000 | 0x00040000; // WS_CAPTION | WS_THICKFRAME
    internal const int NcCalcSizeMessage = 0x0083;
    internal const int CompositionChangedMessage = 0x031E;

    private const int UseImmersiveDarkMode = 20;
    private const int WindowCornerPreference = 33;
    private const int BorderColor = 34;
    private const int SystemBackdropType = 38;
    private const int DesktopAcrylic = 3;
    private const int NoBackdrop = 1;
    private const int RoundCorners = 2;
    private const int NoBorderColor = unchecked((int)0xFFFFFFFE);

    // Apply after HandleCreated, and again if composition or the app theme changes.
    // For native corners on a custom frame, keep NativeFrameStyles in CreateParams
    // and return zero for WM_NCCALCSIZE. Do not use Form.Opacity, TransparencyKey,
    // or a WS_EX_LAYERED window: those change the whole WebView's composition path.
    internal static PanelGlassResult Apply(Form window, bool? darkMode = null, bool forceRoundedClip = false)
    {
        ArgumentNullException.ThrowIfNull(window);
        bool dark = darkMode ?? ReadDarkMode();
        if (!OperatingSystem.IsWindows() || !window.IsHandleCreated || window.IsDisposed)
            return new(false, false, false, dark, "handle-unavailable", null, null, null);

        bool composition = false;
        int? frameResult = null, backdropResult = null, cornerResult = null;
        try
        {
            composition = DwmIsCompositionEnabled(out bool enabled) >= 0 && enabled;
            int darkValue = dark ? 1 : 0;
            DwmSetWindowAttribute(window.Handle, UseImmersiveDarkMode, ref darkValue, sizeof(int));
            int corner = RoundCorners;
            cornerResult = DwmSetWindowAttribute(window.Handle, WindowCornerPreference, ref corner, sizeof(int));

            bool canUseAcrylic = composition && OperatingSystem.IsWindowsVersionAtLeast(10, 0, 22621)
                && !SystemInformation.HighContrast && ReadTransparencyEnabled();
            if (canUseAcrylic)
            {
                var margins = new Margins { Left = -1, Right = -1, Top = -1, Bottom = -1 };
                frameResult = DwmExtendFrameIntoClientArea(window.Handle, ref margins);
                int acrylic = DesktopAcrylic;
                backdropResult = DwmSetWindowAttribute(window.Handle, SystemBackdropType, ref acrylic, sizeof(int));
                if (frameResult.GetValueOrDefault(-1) >= 0 && backdropResult.GetValueOrDefault(-1) >= 0)
                {
                    // The full DWM frame needs an unpainted black base. WebView2
                    // must also use DefaultBackgroundColor=Color.Transparent,
                    // with transparent html/body and translucent CSS surfaces.
                    window.BackColor = Color.Black;
                    int border = NoBorderColor;
                    DwmSetWindowAttribute(window.Handle, BorderColor, ref border, sizeof(int));
                    bool clipped = forceRoundedClip && UpdateRoundedClip(window);
                    if (!forceRoundedClip) ClearRoundedClip(window);
                    return new(true, cornerResult.GetValueOrDefault(-1) >= 0, clipped, dark, "desktop-acrylic", frameResult, backdropResult, cornerResult);
                }
            }

            // The documented system backdrop is unavailable before Windows 11
            // 22H2. Keep an opaque, readable fallback rather than depending on
            // undocumented Windows 10 SetWindowCompositionAttribute structures.
            int none = NoBackdrop;
            DwmSetWindowAttribute(window.Handle, SystemBackdropType, ref none, sizeof(int));
            var reset = new Margins();
            DwmExtendFrameIntoClientArea(window.Handle, ref reset);
            window.BackColor = dark ? Color.FromArgb(28, 34, 45) : Color.FromArgb(240, 244, 250);
            bool roundedClip = (forceRoundedClip || cornerResult.GetValueOrDefault(-1) < 0) && UpdateRoundedClip(window);
            if (!roundedClip) ClearRoundedClip(window);
            string reason = SystemInformation.HighContrast ? "high-contrast" :
                !ReadTransparencyEnabled() ? "transparency-disabled" :
                !composition ? "composition-unavailable" : "solid-fallback";
            return new(false, cornerResult.GetValueOrDefault(-1) >= 0, roundedClip, dark, reason, frameResult, backdropResult, cornerResult);
        }
        catch (DllNotFoundException)
        {
            window.BackColor = dark ? Color.FromArgb(28, 34, 45) : Color.FromArgb(240, 244, 250);
            return new(false, false, UpdateRoundedClip(window), dark, "dwm-unavailable", frameResult, backdropResult, cornerResult);
        }
        catch (EntryPointNotFoundException)
        {
            window.BackColor = dark ? Color.FromArgb(28, 34, 45) : Color.FromArgb(240, 244, 250);
            return new(false, false, UpdateRoundedClip(window), dark, "dwm-unavailable", frameResult, backdropResult, cornerResult);
        }
    }

    // Call only on bounds/DPI changes when a clipped fallback is being used.
    // A native region owns the cut-out, so the corners stay truly transparent
    // outside the HWND instead of exposing the form's rectangular background.
    internal static bool UpdateRoundedClip(Form window, int radiusAt96Dpi = 26)
    {
        if (!OperatingSystem.IsWindows() || !window.IsHandleCreated || window.IsDisposed || window.Width < 2 || window.Height < 2)
            return false;
        int diameter = Math.Clamp((int)Math.Round(radiusAt96Dpi * 2d * window.DeviceDpi / 96d), 2, Math.Min(window.Width, window.Height));
        IntPtr region = CreateRoundRectRgn(0, 0, window.Width + 1, window.Height + 1, diameter, diameter);
        if (region == IntPtr.Zero) return false;
        if (SetWindowRgn(window.Handle, region, true) != 0) return true; // Windows now owns the region.
        DeleteObject(region);
        return false;
    }

    internal static void ClearRoundedClip(Form window)
    {
        if (OperatingSystem.IsWindows() && window.IsHandleCreated && !window.IsDisposed)
            SetWindowRgn(window.Handle, IntPtr.Zero, true);
    }

    internal static bool ReadDarkMode()
    {
        try { return Registry.GetValue(@"HKEY_CURRENT_USER\Software\Microsoft\Windows\CurrentVersion\Themes\Personalize", "AppsUseLightTheme", 1) is int value && value == 0; }
        catch (System.Security.SecurityException) { return false; }
        catch (IOException) { return false; }
        catch (UnauthorizedAccessException) { return false; }
    }

    private static bool ReadTransparencyEnabled()
    {
        try { return Registry.GetValue(@"HKEY_CURRENT_USER\Software\Microsoft\Windows\CurrentVersion\Themes\Personalize", "EnableTransparency", 1) is not int value || value != 0; }
        catch (System.Security.SecurityException) { return true; }
        catch (IOException) { return true; }
        catch (UnauthorizedAccessException) { return true; }
    }

    [StructLayout(LayoutKind.Sequential)] private struct Margins { internal int Left, Right, Top, Bottom; }
    [DllImport("dwmapi.dll")] private static extern int DwmIsCompositionEnabled([MarshalAs(UnmanagedType.Bool)] out bool enabled);
    [DllImport("dwmapi.dll")] private static extern int DwmSetWindowAttribute(IntPtr window, int attribute, ref int value, int size);
    [DllImport("dwmapi.dll")] private static extern int DwmExtendFrameIntoClientArea(IntPtr window, ref Margins margins);
    [DllImport("gdi32.dll")] private static extern IntPtr CreateRoundRectRgn(int left, int top, int right, int bottom, int ellipseWidth, int ellipseHeight);
    [DllImport("user32.dll")] private static extern int SetWindowRgn(IntPtr window, IntPtr region, [MarshalAs(UnmanagedType.Bool)] bool redraw);
    [DllImport("gdi32.dll")] [return: MarshalAs(UnmanagedType.Bool)] private static extern bool DeleteObject(IntPtr handle);
}

// "Configured" records an accepted DWM request. Windows may still render the
// material as solid because of transparency/accessibility or power policies.
internal readonly record struct PanelGlassResult(
    bool NativeBackdropConfigured,
    bool NativeCornersRequested,
    bool RoundedClipApplied,
    bool DarkMode,
    string Mode,
    int? FrameHResult,
    int? BackdropHResult,
    int? CornerHResult);
