// Scaled amounts, as a cook would write them. Runs against the compiled
// src/common/amount.ts (see "test" in package.json).
import assert from 'node:assert/strict';
import { decimal, fraction, isPlural, matchCase, measure } from '../../.test-build/amount.js';

const fractions = [
  [3, '3'],
  [1.5, '1½'],
  [0.75, '¾'],
  [1 / 3, '⅓'],
  [2 / 3, '⅔'],
  [0.125, '⅛'],
  [0.01, '⅛'], // never "0 tsp"
  [0.97, '1'],
  [2.9, '2⅞'],
  [3.01, '3'],
  [24.4, '24'],
];
for (const [value, expected] of fractions) assert.equal(fraction(value), expected, `fraction(${value})`);

assert.equal(decimal(1.5, 'en'), '1.5');
assert.equal(decimal(1.5, 'de'), '1,5');
assert.equal(decimal(0.255, 'en'), '0.26');
assert.equal(decimal(12.34, 'en'), '12.3');
assert.equal(decimal(237, 'en'), '235');
assert.equal(decimal(1250, 'en'), '1,250');

assert.equal(isPlural(1, null), false);
assert.equal(isPlural(0.5, null), false);
assert.equal(isPlural(2, null), true);
assert.equal(isPlural(1, 2), true);

assert.equal(matchCase('cup', 'Cups'), 'Cup');
assert.equal(matchCase('cups', 'cup'), 'cups');

// Field values are measurements: exact, never rounded to a nice number.
assert.equal(measure(123, 'en'), '123');
assert.equal(measure(123 * 1.5, 'en'), '184.5');
assert.equal(measure(12.345, 'de'), '12,345');
assert.equal(measure(0.1 + 0.2, 'en'), '0.3');

console.log('amount: ok');
