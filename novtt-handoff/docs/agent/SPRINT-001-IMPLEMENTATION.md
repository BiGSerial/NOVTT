# SPRINT-001 | Fundação da engine de presets

**Prioridade:** contratos e testes antes de conteúdo extenso. Não alterar escopo de módulos existentes sem justificativa e testes.

## Entregáveis obrigatórios

1. Inventariar arquitetura, padrões, stack, migrations, serviços, testes e políticas existentes do repositório. Adaptar nomenclaturas deste pacote ao projeto atual sem duplicar abstrações.
2. Implementar manifesto de preset versionado validado por JSON Schema e validações semânticas, com metadados de licença, autoria, revisão das regras e fonte.
3. Implementar schemas de ficha e `CharacterDraft`, com campos/coleções e valores calculados usando AST segura e cache de dependências.
4. Implementar criação em etapas parametrizadas (pools de pontos, escolhas, pré-requisitos, geração de trace de validação).
5. Implementar progresso por **compra por pontos** e **nível/marco**, com transações atômicas, aprovação e registro de eventos.
6. Implementar serviço de rolagens baseado em estratégias declarativas: d20+modificadores e pool d10 por sucessos, incluindo configuração de falhas e críticos.
7. Implementar registros de recursos, itens e efeitos como extensões tipadas, não nomes de sistemas.
8. Implementar controle de visibilidade/editabilidade por usuário e mestre, com locking de ficha vinculada à sessão e aprovação auditável.
9. Criar um demo autoral em JSON que exercite os fluxos, sem conteúdo proprietário.
10. Escrever documentação para autores humanos e agentes, erros estruturados, exemplos válidos/inválidos, testes e passo a passo de execução local.

## Fora do sprint

- Publicar preset com nomes, descrições ou obras proprietárias de Vampire/WoD.
- Copiar livro integral de D&D para compêndio.
- Criar integrações privadas ou scrapers D&D Beyond.
- Construir visualização tática avançada ou simulação 3D.
- Cobrança operacional de assinatura (somente especificar boundary de entitlements).

## Testes de aceitação mínimos

### Teste 1: criação por pool
- Preset autoral tem 3 categorias com orçamento 7/5/3 e atributos com valor inicial 1.
- Tentar alocar acima do orçamento rejeita com erro estruturado.
- Tentar alterar campo calculado rejeita; atualização do campo fonte recalcula dependentes.

### Teste 2: criação por escolha
- Preset de demo tem etapas `identity -> origin -> attributes -> review`.
- Uma escolha permite uma opção e proíbe outra por pré-requisito.
- Ficha incompleta não ativa.

### Teste 3: progressão por compra
- Recurso XP começa com saldo controlado.
- Característica de valor 3 usa regra autoral `current * 4` (custa 12); saldo insuficiente bloqueia.
- Duas requisições simultâneas não fazem débito duplicado.
- Rejeição pelo mestre não debita; aprovação aplica upgrade, débito e auditoria como uma transação.

### Teste 4: progressão por nível
- XP/marco configurado libera nível e benefícios; regras de uma revisão não alteram snapshot antigo.

### Teste 5: rolagem
- Expressão `1d20+5`, pool `5d10` com difficulty variável e cancelamento de 1 são interpretados por estratégias, não branches por game ID.
- Testes deterministicamente reproduzíveis com gerador injetável em testes; aleatoriedade segura em produção.

### Teste 6: permissões
- Jogador não consegue alterar ficha fixada/ativa por API direta fora da política.
- Mestre pode aprovar conforme role; não pode forjar identidade do jogador.
- Histórico registra origem da mudança e snapshot.

### Teste 7: direitos
- Item sem `rights_status=approved` não é publicado.
- Conteúdo CC BY sem atribuição não é publicado.
- Conteúdo World of Darkness marcado `quarantined` não aparece no catálogo público.

### Teste 8: qualidade
- Schemas e exemplos passam no validador.
- Linters e suite de testes existentes passam.
- Documentação humana e para IA explica como criar preset válido e reproduzir casos.

## Padrão de entrega

- Commits pequenos por assunto, sem coautoria inventada.
- Nenhuma alteração silenciosa de decisões; registrar ADR complementar.
- Relatório final com commits, migrações, testes executados, falhas e pendências.


## Complemento vinculante: fidelidade por preset (ADR-002)

A infraestrutura construída nesta sprint deve ser capaz de representar taxonomias oficiais (espécies/raças, classes e subclasses, clãs, Disciplinas, magias, monstros, etc.) e comportamento das regras por edição, sem hardcode no core. Não é exigida nesta sprint a distribuição de dados proprietários. Preparar interfaces de compêndio versionado, vínculos de regras, proveniência e status jurídico; implementar testes com dados autorais e registrar lacunas de expressividade. O futuro preset publicado só poderá afirmar fidelidade conforme resultados de testes de conformidade e direitos efetivamente verificados.
