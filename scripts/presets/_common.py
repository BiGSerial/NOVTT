"""Shared builder for the fifth-edition SRD presets (SRD 5.1 / SRD 5.2.1).

The edition scripts (`build_dnd_5e_2014.py`, `build_dnd_5e_2024.py`) hold the data copied from each
SRD (tables, lists, levels) and call `build(cfg)`, which assembles the five package parts with the
generic engine primitives only (budgets, formula costs, holds, derived values, effects, constraints,
tracks, resources, rolls). The engine never sees a game id; every meaning lives in these tables.

Licence: SRD content is CC BY 4.0. Each package carries the exact attribution statement of its SRD
(manifest `license.attribution` and compendium `attributions`). Labels are short functional names;
no SRD prose is copied into summaries.
"""
from __future__ import annotations

import hashlib
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]

# ---------------------------------------------------------------- formula helpers (engine AST)
def const(v): return {"const": v}
def ref(p): return {"ref": p}
def der(i): return {"derived": i}
def var(n): return {"var": n}
def budget(b, of="balance"): return {"budget": b, "of": of}
def item(f): return {"item": f}


def _nary(op, args, neutral):
    args = list(args)
    if not args:
        return neutral
    if len(args) == 1:
        return args[0]
    if len(args) > 32:
        return _nary(op, [_nary(op, args[i:i + 32], neutral) for i in range(0, len(args), 32)], neutral)
    return {"op": op, "args": args}


def add(*a): return _nary("add", a, const(0))
def mul(*a): return _nary("multiply", a, const(1))
def maximum(*a): return _nary("max", a, const(0))
def minimum(*a): return _nary("min", a, const(0))
def and_(*a): return _nary("and", a, const(True))
def or_(*a): return _nary("or", a, const(False))
def sub(a, b): return {"op": "subtract", "args": [a, b]}
def div(a, b): return {"op": "divide", "args": [a, b]}
def floor_(a): return {"op": "floor", "args": [a]}
def not_(a): return {"op": "not", "args": [a]}
def cmp(c, a, b): return {"op": "compare", "cmp": c, "args": [a, b]}
def eq(a, b): return cmp("eq", a, b)
def ne(a, b): return cmp("ne", a, b)
def ge(a, b): return cmp("ge", a, b)
def le(a, b): return cmp("le", a, b)
def lt(a, b): return cmp("lt", a, b)
def if_(c, a, b): return {"op": "if", "args": [c, a, b]}


def table(key, mapping, default=0):
    return {"op": "lookup", "key": key, "table": dict(mapping), "default": const(default)}


def steps(key, pairs, default=None):
    node = {"op": "lookup", "key": key, "steps": [list(p) for p in pairs]}
    if default is not None:
        node["default"] = const(default)
    return node


def in_(key, values):
    """key is one of values (a table lookup to booleans)."""
    return table(key, {v: True for v in values}, False)


def mod_formula(score):
    return floor_(div(sub(score, const(10)), const(2)))


# ---------------------------------------------------------------- shared SRD tables (identical in SRD 5.1 and SRD 5.2.1)
ABILITIES = [("str", "Força", "FOR", "Strength"), ("dex", "Destreza", "DES", "Dexterity"), ("con", "Constituição", "CON", "Constitution"),
             ("int", "Inteligência", "INT", "Intelligence"), ("wis", "Sabedoria", "SAB", "Wisdom"), ("cha", "Carisma", "CAR", "Charisma")]
AB_IDS = [a[0] for a in ABILITIES]
AB_LABEL = {a[0]: a[1] for a in ABILITIES}

# (id, pt-BR label, SRD name, ability)
SKILLS = [
    ("acrobatics", "Acrobacia", "Acrobatics", "dex"), ("animal_handling", "Adestrar Animais", "Animal Handling", "wis"),
    ("arcana", "Arcanismo", "Arcana", "int"), ("athletics", "Atletismo", "Athletics", "str"),
    ("deception", "Enganação", "Deception", "cha"), ("history", "História", "History", "int"),
    ("insight", "Intuição", "Insight", "wis"), ("intimidation", "Intimidação", "Intimidation", "cha"),
    ("investigation", "Investigação", "Investigation", "int"), ("medicine", "Medicina", "Medicine", "wis"),
    ("nature", "Natureza", "Nature", "int"), ("perception", "Percepção", "Perception", "wis"),
    ("performance", "Atuação", "Performance", "cha"), ("persuasion", "Persuasão", "Persuasion", "cha"),
    ("religion", "Religião", "Religion", "int"), ("sleight_of_hand", "Prestidigitação", "Sleight of Hand", "dex"),
    ("stealth", "Furtividade", "Stealth", "dex"), ("survival", "Sobrevivência", "Survival", "wis"),
]
SKILL_IDS = [s[0] for s in SKILLS]
SKILL_LABEL = {s[0]: s[1] for s in SKILLS}

XP_THRESHOLDS = [0, 300, 900, 2700, 6500, 14000, 23000, 34000, 48000, 64000, 85000, 100000, 120000, 140000,
                 165000, 195000, 225000, 265000, 305000, 355000]
PROFICIENCY_STEPS = [(1, 2), (5, 3), (9, 4), (13, 5), (17, 6)]

# Full caster spell slots by class level, slot levels 1..9 (identical tables in both SRDs).
FULL_SLOTS = {
    1: [2, 0, 0, 0, 0, 0, 0, 0, 0], 2: [3, 0, 0, 0, 0, 0, 0, 0, 0], 3: [4, 2, 0, 0, 0, 0, 0, 0, 0], 4: [4, 3, 0, 0, 0, 0, 0, 0, 0],
    5: [4, 3, 2, 0, 0, 0, 0, 0, 0], 6: [4, 3, 3, 0, 0, 0, 0, 0, 0], 7: [4, 3, 3, 1, 0, 0, 0, 0, 0], 8: [4, 3, 3, 2, 0, 0, 0, 0, 0],
    9: [4, 3, 3, 3, 1, 0, 0, 0, 0], 10: [4, 3, 3, 3, 2, 0, 0, 0, 0], 11: [4, 3, 3, 3, 2, 1, 0, 0, 0], 12: [4, 3, 3, 3, 2, 1, 0, 0, 0],
    13: [4, 3, 3, 3, 2, 1, 1, 0, 0], 14: [4, 3, 3, 3, 2, 1, 1, 0, 0], 15: [4, 3, 3, 3, 2, 1, 1, 1, 0], 16: [4, 3, 3, 3, 2, 1, 1, 1, 0],
    17: [4, 3, 3, 3, 2, 1, 1, 1, 1], 18: [4, 3, 3, 3, 3, 1, 1, 1, 1], 19: [4, 3, 3, 3, 3, 2, 1, 1, 1], 20: [4, 3, 3, 3, 3, 2, 2, 1, 1],
}
# Half casters from class level 3 on (identical in both SRDs; levels 1-2 differ and come from the edition).
HALF_SLOTS_FROM_3 = {
    3: [3, 0, 0, 0, 0], 4: [3, 0, 0, 0, 0], 5: [4, 2, 0, 0, 0], 6: [4, 2, 0, 0, 0], 7: [4, 3, 0, 0, 0], 8: [4, 3, 0, 0, 0],
    9: [4, 3, 2, 0, 0], 10: [4, 3, 2, 0, 0], 11: [4, 3, 3, 0, 0], 12: [4, 3, 3, 0, 0], 13: [4, 3, 3, 1, 0], 14: [4, 3, 3, 1, 0],
    15: [4, 3, 3, 2, 0], 16: [4, 3, 3, 2, 0], 17: [4, 3, 3, 3, 1], 18: [4, 3, 3, 3, 1], 19: [4, 3, 3, 3, 2], 20: [4, 3, 3, 3, 2],
}
# Pact Magic: (slots, slot level) by warlock level (identical in both SRDs).
PACT = {1: (1, 1), 2: (2, 1), 3: (2, 2), 4: (2, 2), 5: (2, 3), 6: (2, 3), 7: (2, 4), 8: (2, 4), 9: (2, 5), 10: (2, 5),
        11: (3, 5), 12: (3, 5), 13: (3, 5), 14: (3, 5), 15: (3, 5), 16: (3, 5), 17: (4, 5), 18: (4, 5), 19: (4, 5), 20: (4, 5)}

# (id, pt-BR label, category, base AC) — same values in both SRDs; SRD names come from the edition.
ARMOR = [
    ("padded", "Acolchoada", "light", 11), ("leather", "Couro", "light", 11), ("studded_leather", "Couro batido", "light", 12),
    ("hide", "Gibão de peles", "medium", 12), ("chain_shirt", "Camisão de malha", "medium", 13), ("scale_mail", "Brunea", "medium", 14),
    ("breastplate", "Peitoral", "medium", 14), ("half_plate", "Meia-armadura", "medium", 15),
    ("ring_mail", "Cota de anéis", "heavy", 14), ("chain_mail", "Cota de malha", "heavy", 16), ("splint", "Cota de talas", "heavy", 17),
    ("plate", "Armadura de placas", "heavy", 18),
]
CONDITIONS = [("blinded", "Cego", "Blinded"), ("charmed", "Enfeitiçado", "Charmed"), ("deafened", "Surdo", "Deafened"),
              ("frightened", "Amedrontado", "Frightened"), ("grappled", "Agarrado", "Grappled"), ("incapacitated", "Incapacitado", "Incapacitated"),
              ("invisible", "Invisível", "Invisible"), ("paralyzed", "Paralisado", "Paralyzed"), ("petrified", "Petrificado", "Petrified"),
              ("poisoned", "Envenenado", "Poisoned"), ("prone", "Caído", "Prone"), ("restrained", "Impedido", "Restrained"),
              ("stunned", "Atordoado", "Stunned"), ("unconscious", "Inconsciente", "Unconscious")]
ALIGNMENTS = [("lg", "Leal e Bom"), ("ng", "Neutro e Bom"), ("cg", "Caótico e Bom"), ("ln", "Leal e Neutro"), ("n", "Neutro"),
              ("cn", "Caótico e Neutro"), ("le", "Leal e Mau"), ("ne", "Neutro e Mau"), ("ce", "Caótico e Mau")]
SCHOOLS = [("abjuration", "Abjuração"), ("conjuration", "Conjuração"), ("divination", "Adivinhação"), ("enchantment", "Encantamento"),
           ("evocation", "Evocação"), ("illusion", "Ilusão"), ("necromancy", "Necromancia"), ("transmutation", "Transmutação")]
PROFS = [("armor_light", "Armaduras leves"), ("armor_medium", "Armaduras médias"), ("armor_heavy", "Armaduras pesadas"),
         ("shields", "Escudos"), ("weapons_simple", "Armas simples"), ("weapons_martial", "Armas marciais")]


def level_steps(by_level: dict, index=None):
    """{level: value} (or {level: [values]} with index) -> compact steps starting at level 1."""
    out, last = [], None
    for lvl in range(1, 21):
        v = by_level.get(lvl, 0) if index is None else (by_level[lvl][index] if lvl in by_level else 0)
        if v != last:
            out.append((lvl, v))
            last = v
    return out


# ---------------------------------------------------------------- schema helpers
def s_str(n=200): return {"type": "string", "maxLength": n}
def s_int(lo, hi, default=None):
    d = {"type": "integer", "minimum": lo, "maximum": hi}
    if default is not None:
        d["default"] = default
    return d
def s_bool(): return {"type": "boolean", "default": False}
def s_enum(values): return {"type": "string", "enum": list(values)}
def obj(props): return {"type": "object", "additionalProperties": False, "properties": props}
ITEM_ID = {"type": "string", "pattern": "^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$"}
def coll(props, required, max_items):
    return {"type": "array", "maxItems": max_items, "items": {"type": "object", "additionalProperties": False,
            "required": ["id", *required], "properties": {"id": ITEM_ID, **props}}}
INT = {"type": "integer"}
STR = {"type": "string"}


def field(path, label, widget, **kw): return {"kind": "field", "path": path, "label": label, "widget": widget, **kw}
def fix(path, value, label, mode="exact"): return {"path": path, "value": const(value), "label": label, "mode": mode}


def canonical_hash(entry: dict) -> str:
    body = {k: v for k, v in entry.items() if k != "content_hash"}
    return hashlib.sha256(json.dumps(body, sort_keys=True, separators=(",", ":"), ensure_ascii=False).encode("utf-8")).hexdigest()


def _pretty(value, depth: int, limit: int, pad: str = "") -> str:
    """Indented down to `limit` levels, compact below: one rule/formula per line keeps diffs readable and the
    package well under the 512 KiB import limit (deep formula ASTs triple in size when fully indented)."""
    if depth >= limit or not isinstance(value, (dict, list)) or not value:
        return json.dumps(value, ensure_ascii=False, separators=(", ", ": "))
    inner = pad + "  "
    if isinstance(value, dict):
        body = ",\n".join(f"{inner}{json.dumps(k, ensure_ascii=False)}: {_pretty(v, depth + 1, limit, inner)}" for k, v in value.items())
        return "{\n" + body + "\n" + pad + "}"
    body = ",\n".join(inner + _pretty(v, depth + 1, limit, inner) for v in value)
    return "[\n" + body + "\n" + pad + "]"


def write_json(path: Path, data, limit: int = 3):
    path.write_text(_pretty(data, 0, limit) + "\n", encoding="utf-8")


# ---------------------------------------------------------------- package builder
def build(cfg: dict) -> dict:
    """Assemble manifest, sheet schema, sheet UI, rules and compendium for one edition."""
    ed = cfg
    classes = ed["classes"]
    origin = ed["origin"]               # race (2014) or species (2024)
    OF = origin["field"]
    CLASS, SUBCLASS, LEVEL = ref("/character/class"), ref("/character/subclass"), ref("/progress/level")
    ORIGIN, BG, METHOD = ref(f"/origin/{OF}"), ref("/origin/background"), ref("/character/ability_method")
    SHIELD, ARMOR_REF = ref("/combat/shield"), ref("/combat/armor")
    exh = ref("/conditions/exhaustion")
    point_methods = ed["point_methods"]

    def is_class(c): return eq(CLASS, const(c))
    def is_sub(s): return eq(SUBCLASS, const(s))
    def per_class(fn, default=0):
        """add over classes of if(class == c, fn(c), 0) for classes where fn gives a formula."""
        terms = []
        for cid, c in classes.items():
            f = fn(cid, c)
            if f is not None:
                terms.append(if_(is_class(cid), f, const(default)))
        return add(*terms) if terms else const(default)

    mod = {a: der(f"mod_{a}") for a in AB_IDS}
    PB = der("pb")

    # ------------------------------------------------ choices of the origin (choice-specific data)
    origin_items = origin["items"]
    extra_choices = ed.get("origin_options", [])  # subrace / lineage / ancestry / keen senses / size / species feat

    # Skills given by a selected option (held with at_least): {skill: [condition, ...]}
    held_skill = {s: [] for s in SKILL_IDS}
    for o in origin_items:
        for s in o.get("skills", []):
            held_skill[s].append(eq(ORIGIN, const(o["id"])))
    for b in ed["backgrounds"]:
        for s in b["skills"]:
            held_skill[s].append(eq(BG, const(b["id"])))
    for ch in extra_choices:
        for opt in ch["items"]:
            for s in opt.get("skills", []):
                held_skill[s].append(eq(ref(f"/origin/{ch['field']}"), const(opt["id"])))

    # Skill picks open to any skill (race/species traits, feats, subclass features).
    any_terms = []
    any_origin = {o["id"]: o["any_skills"] for o in origin_items if o.get("any_skills")}
    if any_origin:
        any_terms.append(table(ORIGIN, any_origin, 0))
    for cid, c in classes.items():
        sc = c["subclass"]
        if sc.get("any_skills"):
            any_terms.append(if_(is_sub(sc["id"]), const(sc["any_skills"]), const(0)))
    for ch in extra_choices:
        for opt in ch["items"]:
            if opt.get("any_skills"):
                any_terms.append(if_(eq(ref(f"/origin/{ch['field']}"), const(opt["id"])), const(opt["any_skills"]), const(0)))
    any_picks = add(*any_terms) if any_terms else const(0)
    slack_terms = [if_(eq(ref(f"/origin/{ch['field']}"), const(opt["id"])), const(opt["optional_any_skills"]), const(0))
                   for ch in extra_choices for opt in ch["items"] if opt.get("optional_any_skills")]
    any_picks_total = add(any_picks, *slack_terms) if slack_terms else any_picks

    def class_has(s):
        have = [cid for cid, c in classes.items() if c["skill_list"] is None or s in c["skill_list"]]
        return in_(CLASS, have)

    # ------------------------------------------------ sheet schema
    origin_enum = [o["id"] for o in origin_items]
    character_props = {
        "class": s_enum(classes), "subclass": s_enum([c["subclass"]["id"] for c in classes.values()]),
        "ability_method": {**s_enum([m[0] for m in ed["ability_methods"]]), "default": point_methods[0]},
    }
    for co in ed.get("class_options", []):
        character_props[co["field"]] = s_enum([o["id"] for o in co["items"]])
    origin_props = {OF: s_enum(origin_enum), "background": s_enum([b["id"] for b in ed["backgrounds"]])}
    for ch in extra_choices:
        origin_props[ch["field"]] = s_enum([o["id"] for o in ch["items"]])
    if ed.get("background_feats"):
        origin_props["background_feat"] = STR
    if not any(ch["field"] == "size" for ch in extra_choices):
        origin_props["size"] = STR

    spell_props = {"ability": STR, "modifier": INT, "save_dc": INT, "attack_bonus": INT, "cantrips_known": INT, "prepared": INT,
                   "pact_slots": INT, "pact_slot_level": INT, "slots": obj({f"l{k}": INT for k in range(1, 10)})}
    schema = {
        "$schema": "https://json-schema.org/draft/2020-12/schema",
        "title": f"dnd-5e {ed['variant_id']} {ed['version']} sheet",
        "type": "object", "additionalProperties": False,
        "properties": {
            "identity": obj({"name": s_str(120), "player": s_str(120), "alignment": s_enum([a[0] for a in ALIGNMENTS]), "age": s_str(40)}),
            "character": obj(character_props),
            "origin": obj(origin_props),
            "progress": obj({"level": s_int(1, 20, 1), "xp": INT, "proficiency_bonus": INT}),
            "abilities": obj({
                "base": obj({a: s_int(8, 15, 8) for a in AB_IDS}),
                "assigned": obj({a: s_int(3, 18, 10) for a in AB_IDS}),
                "origin": obj({a: s_int(0, 2, 0) for a in AB_IDS}),
                "improvement": obj({a: s_int(0, 12, 0) for a in AB_IDS}),
                "adjust": obj({a: s_int(-10, 20, 0) for a in AB_IDS}),
                "score": obj({a: INT for a in AB_IDS}),
                "mod": obj({a: INT for a in AB_IDS}),
            }),
            "checks": obj({a: INT for a in AB_IDS}),
            "saving_throws": obj({a: s_bool() for a in AB_IDS}),
            "saves": obj({a: INT for a in AB_IDS}),
            "skills": obj({s: s_int(0, 1, 0) for s in SKILL_IDS}),
            "expertise": obj({s: s_int(0, 1, 0) for s in SKILL_IDS}),
            "skill_bonus": obj({s: INT for s in SKILL_IDS}),
            "senses": obj({"passive_perception": INT, "darkvision": INT}),
            "proficiencies": obj({**{p: s_bool() for p, _ in PROFS}, "class_notes": STR, "other": s_str(500), "tools": s_str(300), "languages": s_str(300)}),
            "combat": obj({
                "armor": {**s_enum(["none", *[a[0] for a in ARMOR]]), "default": "none"}, "shield": s_bool(), "ac_bonus": s_int(-5, 10, 0),
                "armor_class": INT, "initiative": INT, "speed": INT, "hp_max": INT, "hit_die": INT, "hp_adjust": s_int(-999, 999, 0),
                "death_successes": s_int(0, 3, 0), "death_failures": s_int(0, 3, 0), "inspiration": s_bool(),
                "attack": obj({"melee": INT, "ranged": INT, "finesse": INT, "spell": INT}),
            }),
            "resources": obj({"hp": s_int(0, 999, 0), "temp_hp": s_int(0, 999, 0), "hit_dice_spent": s_int(0, 20, 0),
                              "slots_used": obj({f"l{k}": s_int(0, 4, 0) for k in range(1, 10)}), "pact_used": s_int(0, 4, 0)}),
            "spellcasting": obj(spell_props),
            "conditions": obj({**{c[0]: s_bool() for c in CONDITIONS}, "exhaustion": s_int(0, 6, 0)}),
            "currency": obj({k: s_int(0, 999999, 0) for k in ["cp", "sp", "ep", "gp", "pp"]}),
            "load": obj({"carried": {"type": "number"}, "capacity": INT}),
            "computed": obj({"off_list_a": INT, "off_list_b": INT, "off_list_c": INT, "caster_type": STR}),
            "attacks": coll({"name": s_str(80), "bonus": s_str(20), "damage": s_str(40), "damage_type": s_str(40), "range": s_str(40), "notes": s_str(200)}, ["name"], 30),
            "inventory": coll({"name": s_str(80), "quantity": s_int(0, 9999, 1), "weight": {"type": "number", "minimum": 0, "maximum": 10000},
                               "equipped": s_bool(), "notes": s_str(200)}, ["name"], 100),
            "spells": coll({"name": s_str(80), "level": s_int(0, 9, 0), "school": s_enum([x[0] for x in SCHOOLS]), "prepared": s_bool(),
                            "concentration": s_bool(), "ritual": s_bool(), "notes": s_str(300)}, ["name"], 120),
            "features": coll({"name": s_str(80), "source": s_enum(["class", "subclass", OF, "background", "feat", "other"]),
                              "level": s_int(1, 20, 1), "uses_max": s_int(0, 99, 0), "uses_spent": s_int(0, 99, 0), "notes": s_str(300)}, ["name"], 80),
            "feats": coll({"name": s_str(80), "category": s_str(40), "notes": s_str(300)}, ["name"], 30),
            "description": obj({"personality": s_str(1000), "ideals": s_str(500), "bonds": s_str(500), "flaws": s_str(500),
                                "appearance": s_str(1000), "backstory": s_str(4000), "notes": s_str(4000)}),
        },
    }

    # ------------------------------------------------ derived values
    def score_formula(a):
        chosen = if_(in_(METHOD, point_methods), ref(f"/abilities/base/{a}"), ref(f"/abilities/assigned/{a}"))
        return add(chosen, ref(f"/abilities/origin/{a}"), ref(f"/abilities/improvement/{a}"), ref(f"/abilities/adjust/{a}"))

    caster_type = der("caster_type")
    spell_ability = table(CLASS, {cid: c["spell_ability"] for cid, c in classes.items() if c.get("spell_ability")}, "")
    late = {cid: c["spell_from"] for cid, c in classes.items() if c.get("spell_from", 1) > 1}
    if late:  # e.g. half casters that gain Spellcasting at level 2
        spell_ability = if_(ge(LEVEL, table(CLASS, late, 1)), spell_ability, const(""))
    spell_mod = if_(eq(spell_ability, const("int")), mod["int"], if_(eq(spell_ability, const("wis")), mod["wis"], if_(eq(spell_ability, const("cha")), mod["cha"], const(0))))
    is_caster = ne(der("spell_ability"), const(""))

    armor_cat = table(ARMOR_REF, {a[0]: a[2] for a in ARMOR}, "none")
    armor_base = table(ARMOR_REF, {a[0]: a[3] for a in ARMOR}, 10)
    dex_part = if_(eq(armor_cat, const("light")), mod["dex"], if_(eq(armor_cat, const("medium")), minimum(mod["dex"], const(2)), const(0)))
    unarmored_terms = [add(const(10), mod["dex"])]
    for cid, c in classes.items():
        ud = c.get("unarmored")
        if ud:
            cond = is_class(cid) if ud["shield_ok"] else and_(is_class(cid), not_(SHIELD))
            unarmored_terms.append(if_(cond, add(const(ud["base"]), *[mod[x] for x in ud["abilities"]]), const(0)))
        sud = c["subclass"].get("unarmored")
        if sud:
            unarmored_terms.append(if_(is_sub(c["subclass"]["id"]), add(const(sud["base"]), *[mod[x] for x in sud["abilities"]]), const(0)))
    ac = add(if_(eq(ARMOR_REF, const("none")), maximum(*unarmored_terms), add(armor_base, dex_part)),
             if_(SHIELD, const(2), const(0)), ref("/combat/ac_bonus"))

    speed_terms = [table(ORIGIN, {o["id"]: o["speed"] for o in origin_items}, 30)]
    for ch in extra_choices:
        for opt in ch["items"]:
            if opt.get("speed"):
                speed_terms.append(if_(eq(ref(f"/origin/{ch['field']}"), const(opt["id"])), const(opt["speed"] - origin_speed(origin_items, opt["parent"])), const(0)))
    for cid, c in classes.items():
        for sp in c.get("speed", []):
            if sp["unless"] == "heavy":
                cond = and_(is_class(cid), ne(armor_cat, const("heavy")))
            else:  # no armor and no shield
                cond = and_(is_class(cid), eq(ARMOR_REF, const("none")), not_(SHIELD))
            speed_terms.append(if_(cond, steps(LEVEL, sp["steps"]), const(0)))
    darkvision = table(ORIGIN, {o["id"]: o.get("darkvision", 0) for o in origin_items}, 0)
    dv_over = [(ch["field"], opt["id"], opt["darkvision"]) for ch in extra_choices for opt in ch["items"] if opt.get("darkvision")]
    for f, oid, dv in dv_over:
        darkvision = if_(eq(ref(f"/origin/{f}"), const(oid)), const(dv), darkvision)

    hd = table(CLASS, {cid: c["hd"] for cid, c in classes.items()}, 0)
    per_level = table(CLASS, {cid: c["hd"] // 2 + 1 for cid, c in classes.items()}, 0)
    gain = add(per_level, mod["con"])
    if ed["hp_min_one_per_level"]:
        gain = maximum(const(1), gain)
    hp_max = add(if_(ne(CLASS, const("")), add(hd, mod["con"], mul(sub(LEVEL, const(1)), gain)), const(0)), ref("/combat/hp_adjust"))

    def class_steps(key):
        return per_class(lambda cid, c: steps(LEVEL, c[key]) if c.get(key) else None)

    def prepared_formula():
        terms = []
        for cid, c in classes.items():
            p = c.get("prepared")
            if not p:
                continue
            if p[0] == "steps":
                f = steps(LEVEL, p[1])
            else:  # ("mod_plus_level", divisor): spellcasting modifier + level / divisor, minimum 1
                lvl = LEVEL if p[1] == 1 else floor_(div(LEVEL, const(p[1])))
                f = maximum(const(1), add(der("spell_mod"), lvl))
                if c.get("spell_from", 1) > 1:
                    f = if_(ge(LEVEL, const(c["spell_from"])), f, const(0))
            terms.append(if_(is_class(cid), f, const(0)))
        return add(*terms)

    half_slots = {**ed["half_slots_1_2"], **HALF_SLOTS_FROM_3}
    slots = []
    for k in range(1, 10):
        full = steps(LEVEL, level_steps(FULL_SLOTS, k - 1))
        half = steps(LEVEL, level_steps(half_slots, k - 1)) if k <= 5 else const(0)
        slots.append({"id": f"slot_{k}", "path": f"/spellcasting/slots/l{k}", "label": f"Espaços de magia de {k}º círculo",
                      "formula": if_(eq(caster_type, const("full")), full, if_(eq(caster_type, const("half")), half, const(0)))})

    pact_slots = if_(is_class("warlock"), steps(LEVEL, level_steps({l: v[0] for l, v in PACT.items()})), const(0))
    pact_level = if_(is_class("warlock"), steps(LEVEL, level_steps({l: v[1] for l, v in PACT.items()})), const(0))

    # Skills paid from the pick budget that are outside the class list and not given by an option.
    def off_list(skills):
        terms = []
        for s in skills:
            held = or_(*held_skill[s]) if held_skill[s] else const(False)
            terms.append(if_(and_(ge(ref(f"/skills/{s}"), const(1)), not_(or_(class_has(s), held))), const(1), const(0)))
        return add(*terms)

    thirds = [SKILL_IDS[0:6], SKILL_IDS[6:12], SKILL_IDS[12:18]]
    derived = [
        {"id": "pb", "path": "/progress/proficiency_bonus", "label": "Bônus de proficiência", "formula": steps(LEVEL, PROFICIENCY_STEPS)},
        {"id": "xp", "path": "/progress/xp", "label": "Pontos de experiência (concedidos pelo mestre)", "formula": budget("xp", "granted")},
        *[{"id": f"score_{a}", "path": f"/abilities/score/{a}", "label": f"{AB_LABEL[a]} (valor)", "formula": score_formula(a)} for a in AB_IDS],
        *[{"id": f"mod_{a}", "path": f"/abilities/mod/{a}", "label": f"Modificador de {AB_LABEL[a]}", "formula": mod_formula(der(f"score_{a}"))} for a in AB_IDS],
        *[{"id": f"check_{a}", "path": f"/checks/{a}", "label": f"Teste de {AB_LABEL[a]}", "formula": mod[a]} for a in AB_IDS],
        *[{"id": f"save_{a}", "path": f"/saves/{a}", "label": f"Salvaguarda de {AB_LABEL[a]}",
           "formula": add(mod[a], if_(ref(f"/saving_throws/{a}"), PB, const(0)))} for a in AB_IDS],
        *[{"id": f"skill_{s}", "path": f"/skill_bonus/{s}", "label": f"{SKILL_LABEL[s]} ({ab.upper()})",
           "formula": add(mod[ab], mul(ref(f"/skills/{s}"), PB), mul(ref(f"/expertise/{s}"), PB))} for s, _, _, ab in SKILLS],
        {"id": "passive_perception", "path": "/senses/passive_perception", "label": "Percepção passiva", "formula": add(const(10), der("skill_perception"))},
        {"id": "darkvision", "path": "/senses/darkvision", "label": "Visão no escuro (pés)", "formula": darkvision},
        {"id": "initiative", "path": "/combat/initiative", "label": "Iniciativa", "formula": der("check_dex")},
        {"id": "armor_class", "path": "/combat/armor_class", "label": "Classe de Armadura", "formula": ac},
        {"id": "speed", "path": "/combat/speed", "label": "Deslocamento (pés)", "formula": add(*speed_terms)},
        {"id": "hit_die", "path": "/combat/hit_die", "label": "Dado de Vida (faces)", "formula": hd},
        {"id": "hp_max", "path": "/combat/hp_max", "label": "Pontos de Vida máximos", "formula": hp_max},
        {"id": "attack_melee", "path": "/combat/attack/melee", "label": "Ataque corpo a corpo (FOR + proficiência)", "formula": add(mod["str"], PB)},
        {"id": "attack_ranged", "path": "/combat/attack/ranged", "label": "Ataque à distância (DES + proficiência)", "formula": add(mod["dex"], PB)},
        {"id": "attack_finesse", "path": "/combat/attack/finesse", "label": "Ataque com acuidade (FOR ou DES + proficiência)", "formula": add(maximum(mod["str"], mod["dex"]), PB)},
        {"id": "attack_spell", "path": "/combat/attack/spell", "label": "Ataque mágico", "formula": der("spell_attack")},
        {"id": "caster_type", "path": "/computed/caster_type", "label": "interno", "formula": table(CLASS, {cid: c["caster"] for cid, c in classes.items() if c["caster"]}, "none")},
        {"id": "spell_ability", "path": "/spellcasting/ability", "label": "Atributo de conjuração", "formula": spell_ability},
        {"id": "spell_mod", "path": "/spellcasting/modifier", "label": "Modificador de conjuração", "formula": spell_mod},
        {"id": "spell_save_dc", "path": "/spellcasting/save_dc", "label": "CD de magia (0 = sem conjuração)", "formula": if_(is_caster, add(const(8), PB, der("spell_mod")), const(0))},
        {"id": "spell_attack", "path": "/spellcasting/attack_bonus", "label": "Bônus de ataque mágico", "formula": if_(is_caster, add(PB, der("spell_mod")), const(0))},
        {"id": "cantrips_known", "path": "/spellcasting/cantrips_known", "label": "Truques conhecidos", "formula": class_steps("cantrips")},
        {"id": "spells_prepared", "path": "/spellcasting/prepared", "label": ed["prepared_label"], "formula": prepared_formula()},
        *slots,
        {"id": "pact_slots", "path": "/spellcasting/pact_slots", "label": "Espaços de Magia de Pacto", "formula": pact_slots},
        {"id": "pact_slot_level", "path": "/spellcasting/pact_slot_level", "label": "Círculo dos espaços de Pacto", "formula": pact_level},
        {"id": "carried", "path": "/load/carried", "label": "Peso carregado (lb)",
         "formula": {"op": "sum", "collection": "/inventory", "value": mul(item("quantity"), item("weight"))}},
        {"id": "capacity", "path": "/load/capacity", "label": "Capacidade de carga (lb)", "formula": mul(der("score_str"), ed["capacity_multiplier"](ORIGIN))},
        {"id": "class_notes", "path": "/proficiencies/class_notes", "label": "Proficiências específicas da classe",
         "formula": table(CLASS, {cid: c["notes"] for cid, c in classes.items() if c.get("notes")}, "")},
        {"id": "off_list_a", "path": "/computed/off_list_a", "label": "interno", "formula": off_list(thirds[0])},
        {"id": "off_list_b", "path": "/computed/off_list_b", "label": "interno", "formula": off_list(thirds[1])},
        {"id": "off_list_c", "path": "/computed/off_list_c", "label": "interno", "formula": off_list(thirds[2])},
    ]
    if not any(ch["field"] == "size" for ch in extra_choices):
        derived.append({"id": "size", "path": "/origin/size", "label": "Tamanho", "formula": table(ORIGIN, {o["id"]: o["size"] for o in origin_items}, "")})
    if ed.get("background_feats"):
        derived.append({"id": "background_feat", "path": "/origin/background_feat", "label": "Talento de origem do antecedente",
                        "formula": table(BG, {b["id"]: b["feat_label"] for b in ed["backgrounds"]}, "")})

    # ------------------------------------------------ effects (feature-based modifiers with decision trace)
    effects = []
    joat_class = ed["jack_of_all_trades"]  # (class, level, scope: "skills" | "all_checks")
    jc, jl, jscope = joat_class
    joat_on = and_(is_class(jc), ge(LEVEL, const(jl)))
    half_pb = floor_(div(PB, const(2)))
    for s in SKILL_IDS:
        effects.append({"id": f"jack_of_all_trades_{s}", "target": f"skill_{s}", "label": "Versatilidade (metade da proficiência)",
                        "when": and_(joat_on, eq(ref(f"/skills/{s}"), const(0))), "value": half_pb})
    if jscope == "all_checks":
        for a in AB_IDS:
            effects.append({"id": f"jack_of_all_trades_check_{a}", "target": f"check_{a}", "label": "Versatilidade (metade da proficiência)",
                            "when": joat_on, "value": half_pb})
    for cid, c in classes.items():
        for lvl, abilities in c.get("extra_saves", []):
            for a in abilities:
                effects.append({"id": f"{cid}_save_proficiency_{a}", "target": f"save_{a}", "label": f"Proficiência em salvaguarda ({c['label']} {lvl}º)",
                                "when": and_(is_class(cid), ge(LEVEL, const(lvl)), not_(ref(f"/saving_throws/{a}"))), "value": PB})
        aura = c.get("save_aura")
        if aura:
            for a in AB_IDS:
                effects.append({"id": f"{cid}_aura_{a}", "target": f"save_{a}", "label": aura["label"],
                                "when": and_(is_class(cid), ge(LEVEL, const(aura["level"]))), "value": maximum(const(1), mod[aura["ability"]])})
        sc = c["subclass"]
        if sc.get("hp_per_level"):
            effects.append({"id": f"{sc['id']}_hp", "target": "hp_max", "label": sc["hp_label"], "when": is_sub(sc["id"]), "value": LEVEL})
    for o in origin_items:
        if o.get("hp_per_level"):
            effects.append({"id": f"{o['id']}_hp", "target": "hp_max", "label": o["hp_label"], "when": eq(ORIGIN, const(o["id"])), "value": LEVEL})
    for ch in extra_choices:
        for opt in ch["items"]:
            if opt.get("hp_per_level"):
                effects.append({"id": f"{opt['id']}_hp", "target": "hp_max", "label": opt["hp_label"],
                                "when": eq(ref(f"/origin/{ch['field']}"), const(opt["id"])), "value": LEVEL})
    for co in ed.get("class_options", []):
        for opt in co["items"]:
            sb = opt.get("skill_bonus")
            if sb:
                for s in sb["skills"]:
                    effects.append({"id": f"{opt['id']}_{s}", "target": f"skill_{s}", "label": sb["label"],
                                    "when": and_(is_class(co["class"]), eq(ref(f"/character/{co['field']}"), const(opt["id"]))),
                                    "value": maximum(const(sb["min"]), mod[sb["ability"]])})
    for e in ed.get("initiative_pb_when", []):
        effects.append({"id": e["id"], "target": "initiative", "label": e["label"], "when": e["when"](BG), "value": PB})

    # ------------------------------------------------ budgets, costs, limits
    skill_picks = add(table(CLASS, {cid: c["skill_count"] for cid, c in classes.items()}, 0), any_picks)
    asi_amount = per_class(lambda cid, c: steps(LEVEL, [(1, 0)] + [(lvl, 2 * (i + 1)) for i, lvl in enumerate(c["asi_levels"])]))
    expertise_amount = per_class(lambda cid, c: steps(LEVEL, ([] if c["expertise"][0][0] == 1 else [(1, 0)]) + list(c["expertise"])) if c.get("expertise") else None)
    milestone_steps = [(i, XP_THRESHOLDS[i]) for i in range(20)]
    budgets = [
        {"id": "point_buy", "kind": "initial", "label": "Pontos de compra de atributos", "initial_amount": 27},
        {"id": "origin_asi", "kind": "initial", "label": ed["origin_asi_label"], "amount": ed["origin_asi_amount"](ORIGIN, BG)},
        {"id": "skill_picks", "kind": "initial", "label": "Perícias a escolher", "amount": add(skill_picks, *slack_terms) if slack_terms else skill_picks},
        {"id": "expertise", "kind": "other", "label": "Especializações a escolher", "amount": expertise_amount},
        {"id": "asi", "kind": "other", "label": "Aumentos de atributo por nível", "amount": asi_amount},
        {"id": "xp", "kind": "experience", "label": "Experiência (não é gasta)", "initial_amount": 0},
        {"id": "milestones", "kind": "other", "label": "Marcos concedidos pelo mestre (um nível cada)", "initial_amount": 0},
    ]
    point_cost = table(var("new"), {"14": 2, "15": 2}, 1)
    costs = [
        {"id": "point_buy", "phase": "creation", "budget": "point_buy", "targets": ["/abilities/base/*"], "model": {"type": "formula", "formula": point_cost}},
        {"id": "origin_asi", "phase": "creation", "budget": "origin_asi", "targets": ["/abilities/origin/*"], "model": {"type": "fixed_per_dot", "amount": 1}},
        *[{"id": f"skill_picks_{ph}", "phase": ph, "budget": "skill_picks", "targets": ["/skills/*"], "model": {"type": "fixed_per_dot", "amount": 1}} for ph in ("creation", "advancement")],
        *[{"id": f"expertise_{ph}", "phase": ph, "budget": "expertise", "targets": ["/expertise/*"], "model": {"type": "fixed_per_dot", "amount": 1}} for ph in ("creation", "advancement")],
        *[{"id": f"asi_{ph}", "phase": ph, "budget": "asi", "targets": ["/abilities/improvement/*"], "model": {"type": "fixed_per_dot", "amount": 1}} for ph in ("creation", "advancement")],
    ]
    phase_limits = [
        {"id": "point_buy_max", "phase": "creation", "targets": ["/abilities/base/*"], "max": 15},
        {"id": "origin_asi_max", "phase": "creation", "targets": ["/abilities/origin/*"], "max": 2},
    ]

    sub_level = table(CLASS, {cid: c["subclass"]["level"] for cid, c in classes.items()}, 99)
    constraints = [
        *[{"id": f"score_cap_{a}", "label": f"{AB_LABEL[a]}: aumentos não passam de 20",
           "assert": le(sub(der(f"score_{a}"), ref(f"/abilities/adjust/{a}")), const(20)),
           "paths": [f"/abilities/base/{a}", f"/abilities/origin/{a}", f"/abilities/improvement/{a}"]} for a in AB_IDS],
        {"id": "skills_off_class_list", "label": "Perícias fora da lista da classe excedem as escolhas livres",
         "assert": le(add(der("off_list_a"), der("off_list_b"), der("off_list_c")), any_picks_total), "paths": [f"/skills/{s}" for s in SKILL_IDS]},
        *[{"id": f"expertise_needs_proficiency_{s}", "label": f"{SKILL_LABEL[s]}: especialização exige proficiência",
           "assert": le(ref(f"/expertise/{s}"), ref(f"/skills/{s}")), "paths": [f"/expertise/{s}"]} for s in SKILL_IDS],
        {"id": "subclass_due", "label": "Escolha a subclasse no nível indicado pela classe",
         "assert": or_(lt(LEVEL, sub_level), ne(SUBCLASS, const(""))), "paths": ["/character/subclass", "/progress/level"]},
        *ed["extra_constraints"](ORIGIN, BG, METHOD, CLASS),
    ]

    # ------------------------------------------------ choices
    def class_option(cid, c):
        fixes = [fix(f"/saving_throws/{a}", True, f"salvaguarda da classe {c['label']}") for a in c["saves"]]
        fixes += [fix(f"/proficiencies/{p}", True, f"treinamento da classe {c['label']}") for p in c["profs"]]
        return {"value": cid, "label": c["label"], "compendium_id": f"class.{cid}", "fixes": fixes}

    def ability_fixes(o, label):
        return [fix(f"/abilities/origin/{a}", n, label) for a, n in o.get("ability", {}).items()] + \
            [fix(path, value, flabel) for path, value, flabel in o.get("fixes_extra", [])]

    def skill_fixes(o, label):
        return [fix(f"/skills/{s}", 1, label, "at_least") for s in o.get("skills", [])]

    choices = [
        {"id": "class", "path": "/character/class", "label": "Classe", "options": [class_option(cid, c) for cid, c in classes.items()]},
        {"id": "subclass", "path": "/character/subclass", "label": "Subclasse", "options": [
            {"value": c["subclass"]["id"], "label": c["subclass"]["label"], "compendium_id": f"subclass.{c['subclass']['id']}",
             "requires": and_(is_class(cid), ge(LEVEL, const(c["subclass"]["level"]))),
             **({"fixes": [fix(f"/proficiencies/{p}", True, f"treinamento de {c['subclass']['label']}") for p in c["subclass"]["profs"]]} if c["subclass"].get("profs") else {})}
            for cid, c in classes.items()]},
        {"id": "ability_method", "path": "/character/ability_method", "label": "Geração de atributos",
         "options": [{"value": m[0], "label": m[1]} for m in ed["ability_methods"]]},
        {"id": OF, "path": f"/origin/{OF}", "label": origin["label"], "options": [
            {"value": o["id"], "label": o["label"], "compendium_id": f"{OF}.{o['id']}",
             **({"fixes": f} if (f := ability_fixes(o, f"aumento de {o['label']}") + skill_fixes(o, f"perícia de {o['label']}")) else {})}
            for o in origin_items]},
        *[{"id": ch["field"], "path": f"/origin/{ch['field']}", "label": ch["label"], "options": [
            {"value": opt["id"], "label": opt["label"], **({"compendium_id": f"{ch['kind']}.{opt['id']}"} if ch.get("kind") else {}),
             **({"requires": opt["requires"](ORIGIN, BG)} if opt.get("requires") else
                ({"requires": in_(ORIGIN, opt["parent"]) if isinstance(opt["parent"], list) else eq(ORIGIN, const(opt["parent"]))} if opt.get("parent") else {})),
             **({"fixes": f} if (f := ability_fixes(opt, f"aumento de {opt['label']}") + skill_fixes(opt, f"perícia de {opt['label']}")) else {})}
            for opt in ch["items"]]} for ch in extra_choices],
        {"id": "background", "path": "/origin/background", "label": "Antecedente", "options": [
            {"value": b["id"], "label": b["label"], "compendium_id": f"background.{b['id']}",
             "fixes": [fix(f"/skills/{s}", 1, f"perícia do antecedente {b['label']}", "at_least") for s in b["skills"]]} for b in ed["backgrounds"]]},
        *[{"id": co["field"], "path": f"/character/{co['field']}", "label": co["label"], "options": [
            {"value": opt["id"], "label": opt["label"], "requires": is_class(co["class"]),
             **({"fixes": [fix(f"/proficiencies/{p}", True, f"treinamento de {opt['label']}") for p in opt["profs"]]} if opt.get("profs") else {})}
            for opt in co["items"]]} for co in ed.get("class_options", [])],
        {"id": "armor", "path": "/combat/armor", "label": "Armadura vestida", "options": [
            {"value": "none", "label": "Sem armadura"}, *[{"value": a[0], "label": a[1], "compendium_id": f"armor.{a[0]}"} for a in ARMOR]]},
    ]

    # ------------------------------------------------ creation steps
    origin_done = []
    for ch in extra_choices:
        need = ch.get("required_for")
        if need:
            origin_done.append(or_(not_(in_(ORIGIN, need)), ne(ref(f"/origin/{ch['field']}"), const(""))))
    class_done = [or_(lt(LEVEL, sub_level), ne(SUBCLASS, const("")))]
    for co in ed.get("class_options", []):
        class_done.append(or_(not_(is_class(co["class"])), ne(ref(f"/character/{co['field']}"), const(""))))
    steps_ = [
        {"id": "identity", "label": "Identidade", "paths": ["/identity/*"], "required": ["/identity/name"]},
        {"id": "class", "label": "Classe e nível", "paths": ["/character/class", "/character/subclass", "/progress/level",
                                                              *[f"/character/{co['field']}" for co in ed.get('class_options', [])]],
         "required": ["/character/class"], "complete_when": and_(*class_done)},
        {"id": "origin", "label": ed["origin_step_label"], "paths": ["/origin/*"], "required": [f"/origin/{OF}", "/origin/background"],
         **({"complete_when": and_(*origin_done)} if origin_done else {})},
        {"id": "abilities", "label": "Atributos", "paths": ["/abilities/base/*", "/abilities/origin/*", "/abilities/improvement/*", "/character/ability_method"],
         "required": ["/character/ability_method"],
         "complete_when": and_(or_(not_(in_(METHOD, point_methods)), eq(budget("point_buy"), const(0))), eq(budget("origin_asi"), const(0)))},
        {"id": "skills", "label": "Perícias", "paths": ["/skills/*", "/expertise/*"],
         "complete_when": and_(le(budget("skill_picks"), add(*slack_terms) if slack_terms else const(0)), eq(budget("expertise"), const(0)))},
        {"id": "equipment", "label": "Equipamento", "paths": ["/inventory", "/attacks", "/combat/armor", "/combat/shield", "/currency/*"], "complete_when": const(True)},
        {"id": "review", "label": "Revisão", "paths": ["/description/*", "/resources/*", "/spells", "/features", "/feats"], "complete_when": const(True)},
    ]

    # ------------------------------------------------ rolls (official: server-side dice, client picks only declared inputs)
    pen = mul(const(ed["exhaustion_d20_penalty"]), exh) if ed["exhaustion_d20_penalty"] else None
    def with_pen(f): return sub(f, pen) if pen else f
    target = {"min": 1, "max": 40}
    # Champion (SRD, both editions): Improved Critical at 3 (19–20), Superior Critical at 15 (18–20).
    is_champion = eq(SUBCLASS, const("champion"))
    attack_crit = sub(const(20), add(if_(and_(is_champion, ge(LEVEL, const(3))), const(1), const(0)), if_(and_(is_champion, ge(LEVEL, const(15))), const(1), const(0))))
    # 2014 exhaustion imposes disadvantage (level 1: ability checks; level 3: attacks and saves).
    def forced(level):
        return {"force": if_(ge(exh, const(level)), const("disadvantage"), const("normal"))} if ed.get("exhaustion_disadvantage") else {}
    rolls = [
        {"id": "ability_check", "label": "Teste de atributo", "strategy": "sum", "dice": "1d20", "inputs": {"ability": {"targets": ["/checks/*"]}},
         "modifier": with_pen(var("ability")), "target": target, "edge": {"extra_dice": 1, **forced(1)}},
        {"id": "saving_throw", "label": "Salvaguarda", "strategy": "sum", "dice": "1d20", "inputs": {"save": {"targets": ["/saves/*"]}},
         "modifier": with_pen(var("save")), "target": target, "edge": {"extra_dice": 1, **forced(3)}},
        {"id": "skill_check", "label": "Teste de perícia", "strategy": "sum", "dice": "1d20", "inputs": {"skill": {"targets": ["/skill_bonus/*"]}},
         "modifier": with_pen(var("skill")), "target": target, "edge": {"extra_dice": 1, **forced(1)}},
        {"id": "initiative", "label": "Iniciativa", "strategy": "sum", "dice": "1d20", "modifier": with_pen(der("initiative")), "edge": {"extra_dice": 1}},
        {"id": "attack", "label": "Jogada de ataque", "strategy": "sum", "dice": "1d20", "inputs": {"bonus": {"targets": ["/combat/attack/*"]}},
         "modifier": with_pen(var("bonus")), "target": {"min": 1, "max": 40}, "critical_at_least": attack_crit, "fumble_at_most": 1, "edge": {"extra_dice": 1, **forced(3)}},
        # Spending a Hit Die: the class die (faces from the sheet) + Constitution modifier.
        {"id": "hit_die", "label": "Dado de Vida (descanso curto)", "strategy": "sum", "dice": "1d6", "faces": der("hit_die"), "modifier": mod["con"]},
        {"id": "death_save", "label": "Salvaguarda contra a morte", "strategy": "sum", "dice": "1d20", **({"modifier": with_pen(const(0))} if pen else {}),
         "target": {"default": 10, "min": 10, "max": 10}, "critical_at_least": 20, "fumble_at_most": 1, "edge": {"extra_dice": 1}},
        *ed.get("extra_rolls", []),
    ]

    resources = [
        {"id": "hp", "label": "Pontos de Vida", "path": "/resources/hp", "max": der("hp_max"), "min": 0, "recovery": "rest"},
        {"id": "hit_dice", "label": "Dados de Vida gastos", "path": "/resources/hit_dice_spent", "max": LEVEL, "min": 0, "recovery": "rest"},
        *[{"id": f"slots_{k}", "label": f"Espaços de {k}º círculo gastos", "path": f"/resources/slots_used/l{k}", "max": der(f"slot_{k}"), "min": 0, "recovery": "rest"} for k in range(1, 10)],
        {"id": "pact_slots", "label": "Espaços de Pacto gastos", "path": "/resources/pact_used", "max": der("pact_slots"), "min": 0, "recovery": "rest"},
    ]

    rules = {
        "contract": "novtt.rules/2",
        "budgets": budgets, "costs": costs, "phase_limits": phase_limits, "dependencies": [],
        "derived": derived, "effects": effects, "constraints": constraints,
        "creation": {"steps": steps_, "choices": choices},
        "progression": {"tracks": [{"id": "level", "label": "Nível de personagem", "path": "/progress/level", "max_step": 1,
                                    "measure": maximum(budget("xp", "granted"), steps(budget("milestones", "granted"), milestone_steps)),
                                    "thresholds": XP_THRESHOLDS}]},
        "resources": resources,
        "inventory": [{"id": "equipment", "collection": "/inventory", "weight_field": "weight", "quantity_field": "quantity", "equipped_field": "equipped"}],
        "rolls": rolls,
        "governance": {"lock_during_session": True},
    }

    # ------------------------------------------------ sheet UI
    ui = sheet_ui(ed, classes, origin, extra_choices)

    # ------------------------------------------------ compendium
    compendium = build_compendium(ed, classes, origin, extra_choices, rules)

    manifest = {
        "contract": "novtt.preset-manifest/2",
        "family_id": "dnd-5e", "variant_id": ed["variant_id"], "version": ed["version"],
        "display_name": ed["display_name"], "locale": "pt-BR",
        "rules_revision": ed["rules_revision"], "source_version": ed["source_version"], "errata_reference": None,
        "license": {"identifier": "CC-BY-4.0", "source_url": ed["license_url"], "rights_status": "approved", "requires_attribution": True,
                    "attribution": ed["attribution"], "consulted_at": ed["consulted_at"], "policy_version": ed["source_version"]},
        "provenance": {"source_authenticity": "licensed", "authors": ["Equipe NOVTT"], "notes": ed["provenance_notes"]},
        "capabilities": {"character_creation": True, "character_progression": True, "dice_resolution": True, "resource_tracking": True, "effects": True},
        "coverage": "playable_core",
        "limitations": ed["limitations"],
        "publication": {"public": True},
        "engine": {"min_version": "0.3.0"},
        "files": {"sheet_schema": "sheet.schema.json", "sheet_ui": "sheet.ui.json", "rules": "rules.json", "compendium": "compendium.json"},
    }
    return {"manifest.json": manifest, "sheet.schema.json": schema, "sheet.ui.json": ui, "rules.json": rules, "compendium.json": compendium}


def origin_speed(items, parent):
    for o in items:
        if o["id"] == parent:
            return o["speed"]
    return 30


def sheet_ui(ed, classes, origin, extra_choices):
    OF = origin["field"]
    ab = [(a, l, abbr) for a, l, abbr, _ in ABILITIES]
    sections = [
        {"id": "identity", "label": "Identidade", "tab": "Ficha", "access": "editable", "elements": [
            field("/identity/name", "Nome do personagem", "text"), field("/identity/player", "Jogador", "text"),
            field("/identity/alignment", "Tendência", "select"), field("/identity/age", "Idade", "text")]},
        {"id": "character", "label": "Classe e nível", "tab": "Ficha", "access": "approval_required", "elements": [
            field("/character/class", "Classe", "select"), field("/character/subclass", "Subclasse", "select"),
            *[field(f"/character/{co['field']}", co["label"], "select") for co in ed.get("class_options", [])],
            field("/progress/level", "Nível", "integer"),
            field("/progress/xp", "Pontos de experiência", "integer", access="read_only"),
            field("/progress/proficiency_bonus", "Bônus de proficiência", "integer", access="read_only")]},
        {"id": "origin", "label": ed["origin_step_label"], "tab": "Ficha", "access": "approval_required", "elements": [
            field(f"/origin/{OF}", origin["label"], "select"),
            *[field(f"/origin/{ch['field']}", ch["label"], "select") for ch in extra_choices],
            field("/origin/background", "Antecedente", "select"),
            *([field("/origin/background_feat", "Talento de origem do antecedente", "text", access="read_only")] if ed.get("background_feats") else []),
            *([] if any(ch["field"] == "size" for ch in extra_choices) else [field("/origin/size", "Tamanho", "text", access="read_only")])]},
        {"id": "ability_generation", "label": "Geração de atributos", "tab": "Ficha", "access": "approval_required", "elements": [
            field("/character/ability_method", "Método", "select"),
            *[field(f"/abilities/base/{a}", f"{l} — compra de pontos (8–15)", "integer") for a, l, _ in ab],
            *[field(f"/abilities/assigned/{a}", f"{l} — valor definido pelo mestre", "integer", access="read_only") for a, l, _ in ab]]},
        {"id": "ability_increases", "label": "Aumentos de atributo", "tab": "Ficha", "access": "approval_required", "elements": [
            *[field(f"/abilities/origin/{a}", f"{l} — {ed['origin_asi_short']}", "integer") for a, l, _ in ab],
            *[field(f"/abilities/improvement/{a}", f"{l} — aumentos por nível", "integer") for a, l, _ in ab],
            *[field(f"/abilities/adjust/{a}", f"{l} — ajuste do mestre (itens, dons)", "integer", access="read_only") for a, l, _ in ab]]},
        {"id": "abilities", "label": "Atributos", "tab": "Ficha", "access": "read_only", "elements": [
            *[field(f"/abilities/score/{a}", f"{l} ({abbr})", "integer") for a, l, abbr in ab],
            *[field(f"/abilities/mod/{a}", f"Modificador de {l}", "integer") for a, l, _ in ab],
            *[field(f"/checks/{a}", f"Teste de {l}", "integer") for a, l, _ in ab]]},
        {"id": "saves", "label": "Salvaguardas", "tab": "Ficha", "access": "read_only", "elements": [
            *[field(f"/saving_throws/{a}", f"Proficiente em {l}", "boolean") for a, l, _ in ab],
            *[field(f"/saves/{a}", f"Salvaguarda de {l}", "integer") for a, l, _ in ab]]},
        {"id": "skills", "label": "Proficiência em perícias", "tab": "Perícias", "access": "approval_required", "elements": [
            *[field(f"/skills/{s}", f"{l} ({abl.upper()})", "integer") for s, l, _, abl in SKILLS]]},
        {"id": "expertise", "label": "Especialização", "tab": "Perícias", "access": "approval_required", "elements": [
            *[field(f"/expertise/{s}", f"Especialização em {l}", "integer") for s, l, _, _ in SKILLS]]},
        {"id": "skill_bonus", "label": "Bônus de perícia", "tab": "Perícias", "access": "read_only", "elements": [
            *[field(f"/skill_bonus/{s}", f"{l} ({abl.upper()})", "integer") for s, l, _, abl in SKILLS],
            field("/senses/passive_perception", "Percepção passiva", "integer"),
            field("/senses/darkvision", "Visão no escuro (pés)", "integer")]},
        {"id": "combat", "label": "Combate", "tab": "Combate", "access": "read_only", "elements": [
            field("/combat/armor_class", "Classe de Armadura", "integer"), field("/combat/initiative", "Iniciativa", "integer"),
            field("/combat/speed", "Deslocamento (pés)", "integer"), field("/combat/hp_max", "Pontos de Vida máximos", "integer"),
            field("/combat/hit_die", "Dado de Vida (d)", "integer"),
            field("/combat/attack/melee", "Ataque corpo a corpo", "integer"), field("/combat/attack/ranged", "Ataque à distância", "integer"),
            field("/combat/attack/finesse", "Ataque com acuidade", "integer"), field("/combat/attack/spell", "Ataque mágico", "integer"),
            field("/combat/hp_adjust", "Ajuste de PV do mestre (rolagens, efeitos)", "integer")]},
        {"id": "armor", "label": "Armadura", "tab": "Combate", "access": "approval_required", "elements": [
            field("/combat/armor", "Armadura vestida", "select"), field("/combat/shield", "Escudo empunhado", "boolean"),
            field("/combat/ac_bonus", "Outros bônus de CA (estilo, itens)", "integer")]},
        {"id": "vitals", "label": "Vida e condição", "tab": "Combate", "access": "editable", "elements": [
            field("/resources/hp", "PV atuais", "integer"), field("/resources/temp_hp", "PV temporários", "integer"),
            field("/resources/hit_dice_spent", "Dados de Vida gastos", "integer"),
            field("/combat/death_successes", "Salvaguardas contra a morte: sucessos", "integer"),
            field("/combat/death_failures", "Salvaguardas contra a morte: falhas", "integer"),
            field("/combat/inspiration", ed["inspiration_label"], "boolean"),
            *[field(f"/conditions/{c}", label, "boolean") for c, label, _ in CONDITIONS],
            field("/conditions/exhaustion", "Exaustão (nível)", "integer")]},
        {"id": "attacks", "label": "Ataques", "tab": "Combate", "access": "approval_required", "elements": [
            {"kind": "collection", "path": "/attacks", "label": "Armas e ataques", "max_items": 30, "item_fields": [
                {"field": "name", "label": "Nome", "widget": "text"}, {"field": "bonus", "label": "Bônus", "widget": "text"},
                {"field": "damage", "label": "Dano", "widget": "text"}, {"field": "damage_type", "label": "Tipo", "widget": "text"},
                {"field": "range", "label": "Alcance", "widget": "text"}, {"field": "notes", "label": "Notas", "widget": "text"}]}]},
        {"id": "spellcasting", "label": "Conjuração", "tab": "Magias", "access": "read_only", "elements": [
            field("/spellcasting/ability", "Atributo de conjuração", "text"), field("/spellcasting/modifier", "Modificador de conjuração", "integer"),
            field("/spellcasting/save_dc", "CD de magia", "integer"),
            field("/spellcasting/attack_bonus", "Bônus de ataque mágico", "integer"),
            field("/spellcasting/cantrips_known", "Truques conhecidos", "integer"), field("/spellcasting/prepared", ed["prepared_label"], "integer"),
            *[field(f"/spellcasting/slots/l{k}", f"Espaços de {k}º círculo", "integer") for k in range(1, 10)],
            field("/spellcasting/pact_slots", "Espaços de Pacto", "integer"), field("/spellcasting/pact_slot_level", "Círculo do Pacto", "integer")]},
        {"id": "slots_used", "label": "Espaços gastos", "tab": "Magias", "access": "editable", "elements": [
            *[field(f"/resources/slots_used/l{k}", f"{k}º círculo gastos", "integer") for k in range(1, 10)],
            field("/resources/pact_used", "Pacto gastos", "integer")]},
        {"id": "spells", "label": "Magias", "tab": "Magias", "access": "approval_required", "elements": [
            {"kind": "collection", "path": "/spells", "label": "Magias conhecidas e preparadas", "max_items": 120, "item_fields": [
                {"field": "name", "label": "Magia", "widget": "text"}, {"field": "level", "label": "Círculo (0 = truque)", "widget": "integer"},
                {"field": "school", "label": "Escola", "widget": "select"}, {"field": "prepared", "label": "Preparada", "widget": "boolean"},
                {"field": "concentration", "label": "Concentração", "widget": "boolean"}, {"field": "ritual", "label": "Ritual", "widget": "boolean"},
                {"field": "notes", "label": "Notas do jogador", "widget": "textarea"}]}]},
        {"id": "inventory", "label": "Inventário", "tab": "Itens", "access": "approval_required", "elements": [
            {"kind": "collection", "path": "/inventory", "label": "Itens", "max_items": 100, "item_fields": [
                {"field": "name", "label": "Item", "widget": "text"}, {"field": "quantity", "label": "Quantidade", "widget": "integer"},
                {"field": "weight", "label": "Peso unitário (lb)", "widget": "number"}, {"field": "equipped", "label": "Equipado", "widget": "boolean"},
                {"field": "notes", "label": "Notas", "widget": "text"}]},
            field("/load/carried", "Peso carregado (lb)", "number", access="read_only"),
            field("/load/capacity", "Capacidade de carga (lb)", "integer", access="read_only")]},
        {"id": "currency", "label": "Moedas", "tab": "Itens", "access": "editable", "elements": [
            field("/currency/cp", "PC (cobre)", "integer"), field("/currency/sp", "PP (prata)", "integer"), field("/currency/ep", "PE (electro)", "integer"),
            field("/currency/gp", "PO (ouro)", "integer"), field("/currency/pp", "PL (platina)", "integer")]},
        {"id": "proficiencies", "label": "Proficiências e idiomas", "tab": "Detalhes", "access": "read_only", "elements": [
            *[field(f"/proficiencies/{p}", label, "boolean") for p, label in PROFS],
            field("/proficiencies/class_notes", "Da classe", "text"),
            field("/proficiencies/other", "Outras proficiências", "textarea", access="approval_required"),
            field("/proficiencies/tools", "Ferramentas", "textarea", access="approval_required"),
            field("/proficiencies/languages", "Idiomas", "textarea", access="approval_required")]},
        {"id": "features", "label": "Características e talentos", "tab": "Detalhes", "access": "approval_required", "elements": [
            {"kind": "collection", "path": "/features", "label": "Características", "max_items": 80, "item_fields": [
                {"field": "name", "label": "Nome", "widget": "text"}, {"field": "source", "label": "Origem", "widget": "select"},
                {"field": "level", "label": "Nível", "widget": "integer"}, {"field": "uses_max", "label": "Usos", "widget": "integer"},
                {"field": "uses_spent", "label": "Usos gastos", "widget": "integer"}, {"field": "notes", "label": "Anotação do jogador", "widget": "textarea"}]},
            {"kind": "collection", "path": "/feats", "label": "Talentos", "max_items": 30, "item_fields": [
                {"field": "name", "label": "Talento", "widget": "text"}, {"field": "category", "label": "Categoria", "widget": "text"},
                {"field": "notes", "label": "Anotação do jogador", "widget": "textarea"}]}]},
        {"id": "description", "label": "Descrição", "tab": "Detalhes", "access": "editable", "elements": [
            field("/description/personality", "Traços de personalidade", "textarea"), field("/description/ideals", "Ideais", "textarea"),
            field("/description/bonds", "Vínculos", "textarea"), field("/description/flaws", "Defeitos", "textarea"),
            field("/description/appearance", "Aparência", "textarea"), field("/description/backstory", "História", "textarea"),
            field("/description/notes", "Anotações", "textarea")]},
    ]
    return {"contract": "novtt.sheet-ui/1", "default_access": "read_only", "sections": sections}


def build_compendium(ed, classes, origin, extra_choices, rules):
    OF = origin["field"]
    src_base = {"source_id": ed["source_id"], "source_authenticity": "official", "rights_status": "approved", "license_id": "CC-BY-4.0",
                "requires_attribution": True, "attribution_ref": ed["attribution_ref"], "edition": ed["source_version"]}
    items = []

    def add_item(cid, kind, pt, en, location, refs=None, parent=None, verified=True, summary=None):
        d = {"canonical_id": cid, "kind": kind, "labels": {"pt-BR": pt, "en": en}, "source_label": en, "rules_revision": ed["rules_revision"],
             "locale": "pt-BR", "source": {**src_base, "review_status": "verified" if verified else "unverified", "location": location}}
        if parent:
            d["parent_id"] = parent
        if summary:
            d["summary"] = summary
        if refs:
            d["mechanical_refs"] = refs
        d["content_hash"] = canonical_hash(d)
        items.append(d)

    P = ed["pages"]
    for a, pt, abbr, en in ABILITIES:
        add_item(f"ability.{a}", "ability", f"{pt} ({abbr})", en, P["abilities"], [f"derived:score_{a}", f"derived:mod_{a}", f"derived:save_{a}"])
    for s, pt, en, abl in SKILLS:
        add_item(f"skill.{s}", "skill", pt, en, P["skills"], [f"derived:skill_{s}"], summary=f"Perícia de {AB_LABEL[abl]}.")
    for cid, c in classes.items():
        add_item(f"class.{cid}", "class", c["label"], c["en"], c["page"], [f"choice:class:{cid}", "derived:hp_max", "derived:hit_die"],
                 summary=f"Dado de Vida d{c['hd']}; salvaguardas: {', '.join(AB_LABEL[a] for a in c['saves'])}.")
        sc = c["subclass"]
        add_item(f"subclass.{sc['id']}", "subclass", sc["label"], sc["en"], sc["page"], [f"choice:subclass:{sc['id']}"], parent=f"class.{cid}",
                 summary=f"Subclasse de {c['label']}, escolhida no nível {sc['level']}.")
    for o in origin["items"]:
        add_item(f"{OF}.{o['id']}", OF, o["label"], o["en"], o["page"], [f"choice:{OF}:{o['id']}", "derived:speed"])
    for ch in extra_choices:
        if not ch.get("kind"):
            continue
        for opt in ch["items"]:
            parent = opt.get("parent")
            add_item(f"{ch['kind']}.{opt['id']}", ch["kind"], opt["label"], opt["en"], opt["page"], [f"choice:{ch['field']}:{opt['id']}"],
                     parent=f"{OF}.{parent}" if isinstance(parent, str) else None)
    for b in ed["backgrounds"]:
        add_item(f"background.{b['id']}", "background", b["label"], b["en"], b["page"], [f"choice:background:{b['id']}"])
    for f in ed["feats"]:
        add_item(f"feat.{f['id']}", "feat", f["label"], f["en"], f["page"], summary=f.get("category"))
    for c, pt, en in CONDITIONS:
        add_item(f"condition.{c}", "condition", pt, en, P["conditions"])
    add_item("condition.exhaustion", "condition", "Exaustão", "Exhaustion", P["conditions"])
    for a, pt, cat, base in ARMOR:
        add_item(f"armor.{a}", "armor", pt, ed["armor_names"][a], P["armor"], [f"choice:armor:{a}", "derived:armor_class"],
                 summary=f"CA base {base}; {dict(light='leve', medium='média', heavy='pesada')[cat]}.")

    taxonomy = [
        {"kind": "ability", "labels": {"pt-BR": "Atributo", "en": "Ability"}},
        {"kind": "skill", "labels": {"pt-BR": "Perícia", "en": "Skill"}},
        {"kind": "class", "labels": {"pt-BR": "Classe", "en": "Class"}},
        {"kind": "subclass", "labels": {"pt-BR": "Subclasse", "en": "Subclass"}, "parent_kind": "class"},
        {"kind": OF, "labels": {"pt-BR": origin["label"], "en": origin["en"]}},
        *[{"kind": ch["kind"], "labels": {"pt-BR": ch["kind_label"], "en": ch["kind_en"]}, **({"parent_kind": OF} if ch.get("parent_kind") else {})}
          for ch in extra_choices if ch.get("kind")],
        {"kind": "background", "labels": {"pt-BR": "Antecedente", "en": "Background"}},
        {"kind": "feat", "labels": {"pt-BR": "Talento", "en": "Feat"}},
        {"kind": "condition", "labels": {"pt-BR": "Condição", "en": "Condition"}},
        {"kind": "armor", "labels": {"pt-BR": "Armadura", "en": "Armor"}},
    ]
    return {"contract": "novtt.compendium/1", "attributions": ed["attributions"], "taxonomy": taxonomy, "items": items}


def write_package(cfg: dict, register_rows: list, register_header: str):
    out = ROOT / "presets" / "dnd-5e" / cfg["directory"] / cfg["version"]
    out.mkdir(parents=True, exist_ok=True)
    parts = build(cfg)
    for name, data in parts.items():
        write_json(out / name, data)
    lines = [register_header, "", "| Rule / data | Source (section, page) | Confidence | Status |", "|---|---|---|---|"]
    lines += [f"| {r[0]} | {r[1]} | {r[2]} | {r[3]} |" for r in register_rows]
    lines += ["", "To change a row: fix the table in the generator, rebuild, bump the preset version (published",
              "versions are immutable) and run the engine tests. Never paste SRD prose into labels or summaries."]
    (out / "SOURCE-REGISTER.md").write_text("\n".join(lines) + "\n", encoding="utf-8")
    rules = parts["rules.json"]
    worst = max(max_nodes(d["formula"]) for d in rules["derived"])
    print(f"wrote {out.relative_to(ROOT)}: {len(rules['derived'])} derived, {len(rules['effects'])} effects, "
          f"{len(rules['constraints'])} constraints, {len(rules['rolls'])} rolls, {len(parts['compendium.json']['items'])} compendium items, "
          f"largest derived formula {worst} nodes")
    return parts


def max_nodes(f) -> int:
    """Total node count of a formula (engine budget: 256 per formula)."""
    if isinstance(f, dict):
        n = 1
        for k in ("args",):
            n += sum(max_nodes(x) for x in f.get(k, []))
        for k in ("key", "default", "value", "where"):
            if k in f:
                n += max_nodes(f[k])
        return n
    return 0
