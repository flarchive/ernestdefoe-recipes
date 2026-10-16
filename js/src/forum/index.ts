import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import type ItemList from 'flarum/common/utils/ItemList';
import CommentPost from 'flarum/forum/components/CommentPost';
import TextEditor from 'flarum/common/components/TextEditor';
import TextEditorButton from 'flarum/common/components/TextEditorButton';
import { registerWithRichText, registerWithScribe } from './editors';
import { openForm } from './node';

declare const flarum: { reg?: { checkModule(namespace: string, id: string): any | false } };

/**
 * Scribe's editor is actually running here — not merely installed.
 *
 * Asking whether its registry LOADED is the honest question: Scribe can be
 * enabled yet stood down (it does so when FoF Rich Text is on), and Scribe
 * before 1.2.0 has no registry. Either way the plain composer button is then
 * the only way to insert a recipe, so it must stay. `checkModule`, not `get`:
 * `get` throws for a missing module when debug mode is on.
 */
function scribeEditorActive(): boolean {
  return typeof flarum !== 'undefined' && !!flarum.reg && flarum.reg.checkModule('ernestdefoe-scribe', 'registry') !== false;
}

/** Where the cursor sits inside a `[recipe]…[/recipe]` block of a plain textarea, if it does. */
function blockAt(text: string, cursor: number): { start: number; end: number; body: string } | null {
  const lower = text.toLowerCase();
  const start = lower.lastIndexOf('[recipe]', cursor);
  if (start === -1) return null;

  const close = lower.indexOf('[/recipe]', start);
  if (close === -1 || close + '[/recipe]'.length < cursor) return null;

  return { start, end: close + '[/recipe]'.length, body: text.slice(start + '[recipe]'.length, close).replace(/^\n|\n$/g, '') };
}

app.initializers.add('ernestdefoe/recipes', () => {
  registerWithScribe();
  registerWithRichText();

  /*
   * 🚨 `refreshContent`, not `oncreate`: it runs on create AND update, so a
   * reply that arrives live or a post edited in place gets its card too.
   * An oncreate hook only sees the first paint — the bug reloading "fixes".
   */
  extend(CommentPost.prototype, 'refreshContent', function (this: any) {
    const element = this.element as HTMLElement;
    // The card's code is fetched by the first page that has a card on it.
    if (element?.querySelector('.Recipe:not(.Recipe--source)')) import('./card').then((card) => card.default(element));
  });

  /*
   * The composer preview's hook (see Formatter/Configure). Loaded when a
   * composer opens rather than with the page: the preview must be drawn
   * synchronously when s9e asks, and a reader who never posts never needs it.
   */
  let preview: ((source: HTMLElement) => void) | null = null;
  (window as any).ernestdefoeRecipes = {
    preview: (source: HTMLElement) => preview?.(source),
  };

  extend(TextEditor.prototype, 'oninit', function () {
    if (!preview) import('./preview').then((module) => (preview = module.default));
  });

  /*
   * The button for Flarum's own composer — plain text, Markdown, and FoF Rich
   * Text, which keeps the composer's toolbar items and parses what this
   * inserts as Markdown (editors.ts teaches it the block).
   *
   * Left out while Scribe's editor runs: Scribe's registry supplies its own
   * button there, and two buttons doing one job is one too many.
   */
  if (scribeEditorActive()) return;

  extend(TextEditor.prototype, 'toolbarItems', function (this: any, items: ItemList<any>) {
    items.add(
      'ernestdefoe-recipe',
      TextEditorButton.component(
        {
          icon: 'fas fa-utensils',
          onclick: () => {
            const editor = this.attrs.composer.editor;
            const textarea: HTMLTextAreaElement | null = editor?.el instanceof HTMLTextAreaElement ? editor.el : null;

            // In a plain textarea, a cursor inside an existing block edits that block.
            const existing = textarea ? blockAt(textarea.value, textarea.selectionStart) : null;

            openForm(existing?.body, (body) => {
              const block = `[recipe]\n${body}\n[/recipe]`;

              if (existing) {
                editor.insertBetween(existing.start, existing.end, block);
                return;
              }

              // On a line of its own: FoF Rich Text only reads the block there.
              const before = editor.getLastNChars(1);
              editor.insertAtCursor((before && before !== '\n' ? '\n\n' : '') + block + '\n', false);
            });
          },
        },
        app.translator.trans('ernestdefoe-recipes.lib.composer.button')
      ),
      -5
    );
  });
});
