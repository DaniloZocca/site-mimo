/**
 * MIMOO LAB - Centralized Category Metadata Dictionary
 * Single Source of Truth for category icons, color schemes, and styling classes.
 */

/**
 * Default fallback metadata when a category is unrecognized or not found.
 */
export const DEFAULT_CATEGORY_META = Object.freeze({
  slug: 'outros',
  name: 'Geral',
  icon: '✨',
  colorClasses: 'bg-purple-50 text-purple-700 border-purple-200',
});

/**
 * Primary categories catalog with icons, display names, and Tailwind color tokens.
 */
export const CATEGORY_DEFINITIONS = Object.freeze({
  decoracao: {
    slug: 'decoracao',
    name: 'Decoração',
    icon: '🌸',
    colorClasses: 'bg-pink-50 text-pink-700 border-pink-200',
  },
  'suportes-geek': {
    slug: 'suportes-geek',
    name: 'Suportes & Geek',
    icon: '👾',
    colorClasses: 'bg-purple-50 text-purple-700 border-purple-200',
  },
  'articulados-toys': {
    slug: 'articulados-toys',
    name: 'Articulados & Toys',
    icon: '🐉',
    colorClasses: 'bg-emerald-50 text-emerald-700 border-emerald-200',
  },
});

/**
 * List of primary categories for navbars, tabs, and filters.
 */
export const CATEGORY_LIST = Object.freeze(Object.values(CATEGORY_DEFINITIONS));

/**
 * Mapping of all common slugs, aliases, and variations to official category keys.
 */
export const CATEGORY_ALIASES = Object.freeze({
  // Decoração variations
  decoracao: 'decoracao',
  decoracoes: 'decoracao',
  decor: 'decoracao',
  decoration: 'decoracao',
  decorativo: 'decoracao',
  decorativos: 'decoracao',

  // Suportes & Geek variations
  'suportes-geek': 'suportes-geek',
  'suportes-e-geek': 'suportes-geek',
  suportes: 'suportes-geek',
  suporte: 'suportes-geek',
  geek: 'suportes-geek',
  geeks: 'suportes-geek',
  gamer: 'suportes-geek',
  gamers: 'suportes-geek',

  // Articulados & Toys variations
  'articulados-toys': 'articulados-toys',
  'articulados-e-toys': 'articulados-toys',
  articulados: 'articulados-toys',
  articulado: 'articulados-toys',
  articulated: 'articulados-toys',
  toys: 'articulados-toys',
  toy: 'articulados-toys',
  brinquedos: 'articulados-toys',
  brinquedo: 'articulados-toys',
  actionfigures: 'articulados-toys',
  dragons: 'articulados-toys',
  dragoes: 'articulados-toys',
});

/**
 * Sanitizes and normalizes any category string or slug:
 * - Converts to lowercase and trims whitespace
 * - Removes accents and diacritics (e.g., 'decoração' -> 'decoracao')
 * - Replaces '&' with 'e' and non-alphanumeric chars with hyphens
 * - Collapses consecutive hyphens and trims edges
 *
 * @param {string} input - Raw string to normalize
 * @returns {string} Normalized slug/key
 */
export const normalizeCategoryKey = (input) => {
  if (!input || typeof input !== 'string') return '';

  return input
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '') // Remove diacritics
    .toLowerCase()
    .trim()
    .replace(/&/g, ' e ') // Replace & with 'e'
    .replace(/[^a-z0-9]+/g, '-') // Replace non-alphanumeric with hyphens
    .replace(/^-+|-+$/g, ''); // Trim leading/trailing hyphens
};

/**
 * Resolves category metadata for any category input (string, slug, or object).
 *
 * @param {string|object} categoryOrSlug - Category string (e.g. 'decoração', 'geek') or object { slug, name }
 * @returns {{ icon: string, name: string, colorClasses: string, slug: string }} Complete category metadata
 */
export const getCategoryMeta = (categoryOrSlug) => {
  if (!categoryOrSlug) {
    return { ...DEFAULT_CATEGORY_META };
  }

  let rawSlug = '';
  let rawName = '';

  if (typeof categoryOrSlug === 'object') {
    rawSlug = categoryOrSlug.slug || categoryOrSlug.id || '';
    rawName = categoryOrSlug.name || categoryOrSlug.title || categoryOrSlug.label || '';

    // Handle nested category object or fallback property
    if (!rawSlug && !rawName && categoryOrSlug.category) {
      if (typeof categoryOrSlug.category === 'string') {
        rawSlug = categoryOrSlug.category;
        rawName = categoryOrSlug.category;
      } else if (typeof categoryOrSlug.category === 'object') {
        rawSlug = categoryOrSlug.category.slug || categoryOrSlug.category.id || '';
        rawName = categoryOrSlug.category.name || categoryOrSlug.category.title || '';
      }
    }
  } else if (typeof categoryOrSlug === 'string') {
    rawSlug = categoryOrSlug;
    rawName = categoryOrSlug;
  }

  const normalizedSlug = normalizeCategoryKey(rawSlug);
  const normalizedName = normalizeCategoryKey(rawName);

  // Attempt to resolve target key via aliases or direct definition
  const targetKey =
    CATEGORY_ALIASES[normalizedSlug] ||
    CATEGORY_ALIASES[normalizedName] ||
    (CATEGORY_DEFINITIONS[normalizedSlug] ? normalizedSlug : null) ||
    (CATEGORY_DEFINITIONS[normalizedName] ? normalizedName : null);

  if (targetKey && CATEGORY_DEFINITIONS[targetKey]) {
    const meta = CATEGORY_DEFINITIONS[targetKey];
    return {
      slug: meta.slug,
      // If a specific human name was provided on the object, preserve it; otherwise use official name
      name: (typeof categoryOrSlug === 'object' && categoryOrSlug.name) ? categoryOrSlug.name : meta.name,
      icon: meta.icon,
      colorClasses: meta.colorClasses,
    };
  }

  // Graceful fallback for dynamic / unmapped categories
  const fallbackDisplayName =
    (typeof categoryOrSlug === 'object' && (categoryOrSlug.name || categoryOrSlug.label || categoryOrSlug.slug)) ||
    (typeof categoryOrSlug === 'string' && categoryOrSlug.trim()) ||
    DEFAULT_CATEGORY_META.name;

  return {
    slug: normalizedSlug || normalizedName || DEFAULT_CATEGORY_META.slug,
    name: fallbackDisplayName,
    icon: DEFAULT_CATEGORY_META.icon,
    colorClasses: DEFAULT_CATEGORY_META.colorClasses,
  };
};

export default {
  DEFAULT_CATEGORY_META,
  CATEGORY_DEFINITIONS,
  CATEGORY_LIST,
  CATEGORY_ALIASES,
  normalizeCategoryKey,
  getCategoryMeta,
};
