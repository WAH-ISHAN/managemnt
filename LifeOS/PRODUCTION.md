# Production releases and updates

The active GitHub Actions workflow is `/.github/workflows/lifeos-release.yml`.
Open a PR against `main`: QA and the Windows launcher build must pass.
Merging to `main` runs verification, builds the installer and update package,
checks update delivery, then publishes a GitHub Release. A manual workflow run
on `main` can also publish a new build. PR runs never publish releases.

Download `LifeOS-Setup.exe` from the latest release for the first installation.
Existing installations containing the 1.8 updater check shortly after launch
and every 30 seconds while open (including while hidden in the Windows tray)
and online. New published builds trigger a Windows tray notification with the
system alert sound, even when a previous build of the same version is staged.
They download and verify the package, then apply it on the next launch or
through the update action in Settings. The alert follows release publication,
not the initial Git push: GitHub Actions verification and building can take
several minutes. A sub-minute guarantee measured from the Git push needs a
separate online push-notification service and cannot be promised by polling the
release feed. Offline machines and devices with notifications or sound disabled
also cannot be guaranteed audible alerts. Personal data
is excluded from update packages and the updater takes a database backup.
Older installations without this updater must install the new setup once,
over the same location, to receive subsequent updates.

The repository and release downloads must be publicly accessible: the desktop
app does not carry GitHub credentials. Enable GitHub Actions and allow the
workflow its declared contents-write permission. Release asset names are
case-sensitive: the manifest and parser use `LifeOS-update.zip`.

The Windows app and installer remain unsigned until a trusted code-signing
certificate is configured. `build.ps1` is signing-ready: pass the certificate's
40-character public thumbprint with `-SigningCertificateThumbprint` and provide
`signtool.exe` with `-SignTool` (or put it on `PATH`). The build signs both
`LifeOS.exe` and `LifeOS-Setup.exe` with SHA-256 plus an HTTPS RFC 3161 timestamp,
then fails unless Windows validates both signatures. Never put a PFX, password,
private key or certificate export in the repository or build arguments. The
certificate must be installed in the build account's Windows certificate store
by the release owner or signing service.

For GitHub Actions, store the base64-encoded PFX and its password only in the
`LIFEOS_SIGNING_PFX_BASE64` and `LIFEOS_SIGNING_PFX_PASSWORD` repository secrets.
The ephemeral release runner imports the non-exportable key for the build, removes
the temporary PFX before invoking build tools and removes the certificate before
the step ends. Set repository variable `LIFEOS_REQUIRE_SIGNING=true` to make an
unsigned release fail closed. This configuration is a release-owner step; neither
the PFX nor its password belongs in source, workflow variables or build arguments.

Unsigned builds are still supported and are recorded as such in
`Output/LifeOS-release-verification.json`; they must not be represented as signed.
Validate installation and upgrading on clean Windows 10/11 before distributing
broadly. A successful CI release is required; local source changes alone do not
publish an installer or deliver an update.

The Orean website's `/lifeos` page reads the latest published manifest and exposes
its installer only when the matching download is reachable. It automatically
tracks subsequent stable releases. No executable is checked into the website repo.

Release packaging includes the updater, its PHP backup helper, the Windows
startup helper and (when stamped by CI) the installed release identity. Run
`pwsh -File tests/update_package.ps1` for a portable fixture check. Full Windows
installer delivery still requires the release workflow to complete successfully.

## Release hygiene gate

The release verifier rejects debug symbols, databases, environment files,
credential files, private-key/certificate exports, development directories and
reparse points from the shipped payload. Local `dist/data` is intentionally left
untouched so a developer build cannot erase personal records; Inno Setup excludes
that directory from the installer and preserves installed data on uninstall.
Generated `dist/` and `Output/` files are ignored working artifacts, not evidence
that current source has passed release gates. Use only artifacts from the current
successful workflow run, together with its QA and release-verification evidence.
The workflow publishes `LifeOS-release-verification.json` and
`LifeOS-build-inputs.json` beside each future release. The latter records exact
compiler/runtime/prerequisite hashes and versions without recording secrets.
Inno Setup 6.6.1 or newer is required; stored payload timestamps are suppressed to
reduce otherwise irrelevant output differences. Authenticode timestamps and
upstream prerequisite updates still mean releases are not claimed to be bit-for-bit
reproducible.
