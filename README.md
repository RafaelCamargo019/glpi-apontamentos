# Apontamentos para GLPI 11

**Autor:** Rafael - Mkdata  
**Versão:** 2.9.0  
**Uso:** interno da empresa

Plugin de apontamentos de horas por intervalo. A entidade do GLPI controla o
escopo e a segurança; o tipo de apontamento possui cadastro global próprio com
nome e cor. A instalação cria ou amplia as tabelas de forma condicional. A
desinstalação preserva tabelas, dados e direitos configurados.

## Relatório gerencial

A área **Relatório / Exportar** calcula jornada esperada, horas apontadas,
horas não apontadas, excedente e percentual de ocupação por dia e usuário.
Como o plugin não armazena entrada, saída ou intervalo, as horas não apontadas
são uma diferença calculada, e não intervalos exatos de inatividade.

O painel oferece filtros de dia, semana, mês e período personalizado (até 93
dias), gráficos, consolidação paginada, CSV detalhado, CSV gerencial e PDF. O
PDF é gerado pelo próprio plugin, sem carregar bibliotecas pela internet.

O relatório usa todas as entidades autorizadas na sessão, sem apresentar um
filtro de entidade. O filtro **Tipo de vínculo** reúne Chamado, Problema,
Mudança e Projeto; os campos de projeto e tarefa opcional aparecem somente
quando Projeto é selecionado. A interface e as exportações identificam as
pessoas como **Usuários**.

Registros excluídos são ignorados. Se existirem sobreposições históricas, a
listagem preserva todos os registros, mas os indicadores contam a união dos
intervalos e avisam o gestor.

## Direitos

- Ler, criar, atualizar e excluir controlam operações sobre apontamentos próprios.
- **Gerenciar apontamentos de outros usuários** permite operar registros de outros
  usuários, sempre limitado às entidades acessíveis na sessão.
- A exclusão definitiva remove somente o apontamento autorizado; purge genérico
  do GLPI não é oferecido.

## Vínculos

O par `itemtype/items_id` aceita `Ticket`, `Problem` ou `Change`. Projeto e tarefa
usam `projects_id` e `projecttasks_id`; a tarefa deve pertencer ao projeto.

## Horários do formulário

O formulário apresenta três controles independentes e compactos na mesma linha:
`Data`, `Início` e `Fim`. A data aparece uma única vez e os dois horários exibem
somente hora e minuto. No servidor, o plugin reconstrói `begin_time` e
`end_time` usando exclusivamente esses três valores validados; valores completos
forjados para essas colunas são ignorados. O fim deve ser posterior ao início e
o intervalo deve permanecer no mesmo dia.

O mesmo formulário central é reutilizado na criação, na edição e nas abas de
apontamentos de chamados, problemas, mudanças e projetos. Ao abrir pelo
calendário, a seleção é convertida automaticamente para os três controles.

## Calendário

A página principal oferece visualizações de mês, semana e dia. A semana é o
padrão. O endpoint `ajax/events.php` aceita apenas intervalos de 1 a 42 dias e
aplica os direitos de usuário e entidade antes de consultar os registros.

Na tela principal, `Novo apontamento` abre um modal acessível sobre o calendário
sem trocar de página. O formulário compacto apresenta conteúdo, tipo, data e
horários; vínculos ITIL, projeto e tarefa ficam em `Exibir detalhes`. A criação é
enviada a `ajax/create.php`, que responde somente JSON, valida CSRF e reutiliza
as mesmas permissões e validações do formulário tradicional. Após o sucesso, o
calendário, os totais e as cores de jornada são recarregados sem perder usuário,
data ou visualização. A rota tradicional continua disponível como fallback.

Em `Vincular a`, **Projeto** aparece junto de Chamado, Problema e Mudança para
usuários com a permissão correspondente. Os campos **Projeto** e **Tarefa do
projeto** permanecem ocultos até essa opção ser escolhida; ao voltar para um
vínculo ITIL, o projeto anterior é descartado para impedir vínculos misturados.

Nas abas **Apontamentos** de chamados, problemas, mudanças e projetos, o mesmo
modal é aberto sobre o registro atual. O vínculo aparece bloqueado e é imposto
novamente no servidor: um modal aberto no Chamado #1 só pode criar um
apontamento para o Chamado #1; quando aberto pelo Projeto #1, o projeto permanece
fixo e somente a tarefa opcional pode ser selecionada entre as tarefas desse
projeto. Depois de salvar, a listagem da aba é atualizada por AJAX sem abrir
outra página. Para apontar outro registro ou projeto, é necessário abrir a aba
desse outro chamado, problema, mudança ou projeto.

Os totais incluem todos os apontamentos visíveis. Registros removidos não
compõem os totais. Não há carregamento por
CDN nem alteração de arquivos do núcleo do GLPI.

## Configurações e relatório

A versão 2.1 introduziu os seletores de chamados, problemas e mudanças, que usam
registros existentes. Cada usuário possui uma única jornada semanal, sem
entidade ou data de vigência. Desde a versão 2.8, a cor pertence ao tipo de
apontamento e não à entidade.

A exportação CSV exige direito próprio, respeita usuários e entidades acessíveis,
limita o período a 366 dias e neutraliza valores que poderiam ser interpretados
como fórmulas pelo Excel ou LibreOffice. As tabelas auxiliares são criadas apenas
quando ausentes e também são preservadas na desinstalação.

## Versão 2.2

- O calendário usa somente a entidade ativa da sessão e não exibe filtro de entidade.
- A meta diária é vermelha enquanto incompleta e verde quando atingida, inclusive no dia atual; dias futuros, sem expediente ou sem configuração ficam neutros.
- O conteúdo do apontamento é opcional.
- Projeto e tarefa exigem o direito independente `plugin_apontamentos_project`.
- O atalho superior foi identificado como “Relatório / Exportar”.

## Versão 2.3

O fluxo de cancelamento foi removido. Apontamentos removidos não aparecem no
calendário nem nos relatórios. Registros cancelados de versões anteriores são
marcados como excluídos durante a atualização, sem remoção da tabela.

## Versão 2.4

O conceito funcional de status foi removido. Todo apontamento visível pode ser
editado ou excluído, sem ação de finalizar. A coluna `status` permanece somente
por compatibilidade com o esquema legado e não é exibida nem exportada.

## Versão 2.4.1

Vínculos ITIL e de projeto usam a permissão nativa de leitura do GLPI, sem
uma segunda comparação de entidade que rejeitava registros acessíveis em
estruturas recursivas. O mesmo chamado ou projeto pode ser usado em diversos
apontamentos.

## Versão 2.4.2

A leitura de vínculos possui uma verificação segura alternativa para objetos
ITIL: direito de leitura do perfil e acesso à entidade. Isso corrige retornos
inconsistentes da autorização genérica na rota do plugin sem liberar registros
de entidades ou tipos aos quais o usuário não tenha acesso.

## Versão 2.4.3

A verificação de sobreposição considera usuário e entidade. Apontamentos
de outras entidades, que não aparecem no calendário da entidade ativa, não
bloqueiam o mesmo intervalo.

## Versão 2.4.4

Cada cartão de apontamento exibe o nome da entidade selecionada nas visões de
mês, semana e dia. A entidade raiz recebe um rótulo explícito quando o cadastro
do GLPI não retorna um nome.

## Versão 2.5.0

- O início e o fim de um apontamento devem pertencer ao mesmo dia.
- Os cartões possuem ações independentes de editar e excluir; a exclusão usa
  POST, CSRF e permissão DELETE.
- O corpo do cartão abre o chamado, problema ou mudança quando o usuário pode
  ler o vínculo; sem vínculo acessível, abre a edição do apontamento.
- As horas totais ficam verdes quando a meta é atingida e vermelhas enquanto
  incompletas; dias futuros, sem expediente ou sem jornada permanecem neutros.
- O cabeçalho e a grade semanal compartilham a mesma definição de colunas e a
  mesma área rolável, mantendo as divisórias alinhadas.

## Versão 2.5.1

O formulário de criação apresenta somente a ação visual **Salvar**. O
primeiro clique insere o apontamento e retorna diretamente ao calendário com
uma confirmação, eliminando a impressão de um segundo salvamento obrigatório.

## Versão 2.5.2

Esta versão havia removido o bloqueio de sobreposição. O comportamento foi
posteriormente corrigido: um mesmo usuário não pode mais possuir dois
apontamentos que ocupem simultaneamente qualquer parte do mesmo intervalo.
Horários consecutivos, nos quais um registro começa exatamente quando o outro
termina, continuam permitidos.

## Versão 2.5.3

Após criar, o calendário passa para a entidade, o usuário e a data do registro
salvo. Isso garante que o apontamento confirmado seja exibido imediatamente,
mesmo quando foi criado para uma entidade diferente da que estava ativa.

## Versão 2.5.4

Vínculos ITIL continuam visíveis quando o calendário muda para a entidade do
apontamento e o usuário possui perfil autorizado na entidade do item. O cartão
também apresenta o tipo, o ID e o título do chamado, problema ou mudança.

## Versão 2.6.0

- O formulário não exibe usuário: novos apontamentos pertencem sempre à sessão
  autenticada e a edição preserva o proprietário original.
- Entidade e ao menos um vínculo (registro relacionado ou projeto) são
  obrigatórios; conteúdo continua opcional.
- Os cartões mantêm entidade e cor, sem repetir usuário nem exibir legenda.
- A cor utiliza a configuração mais recente da entidade.
- A jornada passa a ser global e única por usuário. O instalador mantém as
  tabelas históricas e copia a configuração antiga mais recente para a nova
  tabela somente quando o usuário ainda não possui jornada global.

## Versão 2.6.1

Na configuração, a troca de entidade ou usuário carrega imediatamente a cor
ou a jornada correspondente, sem botões intermediários de carregamento.

## Versão 2.6.2

Os cartões do calendário usam somente o preenchimento da cor da entidade,
sem a antiga faixa de borda lateral.

## Versão 2.6.3

Apontamentos com duração inferior a uma hora usam uma apresentação compacta
em linha única, com horário, entidade e ações menores, sem conteúdo cortado.

## Versão 2.6.4

Nos cartões, o chamado, problema ou mudança vinculado aparece antes da
entidade, inclusive na apresentação compacta.

## Versão 2.6.5

No formulário de criação e edição, o campo técnico de entidade é apresentado
ao usuário com o rótulo “Tipo de apontamento”.

## Versão 2.7.0

- Chamados, problemas, mudanças e projetos recebem uma aba oficial
  **Apontamentos**, sem alteração de arquivos do núcleo do GLPI.
- A aba lista somente registros visíveis do objeto atual, respeitando direitos,
  usuário, entidades ativas e a exclusão definitiva.
- O botão **Novo apontamento** abre o formulário com a origem preenchida e
  validada novamente no servidor.
- Criação, edição e exclusão iniciadas pela aba retornam à mesma aba quando o
  vínculo continua válido; o retorno é construído apenas com URLs oficiais.

## Versão 2.7.1

Na aba contextual, a listagem não exibe o usuário nem o conteúdo do
apontamento. A antiga posição do conteúdo passa a mostrar **Tipo de vínculo**,
identificando o registro como chamado, problema, mudança ou projeto.

## Versão 2.7.2

As abas contextuais de chamados, problemas e mudanças também reúnem os
apontamentos dos objetos ITIL diretamente relacionados no GLPI. Cada vínculo é
revalidado quanto à existência e à permissão de leitura, e a coluna **Tipo de
vínculo** identifica a origem de cada linha.

## Versão 2.7.3

- Dias sem apontamentos permanecem neutros, independentemente de serem
  anteriores, atuais ou futuros.
- Quando existe ao menos um apontamento, o total é comparado à jornada daquele
  dia da semana em qualquer data: abaixo da meta fica vermelho e, ao atingir ou
  superar, fica verde.
- Após uma exclusão, eventos, totais e estados de jornada são recarregados do
  servidor, evitando que a cor anterior permaneça no calendário.

## Versão 2.8.0

- Entidade e tipo de apontamento são conceitos separados. `entities_id` volta a
  representar somente a entidade real do GLPI, enquanto `appointmenttypes_id`
  referencia o cadastro global `glpi_plugin_apontamentos_types`.
- A configuração permite criar, editar, ativar, desativar e excluir logicamente
  tipos, cada um com sua própria cor hexadecimal. O calendário e as abas
  contextuais usam o novo tipo; a jornada permanece independente.
- Relatório e CSV apresentam tipo e entidade em colunas separadas e permitem
  filtrar pelo tipo.
- A versão original dessa migração previa uma limpeza única dos registros
  anteriores. A versão final 2.9.0 substitui esse comportamento por uma
  migração não destrutiva que associa o tipo padrão aos registros legados.

## Versão 2.8.1

- O formulário não exibe mais o campo de entidade. Novos apontamentos usam
  automaticamente a entidade ativa da sessão do GLPI, validada novamente no
  servidor, e a edição preserva a entidade original.
- O tipo de apontamento permanece visível e obrigatório. Valores de usuário ou
  entidade enviados manualmente por POST são ignorados.
- As mensagens de criação, edição e exclusão são controladas pelo plugin, sem
  expor o ID técnico do registro. A troca do usuário no calendário atualiza os
  dados automaticamente e preserva a data e o modo de visualização.

## Versão 2.9.0

- Release interna estável, identificada com a autoria **Rafael - Mkdata**.
- Instalação e atualização não apagam apontamentos. Registros legados sem tipo
  recebem automaticamente um tipo válido e permanecem disponíveis.
- Relatório reorganizado sem filtro de entidade, com a identificação
  **Usuário** e os filtros de vínculo, registro, projeto e tarefa alinhados.
- Modal contextual padronizado no calendário e nas abas de chamados,
  problemas, mudanças e projetos, preservando o vínculo do registro aberto.
- Pacote preparado para instalação direta no diretório
  `plugins/apontamentos` do GLPI 11.

## Instalação e atualização

1. Faça backup do banco de dados e do diretório atual do plugin.
2. Extraia o pacote mantendo exatamente o diretório `apontamentos` dentro de
   `glpi/plugins`.
3. Ajuste o proprietário dos arquivos para o mesmo usuário do servidor web.
4. Instale ou atualize e ative o plugin pelo console do GLPI:

```sh
php bin/console glpi:plugin:install --force apontamentos
php bin/console glpi:plugin:activate apontamentos
php bin/console glpi:plugin:list
```

A instalação cria apenas estruturas ausentes, preserva as tabelas e os dados
existentes e cadastra o tipo padrão `Geral` somente quando necessário. A
desinstalação também preserva tabelas, dados e direitos. Tipos de apontamento,
jornadas e permissões específicas devem ser configurados em cada ambiente.

## Testes locais

```sh
find . -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/read_only_test.php
```
