/**
 * Improvisation tables for the master (original text, written for NOVTT; no third-party content).
 * Pure functions over a random source so tests can pin results. These are prep and table aids,
 * never official rolls: nothing here touches the session's authoritative state.
 */
export type Rand = () => number
const pick = <T>(list: readonly T[], r: Rand): T => list[Math.floor(r() * list.length) % list.length]

const FIRST = ['Alda', 'Bento', 'Cora', 'Dimas', 'Elva', 'Fábio', 'Gema', 'Heitor', 'Iara', 'Joel', 'Lia', 'Mauro', 'Nuno', 'Otília', 'Pietra', 'Quim', 'Rosa', 'Sabino', 'Tarsila', 'Ulisses', 'Vera', 'Xavier', 'Yara', 'Zeca', 'Brites', 'Caetano', 'Dalva', 'Ester', 'Firmino', 'Graça']
const EPITHET = ['das Cinzas', 'Mão-Leve', 'o Calado', 'de Três Rios', 'Olho-de-Vidro', 'a Ruiva', 'Sem-Sombra', 'do Moinho', 'Língua-de-Prata', 'o Velho', 'Pé-de-Vento', 'da Torre Torta', 'Meia-Lua', 'o Devoto', 'Ferro-Frio']
const LOOK = ['cicatriz que atravessa a sobrancelha', 'casaco remendado com retalhos de outras cores', 'dedos manchados de tinta', 'voz rouca de quem gritou a noite toda', 'cheiro forte de fumaça e cravo', 'dente de ouro que aparece quando mente', 'mãos que nunca param quietas', 'olhar fixo que não pisca', 'chapéu grande demais', 'tatuagem de peixe no pulso']
const MANNER = ['responde perguntas com perguntas', 'ri antes de dar más notícias', 'conta moedas enquanto fala', 'chama todos de "primo"', 'cita um provérbio para tudo', 'fala baixo e obriga a chegar perto', 'interrompe a si mesmo', 'mastiga uma raiz amarga', 'nunca diz o próprio nome', 'trata animais melhor que pessoas']
const WANT = ['pagar uma dívida antes do inverno', 'provar que não é covarde', 'reaver um objeto roubado da família', 'sair da cidade sem ser visto', 'ser lembrado por algo grandioso', 'proteger um irmão encrencado', 'descobrir quem o traiu', 'vender um segredo pelo maior preço', 'reconquistar um amor antigo', 'esconder o que viu na noite passada']
const COMPLICATION = ['Alguém importante chega na pior hora.', 'O chão (ou a ponte, ou o barco) cede um pouco.', 'Um aliado muda de lado — por um bom motivo.', 'O tempo vira: chuva, névoa ou vento forte.', 'A recompensa prometida não existe mais.', 'Uma testemunha inocente está no meio de tudo.', 'O objeto procurado está em duas partes.', 'Um rival chega primeiro e pede ajuda.', 'Uma luz se apaga; algo se move no escuro.', 'Um sino toca: todos ao redor param e olham.', 'O plano funciona bem demais e chama atenção.', 'Uma ferida antiga de um personagem volta a doer.']
const OPENER = ['Os personagens acordam com gritos: o ', 'Uma carta sem remetente chega dizendo apenas: ', 'No meio da travessia, ', 'A porta é arrombada enquanto ', 'O sino da vila toca fora de hora e ']
const OPENER_END = ['celeiro está em chamas e há alguém preso lá dentro.', '"Não confiem no barqueiro."', 'a corda que segura a balsa começa a arrebentar.', 'o grupo divide a última refeição quente.', 'todos correm para a praça, menos uma criança que corre na direção oposta.']
const ASPECT_SIGHT = ['estátuas com os rostos raspados', 'um rio que corre devagar demais', 'lanternas penduradas que ninguém acende', 'teias tão grossas que seguram folhas', 'ossos de um animal enorme servindo de portal', 'janelas pintadas por dentro', 'árvores que crescem tortas na mesma direção']
const ASPECT_SOUND = ['um zumbido grave que vem do chão', 'sinos de vento sem vento nenhum', 'água pingando em ritmo de passos', 'risadas distantes que param quando alguém escuta', 'madeira rangendo como um navio']
const ASPECT_SMELL = ['ferro e chuva', 'mel queimado', 'terra revirada', 'incenso velho', 'sal e alga podre', 'pão recém-assado, sem padaria por perto']
const WEATHER = ['Névoa baixa que esconde os pés.', 'Garoa fina e fria.', 'Céu limpo e lua forte.', 'Vento que apaga tochas.', 'Calor abafado antes da tempestade.', 'Primeira geada do ano.']
const RUMOR = ['Dizem que o moleiro paga em moedas que ninguém reconhece.', 'Alguém viu luzes no fundo do lago.', 'A guarda está sendo paga para não olhar a estrada norte.', 'A velha da ponte sabe o nome de todos os mortos do rio.', 'Uma caravana sumiu inteira, com bois e tudo.', 'O sacerdote novo não aparece nos espelhos.']
const ANSWERS: Record<'likely' | 'even' | 'unlikely', [number, string][]> = {
  likely: [[0.1, 'Não, e…'], [0.25, 'Não'], [0.35, 'Não, mas…'], [0.55, 'Sim, mas…'], [0.85, 'Sim'], [1, 'Sim, e…']],
  even: [[0.15, 'Não, e…'], [0.4, 'Não'], [0.5, 'Não, mas…'], [0.6, 'Sim, mas…'], [0.85, 'Sim'], [1, 'Sim, e…']],
  unlikely: [[0.2, 'Não, e…'], [0.55, 'Não'], [0.65, 'Não, mas…'], [0.75, 'Sim, mas…'], [0.92, 'Sim'], [1, 'Sim, e…']],
}

export const oracle = {
  name: (r: Rand = Math.random) => `${pick(FIRST, r)} ${pick(EPITHET, r)}`,
  npc: (r: Rand = Math.random) => ({ name: `${pick(FIRST, r)} ${pick(EPITHET, r)}`, voice: `${pick(MANNER, r)}; ${pick(LOOK, r)}`, wants: pick(WANT, r), secret: '' }),
  complication: (r: Rand = Math.random) => pick(COMPLICATION, r),
  strongStart: (r: Rand = Math.random) => { const i = Math.floor(r() * OPENER.length) % OPENER.length; return OPENER[i] + OPENER_END[i] },
  aspects: (r: Rand = Math.random) => [`Vê-se ${pick(ASPECT_SIGHT, r)}.`, `Ouve-se ${pick(ASPECT_SOUND, r)}.`, `Cheira a ${pick(ASPECT_SMELL, r)}.`],
  weather: (r: Rand = Math.random) => pick(WEATHER, r),
  rumor: (r: Rand = Math.random) => pick(RUMOR, r),
  /** Yes/no with nuance ("sim, mas…"): a prompt for the master, not a rule. */
  ask: (odds: 'likely' | 'even' | 'unlikely', r: Rand = Math.random) => { const x = r(); return ANSWERS[odds].find(([p]) => x < p)![1] },
}
