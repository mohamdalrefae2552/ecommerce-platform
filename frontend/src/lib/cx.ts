/** Joins the truthy class names: cx('btn', active && 'is-active'). */
export function cx(...parts: readonly (string | false | null | undefined)[]): string {
  return parts.filter((part) => typeof part === 'string' && part !== '').join(' ');
}
