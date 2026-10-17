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
import { emptyFields, isEmpty, parse, serialize, type RecipeFields } from '../common/source';

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
  fields: Record<keyof RecipeFields, Stream<string>> = {} as any;

  oninit(vnode: any) {
    super.oninit(vnode);

    const initial = this.attrs.body ? parse(this.attrs.body) : emptyFields();
    for (const key of Object.keys(initial) as (keyof RecipeFields)[]) {
      this.fields[key] = Stream(initial[key]);
    }
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

          <div className="Form-group">
            <label for="recipe-image">{t('image')}</label>
            <input id="recipe-image" className="FormControl" type="url" inputmode="url" placeholder="https://" bidi={f.image} />
            <div className="helpText">{t('image_help')}</div>
          </div>

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

    const values = {} as RecipeFields;
    for (const key of Object.keys(this.fields) as (keyof RecipeFields)[]) values[key] = this.fields[key]();

    if (isEmpty(values)) {
      this.alertAttrs = { type: 'error', content: t('empty') };
      return;
    }

    if (values.image.trim() && !/^https?:\/\/\S+$/i.test(values.image.trim())) {
      this.alertAttrs = { type: 'error', content: t('invalid_image') };
      return;
    }

    this.attrs.onsubmit(serialize(values), values);
    this.hide();
  }
}
