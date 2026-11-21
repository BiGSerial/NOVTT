# SPEC-PRESETS-001 — Presets declarativos e importação JSON

Status: escopo aprovado para implementação
Data: 2026-11-21
Projeto: NOVTT

## Objetivo
Permitir que campanhas usem presets oficiais mantidos pela equipe NOVTT ou presets personalizados elaborados pelo usuário e importados em JSON. A engine permanece genérica, independente de sistemas específicos e de fornecedores de IA.

## Escopo da primeira etapa
1. Catálogo de presets oficiais produzidos, testados, versionados e publicados pela equipe.
2. Criação de presets personalizados fora da plataforma, diretamente em JSON, inclusive com auxílio de IA externa a critério do usuário.
3. Importação de arquivo JSON, validação e diagnóstico compreensível dos erros, prévia e confirmação.
4. Biblioteca privada de presets do usuário e seleção na criação de campanhas.
5. Contrato declarativo versionado capaz de representar fichas, atributos, habilidades, recursos, dados, fórmulas seguras, modificadores, condições, duração, acumulação e restrições de ações.
6. Mesmo validador e mesma engine para presets oficiais e personalizados.
7. Manual técnico completo e versionado para humanos e agentes de IA, com exemplos, limites, referências, schema e template mínimo.
8. Campanhas vinculadas a versão imutável de preset; mudanças exigem migração explícita e não alteram histórico silenciosamente.

## Artefatos a implementar
- PRESET_MANUAL.md: criação e configuração passo a passo.
- preset.schema.json: contrato JSON Schema versionado.
- preset.example.json: exemplo funcional.
- Validador reutilizável, testes positivos/negativos e testes de compatibilidade.
- Importação segura e interface de prévia/erros.

Os nomes e locais exatos dos artefatos devem seguir a organização do repositório.

## Fora do escopo inicial
- OCR e leitura de fichas por imagem ou PDF.
- Extração automática de regras de manuais.
- IA integrada para gerar presets.
- Editor visual completo de presets.
- Marketplace ou publicação comunitária automática.

## Responsabilidades
NOVTT define e documenta o contrato, valida, armazena e executa presets com segurança. O usuário é responsável por traduzir fielmente a ficha e as mecânicas do sistema desejado para JSON, podendo usar ferramentas e IAs externas. A aprovação técnica do JSON não certifica fidelidade a um RPG original.

## Segurança e qualidade
- JSON apenas declarativo; vedada execução de PHP/JavaScript arbitrário, eval, acesso a arquivos, rede ou operações fora do contexto.
- Validar sintaxe, schema, semântica, referências, limites de tamanho/complexidade, versões e operações permitidas no backend.
- Isolamento de propriedade e autorização; nenhuma importação pode sobrescrever silenciosamente um preset em uso.
- Fórmulas por linguagem restrita, determinística e limitada; cálculo autoritativo no servidor.
- Registrar origem, autoria, versão e trilha de alterações.
- Respeitar direitos autorais e permissões de distribuição; presets privados por padrão.
- Testes automatizados de schema, efeitos, rolagens, condições, versionamento, segurança e rejeição de arquivos malformados.

## Critérios de aceite
- Um usuário consegue importar JSON válido e usá-lo numa campanha sem alterar a engine.
- JSON inválido é rejeitado com indicação clara do caminho e do motivo.
- Um preset oficial e um personalizado equivalentes produzem resultados equivalentes.
- Não é necessário serviço de IA para importar ou executar.
- Atualização de preset não muda campanhas existentes automaticamente.
- O manual e os exemplos são validados contra o mesmo schema usado pela aplicação.

## Princípio de implementação
Não construir antecipadamente OCR, parser de PDF, geração por IA ou editor visual. Projetar contratos extensíveis sem criar infraestrutura desnecessária. Priorizar segurança, desempenho, usabilidade e testes. Vampiro: Idade das Trevas V20 pode ser caso de validação, mas nenhuma regra específica deve ser codificada no núcleo.
