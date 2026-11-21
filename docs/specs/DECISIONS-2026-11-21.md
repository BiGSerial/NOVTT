# NOVTT — Registro consolidado de decisões de produto e arquitetura

Data: 2026-11-21
Status: decisões acordadas; itens explicitamente pendentes não são requisitos aprovados
Referências: [SPEC-PRESETS-001](SPEC-PRESETS-001.md), [SPEC-AI-001](SPEC-AI-001.md)

## Identidade do produto
NOVTT (Not Other Virtual Table Top) é uma plataforma de apoio a RPG de mesa, sem tabuleiro virtual obrigatório. Prioriza facilidade de uso, autonomia do mestre, interpretação narrativa e suporte a sistemas diversos; não pretende transformar RPG de mesa em videogame.

## Experiência e clientes
- Aplicativo Android nativo e PWA para jogadores; painel web multijanela para o mestre.
- Jogadores criam personagens com história, atributos, imagem e inventário e os vinculam a campanhas/sessões.
- Mestre gerencia participação, permissões, evolução e alterações de fichas, com histórico auditável.
- Proximidade narrativa deve permitir ao mestre determinar quem pode interagir, agir ou transferir itens conforme localização; modelo exato de zonas e exceções ainda pendente.
- Sessões precisam de persistência, sincronização e recuperação de estado; contratos e UX ainda pendentes.

## Arquitetura
- Backend PHP/Laravel; PostgreSQL, Redis e Laravel Reverb planejados.
- Engine PHP/Composer independente da infraestrutura, das interfaces e de regras específicas de RPG.
- PWA Vue/TypeScript; Android Kotlin/Jetpack Compose; painel web Laravel/Livewire.
- Contratos explícitos e versionados (OpenAPI, AsyncAPI, JSON Schema), validação autoritativa no backend, segurança, desempenho, usabilidade e testes como critérios de decisão.
- Preferir implementação incremental, commits pequenos e testes; não adicionar atribuição de coautoria de IA.

## Presets (aprovado)
- Presets oficiais mantidos pela equipe e presets personalizados produzidos pelo usuário fora da plataforma.
- Importação de JSON declarativo conforme manual e schema oficiais; usuário pode recorrer a qualquer IA externa para elaborar JSON a partir de fichas e mecânicas.
- NOVTT não lê automaticamente PDFs/imagens, não extrai regras de manuais e não requer IA para presets.
- Mesmo validador/engine para presets oficiais e personalizados; biblioteca privada, prévia, diagnósticos, versionamento imutável por campanha.
- Preset descreve ficha, dados, recursos, efeitos, condições, fórmulas seguras e restrições, dentro das capacidades documentadas do contrato.
- Fidelidade às regras originais é responsabilidade do autor; respeitar direitos autorais.
- Manual técnico legível por humanos e IAs, schema e exemplos versionados. Editor visual completo e marketplace não integram a primeira etapa.

## Integração IA (aprovado)
- BYOAI opcional **exclusivamente por API** do usuário ou endpoint de API compatível e seguro. Não usar assinatura de aplicativo de chat, cookies ou sessão de navegador.
- NOVTT fornece gateway, ferramentas, permissões, contexto autorizado e RAG; o provedor do usuário executa inferência. Aplicativo funciona sem IA.
- IA pode criar narração, diálogos e reações de NPCs; consultar regras, personagens e histórico; identificar contradições; alertar ações potencialmente proibidas; solicitar rolagens e propor atualização de status, recursos, efeitos, condições e inventário.
- Engine decide validade mecânica e aplica alterações autorizadas; mestre preserva autoridade narrativa e controla delegação.
- Três níveis previstos: assistente, copiloto supervisionado e narrador delegado. Modo padrão e matriz de permissões por ação ainda não escolhidos.
- Segredos do mestre e dados de personagens só entram no contexto após controle de acesso; conteúdo recuperado e saída de IA não são instruções confiáveis.
- IA não acessa diretamente banco, não executa código arbitrário e não transforma sugestões em fatos canônicos automaticamente.
- Ações mutáveis exigem autorização, validação, idempotência, transações e auditoria.

## Custos de IA (aprovado)
- Sempre que possível, estimar custo antes de cada chamada e registrar uso e custo estimado após a resposta.
- Exibir consumo por campanha, usuário, modelo e período; diferenciar estimativa de faturamento oficial, quando consultável.
- Contabilizar tokens e serviços tarifados associados (embeddings, ferramentas) conforme disponibilidade de dados.
- Usuário pode desabilitar novas solicitações a qualquer momento; limites orçamentários, alertas e bloqueio automático.
- Controlar concorrência/reserva de orçamento; custos de chamadas já iniciadas podem continuar; limites NOVTT não cobrem uso externo da mesma conta.

## Sequência sugerida, não cronograma aprovado
1. Fundação: autenticação, campanhas, personagens, engine, presets e permissões.
2. Sessões: rolagens, sincronização, histórico, recuperação.
3. Interações: inventário, proximidade, condições.
4. BYOAI leitura e RAG; depois propostas supervisionadas e delegação restrita.

## Pendências explícitas
- Modelo detalhado de autorização de alterações de ficha e autonomia padrão da IA.
- Fluxo completo de campanha/sessão, convites, aprovação, encerramento e retomada.
- Contratos de rolagens, efeitos, ações, inventário e proximidade.
- Modelos de dados, eventos em tempo real, concorrência e UX.
- Política de retenção/privacidade, cotas, preços e acesso a provedores.
- Planejamento de MVP, critérios de aceite por módulo e estratégia de testes.

Este documento consolida decisões de produto; especificações especializadas prevalecem em detalhes e devem ser atualizadas quando novas decisões forem tomadas.
