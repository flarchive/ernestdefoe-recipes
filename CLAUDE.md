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
- forum.js loads on every page. The card, the form and the preview are lazy chunks with namespaced ids.

## Testing

- `composer test:unit`; integration with `DB_DRIVER=sqlite composer test:setup && composer test:integration` (CI runs the full Flarum matrix).
- `cd js && npm test && npm run check-typings && npm run format-check`.
- Test all three editors before a release: plain composer + Markdown, FoF Rich Text, Scribe.
