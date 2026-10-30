import { Recipe } from '@shared/models/api.models';
import { BookDetail, Product } from '@shared/models/commerce.models';
import { SeoService, isoDuration, schemaPrice } from './seo.service';

/*
 * Микроразметка schema.org для поисковиков. Правила, которые здесь соблюдены:
 *  - только то, что видно на странице (иначе Google считает разметку спамом);
 *  - картинки и адреса — абсолютные;
 *  - пустые поля не выводятся вовсе, а не со значением null.
 */

type Json = Record<string, unknown>;

/** Убирает пустые значения: schema.org не любит null и пустые массивы. */
function clean<T extends Json>(o: T): T {
  return Object.fromEntries(Object.entries(o).filter(([, v]) =>
    v !== null && v !== undefined && v !== '' && !(Array.isArray(v) && v.length === 0))) as T;
}

/** Recipe — даёт карусель рецептов, время и рейтинг прямо в выдаче. */
export function recipeSchema(seo: SeoService, r: Recipe): Json {
  const images = [r.cover?.url, ...(r.gallery ?? []).map((g) => g.url)].filter(Boolean);
  const n = r.nutrition ?? { calories: null, protein: null, fat: null, carbs: null };
  const diets = [
    r.is_vegetarian ? 'https://schema.org/VegetarianDiet' : null,
    r.is_gluten_free ? 'https://schema.org/GlutenFreeDiet' : null,
  ].filter(Boolean);

  return clean({
    '@type': 'Recipe',
    name: r.title,
    description: r.og_description || r.lead,
    image: images,
    url: seo.abs(`/recipes/${r.slug}`),
    author: r.author ? { '@type': 'Person', name: r.author.name } : { '@type': 'Organization', name: 'Food Guru' },
    datePublished: r.published_at,
    dateModified: r.updated_at,
    totalTime: isoDuration(r.time_minutes),
    recipeYield: r.servings ? String(r.servings) : null,
    recipeCategory: r.category?.title,
    recipeCuisine: null,
    keywords: (r.tags ?? []).map((t) => t.title).join(', '),
    suitableForDiet: diets.length === 1 ? diets[0] : diets,
    nutrition: n.calories || n.protein || n.fat || n.carbs ? clean({
      '@type': 'NutritionInformation',
      servingSize: '1 порция',
      calories: n.calories ? `${n.calories} ккал` : null,
      proteinContent: n.protein ? `${n.protein} г` : null,
      fatContent: n.fat ? `${n.fat} г` : null,
      carbohydrateContent: n.carbs ? `${n.carbs} г` : null,
    }) : null,
    recipeIngredient: (r.ingredients ?? []).map((i) =>
      [i.name, i.amount != null ? String(i.amount).replace('.', ',') : null, i.unit].filter(Boolean).join(' ')),
    recipeInstructions: (r.steps ?? []).map((s, idx) => clean({
      '@type': 'HowToStep',
      position: idx + 1,
      text: s.hint ? `${s.body} (${s.hint})` : s.body,
      image: s.media?.url,
    })),
    aggregateRating: r.rating && r.reviews_count ? {
      '@type': 'AggregateRating',
      ratingValue: Number(r.rating).toFixed(1),
      ratingCount: r.reviews_count,
      bestRating: 5,
      worstRating: 1,
    } : null,
    video: r.reel_url ? clean({
      '@type': 'VideoObject',
      name: `${r.title} — видеорецепт`,
      description: r.lead || r.title,
      thumbnailUrl: r.cover?.url ? [r.cover.url] : null,
      contentUrl: r.reel_url,
      uploadDate: r.published_at,
      duration: 'PT20S',
    }) : null,
  });
}

/** Product — цена и наличие в выдаче. */
export function productSchema(seo: SeoService, p: Product): Json {
  return clean({
    '@type': 'Product',
    name: p.title,
    description: p.description || p.lead,
    image: p.cover?.url ? [p.cover.url] : null,
    sku: p.sku,
    category: p.category,
    material: p.material,
    color: p.color,
    countryOfOrigin: p.country,
    brand: { '@type': 'Brand', name: 'Food Guru' },
    offers: {
      '@type': 'Offer',
      url: seo.abs(`/shop/${p.slug}`),
      priceCurrency: 'RUB',
      price: schemaPrice(p.price),
      availability: p.in_stock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
      itemCondition: 'https://schema.org/NewCondition',
    },
  });
}

/** Book — бесплатная книга получает цену 0, платная — свою. */
export function bookSchema(seo: SeoService, b: BookDetail): Json {
  return clean({
    '@type': 'Book',
    name: b.title,
    description: b.description || b.lead,
    image: b.cover?.url,
    url: seo.abs(`/books/${b.slug}`),
    author: b.author_name ? { '@type': 'Person', name: b.author_name } : null,
    datePublished: b.year ? String(b.year) : null,
    countryOfOrigin: b.country,
    inLanguage: 'ru',
    bookFormat: 'https://schema.org/EBook',
    numberOfPages: null,
    isAccessibleForFree: b.is_free,
    offers: {
      '@type': 'Offer',
      url: seo.abs(`/books/${b.slug}`),
      priceCurrency: 'RUB',
      price: schemaPrice(b.price),
      availability: 'https://schema.org/InStock',
    },
  });
}

/** Для главной: сайт с поиском (строка поиска прямо в выдаче Google) и организация. */
export function siteSchemas(seo: SeoService): Json[] {
  const site = seo.abs('/');
  return [
    {
      '@type': 'WebSite',
      name: 'Food Guru',
      url: site,
      inLanguage: 'ru',
      potentialAction: {
        '@type': 'SearchAction',
        target: { '@type': 'EntryPoint', urlTemplate: `${seo.abs('/recipes')}?q={search_term_string}` },
        'query-input': 'required name=search_term_string',
      },
    },
    {
      '@type': 'Organization',
      name: 'Food Guru',
      url: site,
      logo: seo.abs('/icon-512.png'),
    },
  ];
}
