# LICENSE-MATRIX: licenciamento, atribuição e publicação de presets

**Natureza:** orientação de engenharia e triagem de compliance, não parecer jurídico. Revisar antes do lançamento.  
**Fontes oficiais verificadas:** 2026-11-23.

## D&D: SRD 5.1 / 5.2.1

- Wizards of the Coast disponibiliza SRD 5.1 e SRD 5.2.1 sob CC BY 4.0; permite uso, adaptação, publicação comercial e VTTs, observando a atribuição e demais termos.
- Fonte oficial: https://www.dndbeyond.com/srd
- FAQ oficial: https://www.dndbeyond.com/creator-faq
- CC BY 4.0: https://creativecommons.org/licenses/by/4.0/legalcode
- Não presumir que livros comerciais ou D&D Beyond Basic Rules sejam integralmente CC BY 4.0.
- Elementos deliberadamente ausentes do SRD 5.2.1, como Beholder, Artificer e Aasimar, não devem ser importados como se licenciados pelo SRD.
- Preservar a declaração de atribuição **exata** exigida na abertura de cada SRD, sem improvisar substituições. Incluir links, mudanças, licença e registro de versão.
- Evitar logotipos oficiais e alegações de afiliação. A documentação oficial do SRD 5.2.1 permite indicar compatibilidade com a quinta edição e fornece orientação específica sobre atribuição; verificar sempre a redação publicada.
- Separar `dnd-5e-2014` (SRD 5.1) de `dnd-5e-2024` (SRD 5.2.1), mas não exigir duas engines.

## World of Darkness / Vampire

- Fonte oficial: https://www.paradoxinteractive.com/games/world-of-darkness/community/dark-pack-agreement
- O Dark Pack abrange certos projetos de fãs **não comerciais**, inclusive fichas, geradores, roladores e RPGs online.
- A política diz que aplicativos/geradores do programa devem ser gratuitos, sem compras dentro do aplicativo nem outras transações monetizadas.
- Há previsão de doações voluntárias para custeio de jogos comunitários em contextos específicos; isso **não equivale** a autorização geral para assinatura em SaaS.
- Nomes de facções/clãs, marcas, ambientações e outros elementos são expressamente reconhecidos pela Paradox como parte de sua IP; a licença permite usos **condicionados**, não sublicença geral e irrestrita.
- Ao utilizar Dark Pack num projeto elegível, verificar exigências vigentes do logo, aviso de direitos e indicação de não oficialidade.
- Storytellers Vault: https://www.paradoxinteractive.com/games/world-of-darkness/business/storytellers-vault (programa específico de publicação, não licença geral de SaaS).
- Unbound: https://www.paradoxinteractive.com/games/world-of-darkness/business/world-of-darkness-unbound (acordo específico, não liberação geral de V20/V5 em SaaS).
- Canal de licenciamento: https://www.paradoxinteractive.com/games/world-of-darkness/business/our-partners

## Matriz de publicação

| Pacote/conteúdo | Desenvolvimento interno | Distribuição pública | Observação |
|---|---|---|---|
| Engine genérica de atributos, dados, XP e efeitos | Sim | Sim | Código original; verificar dependências de terceiros |
| Demo autoral pool d10 / progressão por pontos | Sim | Sim | Sem conteúdo expressivo e marca de terceiros |
| SRD D&D 5.1 ou 5.2.1 devidamente atribuídos | Sim | Sim | Respeitar CC BY 4.0 e escopo exato do SRD |
| Livros integrais D&D / conteúdo exclusivo D&D Beyond | Pesquisa autorizada conforme acesso | Não automaticamente | Ausência do SRD importa |
| Preset com clãs, Disciplinas e conteúdo WoD | Prototipagem interna conforme direitos aplicáveis | **Bloqueado até avaliação/licença** | Dark Pack não autoriza implicitamente SaaS premium |
| Artes, marcas e símbolos de terceiros | Caso a caso | Caso a caso | Direitos e usos distintos das regras |
| Conteúdo criado por usuário | Conforme termos da plataforma | Só com autorização do criador e direitos pertinentes | Não presumir sublicença |

## Regras de compliance no software

- Cada `compendium_item` declara `license_id`, `source_id`, `rights_status`, `attribution_ref`, `rules_revision`, `locale`, `content_hash`.
- Estados: `draft`, `rights_review`, `approved`, `quarantined`, `published`, `retired`.
- Publicação é barrada se falta licença/proveniência/atribuição ou existe `rights_status != approved`.
- Não fazer scraping não autorizado de sites/livros fechados.
- Preservar citações e documentação legal no app e no pacote exportado.
- Registrar data de consulta da licença e versão da política, sobretudo para acordos revogáveis.

## Consulta recomendada à Paradox

Perguntar formalmente se ferramenta universal com cadastro gratuito, uso gratuito dos presets e assinatura **apenas para IA, armazenamento e UI** pode disponibilizar conteúdo nomeado de World of Darkness sob Dark Pack. Descrever claramente o produto, UI premium, ausência de venda de presets e acesso gratuito às fichas. Solicitar resposta escrita; sem resposta favorável, não disponibilizar os elementos proprietários sob o programa na oferta premium.

## Referência legislativa brasileira

Lei 9.610/1998, art. 8º (ideias, métodos, sistemas e regras de jogos não são, por si, objeto da proteção autoral): https://www.planalto.gov.br/ccivil_03/leis/l9610.htm . Isso não resolve proteção marcária, expressão criativa, contrato/licença, concorrência desleal nem distribuição internacional.
