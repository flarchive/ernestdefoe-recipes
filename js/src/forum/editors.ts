import { extend } from 'flarum/common/extend';
import { BUTTON, NODE } from '../common/scribeButton';
import { openForm, recipeNode } from './node';

/**
 * Teaching the two WYSIWYG editors about recipes. Each one is optional; each
 * registration waits for its editor's own module, through `flarum.reg.onLoad`,
 * which fires at once if the module is already there and later if it is not.
 *
 * 🚨 Never `import … from 'ext:…'` for these. That compiles to
 * `flarum.reg.get()`, which is undefined on a forum without the editor, and a
 * throw while this module evaluates aborts the WHOLE bundle — every extension
 * after this one in forum.js silently never loads.
 */

declare const flarum: {
  reg: {
    onLoad(namespace: string, id: string, handler: (module: any) => void): void;
    checkModule(namespace: string, id: string): any | false;
  };
};

const RICH_TEXT = 'fof-rich-text';

/**
 * 🚨 Every handler here runs INSIDE the host editor's own module evaluation —
 * `flarum.reg.add()` calls them synchronously. A throw does not fail our
 * hook; it aborts the editor's chunk, and the composer never opens for
 * anyone. Found exactly that way. So each hook is fenced: if the host
 * changes shape, the editor keeps working without recipes.
 */
function hook(namespace: string, id: string, handler: (exported: any) => void): void {
  flarum.reg.onLoad(namespace, id, (exported: any) => {
    try {
      handler(exported);
    } catch (e) {
      // eslint-disable-next-line no-console
      console.error('[recipes] could not extend', namespace, id, e);
    }
  });
}

/**
 * The registry hands over a module's DEFAULT export — the class itself — not a
 * namespace with `.default` on it. Reading `.default` was the throw above.
 */
const classOf = (exported: any) => (exported?.prototype ? exported : exported?.default);

/**
 * Scribe stores HTML. Its registry hands our factory TipTap's `Node` when it
 * builds an editor, and places the button for admins who have never arranged
 * their toolbar by hand. (The plain composer button in index.ts stands down
 * while Scribe is active — see scribeEditorActive().)
 */
export function registerWithScribe(): void {
  if (typeof flarum === 'undefined' || !flarum.reg) return;

  hook('ernestdefoe-scribe', 'registry', (scribe: any) => {
    scribe.registerExtension?.(({ Node }: any) => recipeNode(Node));
    scribe.registerButton?.({
      ...BUTTON,
      active: (editor: any) => editor.isActive(NODE),
      run: (editor: any) =>
        openForm(undefined, (body) => {
          editor.chain().focus().insertContent({ type: NODE, attrs: { body } }).run();
        }),
    });
  });
}

/**
 * FoF Rich Text stores MARKDOWN. So besides the node, it needs to read a
 * `[recipe]…[/recipe]` block out of Markdown into that node, and write the
 * node back as the same block — which is exactly what the plain composer
 * stores, so a recipe written in either can be edited in the other.
 *
 * All four of its modules live in the editor's lazy chunk; these handlers run
 * when that chunk loads, before the first editor is built from it.
 */
export function registerWithRichText(): void {
  if (typeof flarum === 'undefined' || !flarum.reg) return;

  hook(RICH_TEXT, 'common/tiptap/TiptapEditorDriver', (exported: any) => {
    extend(classOf(exported).prototype, 'buildExtensions', function (items: any) {
      // Same fence, later: this runs each time an editor is built.
      try {
        const kit = flarum.reg.checkModule(RICH_TEXT, 'common/tiptap/tiptap');
        const Node = kit?.Node ?? kit?.default?.Node;
        if (Node) items.add(NODE, recipeNode(Node));
      } catch (e) {
        // eslint-disable-next-line no-console
        console.error('[recipes] could not add the recipe node', e);
      }
    });
  });

  hook(RICH_TEXT, 'common/tiptap/markdown/MarkdownParserBuilder', (exported: any) => {
    const Builder = classOf(exported);
    extend(Builder.prototype, 'buildTokenizer', function (md: any) {
      md.block.ruler.before('fence', 'ernestdefoe_recipe', recipeRule, { alt: ['paragraph', 'reference', 'blockquote', 'list'] });
    });
    extend(Builder.prototype, 'buildTokens', function (tokens: any) {
      tokens.ernestdefoe_recipe = { node: NODE, getAttrs: (token: any) => ({ body: token.content }) };
    });
  });

  hook(RICH_TEXT, 'common/tiptap/markdown/MarkdownSerializerBuilder', (exported: any) => {
    extend(classOf(exported).prototype, 'buildNodes', function (nodes: any) {
      nodes[NODE] = (state: any, node: any) => {
        state.write(`[recipe]\n${String(node.attrs.body || '').trim()}\n[/recipe]`);
        state.closeBlock(node);
      };
    });
  });
}

/**
 * markdown-it block rule: a line that is exactly `[recipe]`, up to a line that
 * is exactly `[/recipe]`, becomes one token holding the raw text between.
 *
 * Raw is the point. Left to markdown-it, the ingredient list becomes a bullet
 * list and `[25m]` a broken link reference, and serialising those back out
 * escapes the brackets — `\[recipe\]` — so the server no longer sees a recipe
 * at all.
 */
function recipeRule(state: any, startLine: number, endLine: number, silent: boolean): boolean {
  const lineText = (n: number) =>
    state.src
      .slice(state.bMarks[n] + state.tShift[n], state.eMarks[n])
      .trim()
      .toLowerCase();

  if (state.sCount[startLine] - state.blkIndent >= 4 || lineText(startLine) !== '[recipe]') return false;

  let end = startLine + 1;
  while (end < endLine && lineText(end) !== '[/recipe]') end++;
  if (end >= endLine) return false;

  if (silent) return true;

  const token = state.push('ernestdefoe_recipe', '', 0);
  token.block = true;
  token.content = state.getLines(startLine + 1, end, 0, false);
  token.map = [startLine, end + 1];
  state.line = end + 1;

  return true;
}
