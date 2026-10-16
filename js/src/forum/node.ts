import app from 'flarum/forum/app';
import extractText from 'flarum/common/utils/extractText';
import { NODE } from '../common/scribeButton';

export { NODE };

/**
 * The recipe block inside a WYSIWYG editor — Scribe and FoF Rich Text are both
 * TipTap, so both get this one node.
 *
 * An atom: the editor never edits the recipe's text inline. It shows a card
 * with the title, and the card's Edit button opens the same form the plain
 * composer uses. Typing a recipe's structure into a rich-text paragraph is
 * exactly the thing the form exists to spare people.
 *
 * 🚨 Built from the `Node` the HOST editor hands us, never from an import of
 * `@tiptap/core`. Importing it would pull ~430KB into forum.js, which every
 * visitor downloads, and would give the node a second ProseMirror that the
 * editor silently refuses to work with.
 */

/** Enough to label the card; the whole text is read only when the form opens. */
function titleOf(body: string): string {
  return /^\s*title\s*:\s*(.+)$/im.exec(body)?.[1].trim() ?? '';
}

function t(key: string, params?: Record<string, string>): string {
  return extractText(app.translator.trans(`ernestdefoe-recipes.lib.composer.${key}`, params ?? {}));
}

export function openForm(body: string | undefined, onsubmit: (body: string) => void): void {
  app.modal.show(() => import('./RecipeModal'), { body, onsubmit });
}

export function recipeNode(Node: any): any {
  return Node.create({
    name: NODE,
    group: 'block',
    atom: true,
    draggable: true,
    selectable: true,

    addAttributes() {
      return {
        body: {
          default: '',
          parseHTML: (element: HTMLElement) => element.textContent || '',
          // Stored as the element's text, never as an attribute (see renderHTML).
          renderHTML: () => ({}),
        },
      };
    },

    /*
     * 🚨 `<article>`, matching the element src/Formatter/Configure.php aliases
     * onto the RECIPE tag for Scribe. Scribe drops any element its formatter
     * does not know at PARSE time, silently: change one side without the
     * other and posts save with the recipe simply gone.
     */
    parseHTML() {
      return [{ tag: 'article' }];
    },

    /*
     * The recipe is the element's TEXT. As an attribute, the plugins Flarum
     * runs over a post (Autolink, HTMLEntities, Emoji) reach into the start
     * tag and tear it apart; as content, `ignoreTags` keeps them all out.
     */
    renderHTML({ node }: any) {
      return ['article', {}, node.attrs.body || ''];
    },

    addNodeView() {
      return ({ node, editor, getPos }: any) => {
        let current = node;

        const dom = document.createElement('div');
        dom.className = 'Recipe-editorCard';
        dom.contentEditable = 'false';

        const icon = document.createElement('i');
        icon.className = 'icon fas fa-utensils';
        icon.setAttribute('aria-hidden', 'true');

        const title = document.createElement('span');
        title.className = 'Recipe-editorTitle';

        const edit = document.createElement('button');
        edit.type = 'button';
        edit.className = 'Button Button--link Recipe-editorEdit';
        edit.textContent = t('card_edit');

        const paint = () => {
          const name = titleOf(current.attrs.body) || t('card_untitled');
          title.textContent = name;
          dom.setAttribute('aria-label', t('card_label', { title: name }));
        };

        const open = () =>
          openForm(current.attrs.body, (body) => {
            const pos = typeof getPos === 'function' ? getPos() : null;
            if (typeof pos !== 'number') return;
            editor
              .chain()
              .focus()
              .command(({ tr }: any) => {
                tr.setNodeMarkup(pos, undefined, { ...current.attrs, body });
                return true;
              })
              .run();
          });

        edit.addEventListener('click', (e) => {
          e.preventDefault();
          open();
        });
        dom.addEventListener('dblclick', open);

        dom.append(icon, title, edit);
        paint();

        return {
          dom,
          update(updated: any) {
            if (updated.type !== current.type) return false;
            current = updated;
            paint();
            return true;
          },
          // The button is ours; ProseMirror should not treat its click as a selection.
          stopEvent: (event: Event) => (event.target as HTMLElement)?.closest?.('button') !== null,
          ignoreMutation: () => true,
        };
      };
    },
  });
}
