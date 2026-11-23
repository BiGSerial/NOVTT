# Identidade NOVTT

Pacote entregue pelo dono em 2026-11-22. **Logo principal: o hexágono com o N** (prancha
`novtt-brand-board.png`). As opções B (mesa redonda) e C (logotipo com dado) em
`novtt-alternative-options.png` são registro de alternativas, não estão em uso.

NOVTT = **Not Other Virtual Table Top**. A tagline acompanha o logo em aberturas (landing, login,
splash); na interface do dia a dia aparece só o símbolo + "NOVTT".

## Arquivos

| Arquivo | Uso |
| --- | --- |
| `apps/player-pwa/public/brand/novtt-symbol-on-dark.svg` | Símbolo sobre fundo escuro (traço lilás, N âmbar) |
| `apps/player-pwa/public/brand/novtt-symbol-on-light.svg` | Símbolo sobre fundo claro (traço tinta, N âmbar profundo) |
| `apps/player-pwa/public/icons/novtt-icon.svg` | Ícone do app com cantos arredondados; também o favicon |
| `apps/player-pwa/public/icons/novtt-maskable.svg` | Ícone sem cantos para máscara (Android), símbolo dentro da zona segura |
| `apps/player-pwa/public/icons/*.png` | 192, 512, maskable 512 e `apple-touch-icon` (180), gerados dos SVGs |
| `src/components/BrandMark.vue` | Símbolo + palavra NOVTT (+ tagline opcional) |

Regenerar os PNG: abrir os SVG num navegador headless e salvar no tamanho (ver PR de identidade).
O Android nativo deve usar os mesmos SVGs como vector drawable / adaptive icon (ADR 0005, 0011).

## Paleta (tokens em `apps/player-pwa/src/styles/base.css`)

| Token | Cor | Papel |
| --- | --- | --- |
| `--bg` | `#0F1020` | Noite: fundo |
| `--surface` / `--surface-2` | `#17182E` / `#171A3A` | Painéis; azul-marinho do ícone |
| `--text` | `#EDEAFF` | Lilás claro: texto e traço do símbolo |
| `--muted` | `#A9A6C8` | Texto secundário, tagline |
| `--accent` | `#FFB224` | Âmbar: o traço do N, ação principal, foco |
| `--accent-deep` | `#E8890C` | Âmbar sobre fundo claro |
| `--ink` / `--paper` | `#17152B` / `#F6F4EE` | Versão clara |

Contraste medido (WCAG): `--text` sobre `--bg` 16.0:1; `--muted` sobre `--bg` 8.0:1; `--accent`
sobre `--bg` 10.4:1; `--bg` sobre `--accent` (texto de botão) 10.4:1; `--ink` sobre `--paper`
16.2:1; bordas de controles (`--line-strong`) 3.6:1 sobre `--bg` e 3.4:1 sobre `--surface`. Âmbar profundo sobre papel (2.4:1) é só para o traço do símbolo, nunca para texto.

Os temas de campanha (fantasia, horror, sci-fi; ADR 0011) sobrescrevem esses tokens como dados;
o símbolo e a palavra NOVTT não mudam de cor por tema.
