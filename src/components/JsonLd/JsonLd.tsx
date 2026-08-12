/**
 * Renders a structured-data block. The payload is built server-side from the
 * site's own data files, never from user input, so serialising it into the
 * script tag is safe — the `<` escape guards against a stray sequence closing
 * the tag early if a product title ever contains markup.
 */
export function JsonLd({ data }: { data: object }) {
  return (
    <script
      type="application/ld+json"
      dangerouslySetInnerHTML={{
        __html: JSON.stringify(data).replace(/</g, '\\u003c'),
      }}
    />
  );
}
