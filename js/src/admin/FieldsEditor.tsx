import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';

/**
 * The admin's list of recipe fields, edited in place and dragged into order.
 *
 * It writes JSON into the page's own setting stream, so the extension page's
 * Save button saves it like any other setting. The server validates on every
 * read (src/Fields.php); the checks here only tell the admin early.
 */

interface Field {
  key: string;
  label: string;
  unit: string;
  scope: 'serving' | 'recipe';
  schema: string | null;
}

const RESERVED = ['title', 'servings', 'prep', 'cook', 'image', 'based-on'];
const SCHEMA = [
  'calories',
  'carbohydrate',
  'cholesterol',
  'fat',
  'fiber',
  'protein',
  'saturatedFat',
  'sodium',
  'sugar',
  'transFat',
  'unsaturatedFat',
];
const MAX = 20;

function t(key: string): string {
  return extractText(app.translator.trans(`ernestdefoe-recipes.admin.fields.${key}`));
}

/** "Saturated fat" → "saturated-fat": what the key becomes until the admin types their own. */
function slug(label: string): string {
  return label
    .toLowerCase()
    .normalize('NFKD')
    .replace(/[̀-ͯ]/g, '')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^[^a-z]+|-+$/g, '')
    .slice(0, 30);
}

function parse(json: string): Field[] {
  try {
    const list = JSON.parse(json || '[]');
    return Array.isArray(list) ? list.filter((f) => f && typeof f === 'object') : [];
  } catch {
    return [];
  }
}

interface Attrs {
  stream: (value?: string) => string;
}

export default class FieldsEditor extends Component<Attrs> {
  fields: Field[] = [];
  /**
   * 🚨 A stable id per row for Mithril's key. Keying on the field's own key
   * rebuilt the row on every keystroke in the Key box, and focus went with it.
   */
  ids = new WeakMap<Field, number>();
  private nextId = 0;

  idOf(field: Field): number {
    if (!this.ids.has(field)) this.ids.set(field, ++this.nextId);
    return this.ids.get(field)!;
  }
  private sortable: any;

  oninit(vnode: Mithril.Vnode<Attrs, this>) {
    super.oninit(vnode);
    this.fields = parse(this.attrs.stream());
  }

  oncreate(vnode: Mithril.VnodeDOM<Attrs, this>) {
    super.oncreate(vnode);

    import('flarum/admin/utils/loadSortable').then(({ default: Sortable }: any) => {
      this.sortable = Sortable.create(this.element.querySelector('.RecipeFields-list'), {
        handle: '.RecipeFields-handle',
        animation: 150,
        onEnd: (e: any) => {
          const [moved] = this.fields.splice(e.oldIndex, 1);
          this.fields.splice(e.newIndex, 0, moved);
          this.save();
          m.redraw();
        },
      });
    });
  }

  onremove(vnode: Mithril.VnodeDOM<Attrs, this>) {
    super.onremove(vnode);
    this.sortable?.destroy();
  }

  save() {
    /*
     * A labelled row is never saved keyless. Core remounts this editor right
     * after its setting first changes, and typing that lands in that window
     * reached the label but not the key; filling it here, on every save,
     * holds however the remount and the keystrokes interleave.
     */
    for (const field of this.fields) if (!field.key && field.label) field.key = slug(field.label);
    this.attrs.stream(JSON.stringify(this.fields));
  }

  error(field: Field): string | null {
    if (!field.key) return field.label ? t('key_required') : null;
    if (!/^[a-z][a-z0-9-]{0,29}$/.test(field.key)) return t('key_invalid');
    if (RESERVED.includes(field.key)) return t('reserved');
    if (this.fields.filter((other) => other.key === field.key).length > 1) return t('duplicate');
    return null;
  }

  view() {
    return (
      <div className="Form-group RecipeFields">
        <label>{t('title')}</label>
        <div className="helpText">{t('help')}</div>
        <div className="helpText">{t('key_help')}</div>

        <ul className="RecipeFields-list">{this.fields.map((field) => this.row(field))}</ul>
        {this.fields.length ? null : <p className="RecipeFields-empty">{t('empty')}</p>}

        <Button
          className="Button"
          icon="fas fa-plus"
          disabled={this.fields.length >= MAX}
          title={this.fields.length >= MAX ? t('max') : undefined}
          onclick={() => {
            this.fields.push({ key: '', label: '', unit: '', scope: 'serving', schema: null });
            this.save();
          }}
        >
          {t('add')}
        </Button>
      </div>
    );
  }

  row(field: Field) {
    const update = (patch: Partial<Field>) => {
      Object.assign(field, patch);
      this.save();
    };
    const error = this.error(field);

    return (
      <li className="RecipeFields-row" key={this.idOf(field)}>
        <span className="RecipeFields-handle" title={t('drag')} aria-label={t('drag')}>
          <i className="icon fas fa-grip-vertical" aria-hidden="true" />
        </span>

        <div className="RecipeFields-grid">
          <label>
            {t('label')}
            <input
              className="FormControl"
              maxlength={40}
              placeholder={t('label_placeholder')}
              value={field.label}
              oninput={(e: InputEvent) => {
                const label = (e.target as HTMLInputElement).value;
                /*
                 * The key follows the label until the admin types their own.
                 * Decided from the values, not remembered: core remounts this
                 * editor when its setting changes, and remembered state went
                 * with it, freezing new rows' keys empty.
                 */
                const follows = !field.key || field.key === slug(field.label);
                update(follows ? { label, key: slug(label) } : { label });
              }}
            />
          </label>
          <label>
            {t('key')}
            <input
              className={'FormControl' + (error ? ' RecipeFields-invalid' : '')}
              maxlength={30}
              pattern="[a-z][a-z0-9\\-]*"
              value={field.key}
              aria-invalid={error ? 'true' : 'false'}
              oninput={(e: InputEvent) => {
                update({ key: (e.target as HTMLInputElement).value.toLowerCase().replace(/[^a-z0-9-]/g, '') });
              }}
            />
          </label>
          <label>
            {t('unit')}
            <input
              className="FormControl"
              maxlength={12}
              placeholder={t('unit_placeholder')}
              value={field.unit}
              oninput={(e: InputEvent) => update({ unit: (e.target as HTMLInputElement).value })}
            />
          </label>
          <label>
            {t('scope')}
            <select
              className="FormControl"
              value={field.scope}
              onchange={(e: Event) => update({ scope: (e.target as HTMLSelectElement).value as Field['scope'] })}
            >
              <option value="serving">{t('scope_serving')}</option>
              <option value="recipe">{t('scope_recipe')}</option>
            </select>
          </label>
          <label>
            {t('schema')}
            <select
              className="FormControl"
              value={field.schema ?? ''}
              onchange={(e: Event) => update({ schema: (e.target as HTMLSelectElement).value || null })}
            >
              <option value="">{t('schema_none')}</option>
              {SCHEMA.map((name) => (
                <option value={name}>{t(`schema_options.${name}`)}</option>
              ))}
            </select>
          </label>
        </div>

        <Button
          className="Button Button--icon Button--flat RecipeFields-remove"
          icon="fas fa-trash-can"
          aria-label={t('remove')}
          title={t('remove')}
          onclick={() => {
            this.fields.splice(this.fields.indexOf(field), 1);
            this.save();
          }}
        />

        {error ? <p className="RecipeFields-error">{error}</p> : null}
      </li>
    );
  }
}

declare const m: Mithril.Static;
