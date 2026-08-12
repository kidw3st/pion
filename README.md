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

## Deploying to GitHub Pages

Every push to `master` runs `.github/workflows/deploy-pages.yml`: tests, static
build, publish. The site is served from `https://kidw3st.github.io/pion/`.

Pages has to be switched on once by the repo owner before the first deploy —
**Settings → Pages → Build and deployment → Source: "GitHub Actions"**. The
default workflow token can't do this itself.

The `/pion` base path is only applied in CI (via `GITHUB_PAGES=true`); local
`npm run dev` and `npm run build` still serve the site from the root.

## Agent discovery

`scripts/build-agent-assets.mjs` runs before every build and emits, into
`public/`: `robots.txt` with Content Signals, `llms.txt`, static catalogue JSON
under `api/`, an Agent Skills index under `.well-known/agent-skills/`, and
markdown copies of every page under `md/`.

`WebMcpTools` registers WebMCP tools (`navigator.modelContext.provideContext`)
for shop info, listing, searching and adding to cart. These work today, at any
path — they are plain client-side JavaScript.

Two things this hosting cannot do, and one thing it should not:

- **`Link:` response headers and `Accept: text/markdown` negotiation** need
  control over HTTP responses. GitHub Pages serves fixed headers, and a static
  export has no server to negotiate with. The markdown copies under `md/` are
  the static stand-in. Moving to Cloudflare Pages, Netlify or Vercel (a
  `_headers` file, or Next's `headers()` with a Node server) would enable both.
- **`robots.txt` and `.well-known/` only count at the origin root.** This site
  is served from `kidw3st.github.io/pion/`, so the generated files sit at
  `/pion/robots.txt` where no crawler looks. Attaching a custom domain (e.g.
  pointing pionperm.ru or a subdomain at Pages) puts them at the root and they
  start working unchanged.
- **OAuth/OIDC discovery, OAuth Protected Resource metadata, `auth.md` and an
  MCP Server Card are deliberately not published.** They describe
  authorization servers, token endpoints, protected resources and an MCP
  transport. This site has no backend, no API and no auth, so those documents
  would point at endpoints that do not exist. They belong here only once there
  is something real to authenticate against.

DNS-AID records are a DNS-zone change on the domain (plus DNSSEC), not a file
in this repository.

## Design constraint: no backend

This project is intentionally frontend-only. There is no server, database, or payment
integration behind checkout or the bouquet-builder popup — both are UI stubs that collect
input and validate it client-side, but do not submit orders or process payments anywhere.
This is a deliberate scope boundary, not an oversight: the goal was to reproduce the site's
look and browsing experience, not to build order fulfillment.
