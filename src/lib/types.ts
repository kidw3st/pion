export interface Product {
  uid: string;
  title: string;
  description: string;
  price: number;
  images: string[];
  slug: string;
}

/**
 * A static page is a sequence of composed sections, mirroring how the live
 * site builds them. A flat list of headings/paragraphs/images loses the
 * layout — every image ends up full width and stacked, which made these pages
 * roughly three times taller than the originals.
 */
export type PageSection =
  | { kind: 'cover'; title: string; subtitle: string; images: string[] }
  | { kind: 'text'; title: string; body: string }
  | { kind: 'cards'; items: { title: string; subtitle: string }[] }
  | { kind: 'quote'; text: string; image: string }
  | { kind: 'gallery'; images: string[] };

/** One tile on the /catalog overview grid. */
export interface CatalogTile {
  label: string;
  href: string;
  image: string;
}

/** Per-category page chrome: cover, optional heading, "не нашли" band flag. */
export interface CategoryMeta {
  title: string | null;
  sub: string | null;
  covers: string[];
  heading: string | null;
  headingSub: string | null;
  hasNotFound: boolean;
}

export interface HeroSlide {
  title: string;
  subtitle: string;
  buttonText: string;
  buttonHref: string;
  image: string;
}

export interface FeaturedProduct {
  title: string;
  subtitle: string;
  price: number;
  image: string;
}

export interface Feature {
  title: string;
  description: string;
  icon: string;
}

/** "Соберите свой идеальный букет" — photo on the left, copy and CTA on the right. */
export interface BouquetBlock {
  title: string;
  text: string;
  buttonText: string;
  image: string;
}

/** Loyalty-programme block: copy and CTA beside a collage of three photos. */
export interface UdsBlock {
  title: string;
  subtitle: string;
  buttonText: string;
  buttonHref: string;
  images: string[];
}

/** VK promo band: heading and CTA above a strip of photos. */
export interface VkBlock {
  title: string;
  text: string;
  buttonText: string;
  href: string;
  images: string[];
  /** Round logo badge shown beside the "go to VK" link. */
  badge: string;
  /** Oversized watermark set behind the photo row. */
  watermark: string;
}

export interface SiteData {
  nav: { label: string; href: string }[];
  phone: string;
  address: string;
  social: { label: string; href: string }[];
  footer: {
    columns: { title: string; links: { label: string; href: string }[] }[];
    legal: string;
    hours: string;
  };
  heroSlides: HeroSlide[];
  newProducts: FeaturedProduct[];
  features: Feature[];
  bouquetBlock: BouquetBlock;
  uds: UdsBlock;
  vk: VkBlock;
}
