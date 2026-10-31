# මේ QA ක්‍රමය ඉගෙනගන්න

මේ folder එකේ තියෙන්නේ LifeOS පරීක්ෂා කරන්න භාවිතා කළ ක්‍රමය නැවත භාවිතා කරන්න පුළුවන් විදිහට ලියපු guide එකක්. Claude, Codex හෝ වෙන coding agent කෙනෙකුට [AGENT-PROMPT.md](AGENT-PROMPT.md) සහ [README.md](README.md) දෙන්න පුළුවන්.

**මූලික අදහස: කරන සෑම claim එකකටම ඒක ඔප්පු කරන සාක්ෂියක් තියෙන්න ඕන.**

“Build එක හරි” කියන්නේ code එක compile වුණා කියන එක. “Button එක වැඩ” කියන්න නම් ඒ button එකෙන් බලාපොරොත්තු වුණු action එක ඇත්තටම වුණාද බලන්න ඕන. “UI එක හරි” කියන්න නම් screenshots විවෘත කරලා බලන්නත් ඕන.

## 1. මුලින්ම පරීක්ෂා කරන දේ පැහැදිලි කරන්න

උදාහරණයකට loan එකකට Rs. 1,000.30ක් තියෙනවා නම්:

| කරන action එක | බලාපොරොත්තු වෙන ප්‍රතිඵලය |
| --- | --- |
| Rs. 200.10ක් ගෙවනවා | Rs. 800.20ක් ඉතිරි වෙනවා |
| ඊට පස්සේ Rs. 800.21ක් ගෙවන්න උත්සාහ කරනවා | Error එකක් එනවා; අලුත් payment එකක් save වෙන්නේ නැහැ |
| වැරදියට දැමූ payment එක ඉවත් කරනවා | ඉතිරි මුදල නිවැරදිව නැවත ගණනය වෙනවා |

Success message එක විතරක් බලලා නවත්වන්න එපා. Database එකේ save වුණු rows සහ balances බලන්න. Expected answer එකත් පරීක්ෂා කරන function එකම භාවිතා කරලා ගණනය කරන්න එපා; එතකොට එකම වැරැද්ද test එකටත් යන්න පුළුවන්.

## 2. ඇත්ත data වෙනුවට test data එකක් භාවිතා කරන්න

Tests වලට අලුත් temporary database එකක් සහ fake records භාවිතා කරනවා. LifeOS එකේ `LIFEOS_DATA_DIR` කියන environment variable එකෙන් test data directory එක තෝරනවා. මේක LifeOS-specific දෙයක්; වෙන app එකක ඒ app එකට අදාළ test configuration එක හොයන්න ඕන.

ඇත්ත app එක force-close කිරීම, real records මකා දැමීම හෝ test fixtures real database එකට දැමීම QA ක්‍රමයක් නෙවෙයි. අසාර්ථක test එකක logs/screenshots investigate කරන තුරු තබාගන්න.

## 3. QA එක layers කිහිපයකින් කරන්න

| Layer එක | ඇයි බලන්නේ? |
| --- | --- |
| Syntax / build | Code parse/compile වෙනවද? |
| Service / database | ගණන්, validation, transactions, save/reopen වැඩ කරනවද? |
| HTTP | Forms, routes, sessions, permissions, CSRF වැඩ කරනවද? |
| UI logic | Double-click එකකින් duplicate save වෙනවද? Loading state clear වෙනවද? |
| Actual window / browser | සැබෑ app එකේ button, navigation, timer, minimize වැඩ කරනවද? |
| Visual review | Text කියවන්න පුළුවන්ද? Buttons කපිලාද? Layers වැරදිද? |
| Release verification | Test කළ filesම install වෙලාද? පරණ data ඉතිරිද? |

එක layer එකක pass වුණා කියලා අනෙක් layer එකත් හරි කියන්න බැහැ.

## 4. වැරදෙන්න පුළුවන් තැන් පරීක්ෂා කරන්න

සාමාන්‍ය success case එකට අමතරව invalid date, empty input, overpayment, duplicate submit, වැරදි parent record එකක් හරහා වෙන record එක වෙනස් කිරීම වගේ අවස්ථා බලන්න. Failed operation එකකින් database එක අඩක් විතර වෙනස් වෙලා නැද්ද බලන්න. මේකට transaction rollback කියනවා.

UI එකේත් boundaries වැදගත්. LifeOS title bar එක හදද්දී Quick Add dialog එක විවෘත කළාම HTML window buttons block වෙන ප්‍රශ්නයක් හම්බවුණා. සාමාන්‍ය screen එකේ screenshot එකකින් ඒක දකින්න බැහැ; dialog එකත් විවෘත කරලා test කරන්න ඕන.

## 5. Screenshots ඇත්තටම විවෘත කරලා බලන්න

LifeOS වල light/dark දෙකේම 1280, 1024 සහ 720 widths වල screenshots ගත්තා. Empty page, data තියෙන page, forms, dialog සහ unlock screen වගේ වෙනස් states බැලුවා.

Text contrast, clipping, horizontal scroll, header overlap, focus outlines සහ long content බලන්න. වැරැද්දක් හම්බවුණාම fix කරලා අදාළ screenshot එක නැවත ගන්න.

**PNG files හදලා තිබීම visual QA pass එකක් නෙවෙයි.** Images බලන්න tool එකක් නැත්නම් “visual review කරලා නැහැ” කියලා පැහැදිලිව ලියන්න.

## 6. Evidence එක තබාගන්න

Run එකකට අලුත් folder එකක් හදලා command logs, exit codes, JSON results සහ screenshot paths තබාගන්න. Test කළ source files වල SHA-256 hashes ගන්නවා. ඒක file එකේ fingerprint එකක් වගේ දෙයක්. Test අතරතුර source වෙනස් වුණොත් result එක අලුත් code එක ගැන සාක්ෂියක් නෙවෙයි.

Report එකේ මේ statuses වෙන් කරන්න:

- **Passed:** check එක run කරලා assertion එක හරි ගියා.
- **Failed:** බලාපොරොත්තු වුණු behavior එක ලැබුණේ නැහැ.
- **Blocked:** අවශ්‍ය runtime/tool/environment එක නැහැ.
- **Not run:** මේ run එකට check එක ඇතුළත් කළේ නැහැ.
- **Pending visual review:** screenshots තියෙනවා; තවම බලලා නැහැ.

Tests 100ක් pass වුණා කියන්නේ ඉල්ලපු features 100ම හැදුවා කියන එක නෙවෙයි. Feature coverage එක වෙනම බලන්න ඕන.

## LifeOS එකේ එක command එකකින් run කරන්න

Project root එකේ PowerShell terminal එකෙන්:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/Invoke-QA.ps1
```

මේක syntax, service, HTTP සහ UI lifecycle checks run කරනවා. Logs සහ `summary.json` තියෙන්නේ `tests/.tmp/qa-<timestamp-id>/` එකේ. Console එකේ exact path එක ලියනවා. සාමාන්‍යයෙන් exit code `0` කියන්නේ automated checks pass; nonzero එකක් ආවොත් report එක කියවන්න.

Actual desktop UI එකත් isolated test data එක්ක බලන්න නම්:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/Invoke-QA.ps1 -Desktop
```

`-Desktop` එක current launcher source එක නැවත publish කරලා temporary data එක්ක actual WebView2 checks run කරනවා. `desktop-result.json` එකෙන් screenshots තියෙන තැන හොයාගන්න. ඒ images විවෘත කරලා බලන්න. Diagnostic instance එකට වෙනම data folder එකක් සහ mutex එකක් තිබෙන නිසා සාමාන්‍ය LifeOS window එක සමඟ run කළ හැකියි; personal records වෙනස් කරන්නේ නැහැ. Performance මනින විට අමතර resource usage අඩු කරන්න සාමාන්‍ය window එක කැමැත්තෙන් close කළ හැකියි.

PHP 8.3 සහ අවශ්‍ය extensions, Composer test dependencies, Python 3 සහ Node.js අවශ්‍යයි. Desktop checks වලට Windows, .NET 8 SDK, WebView2 සහ `dist/php/` runtime එකත් අවශ්‍යයි. Missing dependency එකක් test pass එකක් නෙවෙයි. විස්තර [English README](README.md) එකේ තියෙනවා.

Runner එක update install කරන්නේ නැහැ. Source/test logs වලින් visuals approve කරන්නේත් නැහැ. මේ task එකේ දැනට open user app එක close කිරීම හෝ නැවත install කිරීම අවශ්‍ය නැහැ.

## Update එකක් install කරන වෙලාවේ data රකින විදිහ

User update එක install කරන්න කියලා තියෙන release එකකදී මුලින්ම actual install/data path එක confirm කරන්න. App එක සහ අනෙක් writers නවත්වලා database එකේ consistent backup API එකෙන් backup ගන්න. Live SQLite `.db` file එක විතරක් copy කළොත් WAL එකේ තියෙන අලුත් writes මගහැරෙන්න පුළුවන්.

LifeOS `scripts/verify-update-data.py` එක database backup එකක් හදලා row counts/hashes සහ upload hashes සටහන් කරනවා. Update එකෙන් පස්සේ ඒවා සසඳනවා. **ඒ helper එක upload files copy කරන්නේ නැහැ**; ඒවාට backup එකක් ඕන නම් වෙනම ගන්න. Backup එක සහ hash snapshot එක වෙන වෙනම reads නිසා writers නවත්වලා තබන්න. මේක restore test එකක් වෙනුවට භාවිතා කරන්නත් බැහැ.

UI-only update එකකට පරණ rows නොවෙනස්ව තියෙනවද කියලා සසඳන්න පුළුවන්. Database migration එකකට old data අලුත් schema එකට නිවැරදිව හැරුණාද කියන expectations වෙනම test කරන්න ඕන. Hash check fail වුණා කියලා business tables ignore list එකකට දාලා pass කරන්න එපා.

## වෙන AI agent කෙනෙකුට දෙන්න

1. [AGENT-PROMPT.md](AGENT-PROMPT.md) එකේ prompt එක copy කරන්න.
2. Project path, test කරන change එක සහ expected behavior එක පුරවන්න.
3. [README.md](README.md) එකත් දෙන්න.
4. “Tests actually run, screenshots actually inspected, evidence paths සහ remaining gaps දෙන්න” කියන්න.

වෙන project එකකට මේ ක්‍රමය ගෙනියද්දී commands, test data setting සහ UI driver එක ඒ project එකට ගැළපෙන්න වෙනස් කරන්න. LifeOS scripts වෙන app එකකට නිකම් copy කරලා run කිරීම ප්‍රමාණවත් නැහැ.
