import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Link from 'flarum/common/components/Link';
import extractText from 'flarum/common/utils/extractText';
import Stream from 'flarum/common/utils/Stream';
import type Mithril from 'mithril';
import { urlFor, type RecipeItem } from '../related';

/**
 * /recipes — every recipe on the forum the reader may see, newest first, as
 * cards, with a title search and a tag filter.
 *
 * Paged 20 at a time from /api/recipes; nothing here is cached across visits,
 * and nothing is loaded until someone opens the page.
 */

interface DirectoryItem extends RecipeItem {
  image: string | null;
  servings: number | null;
  prep: number | null;
  cook: number | null;
}

const PAGE = 20;

function t(key: string, params: Record<string, any> = {}): string {
  return extractText(app.translator.trans(`ernestdefoe-recipes.forum.directory.${key}`, params));
}

function minutes(seconds: number): string {
  const h = Math.floor(seconds / 3600);
  const m = Math.round((seconds % 3600) / 60);
  return [
    h ? extractText(app.translator.trans('ernestdefoe-recipes.forum.duration.hours', { count: h })) : '',
    m ? extractText(app.translator.trans('ernestdefoe-recipes.forum.duration.minutes', { count: m })) : '',
  ]
    .filter(Boolean)
    .join(' ');
}

declare const flarum: any;
declare const m: Mithril.Static;

/** Core components this page builds on, loaded through the registry: some live in lazy chunks. */
export let PageStructure: any;
export let IndexSidebar: any;

export async function loadCore(): Promise<void> {
  const [structure, sidebar] = await Promise.all([
    flarum.reg.asyncModuleImport('flarum/forum/components/PageStructure'),
    flarum.reg.asyncModuleImport('flarum/forum/components/IndexSidebar'),
  ]);
  PageStructure = structure.default || structure;
  IndexSidebar = sidebar.default || sidebar;
}

export default class RecipesPage extends Page {
  items: DirectoryItem[] = [];
  loading = true;
  more = false;
  search = Stream('');
  tag = Stream('');
  private debounce = 0;
  private request = 0;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    app.history.push('recipes', t('title'));
    this.bodyClass = 'App--recipes';
    this.load(0);
  }

  oncreate(vnode: Mithril.VnodeDOM) {
    super.oncreate(vnode);
    app.setTitle(t('title'));
    app.setTitleCount(0);
  }

  load(offset: number) {
    const ticket = ++this.request;
    this.loading = true;

    const filter: Record<string, string> = {};
    if (this.search().trim()) filter.q = this.search().trim();
    if (this.tag()) filter.tag = this.tag();

    app
      .request<{ data: DirectoryItem[]; more: boolean }>({
        method: 'GET',
        url: `${app.forum.attribute('apiUrl')}/recipes`,
        params: { filter, page: { offset, limit: PAGE } },
      })
      .then((res) => {
        // A slower, older search must not overwrite a newer one.
        if (ticket !== this.request) return;
        this.items = offset ? this.items.concat(res.data) : res.data;
        this.more = res.more;
      })
      .catch(() => {})
      .finally(() => {
        if (ticket === this.request) this.loading = false;
        m.redraw();
      });
  }

  view() {
    return (
      <PageStructure className="RecipesPage" sidebar={() => <IndexSidebar />}>
        <div className="RecipesPage-toolbar">
          <input
            className="FormControl RecipesPage-search"
            type="search"
            placeholder={t('search_placeholder')}
            aria-label={t('search_placeholder')}
            value={this.search()}
            oninput={(e: InputEvent) => {
              this.search((e.target as HTMLInputElement).value);
              window.clearTimeout(this.debounce);
              this.debounce = window.setTimeout(() => this.load(0), 300);
            }}
          />
          {this.tagSelect()}
        </div>

        {this.items.length ? (
          <ul className="RecipesPage-grid">{this.items.map((item) => this.card(item))}</ul>
        ) : this.loading ? null : (
          <p className="RecipesPage-empty">{t(this.search() || this.tag() ? 'no_results' : 'empty')}</p>
        )}

        {this.loading ? (
          <LoadingIndicator />
        ) : this.more ? (
          <div className="RecipesPage-more">
            <Button className="Button" onclick={() => this.load(this.items.length)}>
              {t('load_more')}
            </Button>
          </div>
        ) : null}
      </PageStructure>
    );
  }

  /** Only tags this reader can see are in the store, so the list leaks nothing. */
  tagSelect() {
    const tags = (app.store.all('tags') as any[]).filter((tag) => tag.position?.() !== null || tag.parent?.());
    if (!tags.length) return null;

    return (
      <select
        className="FormControl RecipesPage-tag"
        aria-label={t('all_tags')}
        value={this.tag()}
        onchange={(e: Event) => {
          this.tag((e.target as HTMLSelectElement).value);
          this.load(0);
        }}
      >
        <option value="">{t('all_tags')}</option>
        {tags
          .sort((a, b) => (a.position?.() ?? 999) - (b.position?.() ?? 999))
          .map((tag) => (
            <option value={tag.slug()}>{tag.name()}</option>
          ))}
      </select>
    );
  }

  card(item: DirectoryItem) {
    const time = (item.prep ?? 0) + (item.cook ?? 0);

    return (
      <li key={`${item.postId}-${item.position}`} className="RecipesPage-item">
        <Link href={urlFor(item)} className="RecipesPage-card">
          <div className="RecipesPage-image">
            {item.image ? <img src={item.image} alt="" loading="lazy" decoding="async" /> : <i className="icon fas fa-utensils" aria-hidden="true" />}
            {item.basedOn ? (
              <span className="RecipesPage-variant">
                <i className="icon fas fa-code-branch" aria-hidden="true" /> {t('variant')}
              </span>
            ) : null}
          </div>
          <div className="RecipesPage-body">
            <h3 className="RecipesPage-title">{item.title}</h3>
            {item.author ? <p className="RecipesPage-by">{t('by', { name: item.author.displayName })}</p> : null}
            <p className="RecipesPage-meta">
              {item.servings ? (
                <span>
                  <i className="icon fas fa-user-group" aria-hidden="true" /> {item.servings}
                </span>
              ) : null}
              {time ? (
                <span>
                  <i className="icon far fa-clock" aria-hidden="true" /> {minutes(time)}
                </span>
              ) : null}
            </p>
          </div>
        </Link>
      </li>
    );
  }
}
