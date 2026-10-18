# Recipes: working rules

- **Commit straight to `main` and push. Never open a pull request on this repo.**
- Commit subjects are conventional (`feat:`, `fix:`, `docs:`, `chore:`). The release drafter reads the bump from the subject.
- `js/dist` is committed and is what forums load. After any change under `js/src`, run `npm run build` in `js/` and commit the bundle; CI fails if it is stale.
- Publishing a release is Ernest's click. Nothing is announced until he says so.
- Every string is translatable (`locale/en.yml`); server-side labels also have an English fallback in `Formatter/Labels.php`.

## Design rules that exist for a reason

- **One text format, one reader.** Every editor stores the same text (documented in `src/Recipe.php`). The server reads it at RENDER time (`Formatter/Render.php`); the parser only marks where a block is. Do not move parsing into the parser: a PHP callback there needs a JS twin for s9e's preview, and a parse bug would then be baked into stored posts.
- **Nothing in the start tag.** Autolink, HTMLEntities and Emoji reach into a start tag and tear it apart. Everything lives in the block's content, opaque behind `ignoreTags`.
- `<article>` is the Scribe element; `js/src/forum/node.ts` and `Formatter/Configure.php` must agree on it, or Scribe silently drops recipes at parse time.
- Amounts are parsed only in PHP (`Quantity.php`). The browser multiplies what the server wrote into `data-q`; never add a second parser in JS.
- Hooks into Scribe and FoF Rich Text run inside THEIR module evaluation. Every hook is fenced with try/catch (`editors.ts`): a throw there broke Rich Text's composer for everyone.
- The registry hands over a module's default export (the class), not a namespace.
- The composer preview is drawn by `data-s9e-livepreview-onrender`, synchronously, before s9e's diff. Keep `preview.ts` synchronous.
- JSON-LD: only a `Recipe`, only the first post, visibility via `whereVisibleTo`. No query when the page's own first post has no card.
- `recipe_cards` is an INDEX, never the source: rows are rebuilt from the post on Posted/Revised (`Index\KeepIndexCurrent`) and backfilled by migration / `recipes:reindex`. Where a recipe lives (discussion, number, author) is joined from `posts` at read time, and who may see it is `Post::whereVisibleTo()` at read time (`Index\RecipeQuery`). Never store or trust visibility in the index.
- Constructor dependencies are REQUIRED. Laravel's container fills `?Foo $x = null` with null, which silently broke the based-on URL and JSON-LD `isBasedOn` in the first build.
- Variants store `based-on: <post id>`. The card links through `/recipes/from/{id}`, which resolves visibility as the visitor; `forum/related.ts` names the original and lists variants with ONE request per page.
- Remix rebuilds the text from the rendered card (data attributes carry raw times and the original's id), so anyone may remix what they can read. Its code is a lazy chunk fetched on click.
- `flarum.reg.asyncModuleImport` takes ONE path string (`flarum/forum/components/X`), not namespace + id.
- Custom fields (`src/Fields.php`): ONE JSON setting, validated on every READ (the settings endpoint accepts any string). Values are numbers only, stored as header lines in the recipe text; a header key not defined (a removed field) is kept hidden and survives edits (`source.ts` carries it). Field values are never rounded (`amount.ts` `measure()`, not `decimal()`, which rounds to 5).
- Admin field editor: core REMOUNTS a customSetting component when its setting first changes. Never keep state that must survive that in the instance (it froze new rows' keys); derive it from the values. Mithril keys must be stable ids, not editable values (focus loss). LESS evaluates `a / b` as division: `grid-column: 2 / 3` silently vanished.
- An input `pattern` is compiled with the `v` flag: escape `-` inside a character class.
- forum.js loads on every page. The card, the form and the preview are lazy chunks with namespaced ids.

## Testing

- `composer test:unit`; integration with `DB_DRIVER=sqlite composer test:setup && composer test:integration` (CI runs the full Flarum matrix).
- `cd js && npm test && npm run check-typings && npm run format-check`.
- Test all three editors before a release: plain composer + Markdown, FoF Rich Text, Scribe.
