// ─── Core content types shared by the public site and the Admin Panel ────────

export type MediaKind = 'image' | 'video' | 'embed';

/** A single gallery item. `url` is required; everything else is optional metadata. */
export interface GalleryMedia {
  url: string;
  kind?: MediaKind;
  alt?: string;
  caption?: string;
  /** Poster frame for videos */
  poster?: string;
  /** Optional custom framing (e.g. '85% center' or 'center 20%') */
  objectPosition?: string;
}

export type GalleryInput = string | GalleryMedia;

/** Normalizes legacy string galleries and object galleries into GalleryMedia[]. */
export function toGalleryItems(input?: GalleryInput[] | null): GalleryMedia[] {
  if (!input || !Array.isArray(input)) return [];
  return input
    .filter(Boolean)
    .map(item => {
      if (typeof item === 'string') return { url: item } as GalleryMedia;
      return { ...(item as GalleryMedia) };
    });
}

/** Best-effort media kind detection from a URL. */
export function detectMediaKind(url: string): MediaKind {
  const u = (url || '').toLowerCase();
  if (u.includes('drive.google.com') && u.includes('/preview')) return 'embed';
  if (u.includes('1drv.ms') || u.includes('onedrive.live.com') || u.includes('sharepoint.com')) return 'embed';
  if (/\.(mp4|webm|mov|m4v)(\?|$)/.test(u)) return 'video';
  if (u.startsWith('/video/') || u.includes('/video/')) return 'video';
  return 'image';
}

export interface AgencyService {
  id: string;
  code: string;
  number: string;
  title: string;
  shortDesc: string;
  fullDesc: string;
  capabilities: string[];
  deliverables: string[];
  highlight: string;
  previewImage: string;
  status?: ContentStatus;
}

export type ContentStatus = 'published' | 'draft' | 'archived';

export interface ProjectCase {
  id: string;
  code: string;
  slug: string;
  title: string;
  client: string;
  type?: 'photography' | 'video';
  featured?: boolean;
  /** Publishing state — only `published` projects render on the public site */
  status?: ContentStatus;
  category: string;
  categoryLabel: string;
  year: string;
  coverImage: string;
  videoUrl?: string;
  videoPoster?: string;
  heroVideoPoster?: string;
  aspectRatio?: 'landscape' | 'portrait' | 'square' | 'wide';
  /** CSS object-position for the detail-page hero media, e.g. "center 20%" */
  heroPosition?: string;
  summary: string;
  challenge: string;
  solution: string;
  deliverables: string[];
  gallery: GalleryInput[];
  tags: string[];
  stats?: { label: string; value: string }[];
  seoTitle?: string;
  seoDescription?: string;
  socialUrl?: string;
  socialLabel?: string;
  socialPosts?: SocialPostItem[];
  /** Legacy homepage flag for the weddings shortcut tile */
  isWedding?: boolean;
}

export interface SocialPostItem {
  name: string;
  url: string;
  type?: 'reel' | 'post' | 'video' | 'album';
  subtitle?: string;
}

export interface StudioMetric {
  label: string;
  value: string;
  descriptor: string;
}

export interface Testimonial {
  id?: string;
  quote: string;
  author: string;
  role: string;
  avatar: string;
  rating?: number; // 1 to 5, default 5
  timeAgo?: string;
  verified?: boolean; // verified review checkmark
  source?: 'google' | 'direct';
  googleReviewUrl?: string;
}

export interface GoogleReviewsConfig {
  enabled: boolean;
  placeUrl: string;
  writeReviewUrl: string;
  rating: number;
  totalReviews: number;
  badgeLabel?: string;
  lastSyncedAt?: string;
}

export interface WeddingTimelineStage {
  id: string;
  stageNumber: string;
  title: string;
  shortDesc: string;
  fullDesc: string;
  image: string;
  moodNote: string;
  moments: string[];
}

export interface WeddingStory {
  id: string;
  slug: string;
  storyNumber: string;
  couple: string;
  location: string;
  venue: string;
  date: string;
  coverImage: string;
  heroImage: string;
  thumbnail: string;
  storyQuote: string;
  storyParagraphs: string[];
  gallery: { url: string; caption?: string; aspect?: 'portrait' | 'landscape' | 'square'; alt?: string }[];
  videoUrl?: string;
  videoPoster?: string;
  highlights: { title: string; desc: string }[];
  details: {
    photographer: string;
    cinematographer: string;
    cameraFormat: string;
    deliveredFrames: string;
  };
  status?: ContentStatus;
}

export interface WeddingApproachPrinciple {
  number: string;
  title: string;
  desc: string;
}

export interface InquiryRecord {
  id: string;
  name: string;
  email?: string;
  phone?: string;
  service?: string;
  message: string;
  status: 'new' | 'contacted' | 'in-progress' | 'completed' | 'archived';
  source?: 'contact' | 'wedding' | 'manual';
  createdAt: string;
}

export interface ProjectInquiry {
  name: string;
  email: string;
  company: string;
  serviceType: string;
  projectDetails: string;
}

export interface TeamMember {
  id: string;
  name: string;
  role: string;
  department?: string;
  image?: string;
  bio?: string;
  skills?: string[];
  social?: {
    instagram?: string;
    linkedin?: string;
    facebook?: string;
    twitter?: string;
  };
}

