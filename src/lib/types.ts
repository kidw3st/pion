export interface Product {
  uid: string;
  title: string;
  description: string;
  price: number;
  images: string[];
  slug: string;
}

export type ContentBlock =
  | { type: 'heading'; text: string }
  | { type: 'paragraph'; text: string }
  | { type: 'image'; src: string; alt: string }
  | { type: 'cta'; text: string; href: string };

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
}
