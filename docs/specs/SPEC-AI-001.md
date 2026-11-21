# SPEC-AI-001 — Copiloto narrativo e gestão inteligente de sessões

Status: escopo de produto aprovado; detalhamento técnico e níveis de autonomia configuráveis pendentes
Data: 2026-11-21
Projeto: NOVTT
Dependência: SPEC-PRESETS-001

## Visão
Oferecer integração opcional BYOAI (Bring Your Own AI): o mestre ou usuário conecta seu próprio provedor/modelo por API ou endpoint seguro. NOVTT fornece orquestração, contexto autorizado, ferramentas e validação; a inferência é executada pelo provedor escolhido. NOVTT permanece plenamente funcional sem IA.

## Capacidades previstas
- Gerar e auxiliar a narração de cenas, consequências, diálogos e reações de NPCs.
- Consultar histórico, fichas, estados, regras do preset e conhecimento autorizado via recuperação contextual/RAG.
- Verificar detalhes e continuidade narrativa, detectar possíveis contradições e indicar incertezas.
- Alertar sobre ações proibidas ou inviáveis por condições, distância, permissões ou regras, com decisão mecânica fundamentada na engine.
- Solicitar testes e rolagens à engine; interpretar e narrar resultados confirmados.
- Propor e, quando explicitamente autorizado, aplicar atualizações de estado: dano, cura, recursos, condições, efeitos e inventário.
- Gerar resumos de sessão e sugerir registros persistentes, distinguindo sugestões de fatos confirmados.

## Separação de responsabilidades
- IA: interpretação contextual, narração, consulta e propostas.
- Engine NOVTT: fonte autoritativa para regras, dados, estado e validação de ações.
- Mestre: autoridade narrativa, configuração de permissões, resolução de exceções e intervenção.
- A IA não pode acessar banco de dados diretamente nem executar código arbitrário; utiliza ferramentas com contratos estritos e autorização por operação.

## Autonomia configurável por campanha
1. Assistente: consulta, narra e recomenda; sem alteração automática de estado.
2. Copiloto supervisionado: propõe mudanças; alterações relevantes exigem aprovação.
3. Narrador delegado: conduz cenas e pode executar apenas operações previamente autorizadas, sujeitas a limites, validação, auditoria e intervenção do mestre.

A escolha do modo padrão ainda não foi decidida. Regras granulares por ação e por papel devem ser especificadas antes da implementação.

## Arquitetura proposta
- AI Gateway/Orchestrator com adaptadores de provedores (iniciar por formato de API compatível com OpenAI, expandir por adaptadores específicos).
- Cofre de credenciais, limites de consumo, timeout, observabilidade e tratamento de falhas.
- Context Builder + filtro de permissões ANTES de recuperação e envio ao modelo.
- Knowledge Service/RAG com separação de regras, fatos públicos da campanha, conhecimento de personagens e segredos do mestre.
- Ferramentas inicialmente de leitura: consultar personagem, histórico, lore, regras, validar ação.
- Ferramentas mutáveis posteriores: solicitar rolagem, propor/aplicar efeitos, recursos, condições e transferências mediante autorização explícita e verificação da engine.
- PostgreSQL/pgvector como candidato para busca vetorial, sujeito a avaliação técnica.

## Requisitos de segurança e integridade
- Credenciais criptografadas no servidor; nunca expor chaves ao PWA/Android.
- Endpoints personalizados protegidos contra SSRF, redirecionamentos inseguros e acesso à rede interna.
- Isolamento entre campanhas e usuários; segredos do mestre nunca enviados a consultas de jogadores sem autorização.
- Prompt injection em documentos e respostas de modelos tratado como conteúdo não confiável.
- Ações mutáveis com autenticação, autorização, validação de estado/versão, idempotência, transações e auditoria.
- Separar fatos confirmados de sugestões geradas; narrativas não persistem automaticamente como fatos canônicos.
- Transparência sobre envio de dados a terceiros, custos, retenção, privacidade e opção de desabilitar IA.
- Modelo não substitui validação determinística: se regra não for expressável ou houver ambiguidade, sinalizar ao mestre em vez de inventar proibição.

## Fases sugeridas
1. Fundação NOVTT independente de IA: campanhas, fichas, presets, engine, sessões e eventos.
2. BYOAI: configuração segura, chat contextual e ferramentas de leitura.
3. RAG autorizado: documentos, cronologia, personagens e regras.
4. Copiloto: narração, alertas, propostas de mudança e aprovação.
5. Narrador delegado: automação restrita, supervisionável e auditável.

## Critérios de aceite
- Campanha funciona sem provedor de IA.
- IA consulta somente informações autorizadas para o solicitante.
- Ações proibidas pela engine não podem ser executadas por comando da IA.
- Sugestões não alteram o estado sem autorização correspondente ao modo configurado.
- Cada mudança aplicada possui origem, autorização, resultado e registro auditável.
- Falha do provedor não compromete estado nem continuidade da sessão.
- Mestre consegue interromper a delegação e retomar o controle.

## Pendências de detalhamento
- Política exata de autorização para cada ferramenta e papel.
- Modo padrão de autonomia e limites por campanha.
- Contratos de ferramentas, eventos e estados de aprovação.
- Política de custos, cotas e compartilhamento de provedores por jogador/campanha.
- Retenção de conversas, embeddings, dados sensíveis e exclusão.
- Suporte a servidores locais via conexão segura sem exposição direta.
