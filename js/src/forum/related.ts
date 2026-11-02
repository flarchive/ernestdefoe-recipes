import app from 'flarum/forum/app';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';

declare const m: Mithril.Static;

/**
 * Variants under each card, and the original named on each variant — for every
 * card on the page, in ONE request.
 *
 * Cards register as they are decorated; the batch goes out on the next tick,
 * so a thread with twenty recipes asks once, not twenty times. The answer is
 * already scoped to what this reader may see (src/Index/RecipeQuery.php): a
 * variant in a private discussion is never listed, and an original the reader
 * cannot open keeps its generic "Based on another recipe" link, which 404s for
 * them rather than naming it.
 */

export interface RecipeItem {
  postId: number;
  position: number;
  title: string;
  basedOn: number | null;
  number: number;
  discussion: string;
  author: { username: string; displayName: string } | null;
}

const pending = new Map<number, HTMLElement[]>();
const asked = new Map<number, Promise<RecipeItem[]>>();
let timer = 0;

function t(key: string, params: Record<string, string>): string {
  return extractText(app.translator.trans(`ernestdefoe-recipes.forum.card.${key}`, params));
}

export function urlFor(item: RecipeItem): string {
  return app.route('discussion.near', { id: item.discussion, near: item.number });
}

/** An in-forum link that moves within the app instead of reloading the page. */
export function routeLink(a: HTMLAnchorElement): void {
  a.addEventListener('click', (e) => {
    if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    const url = new URL(a.href, window.location.href);
    if (url.origin !== window.location.origin) return;
    e.preventDefault();
    m.route.set(url.pathname.slice(app.forum.attribute<string>('basePath')?.length || 0) + url.search);
  });
}

/** Queue a card: its own post (for variants) and, if it is a variant, its original. */
export function related(card: HTMLElement, postId: number): void {
  const ids = [postId];
  const basedOn = parseInt(card.dataset.basedOn || '', 10);
  if (basedOn > 0) ids.push(basedOn);

  for (const id of ids) pending.set(id, [...(pending.get(id) ?? []), card]);
  card.dataset.recipePost = String(postId);

  // A variant that arrives while its original is on screen (someone just
  // posted it) makes the original's list stale: ask again, and repaint it.
  if (basedOn > 0 && asked.has(basedOn)) {
    const originals = document.querySelectorAll<HTMLElement>(`.Recipe[data-recipe-post="${basedOn}"]`);
    if (originals.length) {
      asked.delete(basedOn);
      originals.forEach((original) => pending.set(basedOn, [...(pending.get(basedOn) ?? []), original]));
    }
  }

  window.clearTimeout(timer);
  timer = window.setTimeout(flush, 0);
}

function flush(): void {
  const cards = new Set<HTMLElement>();
  pending.forEach((list) => list.forEach((card) => cards.add(card)));

  const ids = [...pending.keys()].filter((id) => !asked.has(id));
  pending.clear();

  const request: Promise<RecipeItem[]> = ids.length
    ? app
        .request<{ data: RecipeItem[] }>({
          method: 'GET',
          url: `${app.forum.attribute('apiUrl')}/recipes`,
          params: { filter: { related: ids.join(',') } },
        })
        .then((res) => res.data)
        .catch(() => [])
    : Promise.resolve([]);

  ids.forEach((id) => asked.set(id, request));

  // Every card waits for every answer it depends on, including earlier batches.
  Promise.all([...new Set(asked.values())]).then((answers) => {
    // Batches overlap once one is re-asked; each recipe counts once.
    const seen = new Map<string, RecipeItem>();
    answers.flat().forEach((item) => seen.set(`${item.postId}:${item.position}`, item));
    const items = [...seen.values()];
    cards.forEach((card) => paint(card, items));
  });
}

function name(item: RecipeItem): string {
  return item.author?.displayName ?? '';
}

function paint(card: HTMLElement, items: RecipeItem[]): void {
  const postId = parseInt(card.dataset.recipePost || '', 10);

  // "Based on Fluffy pancakes by Ernest", once we know the reader may see it.
  const basedOn = parseInt(card.dataset.basedOn || '', 10);
  const original = items.find((item) => item.postId === basedOn);
  const link = card.querySelector<HTMLAnchorElement>('.Recipe-basedOn a');
  if (original && link && !link.dataset.named) {
    link.dataset.named = '1';
    link.textContent = t('based_on', { title: original.title, name: name(original) });
    link.href = urlFor(original);
    routeLink(link);
  }

  // Variants go under the LAST card of a post, once.
  const cardsOfPost = card.closest('.Post-body')?.querySelectorAll(`.Recipe[data-recipe-post="${postId}"]`);
  const last = cardsOfPost?.[cardsOfPost.length - 1] ?? card;
  if (last !== card) return;

  const variants = items.filter((item) => item.basedOn === postId && item.postId !== postId);
  card.querySelector('.Recipe-variants')?.remove();
  if (!variants.length) return;

  const section = document.createElement('section');
  section.className = 'Recipe-variants';

  const heading = document.createElement('h4');
  heading.className = 'Recipe-sectionTitle';
  heading.textContent = extractText(app.translator.trans('ernestdefoe-recipes.forum.card.variants'));

  const list = document.createElement('ul');
  list.className = 'Recipe-variantList';
  for (const variant of variants) {
    const li = document.createElement('li');
    const icon = document.createElement('i');
    icon.className = 'icon fas fa-code-branch';
    icon.setAttribute('aria-hidden', 'true');
    const a = document.createElement('a');
    a.href = urlFor(variant);
    a.textContent = variant.title;
    routeLink(a);
    li.append(icon, ' ', a);
    if (variant.author) {
      const by = document.createElement('span');
      by.className = 'Recipe-variantBy';
      by.textContent = ` · ${name(variant)}`;
      li.append(by);
    }
    list.append(li);
  }

  section.append(heading, list);
  card.append(section);
}
