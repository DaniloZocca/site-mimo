import React from 'react';
import { getCategoryMeta } from '../../constants/categories';

/**
 * Size styling classes dictionary for CategoryBadge.
 */
const SIZE_CLASSES = {
  sm: 'text-xs px-2 py-0.5 gap-1',
  md: 'text-xs sm:text-sm px-3 py-1 gap-1.5 font-semibold',
};

/**
 * Reusable CategoryBadge component for MIMOO LAB.
 * Renders categorized badge with emoji icon, contextual colors, and rounded styling.
 *
 * @param {Object} props
 * @param {string|Object} props.category - Category string (slug or title) or category object ({ slug, name })
 * @param {'sm'|'md'} [props.size='md'] - Badge size variant ('sm' for catalog cards, 'md' for PDP/Modal)
 * @param {string} [props.className=''] - Additional CSS classes
 * @returns {JSX.Element}
 */
export const CategoryBadge = ({ category, size = 'md', className = '' }) => {
  const meta = getCategoryMeta(category);
  const sizeClasses = SIZE_CLASSES[size] || SIZE_CLASSES.md;

  return (
    <span
      className={`inline-flex items-center font-bold rounded-full border shadow-sm ${meta.colorClasses} ${sizeClasses} ${className}`}
      data-category-slug={meta.slug}
    >
      <span className="text-sm select-none leading-none">{meta.icon}</span>
      <span>{meta.name}</span>
    </span>
  );
};

export default CategoryBadge;
