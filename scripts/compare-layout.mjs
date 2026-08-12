// Layout diff between the live site and the local rebuild.
//
// Measures the same elements on both and prints their box geometry side by
// side, so "does it match?" is answered by numbers instead of by eye. Also
// saves a screenshot of each for a visual check.
//
//   node scripts/compare-layout.mjs                 # header, 1440 + 1866
//   node scripts/compare-layout.mjs header 1600     # one block, one width
//
// Requires the dev server on http://localhost:3000 (npm run dev). Uses a
// system Chromium-based browser via SCRAPE_BROWSER_CHANNEL (default msedge),
// same as scripts/scrape.mjs.

import { chromium } from 'playwright';
import { mkdir } from 'node:fs/promises';

const LIVE = 'https://pionperm.ru';
const LOCAL = 'http://localhost:3000';
const SHOT_DIR = 'tmp/layout-shots';

// Each block knows how to find its own elements on either site. Selectors are
// deliberately loose (the live site is Tilda markup, ours is our own) — they
// match on role and text, not on class names.
const BLOCKS = {
  header: {
    clip: { x: 0, y: 0, height: 110 },
    measure: () => {
      const vis = (el) => el && el.offsetParent !== null;
      const R = (el) => {
        if (!el) return null;
        const r = el.getBoundingClientRect();
        return { x: Math.round(r.x), y: Math.round(r.y), w: Math.round(r.width), h: Math.round(r.height) };
      };
      const hdr = [...document.querySelectorAll('.t228, header')]
        .filter(vis)
        .find((e) => e.getBoundingClientRect().height > 40);
      if (!hdr) return { _error: 'header not found' };

      const links = [...hdr.querySelectorAll('a')];
      const byText = (t) => links.find((l) => (l.textContent || '').trim().toUpperCase() === t);
      const out = {
        header: { ...R(hdr), bg: getComputedStyle(hdr).backgroundColor },
        logo: R(hdr.querySelector('img')),
      };
      ['КАТАЛОГ', 'АКЦИИ', 'ДОСТАВКА', 'О НАС', 'КОНТАКТЫ', 'UDS'].forEach((t) => {
        out['nav:' + t] = R(byText(t));
      });
      out.phone = R(links.find((a) => (a.getAttribute('href') || '').startsWith('tel:')));
      links
        .filter((a) => /vk\.com|t\.me|wa\.me/.test(a.href || ''))
        .forEach((a, i) => { out['social' + i] = R(a); });
      out.orderBtn = R(links.find((a) => /СДЕЛАТЬ ЗАКАЗ/i.test((a.textContent || '').trim())));
      return out;
    },
  },

  hero: {
    clip: { x: 0, y: 90, height: 630 },
    // The carousel rotates on both sites, and slides differ in height, so a
    // straight comparison would be measuring two different slides. Pin both to
    // the first slide before reading anything.
    prepare: () => {
      const dot = document.querySelector(
        '.t-slds__bullet, [aria-label="Перейти к слайду 1"]',
      );
      if (dot instanceof HTMLElement) dot.click();
    },
    measure: () => {
      const vis = (el) => el && el.offsetParent !== null;
      const R = (el) => {
        if (!el) return null;
        const r = el.getBoundingClientRect();
        return { x: Math.round(r.x), y: Math.round(r.y), w: Math.round(r.width), h: Math.round(r.height) };
      };
      const S = (el, prop) => (el ? getComputedStyle(el).getPropertyValue(prop) : null);

      // Live markup is a Tilda t734 slider; ours is a single rendered slide.
      const block = document.querySelector('.t734') || document.querySelector('main > div');
      if (!block) return { _error: 'hero not found' };

      // Only the on-screen slide matters — the live slider keeps the others
      // parked off to the side.
      const slides = [...block.querySelectorAll('.t-slds__item')].filter(vis);
      const slide =
        slides.find((s) => s.getBoundingClientRect().x >= 0 && s.getBoundingClientRect().x < 50) || block;

      const title = slide.querySelector('[class*="title"], h1, h2');
      const descr = slide.querySelector('[class*="descr"], p');
      const btn = slide.querySelector('a[class*="btn"], a[class*="button"]');
      const arrows = [...block.querySelectorAll('[class*="arrow"]')].filter(
        (a) => vis(a) && a.getBoundingClientRect().width < 80,
      );
      const dots = [...block.querySelectorAll('[class*="bullet"], [class*="dot"]')].filter(
        (d) => vis(d) && d.getBoundingClientRect().width < 40,
      );

      return {
        hero: { ...R(block), h: Math.round(block.getBoundingClientRect().height) },
        title: { ...R(title), size: S(title, 'font-size'), weight: S(title, 'font-weight'), color: S(title, 'color') },
        subtitle: { ...R(descr), size: S(descr, 'font-size'), weight: S(descr, 'font-weight') },
        button: {
          ...R(btn),
          bg: S(btn, 'background-color'),
          border: S(btn, 'border-top-width'),
          size: S(btn, 'font-size'),
        },
        arrowL: R(arrows[0]),
        dotsCount: dots.length,
      };
    },
  },
};

async function measure(page, url, block, width, shotPath) {
  await page.goto(url, { waitUntil: 'load', timeout: 60000 });
  await page.waitForTimeout(3500);
  if (block.prepare) {
    await page.evaluate(block.prepare);
    await page.waitForTimeout(1500);
  }
  const data = await page.evaluate(block.measure);
  data._docWidth = await page.evaluate(() => document.documentElement.scrollWidth);
  if (shotPath) {
    await page.screenshot({ path: shotPath, clip: { ...block.clip, x: 0, width } });
  }
  return data;
}

const blockName = process.argv[2] || 'header';
const widths = process.argv[3] ? [Number(process.argv[3])] : [1440, 1866];
const block = BLOCKS[blockName];
if (!block) {
  console.error(`Unknown block "${blockName}". Known: ${Object.keys(BLOCKS).join(', ')}`);
  process.exit(1);
}

await mkdir(SHOT_DIR, { recursive: true });
const browser = await chromium.launch({ channel: process.env.SCRAPE_BROWSER_CHANNEL || 'msedge' });
let worstDelta = 0;

for (const width of widths) {
  const page = await browser.newPage({ viewport: { width, height: 900 } });
  const real = await measure(page, LIVE, block, width, `${SHOT_DIR}/${blockName}-${width}-live.png`);
  const mine = await measure(page, LOCAL, block, width, `${SHOT_DIR}/${blockName}-${width}-local.png`);
  await page.close();

  console.log(`\n=== ${blockName} @ ${width}px ===`);
  if (mine._docWidth > width) {
    console.log(`!! local page overflows horizontally: ${mine._docWidth}px > ${width}px`);
  }

  for (const key of Object.keys(real)) {
    if (key.startsWith('_')) continue;
    const a = real[key];
    const b = mine[key];
    const fmt = (o) => (o && o.w !== undefined ? `x=${o.x} y=${o.y} w=${o.w} h=${o.h}` : String(o));
    let delta = '';
    if (a && b && a.x !== undefined && b.x !== undefined) {
      const dx = b.x - a.x;
      const dy = b.y - a.y;
      const dw = b.w - a.w;
      worstDelta = Math.max(worstDelta, Math.abs(dx), Math.abs(dy), Math.abs(dw));
      delta = dx || dy || dw ? `  <-- Δx=${dx} Δy=${dy} Δw=${dw}` : '  ok';
    }
    console.log(key.padEnd(14), '| live:', fmt(a).padEnd(30), '| local:', fmt(b).padEnd(30), delta);
    if (a?.bg && b?.bg && a.bg !== b.bg) {
      console.log(''.padEnd(14), '| background differs:', a.bg, 'vs', b.bg);
    }
  }
}

console.log(`\nlargest deviation: ${worstDelta}px`);
console.log(`screenshots: ${SHOT_DIR}/`);
await browser.close();
