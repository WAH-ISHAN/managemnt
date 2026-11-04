# School features 65–78

School is an independent folder module at `/school`. The school persona enables it; existing school profiles receive its module preference through a new migration. Kid mode permits the School routes and shows its link. These are narrow integration changes to the persona list and kid access policy.

The workspace includes subjects/colours, dated homework, weekly timetable/materials and date-scoped packed checks, one-time completion XP/levels/badges, guardian-PIN reward approvals with atomic XP spending, pocket-money ledger/goals, tuition/payment periods, exam dates/topic completion, paper attempts/mistake corrections, reading, manually recorded study/screen time and reminder alerts, weekly parent report, sports/clubs, explicitly shared mood trends, and assigned household chores. Records can be edited and archived; linked references prevent premature subject/class/exam archival.

Reports use the selected Monday–Sunday week. Private mood entries and all mood notes are excluded. The owner can print/share a report explicitly; nothing is sent over WhatsApp automatically. Guardian decisions require a currently linked guardian's own PIN and are audited. Rewards do not create purchases or financial transfers. Family board assignments are local household records within the child's profile; this does not silently share another profile's private records.

Screen reminders use manually entered minutes and the configured local time; they do not monitor or enforce operating-system use. Alerts are available while LifeOS is running. Dates and exam topics are entered by the user; no exam dates or syllabus are invented.

Verification commands: `php tests/school.php`, `php tests/school_guardian.php`, `php tests/profiles_packs.php`, `php tests/persona.php`, `python tests/http_acceptance.py`. Native Windows visual/accessibility testing is separate from these service/render and HTTP regression checks.
