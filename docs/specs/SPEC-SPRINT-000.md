# SPEC-SPRINT-000 — Fundação técnica e preparação da IA local

Status: plano de execução proposto, alinhado ao MVP essencial aprovado
Data: 2026-11-21
Referências: SPEC-MVP-001, SPEC-PRESETS-001, SPEC-AI-001, DECISIONS-2026-11-21

## Objetivo
Deixar o monorepo reproduzível, testável, seguro e preparado para implementar o fluxo da Primeira Mesa. Sprint 0 NÃO entrega toda a aplicação, nem funcionalidades de IA.

## Inventário antes de alterar
1. Verificar árvore real, branches, histórico e diferenças entre main, architecture-foundation, docker-dev-environment e specs-i18n-presets; não assumir que todas as mudanças foram integradas.
2. Identificar linguagem, versões, dependências, testes e CI existentes. Verificar estado de packages/engine, apps/player-pwa e apps/master-web.
3. Confirmar se apps/api existe na branch de trabalho antes de criar; não sobrescrever código.
4. Executar testes e linters existentes; registrar baseline, comandos, falhas e limitações. Nunca declarar testes executados sem evidência.
5. Produzir diagnóstico curto com riscos e sequência de commits.

## Entregas técnicas
### S0-01 Reprodutibilidade
- Consolidar instruções de ambiente local e configuração sem segredos versionados.
- Definir versões suportadas de PHP, Node, banco e ferramentas a partir de compatibilidade real.
- Resolver lockfiles e setup Docker/Compose de forma reproduzível quando aplicável, sem mudanças desnecessárias.
- Verificar healthchecks, dependências e isolamento de serviços; evitar portas/credenciais inseguras por padrão.

### S0-02 Estrutura e fronteiras
- Preservar packages/engine como biblioteca Composer sem dependência de Laravel, HTTP, banco ou UI.
- Preparar apps/api Laravel quando inexistente, após confirmar versões e compatibilidade.
- Definir dependência local Composer da engine e configuração básica de testes.
- Documentar responsabilidades: API autoritativa, engine pura, cliente PWA, painel do mestre, futuros Android e AI Gateway.

### S0-03 Contratos iniciais
- Especificar identificadores e invariantes de User, Campaign, Membership, Character, Session, Roll e Event.
- Rascunhar endpoints HTTP versionados, envelopes de erro, paginação e políticas de autorização.
- Rascunhar eventos de sessão versionados, ordenação, replay/reconexão e idempotência; evitar acoplamento a UI.
- Delimitar preset JSON versionado e compatibilidade com SPEC-PRESETS-001; não implementar parser de regras completo na Sprint 0.
- Marcar decisões ainda não aprovadas como perguntas ou ADRs propostos, sem atribuí-las ao usuário.

### S0-04 Segurança desde o início
- Modelo de autenticação proposto: Laravel Sanctum, condicionado à avaliação do cliente PWA, cookies, CSRF, CORS e Android futuro.
- Políticas de autorização no servidor; isolamento entre campanhas e propriedade de personagens.
- Configuração segura, gestão de segredos, proteção contra mass assignment, validação e rate limiting.
- Definir matriz inicial de permissões e testes de acesso negado; alterações estruturais dependem do mestre até decisão granular.
- Preparar auditoria e rastreabilidade de ações mutáveis sem registrar segredos.

### S0-05 Qualidade e entrega
- Testes automatizados de baseline e smoke tests do setup.
- Pipeline CI mínimo para engine, API (se criada), PWA e validação de contratos conforme módulos existentes.
- Documentar comandos de setup/teste e critérios de pronto; criar ADRs para decisões de arquitetura.
- Nenhum componente de IA, RAG, credenciais de provedores ou custo de tokens nesta sprint.

## Ordem sugerida de commits pequenos
1. docs: inventory and baseline
2. chore: reproducible dev environment
3. feat: bootstrap Laravel API (somente se ausente)
4. docs: initial domain and API/event contracts
5. test: baseline checks and CI
Separar correções adicionais por assunto, sem coautoria.

## Definition of Done
- Ambiente sobe a partir de instruções documentadas em máquina limpa, com dependências declaradas.
- Testes aplicáveis e linters executam com resultados registrados; falhas remanescentes explícitas.
- Engine continua desacoplada; API pode consumi-la sem duplicar regras.
- Contratos iniciais e limites de autorização documentados e revisáveis.
- Nenhuma credencial ou dado sensível commitado.
- Nenhum escopo futuro implementado por antecipação.
- Relatório final com commits, arquivos alterados, comandos/testes e riscos conhecidos.

## Diretriz para a IA local
Escolher a solução mais segura, eficiente, acessível e simples que satisfaça os contratos; evitar dependências e abstrações especulativas. Nunca modificar regras de negócio sem teste. Validar antes de renderizar ou persistir; tratar cliente como não confiável; otimizar consultas e comunicação em tempo real. Não atribuir coautoria de IA. Em decisões não aprovadas, documentar proposta e pedir validação em vez de inventar política.
