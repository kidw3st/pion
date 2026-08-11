# Pion — pionperm.ru clone

A Next.js 14 (App Router) + TypeScript clone of [pionperm.ru](https://pionperm.ru), a Perm flower shop's site,
built to move the shop off Tilda onto a self-hosted codebase at the owner's request.

## Getting started

```bash
npm install
npm run dev      # http://localhost:3000
npm test         # vitest unit tests
npm run build    # production build (static export of all routes)
```

## Where things live

- `src/app/` — Next.js App Router pages and layouts (routes are mostly driven by
  `src/app/[slug]/page.tsx`, which resolves category and static-page slugs from `src/lib/content.ts`).
- `src/components/` — UI components, colocated with their CSS modules and unit tests.
- `data/` — site content as JSON: `data/site.json` (nav, footer, hero slides, etc.),
  `data/catalog/*.json` (one file per product category), `data/pages/*.json` (static content pages).
- `public/images/` — downloaded product/site imagery referenced by the JSON above.

## Content pipeline

All content in `data/` and `public/images/` was produced by a one-time scrape of the live
pionperm.ru site, not hand-authored. To re-run it:

```bash
node scripts/scrape.mjs
```

The scraper uses Playwright to drive a real browser (needed because Tilda's catalog is a
JS-rendered store widget). It respects the `SCRAPE_BROWSER_CHANNEL` env var to pick which
installed browser channel to drive (defaults to `msedge`):

```bash
SCRAPE_BROWSER_CHANNEL=chrome node scripts/scrape.mjs
```

Some categories (e.g. `korziny`, `balloons`, `wedding`) are genuinely empty on the live site —
that's expected, not a scrape failure.

## Design constraint: no backend

This project is intentionally frontend-only. There is no server, database, or payment
integration behind checkout or the bouquet-builder popup — both are UI stubs that collect
input and validate it client-side, but do not submit orders or process payments anywhere.
This is a deliberate scope boundary, not an oversight: the goal was to reproduce the site's
look and browsing experience, not to build order fulfillment.
