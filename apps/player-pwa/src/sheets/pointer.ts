/** RFC 6901 helpers. */
export function segments(pointer: string): string[] {
  if (!pointer.startsWith('/')) throw new Error('pointer.invalid_syntax')
  return pointer.slice(1).split('/').map(s => s.replace(/~1/g, '/').replace(/~0/g, '~'))
}

export function read(doc: unknown, pointer: string): unknown {
  let node: unknown = doc
  for (const segment of segments(pointer)) {
    if (node === null || typeof node !== 'object' || Array.isArray(node) || !(segment in (node as object))) return undefined
    node = (node as Record<string, unknown>)[segment]
  }
  return node
}
