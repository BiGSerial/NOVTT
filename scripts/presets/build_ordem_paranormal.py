#!/usr/bin/env python3
"""Builds the Ordem Paranormal RPG preset (livro básico) from the tables below.

Run: python3 scripts/presets/build_ordem_paranormal.py  →  presets/ordem-paranormal-rpg/livro-basico/<VERSION>/

Rules of this package (SPEC-PRESETS-FIDELITY-001, Community Licence v1.0):
- Only general terminology (names of attributes, skills, classes, trails, origins, elements, ranks)
  and mechanics. No official descriptions, art or sheet layout. `summary` stays empty until a human
  writes and legal/editorial review approves it.
- Every table carries a `verified` flag. VERIFIED = from the owner's handoff (public editorial excerpt
  / official blank sheet). PENDING = recalled by the implementing agent and NOT yet checked against
  the book: wired so the sheet works, marked "a conferir" on screen, listed in SOURCE-REGISTER.md.
- Publication stays blocked (rights_status rights_review, public false).
"""
import json
import os
from pathlib import Path

VERSION = "0.1.1"
ROOT = Path(__file__).resolve().parents[2]
OUT = ROOT / "presets" / "ordem-paranormal-rpg" / "livro-basico" / VERSION
PENDING = " · a conferir"

ATTRS = [("agi", "Agilidade", "AGI"), ("for", "Força", "FOR"), ("int", "Intelecto", "INT"), ("pre", "Presença", "PRE"), ("vig", "Vigor", "VIG")]

# VERIFIED: handoff preset/skills.json (official blank sheet). (id, label, attribute, trained_only, load_penalty)
SKILLS = [
    ("acrobacia", "Acrobacia", "agi", False, True), ("adestramento", "Adestramento", "pre", True, False),
    ("artes", "Artes", "pre", True, False), ("atletismo", "Atletismo", "for", False, False),
    ("atualidades", "Atualidades", "int", False, False), ("ciencias", "Ciências", "int", True, False),
    ("crime", "Crime", "agi", True, True), ("diplomacia", "Diplomacia", "pre", False, False),
    ("enganacao", "Enganação", "pre", False, False), ("fortitude", "Fortitude", "vig", False, False),
    ("furtividade", "Furtividade", "agi", False, True), ("iniciativa", "Iniciativa", "agi", False, False),
    ("intimidacao", "Intimidação", "pre", False, False), ("intuicao", "Intuição", "pre", False, False),
    ("investigacao", "Investigação", "int", False, False), ("luta", "Luta", "for", False, False),
    ("medicina", "Medicina", "int", False, False), ("ocultismo", "Ocultismo", "int", True, False),
    ("percepcao", "Percepção", "pre", False, False), ("pilotagem", "Pilotagem", "agi", True, False),
    ("pontaria", "Pontaria", "agi", False, False), ("profissao", "Profissão", "int", True, False),
    ("reflexos", "Reflexos", "agi", False, False), ("religiao", "Religião", "pre", True, False),
    ("sobrevivencia", "Sobrevivência", "int", False, False), ("tatica", "Tática", "int", True, False),
    ("tecnologia", "Tecnologia", "int", True, False), ("vontade", "Vontade", "pre", False, False),
]
SKILL_ATTR = {s[0]: s[2] for s in SKILLS}
# PENDING: training grades and their bonus (0 destreinado, +5 treinado, +10 veterano, +15 expert).
GRADES = [(0, "Destreinado"), (1, "Treinado"), (2, "Veterano"), (3, "Expert")]
GRADE_BONUS = 5

# VERIFIED (names): handoff. PENDING: every number below.
CLASSES = {
    "combatente": {"label": "Combatente", "pv": (20, 4), "pe": (2, 2), "san": (12, 3), "skills_free": 1,
                   "fixed_skills": [], "proficiencies": ["armas_simples", "armas_taticas", "protecoes_leves"]},
    "especialista": {"label": "Especialista", "pv": (16, 3), "pe": (3, 3), "san": (16, 4), "skills_free": 7,
                     "fixed_skills": [], "proficiencies": ["armas_simples", "protecoes_leves"]},
    "ocultista": {"label": "Ocultista", "pv": (12, 2), "pe": (4, 4), "san": (20, 5), "skills_free": 3,
                  "fixed_skills": ["ocultismo", "vontade"], "proficiencies": ["armas_simples"]},
}
# PENDING: the combatente picks one of each pair.
COMBATENTE_PICKS = [("ataque", "Perícia de ataque", ["luta", "pontaria"]), ("defesa", "Perícia de resistência", ["fortitude", "reflexos"])]

# PENDING (names and their two trained skills): livro básico origins as recalled by the agent.
ORIGINS = [
    ("academico", "Acadêmico", ["ciencias", "investigacao"]), ("agente_de_saude", "Agente de Saúde", ["intuicao", "medicina"]),
    ("amnesico", "Amnésico", []), ("artista", "Artista", ["artes", "enganacao"]), ("atleta", "Atleta", ["acrobacia", "atletismo"]),
    ("chef", "Chef", ["fortitude", "profissao"]), ("criminoso", "Criminoso", ["crime", "furtividade"]),
    ("cultista_arrependido", "Cultista Arrependido", ["ocultismo", "religiao"]), ("desgarrado", "Desgarrado", ["fortitude", "sobrevivencia"]),
    ("engenheiro", "Engenheiro", ["profissao", "tecnologia"]), ("executivo", "Executivo", ["diplomacia", "profissao"]),
    ("investigador", "Investigador", ["investigacao", "percepcao"]), ("lutador", "Lutador", ["luta", "reflexos"]),
    ("magnata", "Magnata", ["diplomacia", "pilotagem"]), ("mercenario", "Mercenário", ["iniciativa", "intimidacao"]),
    ("militar", "Militar", ["pontaria", "tatica"]), ("operario", "Operário", ["fortitude", "profissao"]),
    ("policial", "Policial", ["percepcao", "pontaria"]), ("religioso", "Religioso", ["religiao", "vontade"]),
    ("servidor_publico", "Servidor Público", ["intuicao", "vontade"]), ("teorico_da_conspiracao", "Teórico da Conspiração", ["investigacao", "ocultismo"]),
    ("ti", "T.I.", ["investigacao", "tecnologia"]), ("trabalhador_rural", "Trabalhador Rural", ["adestramento", "sobrevivencia"]),
    ("trambiqueiro", "Trambiqueiro", ["crime", "enganacao"]), ("universitario", "Universitário", ["atualidades", "investigacao"]),
    ("vitima", "Vítima", ["reflexos", "vontade"]),
]
# PENDING (names, class, NEX of choice): livro básico trails as recalled by the agent.
TRAIL_NEX = 10
TRAILS = [
    ("aniquilador", "Aniquilador", "combatente"), ("comandante_de_campo", "Comandante de Campo", "combatente"),
    ("guerreiro", "Guerreiro", "combatente"), ("operacoes_especiais", "Operações Especiais", "combatente"), ("tropa_de_choque", "Tropa de Choque", "combatente"),
    ("atirador_de_elite", "Atirador de Elite", "especialista"), ("infiltrador", "Infiltrador", "especialista"),
    ("medico_de_campo", "Médico de Campo", "especialista"), ("negociador", "Negociador", "especialista"), ("tecnico", "Técnico", "especialista"),
    ("conduite", "Conduíte", "ocultista"), ("flagelador", "Flagelador", "ocultista"), ("graduado", "Graduado", "ocultista"),
    ("intuitivo", "Intuitivo", "ocultista"), ("lamina_paranormal", "Lâmina Paranormal", "ocultista"),
]
# VERIFIED (licence Part 3): elements. PENDING: circle costs.
ELEMENTS = [("sangue", "Sangue"), ("morte", "Morte"), ("conhecimento", "Conhecimento"), ("energia", "Energia"), ("medo", "Medo")]
RITUAL_CIRCLE_COST = [(1, 1), (2, 3), (3, 6), (4, 10)]
# PENDING: ranks (patentes).
RANKS = [("recruta", "Recruta"), ("operador", "Operador"), ("agente_especial", "Agente Especial"), ("oficial_de_operacoes", "Oficial de Operações"), ("agente_de_elite", "Agente de Elite")]
PROFICIENCIES = [("armas_simples", "Armas simples"), ("armas_taticas", "Armas táticas"), ("armas_pesadas", "Armas pesadas"), ("protecoes_leves", "Proteções leves"), ("protecoes_pesadas", "Proteções pesadas")]
# PENDING: NEX steps at which an attribute point / training grades are gained.
ATTRIBUTE_NEX = [20, 50, 80, 95]
GRADE_NEX = {2: 35, 3: 70}
NEX_STEPS = 20  # 5%, 10% ... 95%, 99%

# ---------------------------------------------------------------- formula helpers
def const(v): return {"const": v}
def ref(p): return {"ref": p}
def add(*a): return {"op": "add", "args": list(a)}
def sub(a, b): return {"op": "subtract", "args": [a, b]}
def mul(*a): return {"op": "multiply", "args": list(a)}
def cmp(c, a, b): return {"op": "compare", "cmp": c, "args": [a, b]}
def if_(c, a, b): return {"op": "if", "args": [c, a, b]}
def and_(*a): return {"op": "and", "args": list(a)}
def or_(*a): return {"op": "or", "args": list(a)}
def maximum(*a): return {"op": "max", "args": list(a)}
def minimum(*a): return {"op": "min", "args": list(a)}
def table(key, mapping, default=0): return {"op": "lookup", "key": key, "table": mapping, "default": const(default)}
def steps(key, pairs): return {"op": "lookup", "key": key, "steps": [list(p) for p in pairs]}
def budget(b, of="balance"): return {"budget": b, "of": of}

CLASS = ref("/classe/classe")
NEX_LEVEL = ref("/progresso/nex_nivel")
EXTRA_LEVELS = sub(NEX_LEVEL, const(1))
def by_class(field, part):
    return table(CLASS, {k: v[field][part] for k, v in CLASSES.items()})

def write(name, data):
    (OUT / name).write_text(json.dumps(data, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

# ---------------------------------------------------------------- sheet schema
def s_str(n=200): return {"type": "string", "maxLength": n}
def s_int(lo, hi, default=None):
    d = {"type": "integer", "minimum": lo, "maximum": hi}
    if default is not None: d["default"] = default
    return d
def obj(props): return {"type": "object", "additionalProperties": False, "properties": props}
ITEM_ID = {"type": "string", "pattern": "^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$"}
def coll(props, required, max_items):
    return {"type": "array", "maxItems": max_items, "items": {"type": "object", "additionalProperties": False, "required": ["id", *required], "properties": {"id": ITEM_ID, **props}}}

schema = {
    "$schema": "https://json-schema.org/draft/2020-12/schema",
    "title": f"ordem-paranormal-rpg livro-basico {VERSION} sheet",
    "type": "object", "additionalProperties": False,
    "properties": {
        "identidade": obj({"nome": s_str(120), "jogador": s_str(120), "conceito": s_str(200)}),
        "origem": obj({"origem": {"type": "string", "enum": [o[0] for o in ORIGINS]}}),
        "classe": obj({
            "classe": {"type": "string", "enum": list(CLASSES)},
            "trilha": {"type": "string", "enum": [t[0] for t in TRAILS]},
            **{f"pericia_{pid}": {"type": "string", "enum": opts} for pid, _, opts in COMBATENTE_PICKS},
        }),
        "progresso": obj({
            "nex_nivel": s_int(1, NEX_STEPS, 1), "nex": {"type": "integer"},
            "patente": {"type": "string", "enum": [r[0] for r in RANKS], "default": "recruta"},
            "prestigio": s_int(0, 9999, 0),
        }),
        "atributos": obj({a: s_int(0, 5, 1) for a, _, _ in ATTRS}),
        "pericias": obj({s[0]: s_int(0, 3, 0) for s in SKILLS}),
        "bonus": obj({s[0]: {"type": "integer"} for s in SKILLS}),
        "derivados": obj({k: {"type": "integer"} for k in ["pv_max", "pe_max", "san_max", "defesa", "pe_por_rodada", "dt_ritual", "carga"]}),
        "recursos": obj({"pv": s_int(-999, 999, 0), "pe": s_int(0, 999, 0), "san": s_int(0, 999, 0)}),
        "combate": obj({"protecao": s_int(0, 99, 0), "resistencias": s_str(300), "deslocamento": s_int(0, 99, 9)}),
        "proficiencias": obj({p[0]: {"type": "boolean", "default": False} for p in PROFICIENCIES}),
        "ataques": coll({"nome": s_str(80), "teste": s_str(40), "dano": s_str(40), "critico": s_str(20), "alcance": s_str(40), "tipo": s_str(40)}, ["nome"], 20),
        "inventario": coll({"nome": s_str(80), "categoria": s_int(0, 4, 0), "espacos": s_int(0, 20, 1), "quantidade": s_int(1, 99, 1)}, ["nome"], 40),
        "rituais": coll({
            "nome": s_str(80), "elemento": {"type": "string", "enum": [e[0] for e in ELEMENTS]}, "circulo": s_int(1, 4, 1),
            "execucao": s_str(40), "alcance": s_str(40), "alvo": s_str(80), "duracao": s_str(40), "resistencia": s_str(80),
        }, ["nome", "elemento", "circulo"], 30),
        "habilidades": coll({"nome": s_str(80), "fonte": {"type": "string", "enum": ["classe", "trilha", "origem", "paranormal", "outra"]}, "nota": s_str(300)}, ["nome"], 40),
        "descricao": obj({"aparencia": s_str(1000), "personalidade": s_str(1000), "historico": s_str(3000), "objetivo": s_str(500)}),
    },
}

# ---------------------------------------------------------------- sheet ui
def field(path, label, widget, **kw): return {"kind": "field", "path": path, "label": label, "widget": widget, **kw}
ui = {"contract": "novtt.sheet-ui/1", "default_access": "read_only", "sections": [
    {"id": "identidade", "label": "Identidade", "elements": [
        field("/identidade/nome", "Nome do agente", "text", access="editable"),
        field("/identidade/jogador", "Jogador", "text", access="editable"),
        field("/identidade/conceito", "Conceito", "text", access="editable")]},
    {"id": "atributos", "label": "Atributos", "access": "approval_required", "elements": [field(f"/atributos/{a}", f"{label} ({abbr})", "integer") for a, label, abbr in ATTRS]},
    {"id": "origem", "label": "Origem", "access": "approval_required", "elements": [field("/origem/origem", "Origem", "select")]},
    {"id": "classe", "label": "Classe e trilha", "access": "approval_required", "elements": [
        field("/classe/classe", "Classe", "select"),
        *[field(f"/classe/pericia_{pid}", label, "select") for pid, label, _ in COMBATENTE_PICKS],
        field("/classe/trilha", "Trilha", "select")]},
    {"id": "pericias", "label": "Perícias", "access": "approval_required", "elements": [field(f"/pericias/{s[0]}", f"{s[1]} ({s[2].upper()}){' *' if s[3] else ''}{' +' if s[4] else ''}", "integer") for s in SKILLS]},
    {"id": "bonus", "label": "Bônus de perícia", "access": "read_only", "elements": [field(f"/bonus/{s[0]}", f"Bônus de {s[1]}{PENDING}", "integer") for s in SKILLS]},
    {"id": "progresso", "label": "NEX e patente", "access": "approval_required", "elements": [
        field("/progresso/nex_nivel", "Etapa de NEX", "integer"), field("/progresso/nex", "NEX (%)", "integer", access="read_only"),
        field("/progresso/patente", "Patente", "select"), field("/progresso/prestigio", "Pontos de prestígio", "integer")]},
    {"id": "derivados", "label": "Valores calculados", "access": "read_only", "elements": [
        field("/derivados/pv_max", f"PV máximo{PENDING}", "integer"), field("/derivados/pe_max", f"PE máximo{PENDING}", "integer"),
        field("/derivados/san_max", f"Sanidade máxima{PENDING}", "integer"), field("/derivados/defesa", f"Defesa{PENDING}", "integer"),
        field("/derivados/pe_por_rodada", f"Limite de PE por rodada{PENDING}", "integer"), field("/derivados/dt_ritual", f"DT de rituais{PENDING}", "integer")]},
    {"id": "recursos", "label": "Recursos", "access": "editable", "elements": [
        field("/recursos/pv", "PV atuais", "integer"), field("/recursos/pe", "PE atuais", "integer"), field("/recursos/san", "Sanidade atual", "integer")]},
    {"id": "combate", "label": "Combate", "access": "approval_required", "elements": [
        field("/combate/protecao", "Proteção", "integer"), field("/combate/resistencias", "Resistências", "text"), field("/combate/deslocamento", "Deslocamento (m)", "integer"),
        *[field(f"/proficiencias/{p}", label, "boolean") for p, label in PROFICIENCIES],
        {"kind": "collection", "path": "/ataques", "label": "Ataques", "max_items": 20, "item_fields": [
            {"field": "nome", "label": "Arma", "widget": "text"}, {"field": "teste", "label": "Teste", "widget": "text"}, {"field": "dano", "label": "Dano", "widget": "text"},
            {"field": "critico", "label": "Crítico", "widget": "text"}, {"field": "alcance", "label": "Alcance", "widget": "text"}, {"field": "tipo", "label": "Tipo", "widget": "text"}]}]},
    {"id": "inventario", "label": "Inventário", "access": "approval_required", "elements": [
        {"kind": "collection", "path": "/inventario", "label": "Itens", "max_items": 40, "item_fields": [
            {"field": "nome", "label": "Item", "widget": "text"}, {"field": "categoria", "label": "Categoria (0–IV)", "widget": "integer"},
            {"field": "espacos", "label": "Espaços", "widget": "integer"}, {"field": "quantidade", "label": "Quantidade", "widget": "integer"}]}]},
    {"id": "rituais", "label": "Rituais", "access": "approval_required", "elements": [
        {"kind": "collection", "path": "/rituais", "label": "Rituais conhecidos", "max_items": 30, "item_fields": [
            {"field": "nome", "label": "Ritual", "widget": "text"}, {"field": "elemento", "label": "Elemento", "widget": "select"}, {"field": "circulo", "label": "Círculo", "widget": "integer"},
            {"field": "execucao", "label": "Execução", "widget": "text"}, {"field": "alcance", "label": "Alcance", "widget": "text"}, {"field": "alvo", "label": "Alvo", "widget": "text"},
            {"field": "duracao", "label": "Duração", "widget": "text"}, {"field": "resistencia", "label": "Resistência", "widget": "text"}]}]},
    {"id": "habilidades", "label": "Habilidades e poderes", "access": "approval_required", "elements": [
        {"kind": "collection", "path": "/habilidades", "label": "Habilidades", "max_items": 40, "item_fields": [
            {"field": "nome", "label": "Nome", "widget": "text"}, {"field": "fonte", "label": "Fonte", "widget": "select"}, {"field": "nota", "label": "Anotação do jogador", "widget": "textarea"}]}]},
    {"id": "descricao", "label": "Descrição", "access": "editable", "elements": [
        field("/descricao/aparencia", "Aparência", "textarea"), field("/descricao/personalidade", "Personalidade", "textarea"),
        field("/descricao/historico", "Histórico", "textarea"), field("/descricao/objetivo", "Objetivo", "textarea")]},
]}

# ---------------------------------------------------------------- rules
attr_ref = {a: ref(f"/atributos/{a}") for a, _, _ in ATTRS}
zeros = add(*[if_(cmp("eq", attr_ref[a], const(0)), const(1), const(0)) for a, _, _ in ATTRS])
nex_percent = minimum(mul(NEX_LEVEL, const(5)), const(99))

derived = [
    {"id": "nex", "path": "/progresso/nex", "label": "NEX (%)", "formula": nex_percent},
    {"id": "pv_max", "path": "/derivados/pv_max", "label": f"PV máximo{PENDING}", "formula": if_(cmp("ne", CLASS, const("")),
        add(by_class("pv", 0), attr_ref["vig"], mul(EXTRA_LEVELS, add(by_class("pv", 1), attr_ref["vig"]))), const(0))},
    {"id": "pe_max", "path": "/derivados/pe_max", "label": f"PE máximo{PENDING}", "formula": if_(cmp("ne", CLASS, const("")),
        add(by_class("pe", 0), attr_ref["pre"], mul(EXTRA_LEVELS, add(by_class("pe", 1), attr_ref["pre"]))), const(0))},
    {"id": "san_max", "path": "/derivados/san_max", "label": f"Sanidade máxima{PENDING}", "formula": if_(cmp("ne", CLASS, const("")),
        add(by_class("san", 0), mul(EXTRA_LEVELS, by_class("san", 1))), const(0))},
    {"id": "defesa", "path": "/derivados/defesa", "label": f"Defesa{PENDING}", "formula": add(const(10), attr_ref["agi"])},
    {"id": "pe_por_rodada", "path": "/derivados/pe_por_rodada", "label": f"Limite de PE por rodada{PENDING}", "formula": NEX_LEVEL},
    {"id": "dt_ritual", "path": "/derivados/dt_ritual", "label": f"DT de rituais{PENDING}", "formula": add(const(10), NEX_LEVEL, attr_ref["pre"])},
    *[{"id": f"bonus_{s[0]}", "path": f"/bonus/{s[0]}", "label": f"Bônus de {s[1]}{PENDING}", "formula": mul(ref(f"/pericias/{s[0]}"), const(GRADE_BONUS))} for s in SKILLS],
]

# Skill points at creation: by class + Intelecto (PENDING). Never negative.
skill_points = maximum(const(0), add(table(CLASS, {k: v["skills_free"] for k, v in CLASSES.items()}), attr_ref["int"]))
grade_points = add(*[if_(cmp("ge", NEX_LEVEL, const(nex // 5)), maximum(const(0), add(const(2), attr_ref["int"])), const(0)) for nex in GRADE_NEX.values()])
attr_points = add(*[if_(cmp("ge", NEX_LEVEL, const(n // 5)), const(1), const(0)) for n in ATTRIBUTE_NEX])

budgets = [
    {"id": "atributos", "kind": "initial", "label": "Pontos de atributo", "amount": add(const(4), zeros)},
    {"id": "pericias", "kind": "initial", "label": f"Perícias treinadas a escolher{PENDING}", "amount": skill_points},
    {"id": "aumento_atributo", "kind": "other", "label": f"Aumentos de atributo por NEX{PENDING}", "amount": attr_points},
    {"id": "graus_treinamento", "kind": "other", "label": f"Graus de treinamento por NEX{PENDING}", "amount": grade_points},
    {"id": "marcos_nex", "kind": "other", "label": "Etapas de NEX concedidas pelo mestre", "initial_amount": 0},
]
costs = [
    {"id": "atributos_criacao", "phase": "creation", "budget": "atributos", "targets": ["/atributos/*"], "model": {"type": "fixed_per_dot", "amount": 1}, "free_dots": 1},
    {"id": "pericias_criacao", "phase": "creation", "budget": "pericias", "targets": ["/pericias/*"], "model": {"type": "fixed_per_dot", "amount": 1}},
    {"id": "atributos_nex", "phase": "advancement", "budget": "aumento_atributo", "targets": ["/atributos/*"], "model": {"type": "fixed_per_dot", "amount": 1}},
    {"id": "pericias_nex", "phase": "advancement", "budget": "graus_treinamento", "targets": ["/pericias/*"], "model": {"type": "fixed_per_dot", "amount": 1}},
]
phase_limits = [
    {"id": "atributo_maximo_inicial", "phase": "creation", "targets": ["/atributos/*"], "max": 3},
    {"id": "pericia_treinada_na_criacao", "phase": "creation", "targets": ["/pericias/*"], "max": 1},
]
constraints = [
    {"id": "um_atributo_zero", "label": "No máximo um atributo pode ser reduzido a 0", "assert": cmp("le", zeros, const(1)), "paths": [f"/atributos/{a}" for a, _, _ in ATTRS]},
    *[{"id": f"grau_{g}_{s[0]}", "phase": "advancement", "label": f"{s[1]}: grau {g} exige NEX {nex}%{PENDING}",
       "assert": or_(cmp("lt", ref(f"/pericias/{s[0]}"), const(g)), cmp("ge", NEX_LEVEL, const(nex // 5))), "paths": [f"/pericias/{s[0]}"]}
      for s in SKILLS for g, nex in GRADE_NEX.items()],
]

def fix(path, value, label, mode="exact"): return {"path": path, "value": const(value), "label": label, "mode": mode}
choices = [
    {"id": "origem", "path": "/origem/origem", "label": "Origem", "options": [
        {"value": oid, "label": label, "compendium_id": f"origem.{oid}",
         **({"fixes": [fix(f"/pericias/{s}", 1, f"perícia da origem{PENDING}", "at_least") for s in skills]} if skills else {})}
        for oid, label, skills in ORIGINS]},
    {"id": "classe", "path": "/classe/classe", "label": "Classe", "options": [
        {"value": cid, "label": c["label"], "compendium_id": f"classe.{cid}",
         "fixes": [*[fix(f"/pericias/{s}", 1, f"perícia da classe{PENDING}", "at_least") for s in c["fixed_skills"]],
                   *[fix(f"/proficiencias/{p}", True, f"proficiência da classe{PENDING}") for p in c["proficiencies"]]]}
        for cid, c in CLASSES.items()]},
    *[{"id": f"combatente_{pid}", "path": f"/classe/pericia_{pid}", "label": label, "options": [
        {"value": s, "label": dict((x[0], x[1]) for x in SKILLS)[s], "requires": cmp("eq", CLASS, const("combatente")),
         "fixes": [fix(f"/pericias/{s}", 1, f"{label.lower()} do combatente{PENDING}", "at_least")]} for s in opts]}
      for pid, label, opts in COMBATENTE_PICKS],
    {"id": "trilha", "path": "/classe/trilha", "label": "Trilha", "options": [
        {"value": tid, "label": label, "compendium_id": f"trilha.{tid}",
         "requires": and_(cmp("eq", CLASS, const(cls)), cmp("ge", NEX_LEVEL, const(TRAIL_NEX // 5)))} for tid, label, cls in TRAILS]},
]
steps_ = [
    {"id": "identidade", "label": "Identidade", "paths": ["/identidade/*"], "required": ["/identidade/nome"]},
    {"id": "atributos", "label": "Atributos", "paths": ["/atributos/*"], "complete_when": cmp("eq", budget("atributos"), const(0))},
    {"id": "origem", "label": "Origem", "paths": ["/origem/*"], "required": ["/origem/origem"]},
    {"id": "classe", "label": "Classe", "paths": ["/classe/classe", "/classe/pericia_ataque", "/classe/pericia_defesa"], "required": ["/classe/classe"],
     "complete_when": or_(cmp("ne", CLASS, const("combatente")), and_(cmp("ne", ref("/classe/pericia_ataque"), const("")), cmp("ne", ref("/classe/pericia_defesa"), const(""))))},
    {"id": "pericias", "label": "Perícias", "paths": ["/pericias/*"], "complete_when": cmp("eq", budget("pericias"), const(0))},
    {"id": "equipamento", "label": "Equipamento", "paths": ["/inventario", "/ataques", "/combate/*"], "complete_when": const(True)},
    {"id": "revisao", "label": "Revisão", "paths": ["/descricao/*", "/recursos/*"], "complete_when": const(True)},
]

def keep_roll(rid, label, count_formula, modifier):
    return {"id": rid, "label": label, "strategy": "keep", "faces": 20,
            "count": if_(cmp("ge", count_formula, const(1)), count_formula, const(2)),
            "keep_mode": if_(cmp("ge", count_formula, const(1)), const("highest"), const("lowest")),
            "modifier": modifier, "target": {"default": 15, "min": 0, "max": 60}}

rolls = [
    {**keep_roll("atributo", "Teste de atributo", {"var": "atributo"}, const(0)), "inputs": {"atributo": {"targets": ["/atributos/*"]}}},
    *[keep_roll(f"pericia_{s[0]}", s[1], ref(f"/atributos/{s[2]}"), mul(ref(f"/pericias/{s[0]}"), const(GRADE_BONUS))) for s in SKILLS],
]

rules = {
    "contract": "novtt.rules/2",
    "budgets": budgets, "costs": costs, "phase_limits": phase_limits, "dependencies": [],
    "derived": derived, "effects": [], "constraints": constraints,
    "creation": {"steps": steps_, "choices": choices},
    "progression": {"tracks": [{"id": "nex", "label": "NEX", "path": "/progresso/nex_nivel", "max_step": 1,
                                "measure": add(const(1), budget("marcos_nex", "granted")), "thresholds": list(range(1, NEX_STEPS + 1))}]},
    "resources": [
        {"id": "pv", "label": "Pontos de Vida", "path": "/recursos/pv", "max": {"derived": "pv_max"}, "min": -999, "recovery": "rest"},
        {"id": "pe", "label": "Pontos de Esforço", "path": "/recursos/pe", "max": {"derived": "pe_max"}, "min": 0, "recovery": "rest"},
        {"id": "san", "label": "Sanidade", "path": "/recursos/san", "max": {"derived": "san_max"}, "min": 0, "recovery": "manual"},
    ],
    "rolls": rolls,
    "governance": {"lock_during_session": True},
}

# ---------------------------------------------------------------- compendium
SRC = {"source_id": "op-livro-basico", "source_authenticity": "official", "rights_status": "rights_review", "license_id": "OP-Community-1.0",
       "requires_attribution": True, "attribution_ref": "op", "edition": "livro-basico"}
def item(cid, kind, label, verified, parent=None, refs=None):
    d = {"canonical_id": cid, "kind": kind, "labels": {"pt-BR": label}, "rules_revision": "pending", "locale": "pt-BR",
         "source": {**SRC, "review_status": "verified" if verified else "unverified"}}
    if parent: d["parent_id"] = parent
    if refs: d["mechanical_refs"] = refs
    return d
compendium = {
    "contract": "novtt.compendium/1",
    "attributions": {"op": "Este é um conteúdo não oficial, publicado sob a Licença da Comunidade de Ordem Paranormal"},
    "taxonomy": [
        {"kind": "atributo", "labels": {"pt-BR": "Atributo"}}, {"kind": "pericia", "labels": {"pt-BR": "Perícia"}},
        {"kind": "origem", "labels": {"pt-BR": "Origem"}}, {"kind": "classe", "labels": {"pt-BR": "Classe"}},
        {"kind": "trilha", "labels": {"pt-BR": "Trilha"}, "parent_kind": "classe"}, {"kind": "elemento", "labels": {"pt-BR": "Elemento"}},
        {"kind": "patente", "labels": {"pt-BR": "Patente"}},
    ],
    "items": [
        *[item(f"atributo.{a}", "atributo", f"{label} ({abbr})", True) for a, label, abbr in ATTRS],
        *[item(f"pericia.{s[0]}", "pericia", s[1], True, refs=[f"roll:pericia_{s[0]}"]) for s in SKILLS],
        *[item(f"origem.{o}", "origem", label, False, refs=[f"choice:origem:{o}"]) for o, label, _ in ORIGINS],
        *[item(f"classe.{c}", "classe", v["label"], True, refs=[f"choice:classe:{c}", "derived:pv_max", "derived:pe_max", "derived:san_max"]) for c, v in CLASSES.items()],
        *[item(f"trilha.{t}", "trilha", label, False, parent=f"classe.{cls}", refs=[f"choice:trilha:{t}"]) for t, label, cls in TRAILS],
        *[item(f"elemento.{e}", "elemento", label, True) for e, label in ELEMENTS],
        *[item(f"patente.{r}", "patente", label, False) for r, label in RANKS],
    ],
}

manifest = {
    "contract": "novtt.preset-manifest/2",
    "family_id": "ordem-paranormal-rpg", "variant_id": "livro-basico", "version": VERSION,
    "display_name": "Ordem Paranormal RPG — livro básico (não oficial)", "locale": "pt-BR",
    "rules_revision": "pending-exact-source-revision", "source_version": "livro-basico (revisão a confirmar)", "errata_reference": None,
    "license": {"identifier": "OP-Community-1.0", "source_url": "https://ordemparanormal.com.br/licenca", "rights_status": "rights_review",
                "requires_attribution": True, "attribution": "Este é um conteúdo não oficial, publicado sob a Licença da Comunidade de Ordem Paranormal",
                "consulted_at": "2026-11-23", "policy_version": "1.0 (28/06/2026)"},
    "provenance": {"source_authenticity": "licensed", "authors": ["Equipe NOVTT"],
                   "notes": "Terminologia geral e mecânicas sob a Licença da Comunidade v1.0. Sem textos, artes ou layout da ficha oficial. Valores marcados 'a conferir' ainda não foram conferidos no livro (ver SOURCE-REGISTER.md). Publicação bloqueada até revisão jurídica."},
    "capabilities": {"character_creation": True, "character_progression": True, "dice_resolution": True, "resource_tracking": True, "effects": False},
    "coverage": "progression",
    "limitations": [
        "Valores de classe (PV/PE/SAN), perícias de origem, trilhas, graus de treinamento, patentes e custos de ritual: a conferir no livro.",
        "Habilidades de classe, trilha e origem, poderes e rituais são registrados pelo jogador; efeitos mecânicos pendentes de curadoria.",
        "Sobrevivendo ao Horror: não incluído (módulo desativado por padrão).",
        "Carga, críticos de armas e economia de ações: pendentes.",
    ],
    "publication": {"public": False},
    "engine": {"min_version": "0.3.0"},
    "files": {"sheet_schema": "sheet.schema.json", "sheet_ui": "sheet.ui.json", "rules": "rules.json", "compendium": "compendium.json"},
}

OUT.mkdir(parents=True, exist_ok=True)
for name, data in [("manifest.json", manifest), ("sheet.schema.json", schema), ("sheet.ui.json", ui), ("rules.json", rules), ("compendium.json", compendium)]:
    write(name, data)
print(f"wrote {OUT.relative_to(ROOT)}: {len(SKILLS)} perícias, {len(ORIGINS)} origens, {len(TRAILS)} trilhas, {len(rolls)} rolagens")
