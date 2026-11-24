#!/usr/bin/env python3
"""Builds the fifth-edition preset for the 2024 rules from the System Reference Document 5.2.1.

Run: python3 scripts/presets/build_dnd_5e_2024.py  ->  presets/dnd-5e/srd-5.2.1/<VERSION>/

Rules of this package (SPEC-PRESETS-FIDELITY-001, LICENSE-MATRIX):
- Only content present in SRD 5.2.1 (CC BY 4.0): the subclass, species, background and feat lists
  below are the SRD ones; the engine can represent others (taxonomy class -> subclass, choices),
  but none is filled in here.
- The exact attribution statement of the SRD 5.2.1 legal page is carried verbatim.
- Labels are short functional names (pt-BR, with the SRD name kept in the compendium); no SRD prose.
- Page numbers refer to SRD_CC_v5.2.1.pdf (sha256 8974902d…5e3d87), consulted 2026-11-24.
"""
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from _common import (AB_IDS, SKILL_IDS, add, and_, const, eq, if_, in_, level_steps, ne, not_, or_, ref,  # noqa: E402
                     write_package)

VERSION = "0.1.0"
SRD = "SRD 5.2.1"
ATTRIBUTION = ("This work includes material from the System Reference Document 5.2.1 (“SRD 5.2.1”) by Wizards of the Coast LLC, "
               "available at https://www.dndbeyond.com/srd. The SRD 5.2.1 is licensed under the Creative Commons Attribution 4.0 "
               "International License, available at https://creativecommons.org/licenses/by/4.0/legalcode.")

def pg(n): return f"{SRD} p. {n}"
def pp(a, b): return f"{SRD} pp. {a}–{b}"

FULL_PREP = [4, 5, 6, 7, 9, 10, 11, 12, 14, 15, 16, 16, 17, 17, 18, 18, 19, 20, 21, 22]
SORC_PREP = [2, 4, 6, 7, 9, 10, 11, 12, 14, 15, 16, 16, 17, 17, 18, 18, 19, 20, 21, 22]
WIZ_PREP = [4, 5, 6, 7, 9, 10, 11, 12, 14, 15, 16, 16, 17, 18, 19, 21, 22, 23, 24, 25]
HALF_PREP = [2, 3, 4, 5, 6, 6, 7, 7, 9, 9, 10, 10, 11, 11, 12, 12, 14, 14, 15, 15]
WARLOCK_PREP = [2, 3, 4, 5, 6, 7, 8, 9, 10, 10, 11, 11, 12, 12, 13, 13, 14, 14, 15, 15]
def prep(lst): return ("steps", level_steps({i + 1: v for i, v in enumerate(lst)}))

ALL6 = ["armor_light", "armor_medium", "armor_heavy", "shields", "weapons_simple", "weapons_martial"]
STD_ASI = [4, 8, 12, 16, 19]

# Core traits tables of each class (SRD 5.2.1 "Classes"). Subclass: the single SRD one.
CLASSES = {
    "barbarian": dict(label="Bárbaro", en="Barbarian", page=pg(28), hd=12, saves=["str", "con"], skill_count=2,
                      skill_list=["animal_handling", "athletics", "intimidation", "nature", "perception", "survival"],
                      profs=["armor_light", "armor_medium", "shields", "weapons_simple", "weapons_martial"], caster=None, asi_levels=STD_ASI,
                      unarmored=dict(base=10, abilities=["dex", "con"], shield_ok=True), speed=[dict(unless="heavy", steps=[(1, 0), (5, 10)])],
                      subclass=dict(id="path_of_the_berserker", label="Caminho do Berserker", en="Path of the Berserker", level=3, page=pg(30))),
    "bard": dict(label="Bardo", en="Bard", page=pg(31), hd=8, saves=["dex", "cha"], skill_count=3, skill_list=None,
                 profs=["armor_light", "weapons_simple"], notes="Três instrumentos musicais à escolha", caster="full", spell_ability="cha",
                 cantrips=[(1, 2), (4, 3), (10, 4)], prepared=prep(FULL_PREP), asi_levels=STD_ASI, expertise=[(2, 2), (9, 4)],
                 subclass=dict(id="college_of_lore", label="Colégio do Conhecimento", en="College of Lore", level=3, page=pg(35), any_skills=3)),
    "cleric": dict(label="Clérigo", en="Cleric", page=pg(36), hd=8, saves=["wis", "cha"], skill_count=2,
                   skill_list=["history", "insight", "medicine", "persuasion", "religion"],
                   profs=["armor_light", "armor_medium", "shields", "weapons_simple"], caster="full", spell_ability="wis",
                   cantrips=[(1, 3), (4, 4), (10, 5)], prepared=prep(FULL_PREP), asi_levels=STD_ASI,
                   subclass=dict(id="life_domain", label="Domínio da Vida", en="Life Domain", level=3, page=pg(40))),
    "druid": dict(label="Druida", en="Druid", page=pg(41), hd=8, saves=["int", "wis"], skill_count=2,
                  skill_list=["animal_handling", "arcana", "insight", "medicine", "nature", "perception", "religion", "survival"],
                  profs=["armor_light", "shields", "weapons_simple"], notes="Kit de herbalismo", caster="full", spell_ability="wis",
                  cantrips=[(1, 2), (4, 3), (10, 4)], prepared=prep(FULL_PREP), asi_levels=STD_ASI,
                  subclass=dict(id="circle_of_the_land", label="Círculo da Terra", en="Circle of the Land", level=3, page=pg(46))),
    "fighter": dict(label="Guerreiro", en="Fighter", page=pg(47), hd=10, saves=["str", "con"], skill_count=2,
                    skill_list=["acrobatics", "animal_handling", "athletics", "history", "insight", "intimidation", "persuasion", "perception", "survival"],
                    profs=ALL6, caster=None, asi_levels=[4, 6, 8, 12, 14, 16, 19],
                    subclass=dict(id="champion", label="Campeão", en="Champion", level=3, page=pg(49))),
    "monk": dict(label="Monge", en="Monk", page=pg(49), hd=8, saves=["str", "dex"], skill_count=2,
                 skill_list=["acrobatics", "athletics", "history", "insight", "religion", "stealth"],
                 profs=["weapons_simple"], notes="Armas marciais com a propriedade Leve; um tipo de ferramenta de artesão ou instrumento musical",
                 caster=None, asi_levels=STD_ASI, unarmored=dict(base=10, abilities=["dex", "wis"], shield_ok=False),
                 speed=[dict(unless="armor", steps=[(1, 0), (2, 10), (6, 15), (10, 20), (14, 25), (18, 30)])], extra_saves=[(14, AB_IDS)],
                 subclass=dict(id="warrior_of_the_open_hand", label="Guerreiro da Mão Aberta", en="Warrior of the Open Hand", level=3, page=pg(52))),
    "paladin": dict(label="Paladino", en="Paladin", page=pg(53), hd=10, saves=["wis", "cha"], skill_count=2,
                    skill_list=["athletics", "insight", "intimidation", "medicine", "persuasion", "religion"],
                    profs=ALL6, caster="half", spell_ability="cha", prepared=prep(HALF_PREP), asi_levels=STD_ASI,
                    save_aura=dict(level=6, ability="cha", label="Aura de Proteção (CAR, mínimo +1)"),
                    subclass=dict(id="oath_of_devotion", label="Juramento de Devoção", en="Oath of Devotion", level=3, page=pg(56))),
    "ranger": dict(label="Patrulheiro", en="Ranger", page=pg(57), hd=10, saves=["str", "dex"], skill_count=3,
                   skill_list=["animal_handling", "athletics", "insight", "investigation", "nature", "perception", "stealth", "survival"],
                   profs=["armor_light", "armor_medium", "shields", "weapons_simple", "weapons_martial"], caster="half", spell_ability="wis",
                   prepared=prep(HALF_PREP), asi_levels=STD_ASI, expertise=[(2, 1), (9, 3)],
                   subclass=dict(id="hunter", label="Caçador", en="Hunter", level=3, page=pg(61))),
    "rogue": dict(label="Ladino", en="Rogue", page=pg(61), hd=8, saves=["dex", "int"], skill_count=4,
                  skill_list=["acrobatics", "athletics", "deception", "insight", "intimidation", "investigation", "perception", "persuasion", "sleight_of_hand", "stealth"],
                  profs=["armor_light", "weapons_simple"], notes="Armas marciais com Acuidade ou Leve; ferramentas de ladrão",
                  caster=None, asi_levels=[4, 8, 10, 12, 16, 19], expertise=[(1, 2), (6, 4)], extra_saves=[(15, ["wis", "cha"])],
                  subclass=dict(id="thief", label="Ladrão", en="Thief", level=3, page=pg(64))),
    "sorcerer": dict(label="Feiticeiro", en="Sorcerer", page=pg(64), hd=6, saves=["con", "cha"], skill_count=2,
                     skill_list=["arcana", "deception", "insight", "intimidation", "persuasion", "religion"],
                     profs=["weapons_simple"], caster="full", spell_ability="cha", cantrips=[(1, 4), (4, 5), (10, 6)], prepared=prep(SORC_PREP),
                     asi_levels=STD_ASI,
                     subclass=dict(id="draconic_sorcery", label="Feitiçaria Dracônica", en="Draconic Sorcery", level=3, page=pg(69),
                                   hp_per_level=True, hp_label="Resiliência Dracônica (+1 PV por nível)",
                                   unarmored=dict(base=10, abilities=["dex", "cha"]))),
    "warlock": dict(label="Bruxo", en="Warlock", page=pg(70), hd=8, saves=["wis", "cha"], skill_count=2,
                    skill_list=["arcana", "deception", "history", "intimidation", "investigation", "nature", "religion"],
                    profs=["armor_light", "weapons_simple"], caster="pact", spell_ability="cha", cantrips=[(1, 2), (4, 3), (10, 4)],
                    prepared=prep(WARLOCK_PREP), asi_levels=STD_ASI,
                    subclass=dict(id="fiend_patron", label="Patrono Ínfero", en="Fiend Patron", level=3, page=pg(76))),
    "wizard": dict(label="Mago", en="Wizard", page=pg(77), hd=6, saves=["int", "wis"], skill_count=2,
                   skill_list=["arcana", "history", "insight", "investigation", "medicine", "nature", "religion"],
                   profs=["weapons_simple"], caster="full", spell_ability="int", cantrips=[(1, 3), (4, 4), (10, 5)], prepared=prep(WIZ_PREP),
                   asi_levels=STD_ASI, expertise=[(2, 1)],
                   subclass=dict(id="evoker", label="Evocador", en="Evoker", level=3, page=pg(82))),
}
SCHOLAR_SKILLS = ["arcana", "history", "investigation", "medicine", "nature", "religion"]

SPECIES = [
    dict(id="dragonborn", label="Draconato", en="Dragonborn", speed=30, size="medium", darkvision=60, page=pg(84)),
    dict(id="dwarf", label="Anão", en="Dwarf", speed=30, size="medium", darkvision=120, page=pg(84),
         hp_per_level=True, hp_label="Robustez Anã (+1 PV por nível)"),
    dict(id="elf", label="Elfo", en="Elf", speed=30, size="medium", darkvision=60, page=pg(84)),
    dict(id="gnome", label="Gnomo", en="Gnome", speed=30, size="small", darkvision=60, page=pg(85)),
    dict(id="goliath", label="Golias", en="Goliath", speed=35, size="medium", darkvision=0, page=pg(85)),
    dict(id="halfling", label="Halfling", en="Halfling", speed=30, size="small", darkvision=0, page=pg(86)),
    dict(id="human", label="Humano", en="Human", speed=30, size=None, darkvision=0, page=pg(86), any_skills=1),
    dict(id="orc", label="Orc", en="Orc", speed=30, size="medium", darkvision=120, page=pg(86)),
    dict(id="tiefling", label="Tiefling", en="Tiefling", speed=30, size=None, darkvision=60, page=pg(86)),
]
for sp in SPECIES:
    if sp["size"]:
        sp["fixes_extra"] = [("/origin/size", sp["size"], f"tamanho de {sp['label']}")]

DRAGONS = [("black", "Negro", "Black", "ácido"), ("blue", "Azul", "Blue", "elétrico"), ("brass", "Latão", "Brass", "fogo"),
           ("bronze", "Bronze", "Bronze", "elétrico"), ("copper", "Cobre", "Copper", "ácido"), ("gold", "Ouro", "Gold", "fogo"),
           ("green", "Verde", "Green", "veneno"), ("red", "Vermelho", "Red", "fogo"), ("silver", "Prata", "Silver", "frio"), ("white", "Branco", "White", "frio")]
LINEAGES = [
    *[dict(id=f"dragon_{d}", label=f"Dragão {pt} ({dmg})", en=f"{en} Dragon", parent="dragonborn", page=pg(84)) for d, pt, en, dmg in DRAGONS],
    dict(id="drow", label="Drow", en="Drow", parent="elf", page=pg(85), darkvision=120),
    dict(id="high_elf", label="Alto Elfo", en="High Elf", parent="elf", page=pg(85)),
    dict(id="wood_elf", label="Elfo da Floresta", en="Wood Elf", parent="elf", page=pg(85), speed=35),
    dict(id="forest_gnome", label="Gnomo da Floresta", en="Forest Gnome", parent="gnome", page=pg(85)),
    dict(id="rock_gnome", label="Gnomo das Rochas", en="Rock Gnome", parent="gnome", page=pg(85)),
    *[dict(id=f"{g}_giant", label=f"Gigante {pt}", en=f"{en} Giant", parent="goliath", page=pg(85))
      for g, pt, en in [("cloud", "das Nuvens", "Cloud"), ("fire", "do Fogo", "Fire"), ("frost", "do Gelo", "Frost"),
                        ("hill", "das Colinas", "Hill"), ("stone", "das Pedras", "Stone"), ("storm", "das Tempestades", "Storm")]],
    dict(id="abyssal", label="Abissal", en="Abyssal", parent="tiefling", page=pg(86)),
    dict(id="chthonic", label="Ctônico", en="Chthonic", parent="tiefling", page=pg(86)),
    dict(id="infernal", label="Infernal", en="Infernal", parent="tiefling", page=pg(86)),
]

BACKGROUNDS = [
    dict(id="acolyte", label="Acólito", en="Acolyte", page=pg(83), abilities=["int", "wis", "cha"], skills=["insight", "religion"],
         feat="magic_initiate_cleric", feat_label="Iniciado em Magia (Clérigo)"),
    dict(id="criminal", label="Criminoso", en="Criminal", page=pg(83), abilities=["dex", "con", "int"], skills=["sleight_of_hand", "stealth"],
         feat="alert", feat_label="Alerta"),
    dict(id="sage", label="Sábio", en="Sage", page=pg(83), abilities=["con", "int", "wis"], skills=["arcana", "history"],
         feat="magic_initiate_wizard", feat_label="Iniciado em Magia (Mago)"),
    dict(id="soldier", label="Soldado", en="Soldier", page=pg(83), abilities=["str", "dex", "con"], skills=["athletics", "intimidation"],
         feat="savage_attacker", feat_label="Atacante Selvagem"),
]
FEAT_OF = {b["id"]: b["feat"] for b in BACKGROUNDS}

# Origin feats a Human may take with Versatile; a non-repeatable feat (or the same Magic Initiate list) cannot repeat the background's.
def not_bg_feat(feat):
    bgs = [b for b, f in FEAT_OF.items() if f == feat]
    return lambda ORIGIN, BG: and_(eq(ORIGIN, const("human")), *[ne(BG, const(b)) for b in bgs]) if bgs else eq(ORIGIN, const("human"))

HUMAN_FEATS = [
    dict(id="alert", label="Alerta", requires=not_bg_feat("alert")),
    dict(id="magic_initiate_cleric", label="Iniciado em Magia (Clérigo)", requires=not_bg_feat("magic_initiate_cleric")),
    dict(id="magic_initiate_druid", label="Iniciado em Magia (Druida)", requires=not_bg_feat("magic_initiate_druid")),
    dict(id="magic_initiate_wizard", label="Iniciado em Magia (Mago)", requires=not_bg_feat("magic_initiate_wizard")),
    dict(id="savage_attacker", label="Atacante Selvagem", requires=not_bg_feat("savage_attacker")),
    dict(id="skilled", label="Habilidoso", requires=not_bg_feat("skilled"), optional_any_skills=3),
]

ORIGIN_OPTIONS = [
    dict(field="lineage", label="Linhagem, legado ou ancestralidade", kind="lineage", kind_label="Linhagem, legado ou ancestralidade",
         kind_en="Lineage / Legacy / Ancestry", parent_kind=True, items=LINEAGES, required_for=["dragonborn", "elf", "gnome", "goliath", "tiefling"]),
    dict(field="lineage_ability", label="Atributo de conjuração da linhagem", items=[
        dict(id=a, label=l, parent=["elf", "gnome", "tiefling"]) for a, l in [("int", "Inteligência"), ("wis", "Sabedoria"), ("cha", "Carisma")]],
         required_for=["elf", "gnome", "tiefling"]),
    dict(field="keen_senses", label="Sentidos Aguçados (perícia)", items=[
        dict(id=s, label=l, parent="elf", skills=[s]) for s, l in [("insight", "Intuição"), ("perception", "Percepção"), ("survival", "Sobrevivência")]],
         required_for=["elf"]),
    dict(field="size", label="Tamanho", items=[
        dict(id="medium", label="Médio", requires=lambda O, B: not_(in_(O, ["gnome", "halfling"]))),
        dict(id="small", label="Pequeno", requires=lambda O, B: in_(O, ["gnome", "halfling", "human", "tiefling"]))],
         required_for=["human", "tiefling"]),
    dict(field="species_feat", label="Talento de origem (Versátil, Humano)", items=HUMAN_FEATS, required_for=["human"]),
]

CLASS_OPTIONS = [
    dict(field="divine_order", label="Ordem Divina", **{"class": "cleric"}, items=[
        dict(id="protector", label="Protetor", profs=["weapons_martial", "armor_heavy"]),
        dict(id="thaumaturge", label="Taumaturgo", skill_bonus=dict(skills=["arcana", "religion"], ability="wis", min=1, label="Taumaturgo (SAB, mínimo +1)"))]),
    dict(field="primal_order", label="Ordem Primal", **{"class": "druid"}, items=[
        dict(id="magician", label="Mágico", skill_bonus=dict(skills=["arcana", "nature"], ability="wis", min=1, label="Mágico (SAB, mínimo +1)")),
        dict(id="warden", label="Guardião", profs=["weapons_martial", "armor_medium"])]),
]

FEATS = [
    *[dict(id=i, label=l, en=e, page=pg(87), category="Talento de Origem") for i, l, e in
      [("alert", "Alerta", "Alert"), ("magic_initiate", "Iniciado em Magia", "Magic Initiate"), ("savage_attacker", "Atacante Selvagem", "Savage Attacker"), ("skilled", "Habilidoso", "Skilled")]],
    *[dict(id=i, label=l, en=e, page=pg(87), category="Talento Geral") for i, l, e in
      [("ability_score_improvement", "Aumento no Valor de Atributo", "Ability Score Improvement"), ("grappler", "Agarrador", "Grappler")]],
    *[dict(id=i, label=l, en=e, page=pg(87 if i == "archery" else 88), category="Talento de Estilo de Luta") for i, l, e in
      [("archery", "Arquearia", "Archery"), ("defense", "Defesa", "Defense"), ("great_weapon_fighting", "Combate com Armas Grandes", "Great Weapon Fighting"),
       ("two_weapon_fighting", "Combate com Duas Armas", "Two-Weapon Fighting")]],
    *[dict(id=i, label=l, en=e, page=pg(88), category="Talento de Dádiva Épica") for i, l, e in
      [("boon_of_combat_prowess", "Dádiva da Proeza em Combate", "Boon of Combat Prowess"), ("boon_of_dimensional_travel", "Dádiva da Viagem Dimensional", "Boon of Dimensional Travel"),
       ("boon_of_fate", "Dádiva do Destino", "Boon of Fate"), ("boon_of_irresistible_offense", "Dádiva da Ofensiva Irresistível", "Boon of Irresistible Offense"),
       ("boon_of_spell_recall", "Dádiva da Recuperação de Magia", "Boon of Spell Recall"), ("boon_of_the_night_spirit", "Dádiva do Espírito Noturno", "Boon of the Night Spirit"),
       ("boon_of_truesight", "Dádiva da Visão Verdadeira", "Boon of Truesight")]],
]

ARMOR_NAMES = {"padded": "Padded Armor", "leather": "Leather Armor", "studded_leather": "Studded Leather Armor", "hide": "Hide Armor",
               "chain_shirt": "Chain Shirt", "scale_mail": "Scale Mail", "breastplate": "Breastplate", "half_plate": "Half Plate Armor",
               "ring_mail": "Ring Mail", "chain_mail": "Chain Mail", "splint": "Splint Armor", "plate": "Plate Armor"}


def extra_constraints(ORIGIN, BG, METHOD, CLASS):
    listed = {a: in_(BG, [b["id"] for b in BACKGROUNDS if a in b["abilities"]]) for a in AB_IDS}
    out = [{"id": "background_abilities", "phase": "creation", "label": "O antecedente só aumenta os três atributos que lista",
            "assert": and_(*[or_(listed[a], eq(ref(f"/abilities/origin/{a}"), const(0))) for a in AB_IDS]),
            "paths": [f"/abilities/origin/{a}" for a in AB_IDS]},
           {"id": "wizard_scholar", "label": "Erudito: especialização do Mago só em Arcanismo, História, Investigação, Medicina, Natureza ou Religião",
            "assert": or_(ne(CLASS, const("wizard")), and_(*[eq(ref(f"/expertise/{s}"), const(0)) for s in SKILL_IDS if s not in SCHOLAR_SKILLS])),
            "paths": [f"/expertise/{s}" for s in SKILL_IDS if s not in SCHOLAR_SKILLS]}]
    for v in [15, 14, 13, 12, 10, 8]:
        out.append({"id": f"standard_array_{v}", "phase": "creation", "label": f"Conjunto padrão: o valor {v} aparece exatamente uma vez",
                    "assert": or_(ne(METHOD, const("standard_array")),
                                  eq(add(*[if_(eq(ref(f"/abilities/base/{a}"), const(v)), const(1), const(0)) for a in AB_IDS]), const(1))),
                    "paths": [f"/abilities/base/{a}" for a in AB_IDS]})
    return out


CFG = dict(
    directory="srd-5.2.1", variant_id="srd-5-2-1", version=VERSION, rules_revision="2024", source_version=SRD, source_id="srd-5.2.1",
    display_name="Quinta edição — SRD 5.2.1 (regras de 2024, compatível com 5E)",
    attribution=ATTRIBUTION, attributions={"srd521": ATTRIBUTION}, attribution_ref="srd521",
    license_url="https://www.dndbeyond.com/srd", consulted_at="2026-11-24",
    provenance_notes=("Regras e listas do System Reference Document 5.2.1 (CC BY 4.0). Alterações em relação ao SRD: rótulos traduzidos "
                      "para pt-BR pela equipe NOVTT (o nome original fica no compêndio), tabelas reorganizadas como fórmulas do preset, "
                      "nenhum texto descritivo copiado. Sem logotipos nem alegação de afiliação. Fontes por regra em SOURCE-REGISTER.md."),
    limitations=[
        "Apenas o conteúdo do SRD 5.2.1: uma subclasse por classe, 9 espécies, 4 antecedentes e os talentos do SRD.",
        "Multiclasse não é modelada: uma classe por personagem.",
        "Características de classe, subclasse, espécie e talentos são registradas pelo jogador; só as de efeito numérico fixo são calculadas.",
        "Críticos ampliados (Campeão), vantagem/desvantagem impostas por condições e dados de vida por classe não são automáticos.",
        "Magias: o jogador registra as suas; a lista de magias do SRD não está no compêndio.",
        "PV por nível usam o valor fixo da classe; rolagens de PV entram como ajuste do mestre.",
    ],
    classes=CLASSES, origin=dict(field="species", label="Espécie", en="Species", items=SPECIES), origin_options=ORIGIN_OPTIONS,
    origin_step_label="Origem (antecedente e espécie)", backgrounds=BACKGROUNDS, background_feats=True, feats=FEATS, class_options=CLASS_OPTIONS,
    ability_methods=[("point_cost", "Custo em pontos (27)"), ("standard_array", "Conjunto padrão (15, 14, 13, 12, 10, 8)"),
                     ("random_generation", "Geração aleatória (4d6, o mestre registra)")],
    point_methods=["point_cost", "standard_array"],
    half_slots_1_2={1: [2, 0, 0, 0, 0], 2: [2, 0, 0, 0, 0]},
    hp_min_one_per_level=True, jack_of_all_trades=("bard", 2, "skills"), exhaustion_d20_penalty=2,
    prepared_label="Magias preparadas", inspiration_label="Inspiração Heroica",
    capacity_multiplier=lambda ORIGIN: if_(eq(ORIGIN, const("goliath")), const(30), const(15)),
    origin_asi_label="Aumentos do antecedente (+2/+1 ou +1/+1/+1)", origin_asi_short="aumento do antecedente",
    origin_asi_amount=lambda ORIGIN, BG: if_(ne(BG, const("")), const(3), const(0)),
    extra_constraints=extra_constraints,
    initiative_pb_when=[dict(id="alert_initiative", label="Alerta: proficiência na Iniciativa",
                             when=lambda BG: or_(eq(BG, const("criminal")), eq(ref("/origin/species_feat"), const("alert"))))],
    extra_rolls=[{"id": "ability_generation", "label": "Geração aleatória de atributo (4d6, mantém os 3 maiores)", "strategy": "sum", "dice": "4d6kh3"}],
    pages=dict(abilities=pg(5), skills=pg(9), conditions=f"{SRD} pp. 177–191 (Rules Glossary)", armor=pg(92)),
    armor_names=ARMOR_NAMES,
)

V, P, M, X = "verified", "pending", "missing", "not included"
REGISTER = [
    ("Attribution statement (verbatim)", pg(1) + " Legal Information", V, "manifest `license.attribution`, compendium `attributions.srd521`"),
    ("Six abilities; modifier = floor((score − 10) / 2)", pg(5) + "; " + pg(21) + " Ability Scores and Modifiers", V, "derived `score_*`, `mod_*`"),
    ("Point Cost: 27 points, costs 8=0 … 13=5, 14=7, 15=9", pg(21) + " Generate Your Scores", V, "budget `point_buy`, cost `point_buy` (formula on `new`), phase limit 15"),
    ("Standard Array 15, 14, 13, 12, 10, 8", pg(21), V, "method `standard_array` (point costs = 27) + constraints `standard_array_*`"),
    ("Random Generation 4d6 keep 3", pg(21), V, "roll `ability_generation`; master records results in `/abilities/assigned/*`"),
    ("Background: three listed abilities, +2/+1 or +1/+1/+1, not above 20", pg(21) + "; " + pg(83), V, "budget `origin_asi` (3), phase limit 2, constraints `background_abilities`, `score_cap_*`"),
    ("Background skills (2) and origin feat", pg(83), V, "choice `background` holds skills (`at_least`); derived `background_feat`"),
    ("Background tool proficiency and equipment", pg(83), P, "recorded by the player (`/proficiencies/tools`, `/inventory`)"),
    ("12 classes: hit die, saving throws, skill count and list, weapon/armor training", "Core traits tables, " + pp(28, 77), V, "choice `class` holds saves and training; budget `skill_picks`; constraint `skills_off_class_list`"),
    ("Level 1 HP = hit die max + CON; later levels fixed value + CON (minimum 1)", pp(22, 23), V, "derived `hp_max`; rolled HP via master `/combat/hp_adjust`"),
    ("XP thresholds and Proficiency Bonus by level", pg(23) + " Character Advancement", V, "track `level` (XP or master milestones), derived `pb`"),
    ("One SRD subclass per class, all at level 3", "class tables " + pp(28, 82), V, "choice `subclass` (requires class and level 3), constraint `subclass_due`"),
    ("Ability Score Improvement feat levels (incl. Fighter 6/14, Rogue 10; Epic Boon 19 counted as a feat slot)", "class tables", V, "budget `asi` (2 per slot) on `/abilities/improvement/*`"),
    ("18 skills with linked ability", pg(9) + " Skills table", V, "derived `skill_*`"),
    ("Expertise: Bard 2/9, Rogue 1/6, Ranger 2 (Deft Explorer)/9, Wizard 2 (Scholar list)", pg(32) + ", " + pg(61) + ", " + pg(59) + ", " + pg(78), V, "budget `expertise`, constraints `expertise_needs_proficiency_*`, `wizard_scholar`"),
    ("Jack of All Trades: half PB on skill checks without proficiency", pg(32), V, "effects `jack_of_all_trades_*`"),
    ("Divine Order / Primal Order (training or WIS bonus to two skills)", pg(37) + ", " + pg(42), V, "choices `divine_order`, `primal_order`; effects"),
    ("Saving throw proficiencies: Monk 14 all, Rogue 15 WIS/CHA; Aura of Protection +CHA (min 1) at 6", pg(52) + ", " + pg(63) + ", " + pg(55), V, "effects on `save_*`"),
    ("Passive Perception = 10 + Wisdom (Perception)", pg(22), V, "derived `passive_perception`"),
    ("Initiative = DEX modifier; Alert adds PB", pg(22) + "; " + pg(87), V, "derived `initiative`, effect `alert_initiative`"),
    ("AC: 10 + DEX; armor table; shield +2; Unarmored Defense (Barbarian, Monk); Draconic Resilience 10+DEX+CHA", pg(22) + "; " + pg(92) + "; " + pg(29) + ", " + pg(50) + ", " + pg(69), V, "derived `armor_class`"),
    ("Speed by species (Wood Elf 35, Goliath 35); Fast Movement; Unarmored Movement", pp(84, 86) + "; " + pg(29) + ", " + pg(51), V, "derived `speed`"),
    ("Dwarven Toughness; Draconic Resilience HP (+3 at 3, +1 per level)", pg(84) + "; " + pg(69), V, "effects `dwarf_hp`, `draconic_sorcery_hp`"),
    ("Spell save DC = 8 + mod + PB; spell attack = mod + PB; spellcasting ability per class", pp(22, 23), V, "derived `spell_save_dc`, `spell_attack`"),
    ("Spell slots (full and half casters), Pact Magic, cantrips and prepared spells by level", "class feature tables", V, "derived `slot_*`, `pact_*`, `cantrips_known`, `spells_prepared`"),
    ("Hit Point Dice, HP, temporary HP, death saves (10+, 20 regain, 1 two failures)", pp(17, 18) + "; " + pg(22), V, "resources `hp`, `hit_dice`; roll `death_save` (flags critical/fumble)"),
    ("D20 Tests: 1d20 + modifier; Advantage/Disadvantage", pp(6, 7), V, "rolls `ability_check`, `saving_throw`, `skill_check`, `initiative`, `attack` (`edge`)"),
    ("Attack roll: natural 20 critical hit, natural 1 miss", pg(7) + "; " + pg(16), V, "roll `attack` (`critical_at_least` 20, `fumble_at_most` 1)"),
    ("Exhaustion: D20 Tests −2 × level", pg(181) + " (Rules Glossary)", V, "modifier of every d20 roll"),
    ("Conditions (15)", f"{SRD} pp. 177–191 (Rules Glossary)", V, "compendium `condition.*`, sheet `/conditions/*`"),
    ("Carrying capacity Str × 15 (Goliath Powerful Build: one size larger)", pg(178) + "; " + pg(86), V, "derived `capacity`, `carried` (not enforced)"),
    ("Species traits (9), lineages/legacies/ancestries, Keen Senses, Skillful, Versatile", pp(83, 86), V, "choices `species`, `lineage`, `lineage_ability`, `keen_senses`, `species_feat`; size held or chosen"),
    ("Species/feat spells, breath weapon, resistances, Heroic Inspiration timing", pp(84, 87), P, "recorded by the player (`/features`, `/spells`)"),
    ("Feats (Origin, General, Fighting Style, Epic Boon)", pp(87, 88), V, "compendium `feat.*`; player records chosen feats in `/feats`"),
    ("Champion Improved/Superior Critical (19–20, 18–20)", pg(49), M, "engine: `critical_at_least` is a constant (needs a formula)"),
    ("Imposed Advantage/Disadvantage (conditions, Feral Instinct, Powerful Build…)", "various", M, "engine: `edge` is chosen per request; no rule-forced edge"),
    ("Hit Point Die roll per class (d6–d12)", pg(18), M, "engine: roll faces are constants (needs faces from a formula)"),
    ("Multiclassing", pg(24), X, "single class per sheet"),
    ("Spell list and spell descriptions", "Spells chapter", X, "player-recorded `/spells`; compendium does not ship spells yet"),
    ("Weapon Mastery, class resources (Rage, Focus, Sorcery Points…)", "class chapters", P, "recorded in `/features` (uses/spent)"),
]

HEADER = f"""# SOURCE-REGISTER — 5E SRD 5.2.1 (2024 rules), preset {VERSION}

Generated by `scripts/presets/build_dnd_5e_2024.py` (edit the tables there, then rebuild).
Source: System Reference Document 5.2.1, `SRD_CC_v5.2.1.pdf` (sha256 `8974902d109d6e63672d7c490bde9ccf052410503d9cfa768237154fbc5e3d87`),
downloaded from https://www.dndbeyond.com/srd on 2026-11-24. Licence: CC BY 4.0 (exact attribution in the manifest).
Publication: allowed with attribution (`rights_status: approved`, `publication.public: true`). Only SRD content is
filled in; labels are pt-BR translations by the NOVTT team (a change from the SRD, declared in the manifest)."""

if __name__ == "__main__":
    write_package(CFG, REGISTER, HEADER)
