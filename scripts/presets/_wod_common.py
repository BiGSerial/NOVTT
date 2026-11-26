"""Shared builder for the World of Darkness vampire presets (V20 and V20 Dark Ages).

Used by build_vampire_v20.py and build_vampire_dark_ages.py; each passes its book's tables.

Rules of these packages (SPEC-PRESETS-FIDELITY-001, LICENSE-MATRIX):
- Distribution is BLOCKED: the Dark Pack does not cover paid apps. rights_status "rights_review",
  publication.public false, source_authenticity "official". Internal use only.
- Compendium entries carry names (labels) and mechanical refs only. `summary` stays empty: no
  rulebook prose, no art, no copied descriptions.
- There is no source document in the repository. Every value below was recalled by the implementing
  agent and is PENDING verification: derived values and budgets carry " · a conferir" on screen,
  compendium entries are `review_status: unverified`, and SOURCE-REGISTER.md lists each rule.
- Gaps are recorded as missing, never filled with invented rules.
"""
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
PENDING = " · a conferir"

PRIORITY_OPTIONS = ["primary", "secondary", "tertiary"]
PRIORITY_LABELS = {"primary": "Primária", "secondary": "Secundária", "tertiary": "Terciária"}

ATTRIBUTES = {
    "physical": ("Físicos", [("strength", "Força", "Strength"), ("dexterity", "Destreza", "Dexterity"), ("stamina", "Vigor", "Stamina")]),
    "social": ("Sociais", [("charisma", "Carisma", "Charisma"), ("manipulation", "Manipulação", "Manipulation"), ("appearance", "Aparência", "Appearance")]),
    "mental": ("Mentais", [("perception", "Percepção", "Perception"), ("intelligence", "Inteligência", "Intelligence"), ("wits", "Raciocínio", "Wits")]),
}
ABILITY_GROUPS = {"talents": ("Talentos", "Talents"), "skills": ("Perícias", "Skills"), "knowledges": ("Conhecimentos", "Knowledges")}
VIRTUES = [
    ("conscience", "Consciência / Convicção", [("conscience", "Consciência", "Conscience"), ("conviction", "Convicção", "Conviction")]),
    ("self_control", "Autocontrole / Instinto", [("self_control", "Autocontrole", "Self-Control"), ("instinct", "Instinto", "Instinct")]),
    ("courage", "Coragem", [("courage", "Coragem", "Courage")]),
]
# Health levels and their dice penalty (pending): (damage marked, label, penalty)
HEALTH_LEVELS = [(0, "Ileso", 0), (1, "Escoriado", 0), (2, "Machucado", -1), (3, "Ferido", -1), (4, "Ferido gravemente", -2),
                 (5, "Espancado", -2), (6, "Aleijado", -5), (7, "Incapacitado", -5)]


# ---------------------------------------------------------------- formula helpers
def const(v): return {"const": v}
def ref(p): return {"ref": p}
def var(n): return {"var": n}
def derived(i): return {"derived": i}
def add(*a): return {"op": "add", "args": list(a)}
def sub(a, b): return {"op": "subtract", "args": [a, b]}
def mul(*a): return {"op": "multiply", "args": list(a)}
def cmp(c, a, b): return {"op": "compare", "cmp": c, "args": [a, b]}
def if_(c, a, b): return {"op": "if", "args": [c, a, b]}
def and_(*a): return {"op": "and", "args": list(a)} if len(a) > 1 else a[0]
def or_(*a): return {"op": "or", "args": list(a)} if len(a) > 1 else a[0]
def maximum(*a): return {"op": "max", "args": list(a)}
def table(key, mapping, default=0): return {"op": "lookup", "key": key, "table": mapping, "default": const(default)}
def steps(key, pairs): return {"op": "lookup", "key": key, "steps": [list(p) for p in pairs]}
def budget(b, of="balance"): return {"budget": b, "of": of}


# ---------------------------------------------------------------- schema helpers
def s_str(n=200): return {"type": "string", "maxLength": n}
def s_enum(values): return {"type": "string", "enum": list(values)}
def s_int(lo, hi, default=None):
    d = {"type": "integer", "minimum": lo, "maximum": hi}
    if default is not None:
        d["default"] = default
    return d
def obj(props): return {"type": "object", "additionalProperties": False, "properties": props}
ITEM_ID = {"type": "string", "pattern": "^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$"}
def coll(props, required, max_items):
    return {"type": "array", "maxItems": max_items, "items": {"type": "object", "additionalProperties": False,
            "required": ["id", *required], "properties": {"id": ITEM_ID, **props}}}
def field(path, label, widget, **kw): return {"kind": "field", "path": path, "label": label, "widget": widget, **kw}


def build(book):
    """book: dict with the tables of one rulebook (see build_vampire_v20.py for the keys)."""
    out = ROOT / "presets" / "world-of-darkness" / book["variant_id"] / book["version"]
    clans = book["clans"]                    # [(id, pt label, en label, [3 disciplines])]
    clanless = book.get("clanless")          # clan id with no clan Disciplines (V20 Caitiff) or None
    disciplines = book["disciplines"]        # [(id, pt, en)]
    abilities = book["abilities"]            # {group: [(id, pt, en)]}
    backgrounds = book["backgrounds"]        # [(id, pt, en)]
    moralities = book["moralities"]          # [(id, pt, en)]; the first is Humanity
    morality_word = book["morality_word"]    # "Senda" / "Estrada"
    sects = book.get("sects")                # [(id, pt, en)] or None
    base_generation = book["base_generation"]
    blood = book["blood_by_generation"]      # {generation: (pool max, per turn, trait max)}
    budgets_cfg = book["budgets"]
    freebie = book["freebie_costs"]
    xp = book["xp_costs"]
    trait_cap = max(v[2] for v in blood.values())
    gen_bg = "/backgrounds/generation"

    CLAN = ref("/clan/clan")
    clan_ids = [c[0] for c in clans]
    in_clan = {d: [c[0] for c in clans if d in c[3]] for d, _, _ in disciplines}
    for c in clans:
        for d in c[3]:
            assert d in in_clan, f"{c[0]}: unknown discipline {d}"
    attr_paths = [f"/attributes/{g}/{a}" for g, (_, items) in ATTRIBUTES.items() for a, _, _ in items]
    ability_paths = [f"/abilities/{g}/{a}" for g, items in abilities.items() for a, _, _ in items]

    # ------------------------------------------------------------ sheet schema
    identity = {"name": s_str(120), "player": s_str(120), "chronicle": s_str(120), "concept": s_str(200),
                "nature": s_str(80), "demeanor": s_str(80), "sire": s_str(120)}
    clan_obj = {"clan": s_enum(clan_ids)}
    if sects:
        clan_obj["sect"] = s_enum([s[0] for s in sects])
    schema = {
        "$schema": "https://json-schema.org/draft/2020-12/schema",
        "title": f"world-of-darkness {book['variant_id']} {book['version']} sheet",
        "type": "object", "additionalProperties": False,
        "properties": {
            "identity": obj(identity),
            "clan": obj(clan_obj),
            "priorities": obj({k: s_enum(PRIORITY_OPTIONS) for k in [*ATTRIBUTES, *ABILITY_GROUPS]}),
            "attributes": obj({g: obj({a: s_int(0 if a == "appearance" else 1, trait_cap, 1) for a, _, _ in items}) for g, (_, items) in ATTRIBUTES.items()}),
            "abilities": obj({g: obj({a: s_int(0, trait_cap, 0) for a, _, _ in items}) for g, items in abilities.items()}),
            "disciplines": obj({d: s_int(0, trait_cap, 0) for d, _, _ in disciplines}),
            "backgrounds": obj({b: s_int(0, 5, 0) for b, _, _ in backgrounds}),
            "virtues": obj({v: s_int(0, 5, 1) for v, _, _ in VIRTUES}),
            "morality": obj({
                "path": s_enum([m[0] for m in moralities]),
                "first_virtue": s_enum(["conscience", "conviction"]),
                "second_virtue": s_enum(["self_control", "instinct"]),
                "rating": s_int(0, 10, 0),
            }),
            "willpower": obj({"permanent": s_int(0, 10, 0)}),
            "derived": obj({k: {"type": "integer"} for k in ["generation", "blood_pool_max", "blood_per_turn", "trait_max", "morality_base",
                                                             "initiative", "damage_total", "wound_penalty"]} | {"health_state": {"type": "string"}}),
            "resources": obj({"blood": s_int(0, 50, 0), "willpower": s_int(0, 10, 0),
                              "bashing": s_int(0, 7, 0), "lethal": s_int(0, 7, 0), "aggravated": s_int(0, 7, 0)}),
            "specialties": coll({"ability": s_str(80), "name": s_str(80)}, ["ability", "name"], 40),
            "merits_flaws": coll({"name": s_str(80), "kind": s_enum(["merit", "flaw"]), "points": s_int(1, 7, 1), "note": s_str(300)}, ["name", "kind"], 30),
            "discipline_paths": coll({"name": s_str(80), "discipline": s_str(80), "rating": s_int(0, 5, 1), "note": s_str(300)}, ["name"], 20),
            "rituals": coll({"name": s_str(80), "level": s_int(1, 10, 1), "note": s_str(300)}, ["name"], 40),
            "weapons": coll({"name": s_str(80), "difficulty": s_int(2, 10, 6), "damage": s_str(40), "range": s_str(40), "note": s_str(200)}, ["name"], 20),
            "inventory": coll({"name": s_str(80), "quantity": s_int(1, 99, 1), "note": s_str(200)}, ["name"], 40),
            "description": obj({"appearance": s_str(1000), "haven": s_str(500), "history": s_str(3000), "notes": s_str(3000)}),
        },
    }

    # ------------------------------------------------------------ sheet ui
    clan_elements = [field("/clan/clan", "Clã", "select")]
    if sects:
        clan_elements.append(field("/clan/sect", "Seita", "select"))
    clan_elements += [field("/derived/generation", f"Geração{PENDING}", "integer", access="read_only")]
    sections = [
        {"id": "identity", "tab": "Ficha", "label": "Identidade", "access": "editable", "elements": [
            field("/identity/name", "Nome", "text"), field("/identity/player", "Jogador", "text"),
            field("/identity/chronicle", "Crônica", "text"), field("/identity/concept", "Conceito", "text"),
            field("/identity/nature", "Natureza", "text"), field("/identity/demeanor", "Comportamento", "text"),
            field("/identity/sire", "Senhor", "text")]},
        {"id": "clan", "tab": "Ficha", "label": "Clã", "access": "approval_required", "elements": clan_elements},
        {"id": "priorities", "tab": "Ficha", "label": "Prioridades", "access": "approval_required", "elements": [
            *[field(f"/priorities/{g}", f"Atributos {label.lower()}", "select") for g, (label, _) in ATTRIBUTES.items()],
            *[field(f"/priorities/{g}", label, "select") for g, (label, _) in ABILITY_GROUPS.items()]]},
        *[{"id": f"attributes-{g}", "tab": "Ficha", "label": f"Atributos {label.lower()}", "access": "approval_required",
           "elements": [field(f"/attributes/{g}/{a}", pt, "dot_rating") for a, pt, _ in items]} for g, (label, items) in ATTRIBUTES.items()],
        {"id": "resources", "tab": "Ficha", "label": "Recursos", "access": "editable", "elements": [
            field("/resources/blood", "Pontos de sangue", "integer"), field("/resources/willpower", "Força de Vontade atual", "integer"),
            field("/resources/bashing", "Dano por contusão", "integer"), field("/resources/lethal", "Dano letal", "integer"),
            field("/resources/aggravated", "Dano agravado", "integer")]},
        {"id": "derived", "tab": "Ficha", "label": "Valores calculados", "access": "read_only", "elements": [
            field("/derived/blood_pool_max", f"Reserva de sangue máxima{PENDING}", "integer"),
            field("/derived/blood_per_turn", f"Sangue por turno{PENDING}", "integer"),
            field("/derived/trait_max", f"Máximo de características{PENDING}", "integer"),
            field("/derived/initiative", f"Iniciativa (Destreza + Raciocínio){PENDING}", "integer"),
            field("/derived/damage_total", "Níveis de vitalidade marcados", "integer"),
            field("/derived/health_state", f"Vitalidade{PENDING}", "text"),
            field("/derived/wound_penalty", f"Penalidade de ferimento{PENDING}", "integer")]},
        *[{"id": f"abilities-{g}", "tab": "Habilidades", "label": label, "access": "approval_required",
           "elements": [field(f"/abilities/{g}/{a}", pt, "dot_rating") for a, pt, _ in abilities[g]]} for g, (label, _) in ABILITY_GROUPS.items()],
        {"id": "specialties", "tab": "Habilidades", "label": "Especializações", "access": "approval_required", "elements": [
            {"kind": "collection", "path": "/specialties", "label": "Especializações", "max_items": 40, "item_fields": [
                {"field": "ability", "label": "Característica", "widget": "text"}, {"field": "name", "label": "Especialização", "widget": "text"}]}]},
        {"id": "disciplines", "tab": "Disciplinas", "label": "Disciplinas", "access": "approval_required",
         "elements": [field(f"/disciplines/{d}", pt, "dot_rating") for d, pt, _ in disciplines]},
        {"id": "discipline-paths", "tab": "Disciplinas", "label": "Trilhas de Disciplina", "access": "approval_required", "elements": [
            {"kind": "collection", "path": "/discipline_paths", "label": "Trilhas", "max_items": 20, "item_fields": [
                {"field": "name", "label": "Trilha", "widget": "text"}, {"field": "discipline", "label": "Disciplina", "widget": "text"},
                {"field": "rating", "label": "Nível", "widget": "dot_rating"}, {"field": "note", "label": "Anotação do jogador", "widget": "textarea"}]}]},
        {"id": "rituals", "tab": "Disciplinas", "label": "Rituais", "access": "approval_required", "elements": [
            {"kind": "collection", "path": "/rituals", "label": "Rituais", "max_items": 40, "item_fields": [
                {"field": "name", "label": "Ritual", "widget": "text"}, {"field": "level", "label": "Nível", "widget": "integer"},
                {"field": "note", "label": "Anotação do jogador", "widget": "textarea"}]}]},
        {"id": "backgrounds", "tab": "Vantagens", "label": "Antecedentes", "access": "approval_required",
         "elements": [field(f"/backgrounds/{b}", pt, "dot_rating") for b, pt, _ in backgrounds]},
        {"id": "virtues", "tab": "Vantagens", "label": "Virtudes", "access": "approval_required", "elements": [
            field("/morality/first_virtue", "Primeira virtude", "select"), field("/morality/second_virtue", "Segunda virtude", "select"),
            *[field(f"/virtues/{v}", label, "dot_rating") for v, label, _ in VIRTUES]]},
        {"id": "morality", "tab": "Vantagens", "label": f"Humanidade / {morality_word}", "access": "approval_required", "elements": [
            field("/morality/path", morality_word, "select"),
            field("/derived/morality_base", f"Base pelas virtudes{PENDING}", "integer", access="read_only"),
            field("/morality/rating", f"Humanidade / {morality_word}", "dot_rating"),
            field("/willpower/permanent", "Força de Vontade", "dot_rating")]},
        {"id": "merits-flaws", "tab": "Vantagens", "label": f"Qualidades e Defeitos{PENDING}", "access": "approval_required", "elements": [
            {"kind": "collection", "path": "/merits_flaws", "label": "Qualidades e Defeitos", "max_items": 30, "item_fields": [
                {"field": "name", "label": "Nome", "widget": "text"}, {"field": "kind", "label": "Tipo", "widget": "select"},
                {"field": "points", "label": "Pontos", "widget": "integer"}, {"field": "note", "label": "Anotação do jogador", "widget": "textarea"}]}]},
        {"id": "weapons", "tab": "Itens", "label": "Armas", "access": "approval_required", "elements": [
            {"kind": "collection", "path": "/weapons", "label": "Armas", "max_items": 20, "item_fields": [
                {"field": "name", "label": "Arma", "widget": "text"}, {"field": "difficulty", "label": "Dificuldade", "widget": "integer"},
                {"field": "damage", "label": "Dano", "widget": "text"}, {"field": "range", "label": "Alcance", "widget": "text"},
                {"field": "note", "label": "Anotação", "widget": "text"}]}]},
        {"id": "inventory", "tab": "Itens", "label": "Equipamento", "access": "approval_required", "elements": [
            {"kind": "collection", "path": "/inventory", "label": "Itens", "max_items": 40, "item_fields": [
                {"field": "name", "label": "Item", "widget": "text"}, {"field": "quantity", "label": "Quantidade", "widget": "integer"},
                {"field": "note", "label": "Anotação", "widget": "text"}]}]},
        {"id": "description", "tab": "Detalhes", "label": "Descrição", "access": "editable", "elements": [
            field("/description/appearance", "Aparência", "textarea"), field("/description/haven", "Refúgio", "textarea"),
            field("/description/history", "Histórico", "textarea"), field("/description/notes", "Anotações", "textarea")]},
    ]
    ui = {"contract": "novtt.sheet-ui/1", "default_access": "read_only", "sections": sections}

    # ------------------------------------------------------------ rules
    generation = sub(const(base_generation), ref(gen_bg))
    gen_keys = {str(g): v for g, v in sorted(blood.items())}
    morality_base = add(ref("/virtues/conscience"), ref("/virtues/self_control"))
    damage = add(ref("/resources/bashing"), ref("/resources/lethal"), ref("/resources/aggravated"))
    derived_ = [
        {"id": "generation", "path": "/derived/generation", "label": f"Geração{PENDING}", "formula": generation},
        {"id": "blood_pool_max", "path": "/derived/blood_pool_max", "label": f"Reserva de sangue máxima{PENDING}",
         "formula": table(derived("generation"), {k: v[0] for k, v in gen_keys.items()})},
        {"id": "blood_per_turn", "path": "/derived/blood_per_turn", "label": f"Sangue por turno{PENDING}",
         "formula": table(derived("generation"), {k: v[1] for k, v in gen_keys.items()})},
        {"id": "trait_max", "path": "/derived/trait_max", "label": f"Máximo de características{PENDING}",
         "formula": table(derived("generation"), {k: v[2] for k, v in gen_keys.items()}, 5)},
        {"id": "morality_base", "path": "/derived/morality_base", "label": f"Humanidade / {morality_word} pelas virtudes{PENDING}", "formula": morality_base},
        {"id": "initiative", "path": "/derived/initiative", "label": f"Iniciativa{PENDING}",
         "formula": add(ref("/attributes/physical/dexterity"), ref("/attributes/mental/wits"))},
        {"id": "damage_total", "path": "/derived/damage_total", "label": "Níveis de vitalidade marcados", "formula": damage},
        {"id": "wound_penalty", "path": "/derived/wound_penalty", "label": f"Penalidade de ferimento{PENDING}",
         "formula": steps(derived("damage_total"), [(lvl, pen) for lvl, _, pen in HEALTH_LEVELS])},
        {"id": "health_state", "path": "/derived/health_state", "label": f"Vitalidade{PENDING}",
         "formula": steps(derived("damage_total"), [(lvl, label) for lvl, label, _ in HEALTH_LEVELS])},
    ]

    def pri(group, amounts):
        return {"op": "lookup", "key": ref(f"/priorities/{group}"), "table": dict(zip(PRIORITY_OPTIONS, amounts)), "default": const(0)}

    budgets = [
        *[{"id": g, "kind": "initial", "label": f"Pontos de atributos {label.lower()}{PENDING}", "amount": pri(g, budgets_cfg["attributes"])}
          for g, (label, _) in ATTRIBUTES.items()],
        *[{"id": g, "kind": "initial", "label": f"Pontos de {label.lower()}{PENDING}", "amount": pri(g, budgets_cfg["abilities"])}
          for g, (label, _) in ABILITY_GROUPS.items()],
        {"id": "disciplines", "kind": "initial", "label": f"Pontos de Disciplinas{PENDING}", "initial_amount": budgets_cfg["disciplines"]},
        {"id": "backgrounds", "kind": "initial", "label": f"Pontos de Antecedentes{PENDING}", "initial_amount": budgets_cfg["backgrounds"]},
        {"id": "virtues", "kind": "initial", "label": f"Pontos de Virtudes{PENDING}", "initial_amount": budgets_cfg["virtues"]},
        {"id": "freebies", "kind": "bonus", "label": f"Pontos de bônus{PENDING}", "initial_amount": budgets_cfg["freebies"]},
        {"id": "xp", "kind": "experience", "label": "Experiência (concedida pelo mestre)", "initial_amount": 0},
    ]

    def disc_in_clan(d, value_in, caitiff=None):
        mapping = {c: value_in for c in in_clan[d]}
        if clanless and caitiff is not None:
            mapping[clanless] = caitiff
        return mapping

    costs = []
    for g in ATTRIBUTES:
        costs.append({"id": f"{g}_creation", "phase": "creation", "budget": g, "targets": [f"/attributes/{g}/*"], "model": {"type": "fixed_per_dot", "amount": 1}, "free_dots": 1})
        costs.append({"id": f"{g}_freebies", "phase": "creation", "budget": "freebies", "targets": [f"/attributes/{g}/*"], "model": {"type": "fixed_per_dot", "amount": freebie["attribute"]}, "free_dots": 1})
        costs.append({"id": f"{g}_xp", "phase": "advancement", "budget": "xp", "targets": [f"/attributes/{g}/*"], "model": {"type": "current_rating_multiplier", "multiplier": xp["attribute"]}})
    for g in ABILITY_GROUPS:
        cap = budgets_cfg["ability_creation_max"]
        costs.append({"id": f"{g}_creation", "phase": "creation", "budget": g, "targets": [f"/abilities/{g}/*"],
                      # Priority points pay only up to the creation cap; above it the freebie rule pays.
                      "applies_when": cmp("le", var("new"), const(cap)), "model": {"type": "fixed_per_dot", "amount": 1}})
        costs.append({"id": f"{g}_freebies", "phase": "creation", "budget": "freebies", "targets": [f"/abilities/{g}/*"], "model": {"type": "fixed_per_dot", "amount": freebie["ability"]}})
        costs.append({"id": f"{g}_xp", "phase": "advancement", "budget": "xp", "targets": [f"/abilities/{g}/*"],
                      "model": {"type": "current_rating_multiplier", "multiplier": xp["ability"], "when_zero": xp["new_ability"]}})
    for d, _, _ in disciplines:
        allowed = table(CLAN, disc_in_clan(d, 1, 1))
        costs.append({"id": f"disc_{d}_creation", "phase": "creation", "budget": "disciplines", "targets": [f"/disciplines/{d}"],
                      # Creation Discipline dots only for the clan's Disciplines; others cost freebies.
                      "applies_when": cmp("eq", allowed, const(1)), "model": {"type": "fixed_per_dot", "amount": 1}})
    costs.append({"id": "disciplines_freebies", "phase": "creation", "budget": "freebies", "targets": ["/disciplines/*"], "model": {"type": "fixed_per_dot", "amount": freebie["discipline"]}})
    for d, _, _ in disciplines:
        multiplier = table(CLAN, disc_in_clan(d, xp["clan_discipline"], xp.get("clanless_discipline")), xp["other_discipline"])
        costs.append({"id": f"disc_{d}_xp", "phase": "advancement", "budget": "xp", "targets": [f"/disciplines/{d}"],
                      "model": {"type": "formula", "formula": if_(cmp("eq", var("current"), const(0)), const(xp["new_discipline"]), mul(var("current"), multiplier))}})
    costs += [
        {"id": "backgrounds_creation", "phase": "creation", "budget": "backgrounds", "targets": ["/backgrounds/*"], "model": {"type": "fixed_per_dot", "amount": 1}},
        {"id": "backgrounds_freebies", "phase": "creation", "budget": "freebies", "targets": ["/backgrounds/*"], "model": {"type": "fixed_per_dot", "amount": freebie["background"]}},
        {"id": "virtues_creation", "phase": "creation", "budget": "virtues", "targets": ["/virtues/*"], "model": {"type": "fixed_per_dot", "amount": 1}, "free_dots": 1},
        {"id": "virtues_freebies", "phase": "creation", "budget": "freebies", "targets": ["/virtues/*"], "model": {"type": "fixed_per_dot", "amount": freebie["virtue"]}, "free_dots": 1},
        {"id": "virtues_xp", "phase": "advancement", "budget": "xp", "targets": ["/virtues/*"], "model": {"type": "current_rating_multiplier", "multiplier": xp["virtue"]}},
        # Humanity/Path starts at Conscience + Self-Control and Willpower at Courage: those levels are
        # free; levels above them cost freebies.
        {"id": "morality_creation", "phase": "creation", "budget": "freebies", "targets": ["/morality/rating"],
         "model": {"type": "formula", "formula": if_(cmp("le", var("new"), morality_base), const(0), const(freebie["morality"]))}},
        # Degeneration is a loss: lowering Humanity/Path in play does not give the XP back.
        {"id": "morality_xp", "phase": "advancement", "budget": "xp", "targets": ["/morality/rating"], "model": {"type": "current_rating_multiplier", "multiplier": xp["morality"]}, "refund_on_decrease": False},
        {"id": "willpower_creation", "phase": "creation", "budget": "freebies", "targets": ["/willpower/permanent"],
         "model": {"type": "formula", "formula": if_(cmp("le", var("new"), ref("/virtues/courage")), const(0), const(freebie["willpower"]))}},
        {"id": "willpower_xp", "phase": "advancement", "budget": "xp", "targets": ["/willpower/permanent"], "model": {"type": "current_rating_multiplier", "multiplier": xp["willpower"]}, "refund_on_decrease": False},
    ]

    def distinct(paths):
        # Each pair of chosen priorities differs (an unset one does not count yet).
        refs = [ref(p) for p in paths]
        return and_(*[or_(cmp("eq", refs[i], const("")), cmp("eq", refs[j], const("")), cmp("ne", refs[i], refs[j]))
                      for i in range(len(refs)) for j in range(i + 1, len(refs))])

    def capped(paths):
        return and_(*[cmp("le", ref(p), derived("trait_max")) for p in paths])

    constraints = [
        {"id": "distinct_attribute_priorities", "phase": "creation", "label": "Cada prioridade de atributos uma vez",
         "paths": [f"/priorities/{g}" for g in ATTRIBUTES], "assert": distinct([f"/priorities/{g}" for g in ATTRIBUTES])},
        {"id": "distinct_ability_priorities", "phase": "creation", "label": "Cada prioridade de habilidades uma vez",
         "paths": [f"/priorities/{g}" for g in ABILITY_GROUPS], "assert": distinct([f"/priorities/{g}" for g in ABILITY_GROUPS])},
        {"id": "appearance_at_least_one", "phase": "creation", "label": f"Aparência começa em 1, exceto no clã que a mantém em 0{PENDING}",
         "paths": ["/attributes/social/appearance"],
         "assert": or_(*[cmp("eq", CLAN, const(c)) for c in book["appearance_zero_clans"]], cmp("ge", ref("/attributes/social/appearance"), const(1)))},
        {"id": "health_levels", "label": f"No máximo 7 níveis de vitalidade marcados{PENDING}",
         "paths": ["/resources/bashing", "/resources/lethal", "/resources/aggravated"], "assert": cmp("le", damage, const(7))},
        {"id": "trait_max_attributes", "label": f"Atributos até o máximo da geração{PENDING}", "paths": attr_paths, "assert": capped(attr_paths)},
        {"id": "trait_max_abilities", "label": f"Habilidades até o máximo da geração{PENDING}", "paths": ability_paths, "assert": capped(ability_paths)},
        {"id": "trait_max_disciplines", "label": f"Disciplinas até o máximo da geração{PENDING}",
         "paths": [f"/disciplines/{d}" for d, _, _ in disciplines], "assert": capped([f"/disciplines/{d}" for d, _, _ in disciplines])},
    ]

    def fix(path, value, label, mode="exact"): return {"path": path, "value": const(value), "label": label, "mode": mode}
    choices = [
        {"id": "clan", "path": "/clan/clan", "label": "Clã", "options": [
            {"value": cid, "label": pt, "compendium_id": f"clan.{cid}",
             **({"fixes": [fix("/attributes/social/appearance", 0, f"Aparência 0 pela fraqueza do clã{PENDING}")]} if cid in book["appearance_zero_clans"] else {})}
            for cid, pt, _, _ in clans]},
        {"id": "morality", "path": "/morality/path", "label": morality_word, "options": [
            {"value": mid, "label": pt, "compendium_id": f"{book['morality_kind']}.{mid}",
             **({"fixes": [fix("/morality/first_virtue", "conscience", f"A Humanidade usa Consciência{PENDING}"),
                           fix("/morality/second_virtue", "self_control", f"A Humanidade usa Autocontrole{PENDING}")]} if i == 0 else {})}
            for i, (mid, pt, _) in enumerate(moralities)]},
        {"id": "first_virtue", "path": "/morality/first_virtue", "label": "Primeira virtude", "options": [
            {"value": "conscience", "label": "Consciência", "compendium_id": "virtue.conscience"},
            {"value": "conviction", "label": "Convicção", "compendium_id": "virtue.conviction"}]},
        {"id": "second_virtue", "path": "/morality/second_virtue", "label": "Segunda virtude", "options": [
            {"value": "self_control", "label": "Autocontrole", "compendium_id": "virtue.self_control"},
            {"value": "instinct", "label": "Instinto", "compendium_id": "virtue.instinct"}]},
    ]
    if sects:
        choices.insert(1, {"id": "sect", "path": "/clan/sect", "label": "Seita", "options": [
            {"value": sid, "label": pt, "compendium_id": f"sect.{sid}"} for sid, pt, _ in sects]})

    zero = lambda b: cmp("eq", budget(b), const(0))
    steps_ = [
        {"id": "concept", "label": "Conceito", "paths": ["/identity/*"], "required": ["/identity/name", "/identity/concept", "/identity/nature", "/identity/demeanor"]},
        {"id": "clan", "label": "Clã", "paths": ["/clan/*"], "required": ["/clan/clan"]},
        {"id": "attributes", "label": "Atributos", "paths": [f"/priorities/{g}" for g in ATTRIBUTES] + [f"/attributes/{g}/*" for g in ATTRIBUTES],
         "required": [f"/priorities/{g}" for g in ATTRIBUTES], "complete_when": and_(*[zero(g) for g in ATTRIBUTES])},
        {"id": "abilities", "label": "Habilidades", "paths": [f"/priorities/{g}" for g in ABILITY_GROUPS] + [f"/abilities/{g}/*" for g in ABILITY_GROUPS] + ["/specialties"],
         "required": [f"/priorities/{g}" for g in ABILITY_GROUPS], "complete_when": and_(*[zero(g) for g in ABILITY_GROUPS])},
        {"id": "advantages", "label": "Vantagens", "paths": ["/disciplines/*", "/backgrounds/*", "/virtues/*", "/morality/first_virtue", "/morality/second_virtue"],
         "required": ["/morality/first_virtue", "/morality/second_virtue"], "complete_when": and_(zero("disciplines"), zero("backgrounds"), zero("virtues"))},
        {"id": "finishing", "label": "Toques finais", "paths": ["/morality/*", "/willpower/*", "/merits_flaws"], "required": ["/morality/path"],
         # Humanity/Path starts at the sum of the first two virtues and Willpower at Courage (not a
         # constraint: an empty sheet would refuse every edit until they are set).
         "complete_when": and_(cmp("ge", ref("/morality/rating"), morality_base), cmp("ge", ref("/willpower/permanent"), ref("/virtues/courage")))},
        {"id": "review", "label": "Revisão", "paths": ["/resources/*", "/description/*", "/weapons", "/inventory", "/discipline_paths", "/rituals"], "complete_when": const(True)},
    ]

    all_attributes = [f"/attributes/{g}/*" for g in ATTRIBUTES]
    all_abilities = [f"/abilities/{g}/*" for g in ABILITY_GROUPS]
    def pool(rid, label, inputs, count, **extra):
        return {"id": rid, "label": label, "strategy": "pool", "faces": 10, **({"inputs": inputs} if inputs else {}), "count": count,
                "difficulty": {"default": 6, "min": 2, "max": 10}, "required": {"default": 1, "min": 1, "max": 20},
                "cancel_at_most": 1, "botch_at_most": 1, **extra}
    attr_ability = {"attribute": {"targets": all_attributes}, "ability": {"targets": all_abilities}}
    rolls = [
        # Wound penalty applied by the server to action pools (count_modifier, floored at 0).
        pool("test", "Teste (atributo + habilidade)", attr_ability, add(var("attribute"), var("ability")), count_modifier=derived("wound_penalty")),
        pool("specialty_test", f"Teste com especialização (10 vale dois sucessos){PENDING}", attr_ability, add(var("attribute"), var("ability")),
             bonus_at_least=10, bonus_successes=1, count_modifier=derived("wound_penalty")),
        pool("attribute", "Atributo sozinho", {"attribute": {"targets": all_attributes}}, var("attribute"), count_modifier=derived("wound_penalty")),
        pool("virtue", "Virtude", {"virtue": {"targets": ["/virtues/*"]}}, var("virtue")),
        pool("willpower", "Força de Vontade", {}, ref("/willpower/permanent")),
        {"id": "initiative", "label": f"Iniciativa (1d10 + Destreza + Raciocínio){PENDING}", "strategy": "sum", "dice": "1d10", "modifier": derived("initiative")},
    ]

    rules = {
        "contract": "novtt.rules/2",
        "budgets": budgets, "costs": costs, "phase_limits": [], "dependencies": [],
        "derived": derived_, "effects": [], "constraints": constraints,
        "creation": {"steps": steps_, "choices": choices},
        "resources": [
            {"id": "blood", "label": "Reserva de sangue", "path": "/resources/blood", "max": derived("blood_pool_max"), "min": 0, "recovery": "manual"},
            {"id": "willpower", "label": "Força de Vontade", "path": "/resources/willpower", "max": ref("/willpower/permanent"), "min": 0, "recovery": "manual"},
            *[{"id": f"health_{k}", "label": label, "path": f"/resources/{k}", "max": const(7), "min": 0, "recovery": "manual"}
              for k, label in [("bashing", "Dano por contusão"), ("lethal", "Dano letal"), ("aggravated", "Dano agravado")]],
        ],
        "rolls": rolls,
        "governance": {"lock_during_session": True},
    }

    # ------------------------------------------------------------ compendium
    src = {"source_id": book["source_id"], "source_authenticity": "official", "rights_status": "rights_review",
           "license_id": "LicenseRef-WoD-proprietary", "requires_attribution": False, "review_status": "unverified", "edition": book["edition"]}
    def item(cid, kind, pt, en, refs=None, parent=None):
        d = {"canonical_id": cid, "kind": kind, "labels": {"pt-BR": pt, "en": en}, "rules_revision": book["rules_revision"],
             "locale": "pt-BR", "summary": "", "source": dict(src)}
        if parent:
            d["parent_id"] = parent
        if refs:
            d["mechanical_refs"] = refs
        return d
    taxonomy = [
        {"kind": "attribute", "labels": {"pt-BR": "Atributo", "en": "Attribute"}},
        {"kind": "ability_group", "labels": {"pt-BR": "Grupo de Habilidades", "en": "Ability category"}},
        {"kind": "ability", "labels": {"pt-BR": "Habilidade", "en": "Ability"}, "parent_kind": "ability_group"},
        {"kind": "clan", "labels": {"pt-BR": "Clã", "en": "Clan"}},
        {"kind": "discipline", "labels": {"pt-BR": "Disciplina", "en": "Discipline"}},
        {"kind": "background", "labels": {"pt-BR": "Antecedente", "en": "Background"}},
        {"kind": "virtue", "labels": {"pt-BR": "Virtude", "en": "Virtue"}},
        {"kind": book["morality_kind"], "labels": book["morality_kind_labels"]},
    ]
    if sects:
        taxonomy.append({"kind": "sect", "labels": {"pt-BR": "Seita", "en": "Sect"}})
    items = [
        *[item(f"attribute.{a}", "attribute", pt, en, refs=["roll:test", "roll:attribute"]) for _, (_, its) in ATTRIBUTES.items() for a, pt, en in its],
        *[item(f"ability_group.{g}", "ability_group", pt, en) for g, (pt, en) in ABILITY_GROUPS.items()],
        *[item(f"ability.{a}", "ability", pt, en, refs=["roll:test", f"cost:{g}_xp"], parent=f"ability_group.{g}") for g, its in abilities.items() for a, pt, en in its],
        *[item(f"clan.{cid}", "clan", pt, en, refs=[f"choice:clan:{cid}", *[f"cost:disc_{d}_xp" for d in ds]]) for cid, pt, en, ds in clans],
        *[item(f"discipline.{d}", "discipline", pt, en, refs=[f"cost:disc_{d}_creation", f"cost:disc_{d}_xp"]) for d, pt, en in disciplines],
        *[item(f"background.{b}", "background", pt, en, refs=(["derived:generation", "derived:blood_pool_max"] if b == "generation" else None)) for b, pt, en in backgrounds],
        *[item(f"virtue.{v}", "virtue", pt, en, refs=["roll:virtue"]) for _, _, opts in VIRTUES for v, pt, en in opts],
        *[item(f"{book['morality_kind']}.{m}", book["morality_kind"], pt, en, refs=[f"choice:morality:{m}"]) for m, pt, en in moralities],
        *[item(f"sect.{s}", "sect", pt, en, refs=[f"choice:sect:{s}"]) for s, pt, en in (sects or [])],
    ]
    compendium = {"contract": "novtt.compendium/1", "taxonomy": taxonomy, "items": items}

    manifest = {
        "contract": "novtt.preset-manifest/2",
        "family_id": "world-of-darkness", "variant_id": book["variant_id"], "version": book["version"],
        "display_name": book["display_name"], "locale": "pt-BR",
        "rules_revision": book["rules_revision"], "source_version": book["source_version"], "errata_reference": None,
        "license": {"identifier": "LicenseRef-WoD-proprietary", "source_url": "https://www.paradoxinteractive.com/games/world-of-darkness/community/dark-pack-agreement",
                    "rights_status": "rights_review", "requires_attribution": False, "consulted_at": "2026-11-23",
                    "policy_version": "Dark Pack Agreement (não cobre apps pagos; sem autorização escrita)"},
        "provenance": {"source_authenticity": "official", "authors": ["Equipe NOVTT"],
                       "notes": ("Mecânicas e nomes oficiais (via compêndio) para uso interno. Distribuição BLOQUEADA: o Dark Pack não cobre aplicativos "
                                 "pagos e não há autorização escrita da Paradox. Sem textos, artes ou descrições do livro. Nenhum documento-fonte no "
                                 "repositório: todos os valores foram lembrados pelo agente e estão 'a conferir' (ver SOURCE-REGISTER.md). "
                                 "Vampire: The Masquerade e World of Darkness são marcas da Paradox Interactive AB; conteúdo não oficial, sem afiliação.")},
        "capabilities": {"character_creation": True, "character_progression": True, "dice_resolution": True, "resource_tracking": True, "effects": False},
        "coverage": "progression",
        "limitations": book["limitations"],
        "publication": {"public": False},
        "engine": {"min_version": "0.3.0"},
        "files": {"sheet_schema": "sheet.schema.json", "sheet_ui": "sheet.ui.json", "rules": "rules.json", "compendium": "compendium.json"},
    }

    out.mkdir(parents=True, exist_ok=True)
    for name, data in [("manifest.json", manifest), ("sheet.schema.json", schema), ("sheet.ui.json", ui), ("rules.json", rules), ("compendium.json", compendium)]:
        (out / name).write_text(json.dumps(data, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    (out / "SOURCE-REGISTER.md").write_text(book["source_register"], encoding="utf-8")
    print(f"wrote {out.relative_to(ROOT)}: {len(clans)} clãs, {len(disciplines)} disciplinas, {len(costs)} custos, {len(items)} itens de compêndio")
    return out


def register_table(rows):
    """rows: [(rule, value used, confidence, status)] → markdown table."""
    lines = ["| Rule / data | Value used | Confidence | Status |", "|---|---|---|---|"]
    lines += [f"| {r} | {v} | {c} | {s} |" for r, v, c, s in rows]
    return "\n".join(lines) + "\n"


def common_rows(book):
    """SOURCE-REGISTER rows shared by both books, filled with the book's own values."""
    b, f, x = book["budgets"], book["freebie_costs"], book["xp_costs"]
    gen = ", ".join(f"{g}ª: {v[0]}/{v[1]} (máx. {v[2]})" for g, v in sorted(book["blood_by_generation"].items(), reverse=True))
    return [
        ("Attributes Physical/Social/Mental (3 each), start at 1 (free dot)", "9 attributes, `free_dots: 1`", "high", "pending"),
        ("Attribute priorities", "/".join(map(str, b["attributes"])) + " (budget lookup by `/priorities/*`)", "high", "pending"),
        ("Ability priorities Talents/Skills/Knowledges", "/".join(map(str, b["abilities"])), "high", "pending"),
        ("No Ability above 3 with priority points (freebies may raise it)", f"priority cost rule pays only up to {b['ability_creation_max']}; level above falls to freebies", "medium (whether freebies may exceed 3)", "pending"),
        ("Disciplines at creation", f"{b['disciplines']} dots, clan Disciplines only (clanless: any)", "high", "pending"),
        ("Backgrounds at creation", f"{b['backgrounds']} dots", "high", "pending"),
        ("Virtues at creation", f"{b['virtues']} dots, each starts at 1", "high", "pending"),
        ("Humanity/Path(Road) at creation = Conscience/Conviction + Self-Control/Instinct", "levels up to the sum are free; creation step `finishing` requires rating ≥ the sum", "high", "pending"),
        ("Willpower at creation = Courage", "levels up to Courage are free; step `finishing` requires Willpower ≥ Courage", "high", "pending"),
        ("Freebie points", f"{b['freebies']}", "high", "pending"),
        ("Freebie costs", f"Attribute {f['attribute']}, Ability {f['ability']}, Discipline {f['discipline']}, Background {f['background']}, Virtue {f['virtue']}, Humanity/Path {f['morality']}, Willpower {f['willpower']}", "high", "pending"),
        ("Freebies on out-of-clan Disciplines", f"allowed at {f['discipline']} per dot (master reviews the sheet)", "low", "pending"),
        ("XP costs", f"Attribute current×{x['attribute']}; new Ability {x['new_ability']}; Ability current×{x['ability']}; new Discipline {x['new_discipline']}; clan Discipline current×{x['clan_discipline']}; other Discipline current×{x['other_discipline']}"
         + (f"; clanless Discipline current×{x['clanless_discipline']}" if x.get("clanless_discipline") else "")
         + f"; Virtue current×{x['virtue']}; Humanity/Path current×{x['morality']}; Willpower current×{x['willpower']}", "high (clanless ×6: medium)", "pending"),
        ("Generation", f"{book['base_generation']}ª − Generation background", "high", "pending"),
        ("Blood pool max / per turn / trait max by generation", gen, "medium-high", "pending"),
        ("Clan Disciplines (three per clan)", "see `clans` in the generator", "high", "pending"),
        ("Clan that holds Appearance at 0", ", ".join(book["appearance_zero_clans"]) + " (`fixes` exact 0; points spent there are refunded)", "high", "pending"),
        ("Humanity uses Conscience and Self-Control", "Humanity option fixes both virtue types", "high", "pending"),
        ("Dice pool: d10, difficulty 2–10 (default 6), each 1 cancels a success, botch = no successes and at least one 1", "roll `test` (strategy `pool`)", "high", "pending"),
        ("Specialty: a 10 counts as two successes", "separate roll `specialty_test` (`bonus_at_least: 10`); 1s may cancel the bonus success too", "medium", "pending"),
        ("Health: 7 levels and wound penalties", "0, 0, −1, −1, −2, −2, −5, incapacitated; applied to action pools", "high", "pending"),
        ("Initiative", "1d10 + Dexterity + Wits", "high", "pending"),
        ("Attribute/ability/virtue names (en)", "compendium `labels.en`", "high", "pending"),
        ("Portuguese labels", "functional translations, not checked against the Brazilian edition", "low", "pending"),
        ("Merits and Flaws", "player-recorded collection; no freebie cost/bonus wired", "—", "missing"),
        ("Untrained Abilities (Skills +1 difficulty, Knowledges not rollable)", "not wired", "—", "missing"),
        ("Wound penalty applied to dice pools", "`count_modifier` on test / specialty / attribute pools (server-side)", "high", "pending"),
        ("Clan weaknesses other than Appearance 0", "not wired (narrative/situational)", "—", "missing"),
        ("Thaumaturgy/Necromancy secondary paths and rituals (costs)", "player-recorded collections, no costs", "—", "missing"),
        ("Background purchase with XP", "no advancement cost rule (raising a Background in advancement is refused)", "—", "missing"),
        ("Starting blood pool roll", "not wired; the master sets `/resources/blood`", "—", "missing"),
        ("Archetypes (Nature/Demeanor) list and Willpower regain", "free text", "—", "missing"),
        ("Diablerie / generation change in play", "not wired", "—", "missing"),
        ("Soak, damage and combat maneuvers", "not wired (roll `attribute` covers Stamina)", "—", "missing"),
        ("Priority points only up to the creation cap; creation Discipline dots only in clan", "cost rule `applies_when` (engine 0.3.0)", "high", "implemented"),
        ("Losing Humanity/Path or permanent Willpower in play", "`refund_on_decrease: false` on the XP rule (the loss stays on the ledger)", "high", "implemented"),
    ]


def register(book, title, extra_rows):
    rows = common_rows(book) + extra_rows
    return (f"# SOURCE-REGISTER — {title} (preset {book['version']})\n\n"
            f"Generated by `{book['script']}` (edit the tables there, then rebuild).\n"
            "Source: **none in the repository.** Every value was recalled by the implementing agent and is\n"
            "pending verification against the book (record edition, printing and page in `location`).\n"
            "Rights: proprietary (Paradox Interactive). The Dark Pack Agreement does not cover paid apps, and no\n"
            "written authorisation exists: `rights_status: rights_review`, `publication.public: false`, internal use only.\n"
            "No rulebook prose, art or descriptions: compendium entries carry names and mechanical refs only.\n\n"
            + register_table(rows) +
            "\nTo confirm a pending row: check the value in the book, fix the table in the generator if needed, set\n"
            "the row to verified, rebuild, bump the preset version (published versions are immutable) and run the\n"
            "engine tests (`docker compose --profile test run --rm engine`).\n")
