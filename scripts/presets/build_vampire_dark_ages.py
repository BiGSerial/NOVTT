#!/usr/bin/env python3
"""Builds the Vampire: Dark Ages 20th Anniversary (V20 Dark Ages) preset from the tables below.

Run: python3 scripts/presets/build_vampire_dark_ages.py  →  presets/world-of-darkness/vampire-dark-ages-v20/<VERSION>/

Distribution BLOCKED (Dark Pack does not cover paid apps): internal use only. Every value is PENDING
verification (no source document in the repository); see _wod_common.py and SOURCE-REGISTER.md.
"""
import sys
from pathlib import Path

sys.dont_write_bytecode = True  # keep scripts/presets free of __pycache__
sys.path.insert(0, str(Path(__file__).resolve().parent))
import _wod_common as wod  # noqa: E402

VERSION = "0.1.0"

DISCIPLINES = [
    ("animalism", "Animalismo", "Animalism"), ("auspex", "Auspícios", "Auspex"), ("celerity", "Rapidez", "Celerity"),
    ("chimerstry", "Quimerismo", "Chimerstry"), ("dementation", "Demência", "Dementation"), ("dominate", "Dominação", "Dominate"),
    ("fortitude", "Fortitude", "Fortitude"), ("mortis", "Mortis", "Mortis"), ("obeah", "Obeah", "Obeah"), ("obfuscate", "Ofuscação", "Obfuscate"),
    ("obtenebration", "Tenebrosidade", "Obtenebration"), ("potence", "Potência", "Potence"), ("presence", "Presença", "Presence"),
    ("protean", "Metamorfose", "Protean"), ("quietus", "Quietus", "Quietus"), ("serpentis", "Serpentis", "Serpentis"),
    ("thaumaturgy", "Taumaturgia", "Thaumaturgy"), ("vicissitude", "Vicissitude", "Vicissitude"),
]
# (id, pt-BR label, official name, clan Disciplines)
CLANS = [
    ("assamite", "Assamita", "Assamite", ["celerity", "obfuscate", "quietus"]),
    ("brujah", "Brujah", "Brujah", ["celerity", "potence", "presence"]),
    ("cappadocian", "Capadócio", "Cappadocian", ["auspex", "fortitude", "mortis"]),
    ("followers_of_set", "Seguidores de Set", "Followers of Set", ["obfuscate", "presence", "serpentis"]),
    ("gangrel", "Gangrel", "Gangrel", ["animalism", "fortitude", "protean"]),
    ("lasombra", "Lasombra", "Lasombra", ["dominate", "obtenebration", "potence"]),
    ("malkavian", "Malkaviano", "Malkavian", ["auspex", "dementation", "obfuscate"]),
    ("nosferatu", "Nosferatu", "Nosferatu", ["animalism", "obfuscate", "potence"]),
    ("ravnos", "Ravnos", "Ravnos", ["animalism", "chimerstry", "fortitude"]),
    ("salubri", "Salubri", "Salubri", ["auspex", "fortitude", "obeah"]),
    ("toreador", "Toreador", "Toreador", ["auspex", "celerity", "presence"]),
    ("tremere", "Tremere", "Tremere", ["auspex", "dominate", "thaumaturgy"]),
    ("tzimisce", "Tzimisce", "Tzimisce", ["animalism", "auspex", "vicissitude"]),
    ("ventrue", "Ventrue", "Ventrue", ["dominate", "fortitude", "presence"]),
]
ABILITIES = {
    "talents": [("alertness", "Prontidão", "Alertness"), ("athletics", "Esportes", "Athletics"), ("awareness", "Percepção do sobrenatural", "Awareness"),
                ("brawl", "Briga", "Brawl"), ("empathy", "Empatia", "Empathy"), ("expression", "Expressão", "Expression"),
                ("intimidation", "Intimidação", "Intimidation"), ("leadership", "Liderança", "Leadership"), ("legerdemain", "Prestidigitação", "Legerdemain"),
                ("subterfuge", "Lábia", "Subterfuge")],
    "skills": [("animal_ken", "Empatia com animais", "Animal Ken"), ("archery", "Arquearia", "Archery"), ("commerce", "Comércio", "Commerce"),
               ("crafts", "Ofícios", "Crafts"), ("etiquette", "Etiqueta", "Etiquette"), ("melee", "Armas brancas", "Melee"),
               ("performance", "Performance", "Performance"), ("ride", "Cavalgar", "Ride"), ("stealth", "Furtividade", "Stealth"),
               ("survival", "Sobrevivência", "Survival")],
    "knowledges": [("academics", "Acadêmicos", "Academics"), ("enigmas", "Enigmas", "Enigmas"), ("hearth_wisdom", "Sabedoria popular", "Hearth Wisdom"),
                   ("investigation", "Investigação", "Investigation"), ("law", "Direito", "Law"), ("medicine", "Medicina", "Medicine"),
                   ("occult", "Ocultismo", "Occult"), ("politics", "Política", "Politics"), ("seneschal", "Senescal", "Seneschal"),
                   ("theology", "Teologia", "Theology")],
}
BACKGROUNDS = [
    ("allies", "Aliados", "Allies"), ("contacts", "Contatos", "Contacts"), ("domain", "Domínio", "Domain"), ("generation", "Geração", "Generation"),
    ("herd", "Rebanho", "Herd"), ("influence", "Influência", "Influence"), ("mentor", "Mentor", "Mentor"), ("resources", "Recursos", "Resources"),
    ("retainers", "Lacaios", "Retainers"), ("status", "Status", "Status"),
]
# Road of Humanity first (fixes Conscience + Self-Control); other Roads: names only, their virtue pairs are missing.
ROADS = [
    ("humanity", "Estrada da Humanidade", "Road of Humanity"), ("beast", "Estrada da Besta", "Road of the Beast"),
    ("heaven", "Estrada do Céu", "Road of Heaven"), ("kings", "Estrada dos Reis", "Road of Kings"), ("sin", "Estrada do Pecado", "Road of Sin"),
]
# generation: (blood pool max, blood per turn, trait max) — reachable range 12th..7th (Generation 0..5)
BLOOD = {12: (11, 1, 5), 11: (12, 1, 5), 10: (13, 1, 5), 9: (14, 2, 5), 8: (15, 3, 5), 7: (20, 4, 6)}

BOOK = {
    "script": "scripts/presets/build_vampire_dark_ages.py",
    "variant_id": "vampire-dark-ages-v20", "version": VERSION, "edition": "V20 Dark Ages",
    "display_name": "Vampire: Dark Ages 20th Anniversary (uso interno, não oficial)",
    "rules_revision": "v20-dark-ages-pending-printing", "source_version": "V20 Dark Ages core (impressão e errata a confirmar)", "source_id": "vda-v20-core",
    "clans": CLANS, "clanless": None, "disciplines": DISCIPLINES, "abilities": ABILITIES, "backgrounds": BACKGROUNDS,
    "sects": None, "moralities": ROADS, "morality_word": "Estrada", "morality_kind": "road",
    "morality_kind_labels": {"pt-BR": "Estrada", "en": "Road"},
    "appearance_zero_clans": ["nosferatu"],
    "base_generation": 12, "blood_by_generation": BLOOD,
    "budgets": {"attributes": [7, 5, 3], "abilities": [13, 9, 5], "ability_creation_max": 3, "disciplines": 3, "backgrounds": 5, "virtues": 7, "freebies": 15},
    "freebie_costs": {"attribute": 5, "ability": 2, "discipline": 7, "background": 1, "virtue": 2, "morality": 1, "willpower": 1},
    "xp_costs": {"attribute": 4, "new_ability": 3, "ability": 2, "new_discipline": 10, "clan_discipline": 5, "other_discipline": 7,
                 "virtue": 2, "morality": 2, "willpower": 1},
    "limitations": [
        "Distribuição bloqueada: conteúdo proprietário (Paradox), sem autorização para app pago. Uso interno.",
        "Todos os valores estão a conferir no livro (sem documento-fonte no repositório).",
        "Qualidades e Defeitos, especializações, trilhas e rituais são registrados pelo jogador; sem custo ou efeito automático.",
        "Penalidade de ferimento é calculada, mas não é aplicada automaticamente às paradas de dados.",
        "Perder pontos de Estrada ou Força de Vontade permanente devolve a experiência paga por aquele ponto (limitação da engine).",
        "Virtudes de cada Estrada (exceto Humanidade): o jogador escolhe; os pares oficiais não foram registrados.",
        "Fraquezas de clã (exceto Aparência 0), linhagens, Caitiff, diablerie, absorção e combate: não modelados.",
    ],
}
BOOK["source_register"] = wod.register(BOOK, "Vampire: Dark Ages 20th Anniversary", [
    ("Clans V20 Dark Ages core", ", ".join(c[2] for c in CLANS), "medium-high (Salubri in the core list: medium)", "pending"),
    ("Cappadocian Mortis; Salubri Obeah", "clan Disciplines as listed", "medium", "pending"),
    ("Disciplines", ", ".join(d[2] for d in DISCIPLINES), "medium-high", "pending"),
    ("Valeren and bloodline Disciplines", "not included", "—", "missing"),
    ("Bloodlines (Anda, Baali, Lamia, Lhiannan, Nagaraja, …) and Caitiff", "not included", "—", "missing"),
    ("Roads (names)", ", ".join(r[2] for r in ROADS), "medium", "pending"),
    ("Roads' virtue pairs (Conviction/Instinct use), Road paths (sub-paths)", "player chooses virtue types", "—", "missing"),
    ("Generation default 12th; 5 dots = 7th", "base 12 − background", "medium-high", "pending"),
    ("Trait max 6 at 7th generation", "schema max 6, constraints `trait_max_*`", "medium", "pending"),
    ("Backgrounds", ", ".join(b[2] for b in BACKGROUNDS), "medium", "pending"),
    ("Talents / Skills / Knowledges (Dark Ages lists)", "; ".join(", ".join(a[2] for a in its) for its in ABILITIES.values()), "medium-high", "pending"),
    ("Freebie and XP costs equal to V20", "same table as V20 without the clanless row", "medium", "pending"),
])

if __name__ == "__main__":
    wod.build(BOOK)
