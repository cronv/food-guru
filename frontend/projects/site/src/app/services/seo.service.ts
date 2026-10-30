
import { Injectable, inject, DOCUMENT } from '@angular/core';
import { Meta, Title } from '@angular/platform-browser';

import { SITE_URL } from '@shared/tokens';

export interface SeoTags {
  title: string;
  description?: string | null;
  image?: string | null;
  path?: string;
  type?: 'website' | 'article' | 'product' | 'book';
  /** Личные и платные страницы: кошелёк, корзина, текст глав. */
  noindex?: boolean;
}

/** Хлебная крошка для BreadcrumbList: подпись и путь от корня сайта. */
export interface SeoCrumb {
  name: string;
  path: string;
}

/**
 * Проставляет title, description, Open Graph, canonical, robots и микроразметку
 * schema.org (JSON-LD). Вызывается в компонентах страниц: при SSR всё это
 * попадает в готовый HTML — его и читают Яндекс, Google и мессенджеры.
 */
@Injectable({ providedIn: 'root' })
export class SeoService {
  private title = inject(Title);
  private meta = inject(Meta);
  private doc = inject(DOCUMENT);
  private siteUrl = inject(SITE_URL);

  apply(tags: SeoTags): void {
    const fullTitle = tags.title.includes('Food Guru') ? tags.title : `${tags.title} — Food Guru`;
    const url = this.abs(tags.path ?? '/');
    const image = tags.image || `${this.siteUrl}/og-cover.jpg`;
    const description = (tags.description || 'Рецепты, которые получаются с первого раза.').slice(0, 300);

    this.title.setTitle(fullTitle);

    this.set('description', description);
    this.setProp('og:type', tags.type === 'article' ? 'article' : 'website');
    this.setProp('og:site_name', 'Food Guru');
    this.setProp('og:locale', 'ru_RU');
    this.setProp('og:title', fullTitle);
    this.setProp('og:description', description);
    this.setProp('og:url', url);
    this.setProp('og:image', image);
    this.set('twitter:card', 'summary_large_image');

    // Тег держим всегда, меняя значение: иначе после закрытой страницы
    // noindex «прилип» бы к следующей при навигации без перезагрузки.
    this.set('robots', tags.noindex ? 'noindex, nofollow' : 'index, follow, max-image-preview:large');

    // canonical — адрес без параметров: ?sort=… и ?utm=… не плодят дублей в выдаче.
    this.canonical(tags.noindex ? null : url);

    // Микроразметку прошлой страницы снимаем: страница добавит свою после apply().
    this.doc.head.querySelectorAll('script[data-seo]').forEach((s) => s.remove());
  }

  /** Микроразметка schema.org. Ключ — чтобы на странице не было двух одинаковых блоков. */
  jsonLd(key: string, data: Record<string, unknown> | null): void {
    this.doc.head.querySelector(`script[data-seo="${key}"]`)?.remove();
    if (!data) return;

    const script = this.doc.createElement('script');
    script.type = 'application/ld+json';
    script.setAttribute('data-seo', key);
    // </script> внутри строк закрыл бы тег раньше времени — экранируем «<».
    script.textContent = JSON.stringify({ '@context': 'https://schema.org', ...data }).replace(/</g, '\\u003c');
    this.doc.head.appendChild(script);
  }

  /** BreadcrumbList — Яндекс и Google показывают его вместо адреса в выдаче. */
  breadcrumbs(items: SeoCrumb[]): void {
    this.jsonLd('breadcrumbs', {
      '@type': 'BreadcrumbList',
      itemListElement: items.map((c, i) => ({
        '@type': 'ListItem',
        position: i + 1,
        name: c.name,
        item: this.abs(c.path),
      })),
    });
  }

  /** Абсолютный адрес на домене сайта. */
  abs(path: string): string {
    if (/^https?:\/\//.test(path)) return path;
    const clean = path.split('?')[0].split('#')[0];
    return `${this.siteUrl}${clean.startsWith('/') ? '' : '/'}${clean === '/' ? '' : clean}` || this.siteUrl;
  }

  private canonical(url: string | null): void {
    let link = this.doc.head.querySelector<HTMLLinkElement>('link[rel="canonical"]');
    if (!url) {
      link?.remove();
      return;
    }
    if (!link) {
      link = this.doc.createElement('link');
      link.setAttribute('rel', 'canonical');
      this.doc.head.appendChild(link);
    }
    link.setAttribute('href', url);
  }

  private set(name: string, content: string): void {
    this.meta.updateTag({ name, content });
  }

  private setProp(property: string, content: string): void {
    this.meta.updateTag({ property, content }, `property='${property}'`);
  }
}

/** ItemList рецептов — для категорий и подборок: робот видит, что внутри списка. */
export function itemList(
  seo: SeoService,
  name: string,
  recipes: { slug: string; title: string; cover?: { url: string } | null }[],
): Record<string, unknown> {
  return {
    '@type': 'ItemList',
    name,
    numberOfItems: recipes.length,
    itemListElement: recipes.map((r, i) => ({
      '@type': 'ListItem',
      position: i + 1,
      url: seo.abs(`/recipes/${r.slug}`),
      name: r.title,
      ...(r.cover ? { image: r.cover.url } : {}),
    })),
  };
}

/** 45 → «PT45M», 90 → «PT1H30M» — длительность в ISO 8601 для schema.org. */
export function isoDuration(minutes: number | null | undefined): string | undefined {
  if (!minutes || minutes <= 0) return undefined;
  const h = Math.floor(minutes / 60);
  const m = minutes % 60;
  return `PT${h ? h + 'H' : ''}${m ? m + 'M' : ''}`;
}

/** Копейки → «990.00»: schema.org ждёт цену строкой с точкой. */
export function schemaPrice(kopecks: number): string {
  return (kopecks / 100).toFixed(2);
}
