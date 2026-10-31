# LifeOS 1.4.0 — සම්පූර්ණ පරිශීලක මාර්ගෝපදේශය

**LifeOS** කියන්නේ Windows පරිගණකයකින් පෞද්ගලිකව භාවිතා කරන, දවසේ වැඩ, ඉගෙනීම, මුදල්, ඉලක්ක සහ තමන්ට වෙන් කරගන්නා කාලය එක තැනක සැලසුම් කරන්න හදපු app එකක්. 2026-10-28 release එක 1.4.0. දත්ත ඔබේ පරිගණකයේම SQLite database එකක සුරකිනවා; app එකට online account එකක් අවශ්‍ය නැහැ.

## 1. Windows app එක ආරම්භ කරන්න

Start Menu එකේ හෝ Desktop එකේ **LifeOS** shortcut එක අරින්න. App එකේ එක window එක විවෘත වෙයි. පළමු වතාවට නම සහ අවශ්‍ය workspace අංශ තෝරාගෙන හිස් workspace එකක් හදාගන්න. ඔබට ඇතුළත් කළ records නැත්නම් balances, goals, study results හෝ achievements හදා පෙන්වන්නේ නැහැ. පෙර සකස් කළ account/category නම් සහ allocation percentages ඇත්නම් ඒවා ආරම්භක settings පමණයි; එම accounts වල balances සහ budgets ශූන්‍යයි.

LifeOS web server එක 127.0.0.1 නමැති ඔබේ පරිගණකයේම loopback ලිපිනයේ පමණක් ක්‍රියාත්මක වෙනවා; අනෙක් පරිගණකවලින් app එකට සම්බන්ධ වෙන්න බැහැ. App එකට WebView2 runtime එක අවශ්‍යයි. Windows පරිගණකයක runtime එක නැත්නම් installer එක එය ඇතුළත් කර තිබේ. App window එක වහන විට app server එකත් වසා දමනවා.

## 2. ප්‍රධාන පිටු සහ ඉක්මන් භාවිතය

Sidebar එකෙන් දිනපතා භාවිත කරන **Today, Planner, Money, Investments, University, Goals** සහ **Your freedom** පිටු විවෘත කරන්න. අනෙක් විශේෂාංග **More tools** යටතේ තියෙනවා.

- **+ Add** මෙනුවෙන් expense, income, task, goal, assignment හෝ document එකක් එක් කරන්න.
- **Find** හෝ `Ctrl+K` භාවිතයෙන් පිටුවක් හෝ ක්‍රියාවක් හොයන්න.
- `Ctrl+N` නව task එකක් එක් කරන තැනට යනවා.
- `Ctrl+Shift+F` Focus පිටුව විවෘත කරනවා.
- `Ctrl+1` සිට `Ctrl+9` දක්වා sidebar පිටු ඉක්මනින් විවෘත කරනවා.
- Theme බොත්තමෙන් System, Light සහ Dark වර්ණ සැකසුම් මාරු කරන්න.

Page එකක් load වෙන්න ප්‍රමාද වුණොත් ඉහළින් loading line එකක් පේනවා. Form එකක Save බොත්තම ඉක්මනින් නැවත නැවත එබීමෙන් එකම දත්ත duplicate වීම වළක්වනවා. UI transitions අඩු චලන preference එක ගරු කරනවා.

## 3. Today සහ Planner

**Today** පිටුව ඔබ ඇත්තටම සටහන් කළ වැඩ, study, personal time, spending සහ income එක දිනෙන් දින පෙන්වනවා. Date එකක් තෝරා පෙර දවසේ හෝ පසුගිය දින හතේ සටහන් පරීක්ෂා කරන්න. සටහන් නැත්නම් කෘත්‍රිම progress score එකක් හදන්නේ නැහැ. දින සම්බන්ධ යෝජනා සුරකින ලද task, assignment, exam, appointment සහ bill දිනයන්ගෙන් ගන්නවා.

**Planner** පිටුවේ දවස් හතේ picker එකෙන් වෙනත් දිනයක් තෝරන්න; Previous week, Next week සහ Back to today යොදා සතිය මාරු කරන්න. එදිනට නියමිත වැඩ, routines, check-ins, appointments සහ habits බලන්න.

**Tasks** යටතේ task නමෙන් සොයන්න. All tasks, Open, Overdue හෝ Completed ලෙස filter කරන්න. Overdue කියන්නේ තෝරාගත් දිනයට පෙර due වී තවම අවසන් නොකළ tasks. සෙවුමට ගැළපෙන මුළු ගණන පෙන්වන අතර වරකට tasks 200ක් දක්වා පෙන්වයි; වැඩි පරණ task එකක් සෙවීමට සෙවුම තවත් සීමා කරන්න. Task එකක් edit, done, reopen හෝ delete කළත් filter සහ දිනය රැකෙනවා.

**Habits** තුළ දවසේ check-ins සහ streak බලන්න. **Routines** තුළ සතියේ දිනයන් සහ වේලාවන් සකසා, එදින routine එක සම්පූර්ණ කළ බව සටහන් කරන්න. **Appointments** තුළ වේලාව, ස්ථානය සහ reminder එක ඇතුළත් කරන්න. Habit check-in සහ routine completion ඉදිරි දිනයකට සටහන් කරන්න බැහැ.

## 4. Money සහ Salary plan

**Money** තුළ ආදායම් සහ වියදම් සටහන් කර accounts, allocations, categories, monthly budgets සහ cashflow බලන්න. Expense එකක් තෝරාගත් account balance එක අඩු කරනවා; income එකට සකස් කළ allocation rules අදාළ වෙනවා. Account එකට වඩා වැඩි වියදමක් සටහන් වුණොත් balance එක සෘණ විය හැකියි.

ආදායම් වෙන් කිරීමේ ආරම්භක rules: Needs 50%, Savings 20%, Business 20%, Fun 10%. ඒවා Settings වලින් ඔබේ තත්ත්වයට ගැළපෙන ලෙස වෙනස් කරන්න. Active percentages එකතුව 100% ඉක්මවන්න බැහැ. ඉතිරි කොටස Unallocated වෙත යනවා. Rounding නිසාත් සෑම සතයක්ම එකම වරක් allocate වෙන ලෙස බෙදනවා. Allocation rules අලුත් income සඳහායි; දැනටමත් සටහන් කළ transaction එකක් වෙනස් නොකරයි.

**Salary plan** තුළ මාසික ආදායම, bills, loan payments, living costs, savings/investment percentages සහ emergency fund සැලසුම් කරන්න. 12 මාස projection එක interest, salary growth හෝ market returns උපකල්පනය කරන්නේ නැහැ. සුරකින plan එකක් තනිවම ඇත්ත transaction හෝ allocation rules වෙනස් කරන්නේ නැහැ. අනාගත income සඳහා plan එකේ rules යොදන්න ඕන නම් ඒ සඳහා ඇති **Use these allocation rules** ක්‍රියාව පැහැදිලිව යොදන්න. Projection එකක් සැබෑ වියදමක්, ලැබුණු ආදායමක් හෝ ආයෝජන ලාභයක් කියලා සලකන්න එපා.

Financial calendar එකේ bill/payment dates සහ recurring income/expense entries සැලසුම් කරන්න. Auto-generated transactions අනුපිටපත් නොවීමට duplicate protection තියෙනවා. Asset එකකට deposit, withdrawal, valuation සහ business revenue/cost ඇතුළත් කළ හැකියි. Net worth එක active asset values සහ account balances එකතු කර පෙන්වනවා. Valuation, investment return හෝ වෙනත් financial outcome app එක පුරෝකථනය කිරීමක් නොවේ; ඔබ ඇතුළත් කළ වාර්තා මතයි.

## 5. University, goals සහ career

**University** තුළ modules, credits, grade map, assignments, exams සහ සතියේ timetable සටහන් කරන්න. GPA ගණනය වන්නේ Settings වල ඔබ හදා ඇති grade point map එකට ගැළපෙන සහ ප්‍රතිඵල දන්නා credits වලින් පමණයි. ඔබේ පාසලේ හෝ විශ්වවිද්‍යාලයේ grade scale එක වෙනස් නම් භාවිතයට පෙර එය Settings වල සකසන්න. Assignment එකක් overdue වුණ පසුවත් එය ඔබ done කරන තුරු ලැයිස්තුවෙන් මකා නොදමයි.

**Goals** තුළ ඉලක්ක, ඒවාට අදාළ steps, milestones සහ prerequisites දමන්න. Prerequisite අවසන් නොවූ විට එයට යටත් step එක අවහිර විය හැකියි. **Career** තුළ skills, courses, job applications සහ interviews ලියා තබන්න.

**Lessons & growth** තුළ පසුගිය වැරැද්දක්, හේතුව, ඉගෙනගත් දේ සහ ඊළඟ ක්‍රියාව සටහන් කරන්න. Lesson එකකට action task එකක් ස්වයංක්‍රීයව සම්බන්ධ වෙනවා. Task එක සම්පූර්ණ කළොත් lesson එක applied වෙනවා; එය වෙනම තහවුරු කරලා verified කරන්න. Weekly review එක ISO week එකකට එක් entry එකක් සුරකිනවා. Repeated-failure warning එක මෑත දින 90ක entries මත පදනම් වෙනවා.

## 6. Focus, freedom, insights болон reminders

**Focus** нь үргэлжлэх хугацаатай study/focus болон break timer ажиллуулж, төгссөн session එක සටහන් කරනවා. App එක වසා දැමුවත් timer data සුරකිනවා. **Insights** තුළ date-based reminders, overdue dates, payments සහ budgets සමාලෝචනය කර අවශ්‍ය alerts dismiss කරන්න.

Settings තුළ Windows reminders ක්‍රියාත්මක කළොත් Windows notifications app එක විවෘතව පවතින අතරතුර පෙන්වයි. App එක වසා තිබියදී background notification service එකක් සක්‍රියව පවත්වාගෙන යන්නේ නැහැ.

**Your freedom** ඔබ දා ඇති linked savings සහ essential cost plan අනුව financial runway පෙන්වයි. විකල්පයක් ලෙස working week context සහ health, rest, relationships, experiences සහ learning සඳහා වෙන් කරන personal time සටහන් කරන්න. සැබෑ සටහන් මත ගණනය කරන නිසා අනුමාන “freedom score” එකක් නැහැ.

## 7. Documents, exports සහ backups

**Documents** තුළ certificates, CVs болон бусад файлыг laptop дээр хадгалж, хайж, ангීකරණය කර download කරන්න. Delete කළ files recover කළ හැකි Trash එකට යනවා.

LifeOS භාවිතයේදී daily SQLite snapshot එකක් සෑදෙන අතර අලුත්ම දින snapshots 14 තබාගන්නවා. Manual සහ before-restore snapshots ඉවත් කරන තුරු තියෙනවා. Plain database export/snapshot එකක upload කළ files ඇතුළත් වෙන්නේ නැහැ.

Uploaded documents සමඟ backup එකක් හදාගන්න **Encrypted complete backup** තෝරා ශක්තිමත් passphrase එකක් සකසන්න. Database සහ documents authenticated encryption භාවිතයෙන් එකම archive එකකට යනවා. Passphrase එක app එකට ගබඩා වෙන්නේ නැහැ; අමතක වුණොත් backup එක නැවත ඇරීමට නොහැකි වෙයි. Restore කරන විට app එක archive එක validate කර, database integrity සහ schema checks කර, live records මාරු කිරීමට පෙර safety snapshot එකක් හදනවා. Restore කිරීමෙන් PIN sessions නැවත ආරම්භ කරවන්න වෙනවා. Backup එකක් වෙනම external disk එකකටත් පිටපත් කර තබන්න.

Settings තුළ CSV exports ලබාගන්න හෝ report එක Print / Save as PDF මඟින් PDF එකකට සුරකින්න. CSV එකක් empty table එකකටත් headers ඇතුළත් කරන අතර PIN/session settings export නොකරයි. Wide reports සඳහා Print dialog එකේ landscape තෝරන්න.

## 8. PIN සහ පෞද්ගලිකත්වය

Settings තුළ optional PIN එකක් යොදන්න. PIN එක hash කර සුරකිනවා; එය app එක විවෘත කිරීමට access gate එකක්. **PIN එක database එක හෝ plain backups encrypt කරන්නේ නැහැ.** Computer එකේ files වෙනම ආරක්ෂා කර තබන්න. Laptop එකට password/BitLocker වැනි device encryption යොදා ගැනීම ඔබේ Windows settings වලින් වෙනම කළ හැකියි.

LifeOS account එකකට signup වීමක් හෝ cloud sync එකක් අවශ්‍ය කරන්නේ නැහැ. App එකේ libraries, PHP server සහ database local install/data folder තුළ ක්‍රියා කරනවා. Windows notification පෙළට අදාළ OS behaviour වෙනම විය හැකියි. Support එකකට screenshots හෝ backup යවනවා නම් එහි පෞද්ගලික දත්ත තිබිය හැකි බව සලකා බලන්න.

## 9. Version 1.4 update එකෙන් දත්ත රැකීම

සාමාන්‍ය per-user installation path එක `%LOCALAPPDATA%\\Programs\\LifeOS` වුණත් ඔබ වෙනත් folder එකක් තෝරා තිබිය හැකියි. Database එක `<install folder>\\data\\lifeos.db`; uploads, backups, sessions සහ WebView profile එකත් ඒ `data` folder එකේමයි.

Version 1.4 installer එකේ application files පමණක් ඇතුළත්. Install කරන විට කලින් භාවිතා කළ LifeOS install folder එකම තෝරන්න. `data` folder එක වෙනත් තැනකට ගෙන යන්න හෝ මකා දමන්න එපා. Update එකээс පෙර encrypted complete backup үත්ම හදාගැනීම හොඳ පුරුද්දක්. Inno Setup upgrade එක existing install entry එක හඳුනාගෙන application files යාවත්කාලීන කරනවා; installer package එක local `data` folder එක අඩංගු නොවේ.

මෙම workspace එකේ v1.4 update එකෙන් පසු database එකේ pre-update SHA-256 hash එක ඒ ආකාරයෙන්ම පවතින බව තහවුරු කළා. පෙර update එකට backup එක `%LOCALAPPDATA%\\LifeOS-recovery\\pre-v1.4-...` යටතේ සුරකිනවා. `...` වෙනුවට timestamp එකක් තිබෙනවා; ඔබේ පරිගණකයේ backup folder එකේ නම පරීක්ෂා කරන්න.

## 10. Smart features සහ ඉදිරි සැලසුම්

1.4 තුළ generative AI, Sinhala voice/text parsing හෝ cloud intelligence ඇතුළත් නැහැ. එම අලුත් features යෝජනා මට්ටමේයි. ඉදිරියේදී වැදගත් එකක් වන්නේ `heta 6ta study 45min` හෝ `lunch 850` වැනි Sinhala/English quick capture එකකින් task, appointment හෝ expense preview එකක් සාදා, date සහ amount නිවැරදි කර පසුව පමණක් save කරන්න දීමයි. මෙවැනි smart capture එකක් සෑදුවොත් ගැටලුවක් ඇති amount/date එක ස්වයංක්‍රීයව save නොවන preview-first සැලසුම වැදගත්.

## 11. Source code එකෙන් run කිරීම

Development සඳහා Windows 10/11 x64, PHP 8.3 සහ `pdo_sqlite`, `sqlite3`, `mbstring`, `openssl`, `zip`, `fileinfo` extensions අවශ්‍යයි. Project folder එකෙන්:

```powershell
php -S 127.0.0.1:8787 -t public public/index.php
```

ඉන්පසු browser එකෙන් `http://127.0.0.1:8787` විවෘත කරන්න; `Ctrl+C` මඟින් server එක නවත්වන්න. Development database එක project එකෙන් පිටත වෙනම තැනක තැබීමට server එකට පෙර `LIFEOS_DATA_DIR` environment variable එක යොදන්න.

## 12. Developer tests සහ build

PHPUnit සඳහා Composer development dependencies ස්ථාපනය කරන්න:

```powershell
composer install
php vendor/phpunit/phpunit/phpunit -c phpunit.xml
php tests/services.php
php tests/finance_planning.php
php tests/planner_growth_career.php
php tests/planner_search.php
php tests/documents_encryption.php
php tests/freedom.php
php tests/daily_progress.php
python tests/http_acceptance.py
node tests/ui_lifecycle.cjs
```

ඇත්ත Windows installer එක test කිරීමට `Output/LifeOS-Setup.exe` සහ disposable Windows profile එකක් භාවිතා කර `./tests/Windows-Smoke.ps1` run කරන්න. මෙම test එක පෞද්ගලික install එක උඩ overwrite කිරීම ප්‍රතික්ෂේප කරනවා. Installer එක build කිරීමට .NET 8 SDK, Inno Setup 6, PHP 8.3 NTS x64, සහ Microsoft WebView2 Evergreen Standalone x64 installer අවශ්‍යයි. Build prerequisite installers වල signatures පරීක්ෂා කරනවා. Build එක local `dist/data` රැකගෙන installer payload එකෙන් බැහැර කරනවා.

## 13. අදාළ files

- [විස්තරාත්මක developer README](README.md)
- [Version 1.4 release සහ verification වාර්තාව](UPDATE-1.4.md)
- [LifeOS logo සහ එහි නිර්මාණ prompt](public/assets/brand/README.md)
- Installer: `Output/LifeOS-Setup.exe`; SHA-256 checksum: `Output/LifeOS-Setup.exe.sha256`

---

LifeOS කියන්නේ ඔබ දාපු තොරතුරු පිළිවෙළට තියාගන්න පුද්ගලික මෙවලමක්. අධ්‍යාපන, ආයෝජන, බදු, ණය හෝ වෙනත් වෘත්තීය තීරණ සඳහා අවශ්‍ය නම් අදාළ පුද්ගලයාගෙන් වෘත්තීය උපදෙස් ලබාගෙන, app එකට ඇතුළත් කරලා තියෙන තොරතුරු නිවැරදිද කියලා ඔබම පරීක්ෂා කරන්න.
