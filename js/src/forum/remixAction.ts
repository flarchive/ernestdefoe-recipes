import app from 'flarum/forum/app';
import { emptyFields, serialize, type RecipeFields } from '../common/source';
import { NODE, openForm } from './node';

declare const flarum: any;

/**
 * Remix: start a variant of the recipe on screen.
 *
 * The text is rebuilt from the CARD, not fetched: a post's source is only sent
 * to people who may edit it, but anyone may remix what they can read. The
 * card carries everything needed — the server writes the raw times and the
 * original's id into data attributes, the amounts keep their authored text in
 * `data-original` once scaled, and each timer its seconds.
 */

/** 5400 → "1h 30m", the short form the text format reads back. */
function duration(seconds: number): string {
  const h = Math.floor(seconds / 3600);
  const m = Math.floor((seconds % 3600) / 60);
  const s = seconds % 60;
  return [h ? `${h}h` : '', m ? `${m}m` : '', s ? `${s}s` : ''].filter(Boolean).join(' ');
}

const seconds = (value: string | undefined) => {
  const n = parseInt(value || '', 10);
  return Number.isFinite(n) && n > 0 ? duration(n) : '';
};

function listLines(card: HTMLElement, kind: 'ingredients' | 'steps'): string {
  const lines: string[] = [];

  card.querySelectorAll<HTMLElement>(`.Recipe-list--${kind} > li`).forEach((li) => {
    if (li.classList.contains('Recipe-group')) {
      lines.push(`${li.textContent?.trim()}:`);
      return;
    }

    let text = '';
    // The line's own text, inside the tick's label once the card is decorated.
    (li.querySelector('.Recipe-checkText') ?? li).childNodes.forEach((node) => {
      if (node instanceof HTMLElement && node.classList.contains('Recipe-timer')) {
        text += `[${seconds(node.dataset.seconds)}]`;
      } else if (node instanceof HTMLElement) {
        // A scaled amount reads back as the author wrote it, not as scaled.
        text += node.dataset.original ?? node.textContent ?? '';
      } else {
        text += node.textContent ?? '';
      }
    });

    if (text.trim()) lines.push(text.trim());
  });

  return lines.join('\n');
}

/** The raw number the server wrote for each field — not the localised or scaled text. */
function rawFacts(card: HTMLElement): Record<string, string> {
  const extra: Record<string, string> = {};
  card.querySelectorAll<HTMLElement>('.Recipe-fact[data-key]').forEach((fact) => {
    const value = fact.querySelector<HTMLElement>('.Recipe-factValue')?.dataset.q;
    if (fact.dataset.key && value) extra[fact.dataset.key] = value;
  });
  return extra;
}

export function cardToFields(card: HTMLElement, postId: string): RecipeFields {
  return {
    ...emptyFields(),
    title: card.querySelector('.Recipe-title')?.textContent?.trim() ?? '',
    servings: card.dataset.servings ?? '',
    prep: seconds(card.dataset.prep),
    cook: seconds(card.dataset.cook),
    image: card.querySelector<HTMLImageElement>('.Recipe-image')?.getAttribute('src') ?? '',
    basedOn: postId,
    description: card.querySelector('.Recipe-description')?.textContent?.trim() ?? '',
    ingredients: listLines(card, 'ingredients'),
    steps: listLines(card, 'steps'),
    // The raw number the server wrote, not the localised or scaled text.
    extra: rawFacts(card),
  };
}

/**
 * Put a recipe into whichever editor the composer is running.
 *
 * Scribe and FoF Rich Text are TipTap: they get the recipe as a node, so it is
 * a card from the start. The plain composer gets the [recipe] text.
 */
function insert(body: string): void {
  const driver = (app.composer as any).editor;
  const tiptap = driver?.editor;

  if (tiptap?.schema?.nodes?.[NODE]) {
    tiptap.chain().focus().insertContent({ type: NODE, attrs: { body } }).run();
    return;
  }

  const before = driver.getLastNChars(1);
  driver.insertAtCursor((before && before !== '\n' ? '\n\n' : '') + `[recipe]\n${body}\n[/recipe]\n`, false);
}

/** The composer's editor is built asynchronously (Scribe and Rich Text load a chunk first). */
function whenEditorReady(): Promise<void> {
  return new Promise((resolve, reject) => {
    const started = Date.now();
    const check = () => {
      if ((app.composer as any).editor) return resolve();
      if (Date.now() - started > 8000) return reject(new Error('composer did not open'));
      window.setTimeout(check, 100);
    };
    check();
  });
}

async function openComposer(where: 'reply' | 'discussion', discussion: any): Promise<void> {
  if (where === 'reply') {
    const controls = await flarum.reg.asyncModuleImport('flarum/forum/utils/DiscussionControls');
    await (controls.default || controls).replyAction.call(discussion, true, false);
  } else {
    const IndexSidebar = await flarum.reg.asyncModuleImport('flarum/forum/components/IndexSidebar');
    await (IndexSidebar.default || IndexSidebar).prototype.newDiscussionAction.call({});
  }

  await whenEditorReady();
}

export function remix(card: HTMLElement, post: any, where: 'reply' | 'discussion'): void {
  const fields = cardToFields(card, String(post.id()));

  openForm(
    serialize(fields),
    (body) => {
      openComposer(where, post.discussion())
        .then(() => insert(body))
        .catch(() => {});
    },
    'remix'
  );
}
