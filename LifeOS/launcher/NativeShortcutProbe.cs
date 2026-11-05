using System.ComponentModel;
using System.Runtime.InteropServices;

// Used only by the isolated native diagnostics. Feed the Windows input queue
// so a registered shortcut is exercised beyond direct WM_HOTKEY dispatch.
internal static class NativeShortcutProbe
{
    [StructLayout(LayoutKind.Sequential)] private struct Input { public uint Type; public InputData Data; }
    [StructLayout(LayoutKind.Explicit)] private struct InputData
    {
        [FieldOffset(0)] public Keyboard Keyboard;
        [FieldOffset(0)] public Mouse Mouse;
    }
    [StructLayout(LayoutKind.Sequential)] private struct Keyboard { public ushort Key, Scan; public uint Flags, Time; public UIntPtr Extra; }
    [StructLayout(LayoutKind.Sequential)] private struct Mouse { public int X, Y; public uint Data, Flags, Time; public UIntPtr Extra; }
    private static Input Key(ushort key, bool up) => new() { Type = 1, Data = new() { Keyboard = new() { Key = key, Flags = up ? 2u : 0u } } };
    internal static void Assistant(bool iso)
    {
        ushort slash = iso ? (ushort)0xE2 : (ushort)0xDC;
        Input[] press = [Key(0x5B, false), Key(0x10, false), Key(slash, false), Key(slash, true), Key(0x10, true), Key(0x5B, true)];
        if (SendInput((uint)press.Length, press, Marshal.SizeOf<Input>()) != press.Length)
        {
            int error = Marshal.GetLastWin32Error();
            Input[] release = [Key(slash, true), Key(0x10, true), Key(0x5B, true)];
            SendInput((uint)release.Length, release, Marshal.SizeOf<Input>());
            throw new Win32Exception(error, "Windows did not accept the assistant shortcut diagnostic input.");
        }
    }
    [DllImport("user32.dll", SetLastError = true)] private static extern uint SendInput(uint count, Input[] input, int size);
}
