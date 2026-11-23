// Turns a prerequisite formula from the preset (rules/2 AST) into a short sentence for players,
// using the preset's own labels: {"compare ge", ref /progress/level, 3} → "Nível 3 ou mais".
// Presentation only: the server evaluates the real rule. Unknown shapes fall back to null so the
// caller shows a generic line instead of inventing one.

type Node = Record<string, unknown>
export interface LabelSource {
  field: (path: string) => string | undefined
  option: (path: string, value: unknown) => string | undefined
}

const isNode = (v: unknown): v is Node => typeof v === 'object' && v !== null && !Array.isArray(v)

export function describeRequirement(formula: unknown, labels: LabelSource): string | null {
  if (!isNode(formula)) return null
  const op = formula.op
  const args = Array.isArray(formula.args) ? formula.args : []
  if (op === 'and' || op === 'or') {
    const parts = args.map(a => describeRequirement(a, labels))
    if (parts.some(p => p === null)) return null
    return parts.join(op === 'and' ? ' e ' : ' ou ')
  }
  if (op === 'not') {
    const inner = describeRequirement(args[0], labels)
    return inner ? `não ${inner}` : null
  }
  if (op === 'compare') {
    const [left, right] = args
    const ref = isNode(left) && typeof left.ref === 'string' ? left.ref : null
    const value = isNode(right) && 'const' in right ? right.const : undefined
    if (!ref || value === undefined) return null
    const name = labels.field(ref) ?? ref.split('/').pop() ?? ref
    const shown = labels.option(ref, value) ?? String(value)
    switch (formula.cmp) {
      case 'eq': return `${name}: ${shown}`
      case 'ne': return `${name} diferente de ${shown}`
      case 'ge': return `${name} ${shown} ou mais`
      case 'gt': return `${name} acima de ${shown}`
      case 'le': return `${name} ${shown} ou menos`
      case 'lt': return `${name} abaixo de ${shown}`
      default: return null
    }
  }
  return null
}

/** Sheet pointers a formula reads (ref nodes), to link derived values to their source fields. */
export function referencedPaths(formula: unknown, out = new Set<string>()): Set<string> {
  if (Array.isArray(formula)) formula.forEach(f => referencedPaths(f, out))
  else if (isNode(formula)) {
    if (typeof formula.ref === 'string') out.add(formula.ref)
    Object.values(formula).forEach(v => { if (typeof v === 'object') referencedPaths(v, out) })
  }
  return out
}
