// Client mirrors of the rules/2 parts the guided builder reads (contracts/presets/rules.v2).
// Presentation only: the server evaluates every rule and returns the results in the draft preview.
import type { Scalar, ValidationIssue } from './types'

export interface BuilderStep { id: string; label: string; paths?: string[]; required?: string[]; complete_when?: unknown }
export interface BuilderOption { value: Scalar; label?: string; compendium_id?: string; requires?: unknown }
export interface BuilderChoice { id: string; path: string; label?: string; options: BuilderOption[] }
export interface BuilderRules {
  budgets?: { id: string; label: string }[]
  costs?: { id: string; phase: 'creation' | 'advancement'; budget: string; targets: string[] }[]
  derived?: { id: string; path: string; label?: string; formula?: unknown }[]
  effects?: { id: string; label?: string }[]
  creation?: { steps?: BuilderStep[]; choices?: BuilderChoice[] }
}

export interface StepProgress { id: string; available: boolean; complete: boolean; missing: string[]; condition_met: boolean; issues: number }
export interface CreationProgress { complete: boolean; steps: StepProgress[]; issues: ValidationIssue[] }
export interface ChoiceAvailability { id: string; path: string; value: Scalar; options: { value: Scalar; available: boolean }[] }
export interface TraceEntry {
  derived: string
  path: string
  base: Scalar
  value: Scalar
  modifiers: { effect: string; mode: 'add' | 'set'; value: Scalar; applied: boolean; source: { kind: 'rule' | 'item'; collection?: string; item_id?: string } }[]
}
/** A sheet as projected by the server for the viewer: derived values included, never stored. */
export interface SheetView { sheet: Record<string, unknown>; decision_trace: TraceEntry[]; creation?: CreationProgress; choices?: ChoiceAvailability[] }

/** A rules target ("/a/b" or "/a/*" for direct children) matches a concrete pointer. */
export function matchesTarget(target: string, path: string): boolean {
  if (!target.endsWith('/*')) return target === path
  const prefix = target.slice(0, -1)
  return path.startsWith(prefix) && path.length > prefix.length && !path.slice(prefix.length).includes('/')
}

/** Whether two targets can address a common field (used to relate costs to creation steps). */
export function overlaps(a: string, b: string): boolean {
  const base = (t: string) => (t.endsWith('/*') ? t.slice(0, -2) : t)
  const [x, y] = [base(a), base(b)]
  return x === y || x.startsWith(`${y}/`) || y.startsWith(`${x}/`)
}
