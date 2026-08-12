// Static exports have no image optimisation server, so images are served
// straight from /public. This loader exists for one reason: to prefix local
// image paths with the deploy base path (e.g. /pion on GitHub Pages), which
// Next does apply to routes but not to an unoptimised image's `src`.
//
// Width is ignored on purpose — there is only one file per image to serve.

type LoaderArgs = { src: string };

export default function imageLoader({ src }: LoaderArgs): string {
  const basePath = process.env.NEXT_PUBLIC_BASE_PATH ?? '';
  return src.startsWith('/') ? `${basePath}${src}` : src;
}
