/**
 * Parses Google Drive and OneDrive share URLs to usable file IDs / embed URLs.
 */

export interface DriveFile {
  provider: 'google' | 'onedrive';
  fileId: string;
  embedUrl: string;
  directUrl: string;
}

export function parseDriveUrl(url: string): DriveFile | null {
  if (!url) return null;
  const trimmed = url.trim();

  // ── Google Drive ──────────────────────────────────────────────────────────
  const googlePatterns = [
    /drive\.google\.com\/file\/d\/([a-zA-Z0-9_-]+)/,
    /drive\.google\.com\/open\?id=([a-zA-Z0-9_-]+)/,
    /docs\.google\.com\/.*?\/d\/([a-zA-Z0-9_-]+)/,
    /[?&]id=([a-zA-Z0-9_-]{20,})/,
  ];
  for (const pat of googlePatterns) {
    const m = trimmed.match(pat);
    if (m) {
      const fileId = m[1];
      return {
        provider: 'google',
        fileId,
        embedUrl: `https://drive.google.com/file/d/${fileId}/preview`,
        directUrl: `https://drive.google.com/uc?export=download&id=${fileId}&confirm=t`,
      };
    }
  }

  // ── OneDrive ──────────────────────────────────────────────────────────────
  // Share link formats:
  //   https://1drv.ms/v/s!XXXXX
  //   https://onedrive.live.com/...
  //   https://xxx.sharepoint.com/...
  if (
    trimmed.includes('1drv.ms') ||
    trimmed.includes('onedrive.live.com') ||
    trimmed.includes('sharepoint.com')
  ) {
    const embedUrl = trimmed.includes('embed')
      ? trimmed
      : trimmed.replace('/view', '/embed');
    const directUrl = trimmed.includes('download')
      ? trimmed
      : trimmed
          .replace('view.aspx', 'download.aspx')
          .replace('/view?', '/download?')
          .replace('/view', '/download');
    return {
      provider: 'onedrive',
      fileId: trimmed, // full URL used as ID for OneDrive
      embedUrl,
      directUrl,
    };
  }

  return null;
}

export function isDriveUrl(url: string): boolean {
  if (!url) return false;
  return (
    url.includes('drive.google.com') ||
    url.includes('docs.google.com') ||
    url.includes('1drv.ms') ||
    url.includes('onedrive.live.com') ||
    url.includes('sharepoint.com')
  );
}

export function isCloudUrl(url: string): boolean {
  return isDriveUrl(url);
}
