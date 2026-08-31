import type { Metadata } from 'next';
import { Montserrat } from 'next/font/google';
import { CartProvider } from '@/components/Cart/CartContext';
import { CartDrawer } from '@/components/Cart/CartDrawer';
import { Header } from '@/components/Header/Header';
import { CartButton } from '@/components/Header/CartButton';
import { WebMcpTools } from '@/components/AgentTools/WebMcpTools';
import { ScrollTop } from '@/components/ScrollTop/ScrollTop';
import { MessengerFab } from '@/components/MessengerFab/MessengerFab';
import { CookieNotice } from '@/components/CookieNotice/CookieNotice';
import { VkBlock } from '@/components/VkBlock/VkBlock';
import { Footer } from '@/components/Footer/Footer';
import { JsonLd } from '@/components/JsonLd/JsonLd';
import { getSite } from '@/lib/content';
import { SITE_URL, localBusinessJsonLd } from '@/lib/seo';
import './globals.css';

// Montserrat is a variable font, so all of these weights arrive in one file per
// subset (~56 KB for latin + cyrillic) — listing fewer would not save a byte.
// The list is exactly what the stylesheets use; 800 was declared and never used.
const montserrat = Montserrat({
  subsets: ['latin', 'cyrillic'],
  weight: ['300', '400', '500', '600', '700'],
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
  // A static host sends no headers of ours, so the one security policy that a
  // document can declare for itself is set here. HSTS, X-Content-Type-Options
  // and cookie flags are response headers — they have to come from whatever
  // serves the files.
  referrer: 'strict-origin-when-cross-origin',
};

/**
 * Content-Security-Policy, declared in the document because a static host
 * sends no headers of ours. The site loads nothing from other origins — every
 * script, style, font and image is exported alongside the pages — so anything
 * an injected string might try to pull from outside is refused outright.
 * 'unsafe-inline' stays only because Next's own bootstrap and the JSON-LD
 * blocks are inline scripts; a static export has no nonces to pin them with.
 * (frame-ancestors does not work from a meta tag — clickjacking protection
 * has to come from the host's headers; GitHub Pages sends none.)
 */
// mc.yandex.ru везде ниже — Яндекс.Метрика: тот же счётчик, что стоит на
// текущем pionperm.ru, чтобы статистика не оборвалась при переезде.
const CSP = [
  "default-src 'self'",
  // Dev needs eval (react-refresh source maps); the production build must not.
  `script-src 'self' 'unsafe-inline' https://mc.yandex.ru${process.env.NODE_ENV === 'development' ? " 'unsafe-eval'" : ''}`,
  "style-src 'self' 'unsafe-inline'",
  "img-src 'self' data: https://mc.yandex.ru",
  "font-src 'self'",
  // Dev HMR talks over a websocket; production only fetches its own JSON.
  `connect-src 'self' https://mc.yandex.ru${process.env.NODE_ENV === 'development' ? ' ws:' : ''}`,
  "object-src 'none'",
  "frame-src 'none'",
  "base-uri 'self'",
  "form-action 'self'",
].join('; ');

const METRIKA_ID = 93951387;

/** Официальный сниппет Метрики; вебвизор и карта кликов — как на счётчике Tilda-версии. */
const METRIKA_SNIPPET = `
(function(m,e,t,r,i,k,a){m[i]=m[i]||function(){(m[i].a=m[i].a||[]).push(arguments)};
m[i].l=1*new Date();k=e.createElement(t),a=e.getElementsByTagName(t)[0],
k.async=1,k.src=r,a.parentNode.insertBefore(k,a)})
(window,document,"script","https://mc.yandex.ru/metrika/tag.js","ym");
ym(${METRIKA_ID},"init",{clickmap:true,trackLinks:true,accurateTrackBounce:true,webvisor:true});
`;

export default function RootLayout({ children }: { children: React.ReactNode }) {
  // The VK band sits above the footer on every page of the live site, not just
  // the homepage, so it belongs to the layout rather than to any one page.
  const site = getSite();

  return (
    <html lang="ru" className={montserrat.variable}>
      <head>
        <meta httpEquiv="Content-Security-Policy" content={CSP} />
      </head>
      <body>
        <script dangerouslySetInnerHTML={{ __html: METRIKA_SNIPPET }} />
        <noscript>
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img
            src={`https://mc.yandex.ru/watch/${METRIKA_ID}`}
            style={{ position: 'absolute', left: '-9999px' }}
            alt=""
          />
        </noscript>
        <JsonLd data={localBusinessJsonLd()} />
        <CartProvider>
          <Header />
          {children}
          <VkBlock data={site.vk} />
          <Footer />
          <CartButton />
          <ScrollTop />
          <MessengerFab />
          <CookieNotice />
          <CartDrawer />
          <WebMcpTools />
        </CartProvider>
      </body>
    </html>
  );
}
