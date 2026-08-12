import type { Metadata } from 'next';
import { Montserrat } from 'next/font/google';
import { CartProvider } from '@/components/Cart/CartContext';
import { CartDrawer } from '@/components/Cart/CartDrawer';
import { Header } from '@/components/Header/Header';
import { CartButton } from '@/components/Header/CartButton';
import { WebMcpTools } from '@/components/AgentTools/WebMcpTools';
import { VkBlock } from '@/components/VkBlock/VkBlock';
import { Footer } from '@/components/Footer/Footer';
import { JsonLd } from '@/components/JsonLd/JsonLd';
import { getSite } from '@/lib/content';
import { SITE_URL, localBusinessJsonLd } from '@/lib/seo';
import './globals.css';

const montserrat = Montserrat({
  subsets: ['latin', 'cyrillic'],
  weight: ['300', '400', '500', '600', '700', '800'],
  variable: '--font-montserrat',
});

export const metadata: Metadata = {
  metadataBase: new URL(SITE_URL),
  // Pages set their own title; this is the suffix they are wrapped in, and the
  // fallback for any route that does not.
  title: {
    default: 'Салон цветов «Пион» — доставка букетов в Перми',
    template: '%s | Салон цветов «Пион», Пермь',
  },
  description:
    'Авторские букеты, композиции и подарки с доставкой по Перми. Салон «Пион», ул. Газеты Звезда, 27. Ежедневно с 10:00 до 22:00.',
};

export default function RootLayout({ children }: { children: React.ReactNode }) {
  // The VK band sits above the footer on every page of the live site, not just
  // the homepage, so it belongs to the layout rather than to any one page.
  const site = getSite();

  return (
    <html lang="ru" className={montserrat.variable}>
      <body>
        <JsonLd data={localBusinessJsonLd()} />
        <CartProvider>
          <Header />
          {children}
          <VkBlock data={site.vk} />
          <Footer />
          <CartButton />
          <CartDrawer />
          <WebMcpTools />
        </CartProvider>
      </body>
    </html>
  );
}
