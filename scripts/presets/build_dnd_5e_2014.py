#!/usr/bin/env python3
"""Builds the fifth-edition preset for the 2014 rules from the System Reference Document 5.1.

Run: python3 scripts/presets/build_dnd_5e_2014.py  ->  presets/dnd-5e/srd-5.1/<VERSION>/

Rules of this package (SPEC-PRESETS-FIDELITY-001, LICENSE-MATRIX):
- Only content present in SRD 5.1 (CC BY 4.0): one subclass per class, the nine SRD races and their
  four SRD subraces, the Acolyte background and the Grappler feat. The engine can represent other
  subclasses/backgrounds (taxonomy and choices), none is filled in here.
- The exact attribution statement of the SRD 5.1 legal page is carried verbatim.
- SRD 5.1 has no ability score generation rules. The point buy wired here is the SRD 5.2.1 Point Cost
  table (also CC BY 4.0, attributed as well); it is marked PENDING owner confirmation in
  SOURCE-REGISTER.md. The alternative "set by the GM" method stays available.
- Page numbers refer to SRD_CC_v5.1.pdf (sha256 2504d2a0…f0a1f0), consulted 2026-11-24.
"""
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from _common import AB_IDS, and_, const, level_steps, le, ne, or_, ref, table, write_package  # noqa: E402

VERSION = "0.1.0"
SRD = "SRD 5.1"
ATTRIBUTION = ("This work includes material taken from the System Reference Document 5.1 (“SRD 5.1”) by Wizards of the Coast LLC "
               "and available at https://dnd.wizards.com/resources/systems-reference-document. The SRD 5.1 is licensed under the "
               "Creative Commons Attribution 4.0 International License available at https://creativecommons.org/licenses/by/4.0/legalcode.")
ATTRIBUTION_521 = ("This work includes material from the System Reference Document 5.2.1 (“SRD 5.2.1”) by Wizards of the Coast LLC, "
                   "available at https://www.dndbeyond.com/srd. The SRD 5.2.1 is licensed under the Creative Commons Attribution 4.0 "
                   "International License, available at https://creativecommons.org/licenses/by/4.0/legalcode.")

def pg(n): return f"{SRD} p. {n}"
def pp(a, b): return f"{SRD} pp. {a}–{b}"

def known(lst): return ("steps", level_steps({i + 1: v for i, v in enumerate(lst)}))

ALL6 = ["armor_light", "armor_medium", "armor_heavy", "shields", "weapons_simple", "weapons_martial"]
STD_ASI = [4, 8, 12, 16, 19]
LIGHT_CASTER_WEAPONS = "Adagas, dardos, fundas, bordões, bestas leves"

CLASSES = {
    "barbarian": dict(label="Bárbaro", en="Barbarian", page=pg(8), hd=12, saves=["str", "con"], skill_count=2,
                      skill_list=["animal_handling", "athletics", "intimidation", "nature", "perception", "survival"],
                      profs=["armor_light", "armor_medium", "shields", "weapons_simple", "weapons_martial"], caster=None, asi_levels=STD_ASI,
                      unarmored=dict(base=10, abilities=["dex", "con"], shield_ok=True), speed=[dict(unless="heavy", steps=[(1, 0), (5, 10)])],
                      subclass=dict(id="path_of_the_berserker", label="Caminho do Berserker", en="Path of the Berserker", level=3, page=pg(9))),
    "bard": dict(label="Bardo", en="Bard", page=pg(11), hd=8, saves=["dex", "cha"], skill_count=3, skill_list=None,
                 profs=["armor_light", "weapons_simple"], notes="Bestas de mão, espadas longas, rapieiras, espadas curtas; três instrumentos musicais",
                 caster="full", spell_ability="cha", cantrips=[(1, 2), (4, 3), (10, 4)],
                 prepared=known([4, 5, 6, 7, 8, 9, 10, 11, 12, 14, 15, 15, 16, 18, 19, 19, 20, 22, 22, 22]),
                 asi_levels=STD_ASI, expertise=[(3, 2), (10, 4)],
                 subclass=dict(id="college_of_lore", label="Colégio do Conhecimento", en="College of Lore", level=3, page=pg(13), any_skills=3)),
    "cleric": dict(label="Clérigo", en="Cleric", page=pg(15), hd=8, saves=["wis", "cha"], skill_count=2,
                   skill_list=["history", "insight", "medicine", "persuasion", "religion"],
                   profs=["armor_light", "armor_medium", "shields", "weapons_simple"], caster="full", spell_ability="wis",
                   cantrips=[(1, 3), (4, 4), (10, 5)], prepared=("mod_plus_level", 1), asi_levels=STD_ASI,
                   subclass=dict(id="life_domain", label="Domínio da Vida", en="Life Domain", level=1, page=pg(17), profs=["armor_heavy"])),
    "druid": dict(label="Druida", en="Druid", page=pg(19), hd=8, saves=["int", "wis"], skill_count=2,
                  skill_list=["arcana", "animal_handling", "insight", "medicine", "nature", "perception", "religion", "survival"],
                  profs=["armor_light", "armor_medium", "shields"],
                  notes="Clavas, adagas, dardos, azagaias, maças, bordões, cimitarras, foices, fundas, lanças; kit de herbalismo; sem metal",
                  caster="full", spell_ability="wis", cantrips=[(1, 2), (4, 3), (10, 4)], prepared=("mod_plus_level", 1), asi_levels=STD_ASI,
                  subclass=dict(id="circle_of_the_land", label="Círculo da Terra", en="Circle of the Land", level=2, page=pg(21))),
    "fighter": dict(label="Guerreiro", en="Fighter", page=pg(24), hd=10, saves=["str", "con"], skill_count=2,
                    skill_list=["acrobatics", "animal_handling", "athletics", "history", "insight", "intimidation", "perception", "survival"],
                    profs=ALL6, caster=None, asi_levels=[4, 6, 8, 12, 14, 16, 19],
                    subclass=dict(id="champion", label="Campeão", en="Champion", level=3, page=pg(25))),
    "monk": dict(label="Monge", en="Monk", page=pg(26), hd=8, saves=["str", "dex"], skill_count=2,
                 skill_list=["acrobatics", "athletics", "history", "insight", "religion", "stealth"],
                 profs=["weapons_simple"], notes="Espadas curtas; um tipo de ferramenta de artesão ou instrumento musical", caster=None,
                 asi_levels=STD_ASI, unarmored=dict(base=10, abilities=["dex", "wis"], shield_ok=False),
                 speed=[dict(unless="armor", steps=[(1, 0), (2, 10), (6, 15), (10, 20), (14, 25), (18, 30)])], extra_saves=[(14, AB_IDS)],
                 subclass=dict(id="way_of_the_open_hand", label="Caminho da Mão Aberta", en="Way of the Open Hand", level=3, page=pg(28))),
    "paladin": dict(label="Paladino", en="Paladin", page=pg(30), hd=10, saves=["wis", "cha"], skill_count=2,
                    skill_list=["athletics", "insight", "intimidation", "medicine", "persuasion", "religion"],
                    profs=ALL6, caster="half", spell_ability="cha", spell_from=2, prepared=("mod_plus_level", 2), asi_levels=STD_ASI,
                    save_aura=dict(level=6, ability="cha", label="Aura de Proteção (CAR, mínimo +1)"),
                    subclass=dict(id="oath_of_devotion", label="Juramento de Devoção", en="Oath of Devotion", level=3, page=pg(32))),
    "ranger": dict(label="Patrulheiro", en="Ranger", page=pg(35), hd=10, saves=["str", "dex"], skill_count=3,
                   skill_list=["animal_handling", "athletics", "insight", "investigation", "nature", "perception", "stealth", "survival"],
                   profs=["armor_light", "armor_medium", "shields", "weapons_simple", "weapons_martial"], caster="half", spell_ability="wis",
                   spell_from=2, prepared=known([0, 2, 3, 3, 4, 4, 5, 5, 6, 6, 7, 7, 8, 8, 9, 9, 10, 10, 11, 11]), asi_levels=STD_ASI,
                   subclass=dict(id="hunter", label="Caçador", en="Hunter", level=3, page=pg(37))),
    "rogue": dict(label="Ladino", en="Rogue", page=pg(39), hd=8, saves=["dex", "int"], skill_count=4,
                  skill_list=["acrobatics", "athletics", "deception", "insight", "intimidation", "investigation", "perception", "performance",
                              "persuasion", "sleight_of_hand", "stealth"],
                  profs=["armor_light", "weapons_simple"], notes="Bestas de mão, espadas longas, rapieiras, espadas curtas; ferramentas de ladrão",
                  caster=None, asi_levels=[4, 8, 10, 12, 16, 19], expertise=[(1, 2), (6, 4)], extra_saves=[(15, ["wis"])],
                  subclass=dict(id="thief", label="Ladrão", en="Thief", level=3, page=pg(40))),
    "sorcerer": dict(label="Feiticeiro", en="Sorcerer", page=pg(42), hd=6, saves=["con", "cha"], skill_count=2,
                     skill_list=["arcana", "deception", "insight", "intimidation", "persuasion", "religion"],
                     profs=[], notes=LIGHT_CASTER_WEAPONS, caster="full", spell_ability="cha", cantrips=[(1, 4), (4, 5), (10, 6)],
                     prepared=known([2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 12, 13, 13, 14, 14, 15, 15, 15, 15]), asi_levels=STD_ASI,
                     subclass=dict(id="draconic_bloodline", label="Linhagem Dracônica", en="Draconic Bloodline", level=1, page=pg(44),
                                   hp_per_level=True, hp_label="Resiliência Dracônica (+1 PV por nível)",
                                   unarmored=dict(base=13, abilities=["dex"]))),
    "warlock": dict(label="Bruxo", en="Warlock", page=pg(46), hd=8, saves=["wis", "cha"], skill_count=2,
                    skill_list=["arcana", "deception", "history", "intimidation", "investigation", "nature", "religion"],
                    profs=["armor_light", "weapons_simple"], caster="pact", spell_ability="cha", cantrips=[(1, 2), (4, 3), (10, 4)],
                    prepared=known([2, 3, 4, 5, 6, 7, 8, 9, 10, 10, 11, 11, 12, 12, 13, 13, 14, 14, 15, 15]), asi_levels=STD_ASI,
                    subclass=dict(id="the_fiend", label="O Ínfero", en="The Fiend", level=1, page=pg(50))),
    "wizard": dict(label="Mago", en="Wizard", page=pg(52), hd=6, saves=["int", "wis"], skill_count=2,
                   skill_list=["arcana", "history", "insight", "investigation", "medicine", "religion"],
                   profs=[], notes=LIGHT_CASTER_WEAPONS, caster="full", spell_ability="int", cantrips=[(1, 3), (4, 4), (10, 5)],
                   prepared=("mod_plus_level", 1), asi_levels=STD_ASI,
                   subclass=dict(id="school_of_evocation", label="Escola de Evocação", en="School of Evocation", level=2, page=pg(54))),
}

RACES = [
    dict(id="dwarf", label="Anão", en="Dwarf", speed=25, size="Médio", darkvision=60, ability={"con": 2}, page=pg(3)),
    dict(id="elf", label="Elfo", en="Elf", speed=30, size="Médio", darkvision=60, ability={"dex": 2}, skills=["perception"], page=pg(4)),
    dict(id="halfling", label="Halfling", en="Halfling", speed=25, size="Pequeno", darkvision=0, ability={"dex": 2}, page=pg(4)),
    dict(id="human", label="Humano", en="Human", speed=30, size="Médio", darkvision=0, ability={a: 1 for a in AB_IDS}, page=pg(5)),
    dict(id="dragonborn", label="Draconato", en="Dragonborn", speed=30, size="Médio", darkvision=0, ability={"str": 2, "cha": 1}, page=pg(5)),
    dict(id="gnome", label="Gnomo", en="Gnome", speed=25, size="Pequeno", darkvision=60, ability={"int": 2}, page=pg(6)),
    dict(id="half_elf", label="Meio-Elfo", en="Half-Elf", speed=30, size="Médio", darkvision=60, ability={"cha": 2}, any_skills=2, page=pg(6)),
    dict(id="half_orc", label="Meio-Orc", en="Half-Orc", speed=30, size="Médio", darkvision=60, ability={"str": 2, "con": 1}, skills=["intimidation"], page=pg(7)),
    dict(id="tiefling", label="Tiefling", en="Tiefling", speed=30, size="Médio", darkvision=60, ability={"int": 1, "cha": 2}, page=pg(7)),
]
SUBRACES = [
    dict(id="hill_dwarf", label="Anão da Colina", en="Hill Dwarf", parent="dwarf", ability={"wis": 1}, page=pg(4),
         hp_per_level=True, hp_label="Robustez Anã (+1 PV por nível)"),
    dict(id="high_elf", label="Alto Elfo", en="High Elf", parent="elf", ability={"int": 1}, page=pg(4)),
    dict(id="lightfoot", label="Pés-Leves", en="Lightfoot", parent="halfling", ability={"cha": 1}, page=pg(5)),
    dict(id="rock_gnome", label="Gnomo das Rochas", en="Rock Gnome", parent="gnome", ability={"con": 1}, page=pg(6)),
]
DRAGONS = [("black", "Negro", "Black", "ácido"), ("blue", "Azul", "Blue", "elétrico"), ("brass", "Latão", "Brass", "fogo"),
           ("bronze", "Bronze", "Bronze", "elétrico"), ("copper", "Cobre", "Copper", "ácido"), ("gold", "Ouro", "Gold", "fogo"),
           ("green", "Verde", "Green", "veneno"), ("red", "Vermelho", "Red", "fogo"), ("silver", "Prata", "Silver", "frio"), ("white", "Branco", "White", "frio")]
ORIGIN_OPTIONS = [
    dict(field="subrace", label="Sub-raça", kind="subrace", kind_label="Sub-raça", kind_en="Subrace", parent_kind=True, items=SUBRACES,
         required_for=["dwarf", "elf", "halfling", "gnome"]),
    dict(field="draconic_ancestry", label="Ancestralidade dracônica", kind="draconic_ancestry", kind_label="Ancestralidade dracônica",
         kind_en="Draconic Ancestry", parent_kind=True, required_for=["dragonborn"],
         items=[dict(id=d, label=f"Dragão {pt} ({dmg})", en=en, parent="dragonborn", page=pg(5)) for d, pt, en, dmg in DRAGONS]),
]
BACKGROUNDS = [dict(id="acolyte", label="Acólito", en="Acolyte", page=pg(61), skills=["insight", "religion"])]
FEATS = [dict(id="grappler", label="Agarrador", en="Grappler", page=pg(75), category="Talento")]
ARMOR_NAMES = {"padded": "Padded", "leather": "Leather", "studded_leather": "Studded leather", "hide": "Hide", "chain_shirt": "Chain shirt",
               "scale_mail": "Scale mail", "breastplate": "Breastplate", "half_plate": "Half plate", "ring_mail": "Ring mail",
               "chain_mail": "Chain mail", "splint": "Splint", "plate": "Plate"}


def extra_constraints(ORIGIN, BG, METHOD, CLASS):
    return [{"id": "half_elf_increases", "phase": "creation", "label": "Meio-Elfo: dois outros atributos à escolha aumentam em 1",
             "assert": or_(ne(ORIGIN, const("half_elf")), and_(*[le(ref(f"/abilities/origin/{a}"), const(1)) for a in AB_IDS if a != "cha"])),
             "paths": [f"/abilities/origin/{a}" for a in AB_IDS if a != "cha"]}]


CFG = dict(
    directory="srd-5.1", variant_id="srd-5-1", version=VERSION, rules_revision="2014", source_version=SRD, source_id="srd-5.1",
    display_name="Quinta edição — SRD 5.1 (regras de 2014, compatível com 5E)",
    attribution=ATTRIBUTION + "\n\n" + ATTRIBUTION_521, attributions={"srd51": ATTRIBUTION, "srd521": ATTRIBUTION_521}, attribution_ref="srd51",
    license_url="https://www.dndbeyond.com/srd", consulted_at="2026-11-24",
    provenance_notes=("Regras e listas do System Reference Document 5.1 (CC BY 4.0). A tabela de compra de pontos vem do SRD 5.2.1 "
                      "(CC BY 4.0, atribuído também), porque o SRD 5.1 não traz regras de geração de atributos; pendente de confirmação do "
                      "dono do projeto. Alterações em relação ao SRD: rótulos traduzidos para pt-BR pela equipe NOVTT (o nome original fica "
                      "no compêndio), tabelas reorganizadas como fórmulas, nenhum texto descritivo copiado. Sem logotipos nem alegação de afiliação."),
    limitations=[
        "Apenas o conteúdo do SRD 5.1: uma subclasse por classe, 9 raças e 4 sub-raças, o antecedente Acólito e o talento Agarrador.",
        "O SRD 5.1 não define a geração de atributos: a compra de pontos usa a tabela do SRD 5.2.1 (pendente); o mestre pode definir os valores.",
        "Multiclasse não é modelada: uma classe por personagem.",
        "Características de classe, subclasse e raça são registradas pelo jogador; só as de efeito numérico fixo são calculadas.",
        "Exaustão e condições que impõem desvantagem não alteram as rolagens automaticamente.",
        "PV por nível usam o valor fixo da classe; rolagens de PV entram como ajuste do mestre.",
    ],
    classes=CLASSES, origin=dict(field="race", label="Raça", en="Race", items=RACES), origin_options=ORIGIN_OPTIONS,
    origin_step_label="Raça e antecedente", backgrounds=BACKGROUNDS, background_feats=False, feats=FEATS, class_options=[],
    ability_methods=[("point_buy", "Compra de pontos (27) — tabela do SRD 5.2.1, pendente"), ("gm_assigned", "Valores definidos pelo mestre")],
    point_methods=["point_buy"],
    half_slots_1_2={1: [0, 0, 0, 0, 0], 2: [2, 0, 0, 0, 0]},
    hp_min_one_per_level=False, jack_of_all_trades=("bard", 2, "all_checks"), exhaustion_d20_penalty=0, exhaustion_disadvantage=True,
    prepared_label="Magias conhecidas ou preparadas", inspiration_label="Inspiração",
    capacity_multiplier=lambda ORIGIN: const(15),
    origin_asi_label="Aumentos raciais à escolha (Meio-Elfo)", origin_asi_short="aumento racial",
    origin_asi_amount=lambda ORIGIN, BG: table(ORIGIN, {"half_elf": 2}, 0),
    extra_constraints=extra_constraints, initiative_pb_when=[], extra_rolls=[],
    pages=dict(abilities=pg(76), skills=pg(77), conditions=pg(358) + " (Appendix PH-A)", armor=pg(63)),
    armor_names=ARMOR_NAMES,
)

V, P, M, X = "verified", "pending", "missing", "not included"
REGISTER = [
    ("Attribution statement (verbatim)", pg(1) + " Legal Information", V, "manifest `license.attribution` (with the SRD 5.2.1 one, see point buy), compendium `attributions.srd51`"),
    ("Six abilities; modifier = floor((score − 10) / 2)", pg(76) + " Ability Scores and Modifiers", V, "derived `score_*`, `mod_*`"),
    ("Ability score generation (point buy 27, costs 14=7, 15=9)", "not in SRD 5.1; SRD 5.2.1 p. 21 Point Cost (CC BY 4.0)", P,
     "**pending owner decision**: method `point_buy` wired with the SRD 5.2.1 table; method `gm_assigned` lets the master set `/abilities/assigned/*`"),
    ("Racial ability score increases (9 races, 4 subraces)", pp(3, 7), V, "choices `race`, `subrace` hold `/abilities/origin/*` (exact)"),
    ("Half-Elf: CHA +2 and two other abilities +1", pg(6), V, "budget `origin_asi` (2), constraint `half_elf_increases`"),
    ("Racial skills: Elf Perception, Half-Orc Intimidation; Half-Elf two skills of choice", pg(4) + ", " + pg(7) + ", " + pg(7), V, "holds `at_least` on `/skills/*`; budget `skill_picks` +2 for Half-Elf"),
    ("Speed, size, darkvision by race", pp(3, 7), V, "derived `speed`, `size`, `darkvision`"),
    ("Dwarven Toughness (Hill Dwarf)", pg(4), V, "effect `hill_dwarf_hp`"),
    ("Draconic Ancestry (10 dragons)", pg(5), V, "choice `draconic_ancestry`; breath weapon recorded by the player"),
    ("Racial traits with no fixed number (resistances, cantrips, weapon training, tools, languages)", pp(3, 7), P, "recorded by the player (`/features`, `/proficiencies/*`)"),
    ("Acolyte background: Insight, Religion", pg(61), V, "choice `background` holds skills (`at_least`)"),
    ("Background languages, equipment, feature, suggested characteristics", pp(60, 61), P, "recorded by the player (`/proficiencies/languages`, `/inventory`, `/description/*`)"),
    ("12 classes: hit dice, HP at 1st level, HP at higher levels (fixed value), proficiencies, saving throws, skills", pp(8, 52), V,
     "choice `class` holds saves and training; derived `hp_max`, `hit_die`; budget `skill_picks`; constraint `skills_off_class_list`"),
    ("Class weapon lists (Bard, Druid, Monk, Rogue, Sorcerer, Wizard)", "class Proficiencies", V, "derived `class_notes` (text)"),
    ("Character Advancement: XP thresholds and proficiency bonus", pg(56), V, "track `level` (XP or master milestones), derived `pb`"),
    ("One SRD subclass per class at its level (Cleric/Sorcerer/Warlock 1, Druid/Wizard 2, others 3)", pp(9, 54), V,
     "choice `subclass` (requires class and level), constraint `subclass_due`"),
    ("Life Domain heavy armor proficiency", pg(17), V, "subclass hold `/proficiencies/armor_heavy`"),
    ("College of Lore: three skills of choice", pg(13), V, "budget `skill_picks` +3"),
    ("Ability Score Improvement levels (Fighter 6/14, Rogue 10)", "class tables", V, "budget `asi` (2 per level) on `/abilities/improvement/*`, constraints `score_cap_*`"),
    ("18 skills with linked ability", pp(77, 78), V, "derived `skill_*`"),
    ("Expertise: Rogue 1/6, Bard 3/10", pg(39) + ", " + pg(13), V, "budget `expertise`, constraints `expertise_needs_proficiency_*`"),
    ("Jack of All Trades: half PB to ability checks without proficiency (incl. initiative)", pg(12), V, "effects `jack_of_all_trades_*`"),
    ("Saving throws: Monk Diamond Soul (14), Rogue Slippery Mind (15), Aura of Protection (6)", pg(28) + ", " + pg(40) + ", " + pg(32), V, "effects on `save_*`"),
    ("Passive check = 10 + modifiers", pg(78), V, "derived `passive_perception`"),
    ("Initiative = Dexterity check", pg(80), V, "derived `initiative`"),
    ("Armor table, shield +2, Unarmored Defense (Barbarian, Monk), Draconic Resilience 13 + DEX", pp(63, 64) + "; " + pg(8) + ", " + pg(26) + ", " + pg(45), V, "derived `armor_class`"),
    ("Fast Movement (Barbarian 5), Unarmored Movement (Monk)", pg(9) + ", " + pg(27), V, "derived `speed`"),
    ("Draconic Resilience HP", pg(45), V, "effect `draconic_bloodline_hp`"),
    ("Spellcasting ability, save DC, attack bonus", "class Spellcasting sections", V, "derived `spell_ability`, `spell_save_dc`, `spell_attack`"),
    ("Spell slots (full casters; Paladin/Ranger from 2nd level), Pact Magic, cantrips known", "class tables; " + pg(58) + " multiclass table", V, "derived `slot_*`, `pact_*`, `cantrips_known`"),
    ("Spells known (Bard, Ranger, Sorcerer, Warlock) / prepared (mod + level; Paladin mod + half level)", "class Spellcasting sections", V, "derived `spells_prepared`"),
    ("Death saving throws (10+, 20 regains 1 HP, 1 counts two failures)", pg(98), V, "roll `death_save` (critical/fumble flags), sheet counters"),
    ("Ability checks, saving throws, attack rolls: d20 + modifier; advantage/disadvantage", pp(76, 77), V, "rolls with `edge`"),
    ("Attack: natural 20 critical, natural 1 miss", pp(93, 94) + " Making an Attack", V, "roll `attack`"),
    ("Conditions (14) and exhaustion (6 levels)", pp(358, 359), V, "compendium `condition.*`, sheet `/conditions/*`"),
    ("Exhaustion disadvantage (level 1 checks; level 3 attacks and saves)", pg(358), V, "roll `edge.force` on checks, saves and attacks; halved speed / HP max: master applies"),
    ("Carrying capacity = Strength × 15", pg(79) + " Lifting and Carrying", V, "derived `capacity`, `carried` (not enforced)"),
    ("Grappler feat", pg(75), V, "compendium `feat.grappler`; recorded in `/feats`"),
    ("Champion Improved/Superior Critical (19–20 at 3, 18–20 at 15)", pg(25), V, "attack roll `critical_at_least` formula"),
    ("Champion Remarkable Athlete", pg(25), M, "half proficiency rounded up to STR/DEX/CON checks not wired"),
    ("Hit Die roll per class", pp(8, 52), V, "roll `hit_die`: faces from derived `hit_die` + CON modifier"),
    ("Multiclassing", pp(56, 58), X, "single class per sheet"),
    ("Spell list and descriptions", "Spells chapter", X, "player-recorded `/spells`"),
]

HEADER = f"""# SOURCE-REGISTER — 5E SRD 5.1 (2014 rules), preset {VERSION}

Generated by `scripts/presets/build_dnd_5e_2014.py` (edit the tables there, then rebuild).
Source: System Reference Document 5.1, `SRD_CC_v5.1.pdf` (sha256 `2504d2a0abb0a4d491a939be4f17910a2dde0312570ab8d208080225ccf0a1f0`),
downloaded from https://www.dndbeyond.com/srd on 2026-11-24. Licence: CC BY 4.0 (exact attribution in the manifest).
Publication: allowed with attribution (`rights_status: approved`, `publication.public: true`). Only SRD content is
filled in; labels are pt-BR translations by the NOVTT team (a change from the SRD, declared in the manifest).
The only rule taken from another document is the point-buy table (SRD 5.2.1, also CC BY 4.0 and attributed): SRD 5.1
has no ability score generation rules. It stays PENDING until the owner confirms it."""

if __name__ == "__main__":
    write_package(CFG, REGISTER, HEADER)
