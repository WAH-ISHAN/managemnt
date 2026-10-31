# Production releases and updates

The active GitHub Actions workflow is `/.github/workflows/lifeos-release.yml`.
Open a PR against `main`: QA and the Windows launcher build must pass.
Merging to `main` runs verification, builds the installer and update package,
checks update delivery, then publishes a GitHub Release. A manual workflow run
on `main` can also publish a new build. PR runs never publish releases.

Download `LifeOS-Setup.exe` from the latest release for the first installation.
Existing installations containing the 1.8 updater check after 20 seconds and
hourly while open and online. They download and verify the package, then apply
it on the next launch, or through the update action in Settings. Personal data
is excluded from update packages and the updater takes a database backup.
Older installations without this updater must install the new setup once,
over the same location, to receive subsequent updates.

The repository and release downloads must be publicly accessible: the desktop
app does not carry GitHub credentials. Enable GitHub Actions and allow the
workflow its declared contents-write permission. Release asset names are
case-sensitive: the manifest and parser use `LifeOS-update.zip`.

The Windows app and installer remain unsigned until a code-signing certificate
is configured. Validate installation and upgrading on clean Windows 10/11
before distributing broadly. A successful CI release is required; local source
changes alone do not publish an installer or deliver an update.

The Orean website's `/lifeos` page reads the latest published manifest and exposes
its installer only when the matching download is reachable. It automatically
tracks subsequent stable releases. No executable is checked into the website repo.

Release packaging includes the updater, its PHP backup helper, the Windows
startup helper and (when stamped by CI) the installed release identity. Run
`pwsh -File tests/update_package.ps1` for a portable fixture check. Full Windows
installer delivery still requires the release workflow to complete successfully.
