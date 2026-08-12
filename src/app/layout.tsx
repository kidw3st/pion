import type { Metadata } from 'next';
import { Montserrat } from 'next/font/google';
import { CartProvider } from '@/components/Cart/CartContext';
import { CartDrawer } from '@/components/Cart/CartDrawer';
import { Header } from '@/components/Header/Header';
import { CartButton } from '@/components/Header/CartButton';
import { WebMcpTools } from '@/components/AgentTools/WebMcpTools';
import { VkBlock } from '@/components/VkBlock/VkBlock';
import { Footer } from '@/components/Footer/Footer';
import { getSite } from '@/lib/content';
import './globals.css';

const montserrat = Montserrat({
  subsets: ['latin', 'cyrillic'],
  weight: ['300', '400', '500', '600', '700', '800'],
  variable: '--font-montserrat',
});

export const metadata: Metadata = {
  title: 'Салон цветов и подарков «Пион»',
  description: 'Букеты и подарки с доставкой по Перми',
};

export default function RootLayout({ children }: { children: React.ReactNode }) {
  // The VK band sits above the footer on every page of the live site, not just
  // the homepage, so it belongs to the layout rather than to any one page.
  const site = getSite();

  return (
    <html lang="ru" className={montserrat.variable}>
      <body>
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
