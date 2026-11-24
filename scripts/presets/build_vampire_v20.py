#!/usr/bin/env python3
"""Builds the Vampire: The Masquerade 20th Anniversary (V20) preset from the tables below.

Run: python3 scripts/presets/build_vampire_v20.py  →  presets/world-of-darkness/vampire-v20/<VERSION>/

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
    ("fortitude", "Fortitude", "Fortitude"), ("necromancy", "Necromancia", "Necromancy"), ("obfuscate", "Ofuscação", "Obfuscate"),
    ("obtenebration", "Tenebrosidade", "Obtenebration"), ("potence", "Potência", "Potence"), ("presence", "Presença", "Presence"),
    ("protean", "Metamorfose", "Protean"), ("quietus", "Quietus", "Quietus"), ("serpentis", "Serpentis", "Serpentis"),
    ("thaumaturgy", "Taumaturgia", "Thaumaturgy"), ("vicissitude", "Vicissitude", "Vicissitude"),
]
# (id, pt-BR label, official name, clan Disciplines)
CLANS = [
    ("assamite", "Assamita", "Assamite", ["celerity", "obfuscate", "quietus"]),
    ("brujah", "Brujah", "Brujah", ["celerity", "potence", "presence"]),
    ("followers_of_set", "Seguidores de Set", "Followers of Set", ["obfuscate", "presence", "serpentis"]),
    ("gangrel", "Gangrel", "Gangrel", ["animalism", "fortitude", "protean"]),
    ("giovanni", "Giovanni", "Giovanni", ["dominate", "necromancy", "potence"]),
    ("lasombra", "Lasombra", "Lasombra", ["dominate", "obtenebration", "potence"]),
    ("malkavian", "Malkaviano", "Malkavian", ["auspex", "dementation", "obfuscate"]),
    ("nosferatu", "Nosferatu", "Nosferatu", ["animalism", "obfuscate", "potence"]),
    ("ravnos", "Ravnos", "Ravnos", ["animalism", "chimerstry", "fortitude"]),
    ("toreador", "Toreador", "Toreador", ["auspex", "celerity", "presence"]),
    ("tremere", "Tremere", "Tremere", ["auspex", "dominate", "thaumaturgy"]),
    ("tzimisce", "Tzimisce", "Tzimisce", ["animalism", "auspex", "vicissitude"]),
    ("ventrue", "Ventrue", "Ventrue", ["dominate", "fortitude", "presence"]),
    ("caitiff", "Caitiff", "Caitiff", []),
]
ABILITIES = {
    "talents": [("alertness", "Prontidão", "Alertness"), ("athletics", "Esportes", "Athletics"), ("awareness", "Percepção do sobrenatural", "Awareness"),
                ("brawl", "Briga", "Brawl"), ("empathy", "Empatia", "Empathy"), ("expression", "Expressão", "Expression"),
                ("intimidation", "Intimidação", "Intimidation"), ("leadership", "Liderança", "Leadership"), ("streetwise", "Manha", "Streetwise"),
                ("subterfuge", "Lábia", "Subterfuge")],
    "skills": [("animal_ken", "Empatia com animais", "Animal Ken"), ("crafts", "Ofícios", "Crafts"), ("drive", "Condução", "Drive"),
               ("etiquette", "Etiqueta", "Etiquette"), ("firearms", "Armas de fogo", "Firearms"), ("larceny", "Furto", "Larceny"),
               ("melee", "Armas brancas", "Melee"), ("performance", "Performance", "Performance"), ("stealth", "Furtividade", "Stealth"),
               ("survival", "Sobrevivência", "Survival")],
    "knowledges": [("academics", "Acadêmicos", "Academics"), ("computer", "Computação", "Computer"), ("finance", "Finanças", "Finance"),
                   ("investigation", "Investigação", "Investigation"), ("law", "Direito", "Law"), ("medicine", "Medicina", "Medicine"),
                   ("occult", "Ocultismo", "Occult"), ("politics", "Política", "Politics"), ("science", "Ciências", "Science"),
                   ("technology", "Tecnologia", "Technology")],
}
BACKGROUNDS = [
    ("allies", "Aliados", "Allies"), ("alternate_identity", "Identidade alternativa", "Alternate Identity"), ("contacts", "Contatos", "Contacts"),
    ("domain", "Domínio", "Domain"), ("fame", "Fama", "Fame"), ("generation", "Geração", "Generation"), ("herd", "Rebanho", "Herd"),
    ("influence", "Influência", "Influence"), ("mentor", "Mentor", "Mentor"), ("resources", "Recursos", "Resources"),
    ("retainers", "Lacaios", "Retainers"), ("status", "Status", "Status"),
]
SECTS = [("camarilla", "Camarilla", "Camarilla"), ("sabbat", "Sabá", "Sabbat"), ("anarch", "Movimento Anarquista", "Anarch Movement"),
         ("independent", "Independente", "Independent")]
# Humanity first (fixes Conscience + Self-Control); Paths of Enlightenment: names only, their virtue pairs are missing.
MORALITIES = [
    ("humanity", "Humanidade", "Humanity"), ("caine", "Senda de Caim", "Path of Caine"), ("cathari", "Senda dos Cátaros", "Path of Cathari"),
    ("death_and_the_soul", "Senda da Morte e da Alma", "Path of Death and the Soul"), ("evil_revelations", "Senda das Revelações Malignas", "Path of Evil Revelations"),
    ("feral_heart", "Senda do Coração Selvagem", "Path of the Feral Heart"), ("honorable_accord", "Senda do Acordo Honrado", "Path of Honorable Accord"),
    ("lilith", "Senda de Lilith", "Path of Lilith"), ("night", "Senda da Noite", "Path of Night"),
    ("power_and_the_inner_voice", "Senda do Poder e da Voz Interior", "Path of Power and the Inner Voice"),
]
# generation: (blood pool max, blood per turn, trait max) — reachable range 13th..8th (Generation 0..5)
BLOOD = {13: (10, 1, 5), 12: (11, 1, 5), 11: (12, 1, 5), 10: (13, 1, 5), 9: (14, 2, 5), 8: (15, 3, 5)}

BOOK = {
    "script": "scripts/presets/build_vampire_v20.py",
    "variant_id": "vampire-v20", "version": VERSION, "edition": "V20",
    "display_name": "Vampire: The Masquerade 20th Anniversary (uso interno, não oficial)",
    "rules_revision": "v20-pending-printing", "source_version": "V20 core (impressão e errata a confirmar)", "source_id": "vtm-v20-core",
    "clans": CLANS, "clanless": "caitiff", "disciplines": DISCIPLINES, "abilities": ABILITIES, "backgrounds": BACKGROUNDS,
    "sects": SECTS, "moralities": MORALITIES, "morality_word": "Senda", "morality_kind": "path",
    "morality_kind_labels": {"pt-BR": "Humanidade / Senda", "en": "Humanity / Path of Enlightenment"},
    "appearance_zero_clans": ["nosferatu"],
    "base_generation": 13, "blood_by_generation": BLOOD,
    "budgets": {"attributes": [7, 5, 3], "abilities": [13, 9, 5], "ability_creation_max": 3, "disciplines": 3, "backgrounds": 5, "virtues": 7, "freebies": 15},
    "freebie_costs": {"attribute": 5, "ability": 2, "discipline": 7, "background": 1, "virtue": 2, "morality": 1, "willpower": 1},
    "xp_costs": {"attribute": 4, "new_ability": 3, "ability": 2, "new_discipline": 10, "clan_discipline": 5, "other_discipline": 7,
                 "clanless_discipline": 6, "virtue": 2, "morality": 2, "willpower": 1},
    "limitations": [
        "Distribuição bloqueada: conteúdo proprietário (Paradox), sem autorização para app pago. Uso interno.",
        "Todos os valores estão a conferir no livro (sem documento-fonte no repositório).",
        "Qualidades e Defeitos, especializações, trilhas e rituais são registrados pelo jogador; sem custo ou efeito automático.",
        "Penalidade de ferimento é calculada, mas não é aplicada automaticamente às paradas de dados.",
        "Perder Humanidade/Senda ou Força de Vontade permanente devolve a experiência paga por aquele ponto (limitação da engine).",
        "Virtudes de cada Senda de Iluminação: o jogador escolhe; os pares oficiais não foram registrados.",
        "Fraquezas de clã (exceto Aparência 0), diablerie, absorção e combate: não modelados.",
    ],
}
BOOK["source_register"] = wod.register(BOOK, "Vampire: The Masquerade 20th Anniversary", [
    ("Clans V20 core: 13 + Caitiff", ", ".join(c[2] for c in CLANS), "high", "pending"),
    ("Caitiff: no clan Disciplines; any Discipline at creation; XP current×6", "creation table admits any Discipline; XP multiplier 6", "medium", "pending"),
    ("Disciplines (17 clan Disciplines)", ", ".join(d[2] for d in DISCIPLINES), "high", "pending"),
    ("Other V20 Disciplines (Daimoinon, Melpominee, Mytherceria, Obeah, Sanguinus, Temporis, Thanatosis, Valeren, Visceratika…)", "not included", "—", "missing"),
    ("Sects", ", ".join(s[2] for s in SECTS), "medium", "pending"),
    ("Paths of Enlightenment (names)", ", ".join(m[2] for m in MORALITIES[1:]), "medium", "pending"),
    ("Paths' virtue pairs (Conviction/Instinct use)", "player chooses virtue types", "—", "missing"),
    ("Other Paths (Blood, Bones, Metamorphosis, Paradox, Typhon…)", "not included", "—", "missing"),
    ("Backgrounds", ", ".join(b[2] for b in BACKGROUNDS), "medium-high", "pending"),
    ("Sabbat Backgrounds (Black Hand Membership, Rituals)", "not included", "—", "missing"),
    ("Talents / Skills / Knowledges", "; ".join(", ".join(a[2] for a in its) for its in ABILITIES.values()), "high", "pending"),
    ("Generations below 8th, thin-blooded 14th/15th", "not reachable / not included", "—", "missing"),
])

if __name__ == "__main__":
    wod.build(BOOK)
