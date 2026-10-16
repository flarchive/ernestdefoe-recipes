<?php

namespace ErnestDefoe\Recipes\Formatter;

use Flarum\Extension\ExtensionManager;
use s9e\TextFormatter\Configurator;

/**
 * Teaches s9e\TextFormatter that a post can hold a recipe.
 *
 * Two syntaxes reach one tag, `RECIPE`:
 *   - `[recipe]…[/recipe]`, for the plain composer, Markdown and FoF Rich Text
 *     (which stores Markdown);
 *   - `<article>…</article>`, only where Scribe is enabled, because Scribe
 *     stores posts as HTML.
 *
 * 🚨 Everything lives in the block's CONTENT, never in its start tag. Every
 * plugin Flarum runs reaches inside a start tag: Autolink takes a URL in an
 * `image=` attribute, HTMLEntities takes the `&amp;` in "Mac &amp; Cheese",
 * Emoji takes ":)" — each claims a piece of the tag and s9e tears it apart,
 * leaving a recipe with a literal `[/recipe]` after it. Scribe learnt this with
 * its video captions. Content, with `ignoreTags`, is opaque to all of them.
 *
 * 🚨 The parser does not understand the recipe. It only marks where one is.
 * Render::__invoke() reads the text and builds the card at render time, so:
 *   - a parse that fails cannot lose a post's content — the text is all there;
 *   - labels follow the READER's language, not the forum default at compile
 *     time;
 *   - there is no PHP callback in the parser, so s9e's JavaScript copy (the
 *     composer preview) compiles without a JS twin of anything.
 *
 * The child tags below are never produced by any syntax. They exist so their
 * templates compile; Render writes them into the XML just before rendering.
 */
class Configure
{
    public const TAG = 'RECIPE';

    /** The element Scribe stores. `figure` is Scribe's, `embed` Ruffle's, `video` Scribe's video. */
    public const ELEMENT = 'article';

    public function __construct(private readonly ExtensionManager $extensions)
    {
    }

    public function __invoke(Configurator $config): void
    {
        // Another extension owns this name: doing nothing beats re-templating its posts.
        if ($config->tags->exists(self::TAG)) {
            return;
        }

        $tag = $config->tags->add(self::TAG);
        $this->attributes($tag, ['v', 'title', 'servings', 'prep', 'cook', 'total', 'l_servings', 'l_prep', 'l_cook', 'l_total']);
        $tag->attributes->add('image')->filterChain->append('#url');
        $tag->attributes->get('image')->required = false;

        /*
         * Opaque contents (see the class comment), and no <br> inserted for the
         * newlines inside: the preview shows the source with `white-space:
         * pre-wrap`, and Render reads it line by line.
         */
        $tag->rules->ignoreTags();
        $tag->rules->disableAutoLineBreaks();
        // s9e otherwise wraps each blank-line-separated run in its own <p>,
        // which ignoreTags does not stop: paragraphs are a rule, not a tag.
        $tag->rules->createParagraphs(false);
        $tag->template = $this->recipeTemplate();

        $this->children($config);

        // 🚨 Through the property, never plugins->load(): load() builds a NEW
        // plugin and replaces the one flarum/bbcode already filled, and every
        // [b], [url] and [img] on the forum stops parsing in new posts.
        $config->BBCodes->add('RECIPE', [
            'tagName' => self::TAG,
            'forceLookahead' => true,
        ]);

        /*
         * 🚨 Only where Scribe is enabled. On a Markdown forum raw HTML is
         * escaped, which is that admin's choice; loading HTMLElements here
         * would punch a hole through it that nobody asked for. Scribe boots
         * before us (optional dependency), so this is the plugin it filled.
         */
        if ($this->extensions->isEnabled('ernestdefoe-scribe')) {
            $config->HTMLElements->aliasElement(self::ELEMENT, self::TAG);
        }
    }

    private function recipeTemplate(): string
    {
        $meta = '';
        foreach (['servings', 'prep', 'cook', 'total'] as $key) {
            $meta .= '<xsl:if test="@'.$key.'"><div class="Recipe-metaItem Recipe-metaItem--'.$key.'">'
                .'<dt><xsl:value-of select="@l_'.$key.'"/></dt>'
                .'<dd class="Recipe-'.$key.'"><xsl:value-of select="@'.$key.'"/></dd></div></xsl:if>';
        }

        return '<div><xsl:choose>'
            .'<xsl:when test="@v">'
            .'<xsl:attribute name="class">Recipe</xsl:attribute>'
            .'<xsl:if test="@servings"><xsl:attribute name="data-servings"><xsl:value-of select="@servings"/></xsl:attribute></xsl:if>'
            .'<xsl:if test="@image"><img class="Recipe-image" src="{@image}" alt="" loading="lazy"/></xsl:if>'
            .'<div class="Recipe-header">'
            .'<xsl:if test="@title"><h3 class="Recipe-title"><xsl:value-of select="@title"/></h3></xsl:if>'
            .'<xsl:if test="@servings or @prep or @cook"><dl class="Recipe-meta">'.$meta.'</dl></xsl:if>'
            .'</div>'
            .'<xsl:apply-templates/>'
            .'</xsl:when>'
            /*
             * Not expanded: the composer preview, where only s9e's JavaScript
             * runs and Render never sees the post. s9e calls `onrender` on the
             * freshly rendered element BEFORE diffing it into the page, so
             * forum/preview.ts can turn the source into the card there and the
             * diff keeps it — no flicker, nothing for the next keystroke to
             * fight. s9e strips every `data-s9e-livepreview-*` attribute from
             * the PHP renderer, so a post never carries it.
             */
            .'<xsl:otherwise><xsl:attribute name="class">Recipe Recipe--source</xsl:attribute>'
            .'<div class="Recipe-source" data-s9e-livepreview-onrender="if(window.ernestdefoeRecipes)window.ernestdefoeRecipes.preview(this)">'
            .'<xsl:apply-templates/></div></xsl:otherwise>'
            .'</xsl:choose></div>';
    }

    private function children(Configurator $config): void
    {
        $desc = $config->tags->add('RECIPE_DESC');
        $desc->template = '<div class="Recipe-description"><xsl:apply-templates/></div>';

        $list = $config->tags->add('RECIPE_LIST');
        $this->attributes($list, ['kind', 'label']);
        $list->template = '<section class="Recipe-section Recipe-section--{@kind}">'
            .'<h4 class="Recipe-sectionTitle"><xsl:value-of select="@label"/></h4>'
            .'<ul class="Recipe-list Recipe-list--{@kind}"><xsl:apply-templates/></ul>'
            .'</section>';

        $group = $config->tags->add('RECIPE_GROUP');
        $group->template = '<li class="Recipe-group"><xsl:apply-templates/></li>';

        $ingredient = $config->tags->add('RECIPE_ING');
        $this->attributes($ingredient, ['q', 'q2', 'dec', 'qty', 'gap', 'unit', 'one', 'many']);
        $data = '';
        foreach (['q', 'q2', 'dec', 'one', 'many'] as $key) {
            $data .= '<xsl:if test="@'.$key.'"><xsl:attribute name="data-'.$key.'"><xsl:value-of select="@'.$key.'"/></xsl:attribute></xsl:if>';
        }
        $ingredient->template = '<li class="Recipe-ingredient">'.$data
            .'<xsl:if test="@qty"><span class="Recipe-qty"><xsl:value-of select="@qty"/></span></xsl:if>'
            .'<xsl:value-of select="@gap"/>'
            .'<xsl:if test="@unit"><span class="Recipe-unit"><xsl:value-of select="@unit"/></span></xsl:if>'
            .'<xsl:apply-templates/></li>';

        $step = $config->tags->add('RECIPE_STEP');
        $step->template = '<li class="Recipe-step"><xsl:apply-templates/></li>';

        $timer = $config->tags->add('RECIPE_TIMER');
        $this->attributes($timer, ['s', 'label']);
        // A span, not a button: with no JavaScript (an email, a feed reader)
        // it cannot do anything, so it must not look like it can.
        // forum/recipe.ts turns it into the button.
        $timer->template = '<span class="Recipe-timer" data-seconds="{@s}"><xsl:value-of select="@label"/></span>';
    }

    /**
     * @param list<string> $names
     */
    private function attributes(\s9e\TextFormatter\Configurator\Items\Tag $tag, array $names): void
    {
        foreach ($names as $name) {
            $tag->attributes->add($name)->required = false;
        }
    }
}
