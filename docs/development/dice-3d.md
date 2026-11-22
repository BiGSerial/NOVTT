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
- Animação: 1,3–2,1 s de física + 0,55 s de organização.
- Chromium headless com SwiftShader (render por software): 6–16 ms com o chunk já carregado.

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
- **d4 e d100** saíram dos dados livres (antes ofereciam d4–d100 até 100 dados); voltam quando
  houver leitura por vértice (d4) e par percentil (d100). Limite atual: 6 dados.
- Validação visual em aparelhos reais (iOS Safari, Android Chrome) e leitores de tela.
