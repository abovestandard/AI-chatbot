=== AI Assistant Bot ===

An AI-powered search assistant for WordPress that answers visitor questions using
your products, product categories, and blog posts. Supports Gemini (free) and ChatGPT.

== INSTALLATION ==

1. Zip the "ai-assistant-bot" folder (already done if you downloaded the zip).
2. In WordPress admin: Plugins > Add New > Upload Plugin > choose the zip > Install > Activate.
3. Go to Settings > AI Assistant Bot.
4. Provider is set to "Google Gemini — Free tier" by default. Get a free key at
   https://aistudio.google.com/apikey and paste it in. (You can switch to
   OpenAI ChatGPT later — paid — if you need more capacity or quality.)
5. Set "Post types to search" to your actual post type slugs, comma separated.
   - For WooCommerce products: product
   - For a custom "Blog" post type: whatever slug you registered it with (check
     Custom Post Type UI or your theme's functions.php)
6. Set "Taxonomies to search" to your product category taxonomy slug
   (WooCommerce default: product_cat).
7. Set your brand color, bot name, and welcome message.
8. Optionally add "Extra instructions for the AI" — company-specific guidance
   the bot should always follow (tone, what to emphasize, disclaimers, etc.)
9. Optionally paste an image URL into "Logo image URL" (upload your mascot/logo
   to the WordPress Media Library first, then paste its URL) — it appears at
   the top of both the search screen and the results screen.
10. Save. The chat bubble now appears in the bottom-right of every page.

== ABOUT THE FREE TIER ==

Gemini's free tier is genuinely free (no card required) but rate-limited —
roughly 10-30 requests/minute and a daily cap depending on the model, and
Google adjusts these limits periodically. For a small-to-medium dealer site
this is normally plenty. If you outgrow it, switch "AI Provider" to ChatGPT
in settings (paid, no code changes needed) or enable billing on your Google
Cloud project for higher Gemini limits.

== REQUIREMENTS FOR YOUR CONTENT TO BE FOUND ==

Your custom post types must be registered as public and searchable
(the defaults for 'public' => true already include this). If a post type was
registered with 'exclude_from_search' => true, the bot won't find it — either
change that in your post type registration, or ask a developer to adjust it.

== HOW IT WORKS ==

1. A visitor types a question into the widget.
2. The plugin searches your WordPress content (native search across the post
   types and taxonomies you configured) for the most relevant items.
3. Those items are sent to the configured AI provider along with the question,
   and the model returns a natural-language answer plus structured product/category
   recommendations.
4. The widget renders the answer, product cards (image, title, price, link),
   related categories, and key highlights — same layout as your mockup.

== SCALING UP LATER ==

Native WordPress search works well for most catalogs (up to a few thousand
items). If your catalog grows very large or search relevance needs to improve,
the retrieval step (AIAB_Search class) can be swapped for a vector-embedding
search (e.g. via Pinecone or Supabase pgvector) without changing anything else
in the plugin — the rest of the pipeline (AI call, caching, widget) stays
the same.

== SHORTCODE ==

Use [ai_assistant_bot] to embed the assistant inline on a specific page instead
of (or in addition to) the floating bubble. Turn off "auto inject" in settings
if you only want it on specific pages via the shortcode.

== SECURITY NOTES ==

- The API key is stored in the WordPress options table. Restrict admin access
  to trusted users.
- Requests are rate-limited per IP (20 requests / 5 minutes) to control API cost
  and prevent abuse.
- Answers are cached (default 60 minutes) so repeated identical questions don't
  re-call the API.
