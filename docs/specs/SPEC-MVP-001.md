# SPEC-MVP-001 — NOVTT MVP essencial (Primeira Mesa)

Status: escopo aprovado
Data: 2026-11-21

## Objetivo
Entregar uma mesa de RPG funcional de ponta a ponta, sem tabuleiro virtual obrigatório e sem dependência de IA.

## Incluído no MVP
1. Cadastro, login, logout e recuperação de acesso com autenticação segura.
2. Mestre cria campanha e seleciona uma versão de preset disponível.
3. Convite/entrada de jogadores e gestão de participantes.
4. Jogador cria personagem com ficha dinâmica baseada no preset; mestre aprova vinculação à campanha.
5. Controle de permissões: jogador realiza apenas ações permitidas; mudanças estruturais da ficha dependem de aprovação do mestre até definição de política granular.
6. Mestre inicia, pausa, retoma e encerra sessões.
7. Rolagens por engine universal com resultados auditáveis e visibilidade autorizada.
8. Sincronização de eventos de sessão e persistência do estado de personagens/recursos; reconexão recupera estado sem duplicar ações.
9. Presets oficiais e personalizados via JSON declarativo conforme SPEC-PRESETS-001; implementação mínima necessária para suportar a primeira mesa, sem regras de RPG codificadas no núcleo.

## Fora do primeiro marco de entrega
- IA, RAG, narração automatizada e integração BYOAI (continuam aprovados para fases posteriores, SPEC-AI-001).
- Android nativo completo; iniciar pelo PWA, preservando contratos para cliente Android futuro.
- Inventário avançado, transferência de itens, proximidade narrativa, mapas táticos, marketplace, OCR, editor visual de presets.
- Não remover esses itens do roadmap ou inviabilizá-los arquiteturalmente.

## Jornada de aceite de ponta a ponta
1. Mestre autenticado cria campanha com preset versionado.
2. Jogador autenticado entra por convite e cria personagem.
3. Mestre aprova o personagem.
4. Mestre abre sessão; jogador conecta e vê ficha autorizada.
5. Jogador solicita rolagem; backend autoriza e engine resolve; mestre e jogador veem resultados conforme visibilidade.
6. Mestre encerra; sistema preserva histórico e estado.
7. Ao reconectar, cada participante recupera apenas dados permitidos e não há duplicação de efeitos/rolagens.

## Qualidade obrigatória
- Backend autoritativo; autorização por campanha, sessão, personagem e operação.
- Isolamento entre usuários/campanhas, validação de entradas, rate limiting e trilha de auditoria.
- Testes unitários, integração e fluxo crítico end-to-end; CI e documentação de execução.
- Performance mensurável e UX responsiva; não introduzir complexidade sem necessidade.
- Contratos de API/eventos versionados, erros consistentes, idempotência em comandos mutáveis.
- Sem dependência de fornecedor de IA ou de sistema específico de RPG.

## Decisões ainda abertas
Fluxo detalhado de recuperação de conta, convites, permissões granulares, políticas de rolagem secreta e limites de sessão devem ser especificados antes de implementar cada funcionalidade, não presumidos como decisões do usuário.
