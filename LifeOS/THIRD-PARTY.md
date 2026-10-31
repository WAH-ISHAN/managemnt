# Bundled dependencies

All browser dependencies are served from `public/assets`; the app makes no external requests.

- htmx 2.0.4 — Zero-Clause BSD. Source and license: https://github.com/bigskysoftware/htmx/tree/v2.0.4
- Alpine.js 3.14.9 — MIT. Source and license: https://github.com/alpinejs/alpine/tree/v3.14.9
- Inter 4.1 — SIL Open Font License 1.1. Source and license: https://github.com/rsms/inter/tree/v4.1
- Chart.js 4.4.7 — MIT. Source and license: https://github.com/chartjs/Chart.js/tree/v4.4.7
- Microsoft.Web.WebView2 1.0.2592.51 — Microsoft WebView2 SDK license, restored by NuGet. https://www.nuget.org/packages/Microsoft.Web.WebView2/1.0.2592.51
- PHP 8.3 — PHP license, copied with the runtime from the build machine. https://www.php.net/license/
- Microsoft .NET 8 — runtime and third party notices included by self-contained publish. https://github.com/dotnet/runtime/blob/main/LICENSE.TXT
- Microsoft WebView2 Runtime — Microsoft redistribution terms apply. The offline installer is bundled for machines without the runtime. https://developer.microsoft.com/en-us/microsoft-edge/webview2/
- Microsoft Visual C++ runtime — app-local redistributable runtime DLLs from the Windows build machine. Microsoft redistribution terms apply. https://learn.microsoft.com/en-us/visualstudio/releases/2022/redistribution

License text for the vendored browser libraries and font is in `public/assets/licenses`.
# Research references for Work 1.7.0

Kanboard (MIT) and Redmine (GPL-2.0-or-later) were cloned for source/documentation review alongside the user-supplied awesome-jira-alternatives list (MIT). Their clones retain their licenses under `.tools/jira-references` and are excluded from the LifeOS installer. The Work issue-type, release and WIP-limit features use original LifeOS implementation. See `qa/open-source-jira-references.md` for exact commits and source references.

