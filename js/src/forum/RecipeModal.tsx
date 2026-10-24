import app from 'flarum/forum/app';
/*
 * 🚨 FormModal, not Modal. In Flarum 2 the base Modal renders no <form>, so a
 * submit button inside it submits nothing and `onsubmit` is never called.
 */
import FormModal from 'flarum/common/components/FormModal';
import type { IInternalModalAttrs } from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import Stream from 'flarum/common/utils/Stream';
import extractText from 'flarum/common/utils/extractText';

declare const m: import('mithril').Static;
import { emptyFields, isEmpty, parse, serialize, type RecipeFields } from '../common/source';

/** A field the admin defined (src/Fields.php), as the forum payload carries it. */
export interface FieldDefinition {
  key: string;
  label: string;
  unit: string;
  scope: 'serving' | 'recipe';
}

export function fieldDefinitions(): FieldDefinition[] {
  const list = app.forum.attribute<FieldDefinition[]>('recipesFields');
  return Array.isArray(list) ? list : [];
}

export interface RecipeModalAttrs extends IInternalModalAttrs {
  /** The block's text when editing one; absent when inserting. */
  body?: string;
  /** 'remix': a copy of someone's recipe, about to become a variant. */
  mode?: 'remix';
  /** Receives the block's text (without the [recipe] wrapper). */
  onsubmit: (body: string, fields: RecipeFields) => void;
}

function t(key: string): string {
  return extractText(app.translator.trans(`ernestdefoe-recipes.lib.composer.${key}`));
}

/**
 * The form behind every editor's Recipe button: the plain composer, FoF Rich
 * Text and Scribe all open this, and all receive the same text back. Only how
 * that text is put into the post differs.
 */
export default class RecipeModal extends FormModal<RecipeModalAttrs> {
  fields: Record<Exclude<keyof RecipeFields, 'extra'>, Stream<string>> = {} as any;
  definitions: FieldDefinition[] = [];
  extra: Record<string, Stream<string>> = {};
  /** Header values with no box in the form (a field since removed): carried through untouched. */
  hidden: Record<string, string> = {};
  uploading = false;
  dragging = false;

  oninit(vnode: any) {
    super.oninit(vnode);

    this.definitions = fieldDefinitions();
    const keys = this.definitions.map((field) => field.key);
    const initial = this.attrs.body ? parse(this.attrs.body, keys) : emptyFields();

    for (const key of Object.keys(initial) as (keyof RecipeFields)[]) {
      if (key !== 'extra') this.fields[key] = Stream(initial[key] as string);
    }
    for (const field of this.definitions) this.extra[field.key] = Stream(initial.extra[field.key] ?? '');
    for (const [key, value] of Object.entries(initial.extra)) if (!keys.includes(key)) this.hidden[key] = value;
  }

  /**
   * The photo: uploaded here (when the member may), or a web address (when the
   * admin allows them). The server decides both again when the post is saved.
   */
  photoField() {
    const canUpload = !!app.forum.attribute('canUploadRecipePhotos');
    const urls = app.forum.attribute('recipesImageUrls') !== false;
    const image = this.fields.image().trim();

    // Nothing this member could add: the field would only be in the way.
    if (!canUpload && !urls && !image) return null;

    const isUpload = image.startsWith(String(app.forum.attribute('baseUrl')) + '/assets/recipes/');
    const pick = (files: FileList | null | undefined) => files?.[0] && this.upload(files[0]);

    return (
      <div className="Form-group RecipeModal-photo">
        <label for={urls ? 'recipe-image' : 'recipe-photo'}>{t('image')}</label>
        {image && /^https?:\/\//i.test(image) ? (
          <div className="RecipeModal-photoPreview">
            <img src={image} alt="" />
            <Button
              className="Button Button--icon Button--flat"
              icon="fas fa-times"
              aria-label={t('image_remove')}
              title={t('image_remove')}
              onclick={() => this.fields.image('')}
            />
          </div>
        ) : null}
        {canUpload ? (
          <div
            className={'RecipeModal-drop' + (this.dragging ? ' is-dragging' : '')}
            ondragover={(e: DragEvent) => {
              e.preventDefault();
              this.dragging = true;
            }}
            ondragleave={() => (this.dragging = false)}
            ondrop={(e: DragEvent) => {
              e.preventDefault();
              this.dragging = false;
              pick(e.dataTransfer?.files);
            }}
          >
            <input
              id="recipe-photo"
              type="file"
              accept="image/jpeg,image/png,image/webp"
              className="RecipeModal-file"
              disabled={this.uploading}
              onchange={(e: Event) => {
                const input = e.target as HTMLInputElement;
                pick(input.files);
                input.value = '';
              }}
            />
            <i className="icon fas fa-camera" aria-hidden="true" />{' '}
            {this.uploading
              ? t('image_uploading')
              : [
                  t('image_drop'),
                  ' ',
                  <label for="recipe-photo" className="RecipeModal-choose">
                    {t('image_choose')}
                  </label>,
                ]}
          </div>
        ) : null}
        {urls ? (
          <input
            id="recipe-image"
            className="FormControl"
            type="url"
            inputmode="url"
            placeholder={canUpload ? t('image_url_placeholder') : 'https://'}
            aria-label={canUpload ? t('image_url_placeholder') : undefined}
            value={isUpload ? '' : image}
            oninput={(e: InputEvent) => this.fields.image((e.target as HTMLInputElement).value)}
          />
        ) : image && !isUpload ? (
          <div className="helpText">{t('image_kept')}</div>
        ) : null}
        <div className="helpText">{t('image_help')}</div>
      </div>
    );
  }

  upload(file: File) {
    // Mirrors Photos\PhotoValidator, to save a pointless upload; the server checks the bytes.
    if (!/^image\/(jpeg|png|webp)$/.test(file.type)) {
      this.alertAttrs = { type: 'error', content: t('image_type') };
      return;
    }
    if (file.size > 10 * 1024 * 1024) {
      this.alertAttrs = { type: 'error', content: t('image_too_big') };
      return;
    }

    const body = new FormData();
    body.append('photo', file);
    this.uploading = true;
    this.alertAttrs = null;

    app
      .request<{ url: string }>({
        method: 'POST',
        url: app.forum.attribute('apiUrl') + '/recipes/photos',
        serialize: (raw: FormData) => raw,
        body,
      })
      .then((response) => this.fields.image(response.url))
      .catch(() => {})
      .finally(() => {
        this.uploading = false;
        m.redraw();
      });
    m.redraw();
  }

  className() {
    return 'RecipeModal Modal--medium';
  }

  title() {
    return t(this.attrs.mode === 'remix' ? 'remix_title' : this.attrs.body ? 'edit_title' : 'title');
  }

  content() {
    const f = this.fields;

    return (
      <div className="Modal-body">
        <div className="Form">
          <div className="Form-group">
            <label for="recipe-title">{t('name')}</label>
            <input id="recipe-title" className="FormControl" maxlength={200} placeholder={t('name_placeholder')} bidi={f.title} />
          </div>

          <div className="RecipeModal-row">
            <div className="Form-group">
              <label for="recipe-servings">{t('servings')}</label>
              <input id="recipe-servings" className="FormControl" type="number" min="1" max="999" inputmode="numeric" bidi={f.servings} />
            </div>
            <div className="Form-group">
              <label for="recipe-prep">{t('prep')}</label>
              <input id="recipe-prep" className="FormControl" placeholder={t('time_placeholder')} bidi={f.prep} />
            </div>
            <div className="Form-group">
              <label for="recipe-cook">{t('cook')}</label>
              <input id="recipe-cook" className="FormControl" placeholder={t('time_placeholder')} bidi={f.cook} />
            </div>
          </div>

          {this.definitions.length ? (
            <div className="RecipeModal-row RecipeModal-facts">
              {this.definitions.map((field) => (
                <div className="Form-group">
                  <label for={`recipe-field-${field.key}`}>
                    {field.label}
                    {field.unit ? ` (${field.unit})` : ''}
                  </label>
                  <input
                    id={`recipe-field-${field.key}`}
                    className="FormControl"
                    inputmode="decimal"
                    placeholder={t(field.scope === 'recipe' ? 'field_recipe' : 'field_serving')}
                    bidi={this.extra[field.key]}
                  />
                </div>
              ))}
            </div>
          ) : null}

          {this.photoField()}

          <div className="Form-group">
            <label for="recipe-description">{t('description')}</label>
            <textarea id="recipe-description" className="FormControl" rows={2} bidi={f.description} />
          </div>

          <div className="Form-group">
            <label for="recipe-ingredients">{t('ingredients')}</label>
            <textarea id="recipe-ingredients" className="FormControl RecipeModal-list" rows={6} bidi={f.ingredients} />
            <div className="helpText">{t('ingredients_help')}</div>
          </div>

          <div className="Form-group">
            <label for="recipe-steps">{t('steps')}</label>
            <textarea id="recipe-steps" className="FormControl RecipeModal-list" rows={6} bidi={f.steps} />
            <div className="helpText">{t('steps_help')}</div>
          </div>

          <div className="Form-group">
            <Button className="Button Button--primary" type="submit">
              {t(this.attrs.mode === 'remix' ? 'remix_insert' : this.attrs.body ? 'update' : 'insert')}
            </Button>
          </div>
        </div>
      </div>
    );
  }

  /**
   * 🚨 These checks catch typos only. The server re-checks everything on text
   * this form never saw — a hand-written block, an import, an API client — and
   * that is the check that protects anyone (src/Recipe.php).
   */
  onsubmit(e: SubmitEvent) {
    e.preventDefault();

    const values = { extra: {} } as RecipeFields;
    for (const key of Object.keys(this.fields) as Exclude<keyof RecipeFields, 'extra'>[]) values[key] = this.fields[key]();

    for (const field of this.definitions) {
      const value = this.extra[field.key]().trim();
      if (!value) continue;
      // Mirrors Fields::number(): the server shows nothing it cannot read.
      if (!/^\d{1,7}(?:[.,]\d{1,3})?$/.test(value)) {
        this.alertAttrs = {
          type: 'error',
          content: extractText(app.translator.trans('ernestdefoe-recipes.lib.composer.invalid_number', { field: field.label })),
        };
        return;
      }
      values.extra[field.key] = value;
    }
    // The admin's fields first, in their order; values with no box after them.
    Object.assign(values.extra, this.hidden);

    if (isEmpty(values)) {
      this.alertAttrs = { type: 'error', content: t('empty') };
      return;
    }

    if (values.image.trim() && !/^https?:\/\/\S+$/i.test(values.image.trim())) {
      this.alertAttrs = { type: 'error', content: t('invalid_image') };
      return;
    }

    if (this.uploading) return;

    this.attrs.onsubmit(serialize(values), values);
    this.hide();
  }
}
