import { clsx, type ClassValue } from 'clsx'
import { extendTailwindMerge } from 'tailwind-merge'

/**
 * Os tokens de tipografia do design system (text-micro, text-meta, text-body,
 * text-copy, text-title) precisam ser registrados como font-size; caso
 * contrário o tailwind-merge os trata como cor de texto e remove conflitos
 * como `text-white` ao combinar variantes.
 */
const twMerge = extendTailwindMerge({
  extend: {
    classGroups: {
      'font-size': [{ text: ['micro', 'meta', 'body', 'copy', 'title'] }],
    },
  },
})

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs))
}
