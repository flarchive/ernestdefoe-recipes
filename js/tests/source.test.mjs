// The form's round trip: text → fields → text, and the edges that would break
// a post in the plain composer.
import assert from 'node:assert/strict';
import { bbcode, emptyFields, isEmpty, parse, serialize } from '../../.test-build/source.js';

const body = `title: Pancakes
servings: 4
prep: 10m
cook: 20m
image: https://e.com/p.jpg

A weekend breakfast.

Second paragraph.

[ingredients]
1 1/2 cups flour
For the topping:
200 g berries
[/ingredients]

[steps]
Whisk.
Cook [2m].
[/steps]`;

const fields = parse(body);
assert.equal(fields.title, 'Pancakes');
assert.equal(fields.servings, '4');
assert.equal(fields.image, 'https://e.com/p.jpg');
assert.equal(fields.description, 'A weekend breakfast.\n\nSecond paragraph.');
assert.equal(fields.ingredients, '1 1/2 cups flour\nFor the topping:\n200 g berries');
assert.equal(fields.steps, 'Whisk.\nCook [2m].');
assert.equal(serialize(fields), body, 'round trip is exact');

// Leading blank lines before the header (how the composer lays out a block).
assert.equal(parse('\n\ntitle: X').title, 'X');

// "[/recipe]" anywhere would end the BBCode in the plain composer.
const hostile = { ...emptyFields(), title: 'A [/recipe] B', steps: 'one\n[/steps]\ntwo [/RECIPE] three' };
const out = bbcode(hostile);
assert.equal(out.match(/\[\/recipe\]/gi).length, 1, 'only the closing tag');
assert.ok(out.endsWith('[/recipe]'));
assert.equal(parse(serialize(hostile)).steps, 'one\ntwo  three');

// Empty header: no stray blank line first.
assert.equal(serialize({ ...emptyFields(), steps: 'Stir' }), '[steps]\nStir\n[/steps]');

assert.equal(isEmpty(emptyFields()), true);
assert.equal(isEmpty({ ...emptyFields(), description: 'only words' }), true);
assert.equal(isEmpty({ ...emptyFields(), steps: 'Stir' }), false);

// A variant keeps its original's id through the form.
const variant = parse('title: Vegan pancakes\nbased-on: 42\n\n[steps]\nStir\n[/steps]');
assert.equal(variant.basedOn, '42');
assert.equal(serialize(variant), 'title: Vegan pancakes\nbased-on: 42\n\n[steps]\nStir\n[/steps]');

// The admin's fields: read when known, and an unknown key in a header (a field
// since removed) survives an edit instead of being dropped.
const withFields = parse('title: Soup\nprotein: 12.5\nretired: 9\n\nNice.', ['protein']);
assert.deepEqual(withFields.extra, { protein: '12.5', retired: '9' });
assert.equal(withFields.description, 'Nice.');
assert.equal(serialize(withFields), 'title: Soup\nprotein: 12.5\nretired: 9\n\nNice.');

// A note at the very top is not a header.
assert.equal(parse('Note: best cold\n\n[steps]\nStir\n[/steps]').description, 'Note: best cold');

// Keys that could break the format are never written.
assert.equal(serialize({ ...emptyFields(), title: 'T', extra: { 'bad key': '1', title: 'x', ok: '2' } }), 'title: T\nok: 2');

console.log('source: ok');
