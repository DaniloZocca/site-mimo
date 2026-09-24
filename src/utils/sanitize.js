import DOMPurify from 'dompurify';

/**
 * Allowed HTML tags for Product Rich Text rendering in MIMOO LAB.
 */
export const ALLOWED_TAGS = [
  'p',
  'br',
  'strong',
  'b',
  'em',
  'i',
  'u',
  's',
  'h2',
  'h3',
  'h4',
  'ul',
  'ol',
  'li',
  'span',
  'a',
  'blockquote',
];

/**
 * Allowed attributes for sanitized elements.
 */
export const ALLOWED_ATTR = ['href', 'target', 'rel', 'class', 'title'];

/**
 * Sanitizes an untrusted HTML string to prevent XSS vulnerabilities,
 * guaranteeing only safe formatting and structure are rendered.
 *
 * @param {string} dirty - Raw HTML string
 * @returns {string} Clean, safe HTML string
 */
export const sanitizeHtml = (dirty) => {
  if (!dirty || typeof dirty !== 'string') return '';

  return DOMPurify.sanitize(dirty, {
    ALLOWED_TAGS,
    ALLOWED_ATTR,
    FORBID_TAGS: ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button'],
    FORBID_ATTR: ['onerror', 'onload', 'onclick', 'onmouseover', 'onfocus', 'style'],
  });
};

export default sanitizeHtml;
