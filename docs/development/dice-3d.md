# Dados 3D (PWA)

Implementa a política do ADR 0002 (animação é apresentação; resultado vem da autoridade) com
base no POC `dados3D.html`, reescrito para desempenho.

## Como o lançamento funciona

1. **Resultado primeiro.** Rolagem livre: `crypto.getRandomValues` (PC-04). Rolagem oficial
   (futuro): resultado do servidor.
2. **Física sem busca.** Um único lançamento natural com Rapier (`@dimforge/rapier3d-deterministic`),
   simulado sem renderizar e gravado a 60 Hz. Não há tentativas para "acertar" a face.
3. **Troca de rótulos por simetria** (`src/dice/symmetry.ts`). A colisão só vê o casco convexo;
   toda rotação de simetria do dado leva o casco nele mesmo. Se a face física L ficou para cima,
   o modelo é desenhado girado pela simetria R com R·n(T) = n(L), e o número sorteado T aparece
   no topo. Visual e colisão coincidem; nada é corrigido à força.
4. **Reprodução e organização.** A gravação é tocada com interpolação; depois os dados são
   puxados para uma fileira na borda do jogador (parte de baixo da tela, câmera fixa de cima),
   apoiados na face do resultado e com o número na posição de leitura.

Repetição só ocorre se um dado parar inclinado (até 4 vezes, semente derivada).

### Medições (`tests/dice-core.test.ts`, Node 24, 6 dados mistos, 20 lançamentos)

- Cálculo: p50 ≈ 6–13 ms, p95 ≈ 11–26 ms (o POC usava até 8 s de busca por lançamento).
- Animação: 1,2–2,2 s de física + 0,55 s de organização.

### Giro

O dado é arremessado rolando para a frente (eixo −x, com desvio aleatório), um pouco mais alto, e
com amortecimento angular baixo, para o giro não morrer no ar. Medido em 60 lançamentos por
conjunto (voltas totais por dado, p50):

| Conjunto | Antes | Agora | Duração p50 |
|---|---|---|---|
| 1d20 | 1,41 | 2,82 | 1,27 → 1,47 s |
| 3d6 | 1,28 | 2,87 | 1,08 → 1,23 s |
| 6 mistos | 1,15 | 2,55 | 1,68 → 1,82 s |

A velocidade inicial fica em no máximo 46 rad/s (≈ 44° por quadro a 60 Hz). Acima disso, o d6
parece girar para trás (efeito roda de carroça), porque a animação não tem desfoque de movimento.
- Chromium headless com SwiftShader (render por software): 6–16 ms com o chunk já carregado.

## Experiência do usuário

- **Primeira rolagem sem espera**: o 3D é baixado e aquecido (shaders compilados, física e
  tabelas de simetria prontas) enquanto a pessoa escolhe os dados, em tempo ocioso. Com
  "economia de dados" do navegador ativa, só carrega na primeira rolagem.
- **Rolar de novo a qualquer momento**: um novo lançamento substitui o atual na hora.
- **Som e vibração**: impactos inferidos da trajetória gravada (determinísticos, iguais para o
  mestre), som sintetizado com Web Audio (sem arquivos), vibração curta onde o aparelho suporta.
  Botão "Som ligado/desligado" ao lado de "Rolar", lembrado no dispositivo.
- **d4**: resultado no vértice de cima; cada face traz três números, um por canto.
- **d100**: par de d10 (dezenas 00–90 e unidades 0–9; 00 + 0 = 100) lado a lado; até 3 por
  rolagem para respeitar o limite de 6 dados na mesa.
- A fileira final se ajusta à largura da mesa.

Medido no Chromium headless com renderização por software (pior caso que um aparelho real),
do clique até os dados se moverem: primeira rolagem 97 ms (era 272 ms sem aquecimento),
rerrolagem no meio da animação 58 ms, 3d100 47 ms.

## Mesma animação para o mestre

Um `RollAnimationRecord` (`novtt.dice-roll-animation/1`: dados, resultados, semente, skin)
reproduz a mesma animação em qualquer cliente: a variante *deterministic* do Rapier é
bit-idêntica entre plataformas, e a semeadura não usa trigonometria do JS. Mesmo se uma
trajetória divergisse, os números mostrados seriam os do registro (troca de rótulos local).

No MVP, a rolagem livre é espelhada só entre janelas do mesmo navegador (`BroadcastChannel`,
seção "Dados do jogador" em `demo.html`), continua LOCAL · NÃO OFICIAL e não vai ao servidor.

## Skins

Somente dados (cor do dado, cor dos números, acabamento), sem imagens ou código do usuário.
Contraste mínimo 3:1 entre número e dado. Preferência salva no dispositivo; a skin viaja no
registro para o mestre ver os dados como o jogador escolheu.

## Desempenho e carregamento

- three.js (≈ 134 kB gzip) e Rapier (52 kB JS + 1,18 MB WebAssembly gzip) são carregados sob
  demanda na primeira rolagem; o service worker guarda os chunks e o `.wasm` (com hash).
- Renderização sob demanda (o laço para quando os dados param), 2 draw calls por dado (atlas de
  números por skin), sombras 1024², `devicePixelRatio` ≤ 2.
- Sem WebGL, offline antes do primeiro uso ou com falha de carregamento: resultado em texto.

## Acessibilidade

Canvas decorativo (`aria-hidden`); o resultado em texto é anunciado (`aria-live`) na ordem da
fileira. `prefers-reduced-motion` mostra direto a posição final; botão "Pular animação".

## Pendências

- **Rolagens oficiais**: para o mestre e o jogador verem a mesma animação, o evento
  `dice.roll.resolved` precisa carregar a semente de animação (nova versão de contrato; exige
  aprovação do dono). Hoje só o espelho local existe.
- **Android** (ADR 0005/SPEC-001 PC-11): renderer equivalente ainda não existe.
- Limite de 6 dados na mesa (antes os dados livres aceitavam até 100); aprovado pelo dono.
- Validação visual em aparelhos reais (iOS Safari, Android Chrome) e leitores de tela.
