# Knowledge Hub: 20 new capabilities

Research references: [Joplin](https://github.com/laurent22/joplin#readme) describes local-first, searchable, tagged Markdown notes and import/export; [AppFlowy](https://github.com/AppFlowy-IO/AppFlowy#readme) uses structured workspaces. LifeOS adapts these ideas for one person's existing local SQLite workspace. No code or visual assets were copied.

1. Capture a new note.
2. Edit an existing note.
3. Organize notes in named notebooks.
4. Add up to ten tags per note.
5. Search titles and note bodies.
6. Filter by notebook.
7. Filter by tag.
8. Pin important notes to the top.
9. Archive notes without deleting them.
10. Move notes to Trash.
11. Restore notes from Trash.
12. Duplicate a note.
13. Open a dated daily journal (or start its draft).
14. Start from a meeting template.
15. Start from a project brief template.
16. Start from a learning template.
17. Link notes with `[[note title]]` references.
18. Find notes that link back to the current note.
19. Import a UTF-8 Markdown file (up to 50 KB).
20. Export an individual note as Markdown.

Additional conveniences: recent or title sorting, word count, keyboard command search entries, and a responsive layout. The editor stores plain text; Markdown syntax is preserved for export rather than rendered as rich HTML. Notes stay in LifeOS's normal SQLite database and therefore participate in existing backups. The list shows up to 200 matching notes at once; searching and filtering narrow large collections. Imported files do not include attachments. There is no external sync or AI data transfer.

Validation: `php tests/knowledge.php`, `php tests/module_loading.php`, `php tests/fresh_workspace.php`, `php tests/services.php`, and the full Windows `scripts/Invoke-QA.ps1` workflow. The service checks cover migrations, filtering, escaping, backlinks, archive, trash, daily note, Markdown import, and input rejection.
