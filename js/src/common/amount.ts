/**
 * Formatting a scaled amount the way a cook would write it.
 *
 * The server has already READ every amount (src/Quantity.php) and written the
 * number into the card; this file only multiplies and writes it back. There is
 * deliberately no ingredient parser on this side — see Quantity's comment.
 */

const GLYPHS: [number, string][] = [
  [1 / 8, '⅛'],
  [1 / 4, '¼'],
  [1 / 3, '⅓'],
  [3 / 8, '⅜'],
  [1 / 2, '½'],
  [5 / 8, '⅝'],
  [2 / 3, '⅔'],
  [3 / 4, '¾'],
  [7 / 8, '⅞'],
];

/**
 * Kitchen fractions: "1½", "⅓", "2¾". Above 20 the fraction stops being worth
 * reading and the number is rounded to a whole one.
 *
 * Never rounds a real amount down to nothing: a pinch scaled to a sixteenth
 * still reads ⅛, because "0 tsp salt" is a recipe that is wrong.
 */
export function fraction(value: number): string {
  if (value >= 20) return String(Math.round(value));

  let whole = Math.floor(value);
  const rest = value - whole;

  let best = '';
  let bestDiff = rest; // rounding down to the whole number
  for (const [amount, glyph] of GLYPHS) {
    const diff = Math.abs(rest - amount);
    if (diff < bestDiff) {
      bestDiff = diff;
      best = glyph;
    }
  }
  if (1 - rest < bestDiff) {
    whole += 1;
    best = '';
  }

  if (whole === 0 && best === '') return '⅛';

  return (whole > 0 ? String(whole) : '') + best;
}

/**
 * Decimals for metric amounts: "1.5", "250", "0.25" — in the reader's locale,
 * so a German forum reads "1,5".
 *
 * Precision falls as the number grows; 237.6 g of flour is not a measurement
 * anybody makes.
 */
export function decimal(value: number, locale?: string): string {
  const digits = value >= 100 ? 0 : value >= 10 ? 1 : 2;
  const rounded = value >= 100 ? Math.round(value / 5) * 5 || Math.round(value) : value;

  return rounded.toLocaleString(locale, { maximumFractionDigits: digits, minimumFractionDigits: 0 });
}

export function format(value: number, useDecimal: boolean, locale?: string): string {
  return useDecimal ? decimal(value, locale) : fraction(value);
}

/** Is this displayed amount more than one, for "1 cup" against "2 cups"? */
export function isPlural(value: number, upper: number | null): boolean {
  return upper !== null || value > 1.0001;
}

/** Match the author's capitalisation: "Cups" scaled to one is "Cup". */
export function matchCase(word: string, like: string): string {
  return like && like[0] === like[0].toUpperCase() && like[0] !== like[0].toLowerCase() ? word[0].toUpperCase() + word.slice(1) : word;
}
