/**
 * The recipe's text, as the composer form edits it.
 *
 * This is the round trip between the form's fields and the text every editor
 * stores (the format is documented in src/Recipe.php). It splits the text into
 * its parts and joins them again; it does not interpret amounts or timers —
 * the server does that, once, when the post is rendered.
 */

export interface RecipeFields {
  title: string;
  servings: string;
  prep: string;
  cook: string;
  image: string;
  description: string;
  ingredients: string;
  steps: string;
}

const KEYS = ['title', 'servings', 'prep', 'cook', 'image'] as const;

export function emptyFields(): RecipeFields {
  return { title: '', servings: '', prep: '', cook: '', image: '', description: '', ingredients: '', steps: '' };
}

/** Text → fields. Mirrors the reader in src/Recipe.php: header, text, two sections. */
export function parse(body: string): RecipeFields {
  const fields = emptyFields();
  const description: string[] = [];
  const ingredients: string[] = [];
  const steps: string[] = [];
  let state: 'head' | 'text' | 'ingredients' | 'steps' = 'head';
  let seen = false;

  for (const raw of body.split(/\r\n|\r|\n/)) {
    const line = raw.trim();
    const section = /^\[(\/?)(ingredients|steps)\]$/i.exec(line);

    if (section) {
      state = section[1] === '/' ? 'text' : (section[2].toLowerCase() as 'ingredients' | 'steps');
      continue;
    }

    if (state === 'head') {
      if (line === '') {
        if (seen) state = 'text';
        continue;
      }

      const kv = /^(title|servings|prep|cook|image)\s*:\s*(.*)$/i.exec(line);
      if (kv) {
        fields[kv[1].toLowerCase() as (typeof KEYS)[number]] = kv[2].trim();
        seen = true;
        continue;
      }

      state = 'text';
    }

    if (state === 'ingredients') {
      if (line) ingredients.push(line);
    } else if (state === 'steps') {
      if (line) steps.push(line);
    } else if (line || (description.length && description[description.length - 1] !== '')) {
      description.push(line);
    }
  }

  fields.description = description.join('\n').trim();
  fields.ingredients = ingredients.join('\n');
  fields.steps = steps.join('\n');

  return fields;
}

/**
 * Fields → text.
 *
 * 🚨 A line in the description that happens to look like a header key
 * ("Note: …" is fine, "Title: …" is not) would be read back as one. The header
 * always ends with a blank line before the description starts, which is what
 * keeps the two apart — and an empty header gets nothing at all, so a recipe
 * with no fields set does not start with a stray blank line.
 */
export function serialize(input: RecipeFields): string {
  const fields = { ...input };
  for (const key of Object.keys(fields) as (keyof RecipeFields)[]) fields[key] = unclose(fields[key]);

  const out: string[] = [];

  for (const key of KEYS) {
    const value = oneLine(fields[key]);
    if (value) out.push(`${key}: ${value}`);
  }

  const description = fields.description.trim();
  if (description) {
    if (out.length) out.push('');
    out.push(...description.split(/\r\n|\r|\n/).map((line) => line.trimEnd()));
  }

  for (const section of ['ingredients', 'steps'] as const) {
    const lines = lists(fields[section]);
    if (!lines.length) continue;
    if (out.length) out.push('');
    out.push(`[${section}]`, ...lines, `[/${section}]`);
  }

  return out.join('\n');
}

/** The block as the plain composer, Markdown and FoF Rich Text store it. */
export function bbcode(fields: RecipeFields): string {
  return `[recipe]\n${serialize(fields)}\n[/recipe]`;
}

export function isEmpty(fields: RecipeFields): boolean {
  return !fields.title.trim() && !lists(fields.ingredients).length && !lists(fields.steps).length;
}

/**
 * 🚨 `[/recipe]` ANYWHERE in the text — mid-sentence too — ends the BBCode in
 * the plain composer, and everything after it becomes ordinary post text. It
 * is never something a recipe means to say.
 */
function unclose(value: string): string {
  return value.replace(/\[\/recipe\]/gi, '');
}

function oneLine(value: string): string {
  return value.replace(/\s+/g, ' ').trim();
}

/** A list line that reads `[/steps]` would close the section early; it is never an ingredient. */
function lists(value: string): string[] {
  return value
    .split(/\r\n|\r|\n/)
    .map((line) => line.trim())
    .filter((line) => line && !/^\[\/?(recipe|ingredients|steps)\]$/i.test(line));
}
