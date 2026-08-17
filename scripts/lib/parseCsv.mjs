/**
 * Minimal RFC-4180 CSV reader. Tilda's export quotes any field containing the
 * separator, a newline or a quote, and escapes quotes by doubling them — so a
 * split on the separator corrupts product descriptions, which routinely span
 * several lines.
 *
 * @param {string} text
 * @param {string} sep
 * @returns {string[][]} rows of raw cell strings
 */
export function parseCsv(text, sep = ';') {
  const rows = [];
  let row = [];
  let cell = '';
  let quoted = false;

  const src = text.replace(/\r\n/g, '\n');

  for (let i = 0; i < src.length; i++) {
    const ch = src[i];

    if (quoted) {
      if (ch === '"') {
        if (src[i + 1] === '"') {
          cell += '"';
          i++;
        } else {
          quoted = false;
        }
      } else {
        cell += ch;
      }
      continue;
    }

    if (ch === '"') quoted = true;
    else if (ch === sep) {
      row.push(cell);
      cell = '';
    } else if (ch === '\n') {
      row.push(cell);
      rows.push(row);
      row = [];
      cell = '';
    } else cell += ch;
  }

  if (cell !== '' || row.length) {
    row.push(cell);
    rows.push(row);
  }

  return rows.filter((r) => r.some((c) => c.trim() !== ''));
}

/** Rows as objects keyed by the header row. */
export function parseCsvRecords(text, sep = ';') {
  const [header, ...rest] = parseCsv(text, sep);
  const keys = header.map((h) => h.trim());
  return rest.map((cells) => Object.fromEntries(keys.map((k, i) => [k, (cells[i] ?? '').trim()])));
}
