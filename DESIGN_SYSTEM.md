# Design System — Local Build Platform

Este documento é a fonte de verdade da linguagem visual do painel (`web/`). Ele descreve os
tokens, os componentes compartilhados e as regras de composição. Toda alteração visual deve
respeitar o que está aqui — e atualizar este arquivo quando a regra mudar.

Os tokens vivem em `web/src/index.css` (bloco `@theme` do Tailwind 4). Não crie cores, raios,
sombras ou tamanhos de fonte locais fora desse bloco.

---

## 1. Direção visual

**Ferramenta de operação: precisão, sobriedade, hierarquia e profundidade.**

O painel é usado por horas seguidas para acompanhar filas, investigar falhas e ler logs. A
identidade vem de tipografia, contraste de superfícies, densidade controlada e uso intencional
da cor de destaque — não de gradientes, sombras decorativas ou ilustrações.

- Tema único **claro**, otimizado para uso diurno em estação de trabalho. O escuro é reservado
  ao terminal de logs, onde reduz o cansaço de leitura.
- **Violeta é a cor primária** (`#6d28d9`): botões primários, links, seleção, foco, aba/item
  ativo da sidebar e atividade (build em execução). Aplicada em superfícies sólidas — sem
  gradientes nem glow.
- Latências: nenhuma animação decorativa. Transições de 100 ms para feedback; toasts com 140 ms.
- `prefers-reduced-motion` é respeitado globalmente.

## 2. Princípios de composição

1. **A página é uma região, não uma pilha de caixas.** Conteúdo que pertence à mesma tarefa
   fica junto, separado por espaçamento, tipografia ou divisores discretos.
2. **Superfície antes de borda.** Cards existem por contraste + sombra. Bordas são funcionais
   (linhas de tabela, inputs, divisores de seção, menus).
3. **Hierarquia tipográfica antes de decoração.** Título, seção, corpo, metadado e rótulo têm
   papéis fixos (seção 5).
4. **A densidade é intencional.** Tabelas compactas, formulários agrupados, nada de áreas
   vazias grandes para preencher altura.
5. **Cor comunica estado.** Fora dos estados semânticos, a interface é neutra.
6. **Nada decorativo.** Se um elemento não ajuda a operar (ícone em quadrado colorido, badge de
   metadata, gráfico sem pergunta), ele não existe.

## 3. Paleta

Definida em `web/src/index.css`. Tokens de cor geram utilitários Tailwind
(`bg-canvas`, `text-fg-muted`, `border-line`, `text-accent` etc.).

| Papel | Token | Valor |
| --- | --- | --- |
| Fundo do app | `--color-canvas` | `#edeff1` |
| Painel (sidebar/header) | `--color-panel` | `#fbfbfc` |
| Card / input / modal | `--color-surface` | `#ffffff` |
| Região secundária (chips, cabeçalho de tabela) | `--color-inset` | `#f4f6f7` |
| Hover de interação | `--color-fill-hover` | `#eef1f3` |
| Texto principal | `--color-fg` | `#191c1f` |
| Texto secundário | `--color-fg-muted` | `#4e555e` |
| Texto auxiliar | `--color-fg-subtle` | `#626b78` |
| Linhas funcionais | `--color-line` | `#e4e8ea` |
| Borda de controles | `--color-line-strong` | `#ccd3d8` |
| Primária (destaque) | `--color-accent` / `--color-accent-hover` / `--color-accent-soft` | `#6d28d9` / `#5b21b6` / `#f1edfd` |
| Sucesso | `--color-success` / `--color-success-soft` | `#15803d` / `#edfaf1` |
| Falha | `--color-danger` / `--color-danger-hover` / `--color-danger-soft` | `#b91c1c` / `#991b1b` / `#fdf0f0` |
| Aviso | `--color-warning` / `--color-warning-soft` | `#b45309` / `#fdf7ea` |
| Informação | `--color-info` / `--color-info-soft` | `#1d4ed8` / `#eef4fe` |
| Terminal | `--color-terminal`, `--color-terminal-fg`, `--color-terminal-muted`, `--color-terminal-stderr`, `--color-terminal-system` | `#101418`, `#c9cfd8`, `#98a1af`, `#fca5a5`, `#7dd3fc` |

Contraste: `fg` e `fg-muted` atendem AA sobre qualquer superfície; `fg-subtle` é reservado a
metadados sobre `surface`/`panel`. Nunca use `fg-subtle` para texto essencial sobre `canvas`.

## 4. Tokens de superfície (profundidade)

Quatro níveis, na ordem de profundidade. Não invente níveis intermediários.

| Nível | Uso | Implementação |
| --- | --- | --- |
| 0 — Canvas | Fundo geral da aplicação e das páginas | `bg-canvas` |
| 1 — Painel/secundária | Sidebar, header mobile, cabeçalho de tabela, chips, regiões "inset" | `bg-panel`, `bg-inset` |
| 2 — Card | Superfícies elevadas sobre o canvas | `bg-surface shadow-card rounded-surface` |
| 3 — Overlay | Modais, popovers e toasts | `bg-surface shadow-overlay rounded-overlay` |

Hover de linhas e controles usa `bg-fill-hover`; não é um nível de elevação.

## 5. Tipografia

Família única de interface: **Inter Variable** (self-hosted via
`@fontsource-variable/inter`). Código, IDs, logs, paths e valores técnicos:
**JetBrains Mono Variable** (`@fontsource-variable/jetbrains-mono`). Ambas são empacotadas
pelo Vite — o painel funciona sem rede.

Escala (tokens em `index.css`):

| Token | Tamanho | Uso |
| --- | --- | --- |
| `text-title` | 17 px | Título de página (`PageHeader`) |
| `text-copy` | 14 px | Corpo padrão, títulos de card e modal |
| `text-body` | 13 px | Células de tabela, corpo denso, botões md |
| `text-meta` | 12 px | Labels, ajuda, timestamps, botões sm, texto de tabela secundário |
| `text-micro` | 11 px | Badges, contagens, IDs monoespaçados auxiliares |

Regras:

- Título de página: `text-title font-semibold tracking-[-0.01em]`. **Nunca** use `font-bold`.
- Títulos de card/seção: `text-copy`/`text-body font-semibold`.
- Labels de formulário: `text-meta font-medium`.
- Metadados e descrições: `text-meta text-fg-subtle`.
- Números tabulares: `tabular-nums` (durações, tamanhos, datas).
- Não use letter-spacing decorativo nem caixa alta em títulos. Metadados técnicos em
  monoespaçada (`font-mono text-meta`/`text-micro`).

## 6. Escala de espaçamento

Grade de 4 px (padrão Tailwind). Valores em uso — use somente estes para manter o ritmo:

| Contexto | Valor |
| --- | --- |
| Gap entre seções de página | `gap-4` / `gap-5` |
| Padding de card | `p-4` |
| Padding de célula de tabela | `px-3 py-2` |
| Gap entre campos de formulário | `gap-3` |
| Gap dentro de grupo de botões | `gap-2` |
| Padding de controle (input/botão md) | `px-2.5` / `px-3`, altura `h-8` |
| Botão pequeno | `h-7 px-2.5`, texto `text-meta` |

Conteúdo: largura máxima `1280px` (`AppLayout`); formulários e leitura focada
`max-w-2xl` (`672px`). Tabelas podem ocupar toda a largura.

## 7. Raios

Escala curta e por função — sem `rounded-xl/2xl`:

| Token | Valor | Uso |
| --- | --- | --- |
| `rounded-sm` | 4 px | Badges, indicadores, hover de linha do terminal |
| `rounded-control` | 6 px | Botões, inputs, selects, textareas, tabs, chips, ícones |
| `rounded-surface` | 8 px | Cards, painéis de log |
| `rounded-overlay` | 10 px | Modais e popovers |

Exceção: pontos de status usam `rounded-full` (são círculos).

## 8. Elevação

Somente dois níveis de sombra. A sombra **sempre** acompanha o contraste de superfície.

| Token | Uso |
| --- | --- |
| `shadow-card` | Card padrão sobre o canvas (`0 1px 2px` + `0 2px 6px`) |
| `shadow-overlay` | Modal, toast, dropdown (`0 4px 12px` + `0 16px 40px`) |

Não aplique sombra em botões, inputs, linhas de tabela, badges ou na sidebar.

## 9. Regras para cards

Use `Card` (`web/src/components/ui.tsx`) somente quando existir um grupo de conteúdo
independente que precise de superfície própria (tabela, formulário, painel de logs,
resumo de execução, indicador operacional). Justificativas aceitas: agrupar conteúdo
relacionado, isolar área de interação, destacar informação.

Um card deve ser definido por **contraste + `shadow-card`**, sem borda externa. O único
divisor permitido dentro de um card é o `border-b border-line` entre cabeçalho e corpo
(ou divisores de seção `border-t` quando o card agrupa blocos).

Proibido:

- Card dentro de card. Para separar conteúdo interno use tipografia, `bg-inset`, `border-t`
  ou espaçamento.
- Um card por campo de formulário, por linha de tabela, por estatística ou por metadado.
- Card apenas porque existe um componente React na região.
- Borda fina contornando todos os cards.

Exemplo correto — cabeçalho + tabela em uma única superfície:

```tsx
<Card title="Perfis de build" actions={<Button size="sm">Novo perfil</Button>} bodyClassName="p-0">
  <TableShell>…</TableShell>
  <div className="border-t border-line px-3 py-2">
    <Pagination … />
  </div>
</Card>
```

Exemplo incorreto — cada linha vira um card, cada campo vira um card:

```tsx
{profiles.map((p) => (
  <Card key={p.id}>…</Card>
))}
<Card><Field label="Nome">…</Field></Card>
<Card><Field label="Slug">…</Field></Card>
```

## 10. Regras para tabelas

Componentes: `TableShell`, `Th`, `Td`.

- Cabeçalho: `h-9`, `bg-inset`, `text-meta font-medium text-fg-subtle`, sem caixa alta.
- Linha: `px-3 py-2.5`, ~40 px de altura, `text-body`, divisor apenas inferior
  (`border-b border-line`); a última linha não tem divisor (regra global em `index.css`).
- Hover de linha: `hover:bg-fill-hover/60` quando a linha é navegável.
- Alinhamento: texto à esquerda, números/datas à direita com `tabular-nums`.
- Colunas técnicas (SHAs, paths, branches) em `font-mono text-meta`.
- Ações por linha: `IconButton` (com `label`) à direita — nunca botões de texto repetidos.
- Paginação fica no rodapé do mesmo card, separada por `border-t border-line`.
- Estado vazio usa `EmptyState` dentro do card (com ação de continuidade quando existir); erro usa
  `ErrorState`; carregamento de listagens usa `TableSkeleton` (nunca uma tabela vazia sem mensagem).
- Carregamento preserva a estrutura da tela: `TableSkeleton` para tabelas, `Skeleton` para
  cartões, formulários e páginas de detalhe. Spinner fica reservado a botões e autenticação —
  não substitua a página inteira por um spinner quando for possível esqueletizar o layout.
- Tabelas têm `overflow-x-auto`; em telas pequenas a rolagem é horizontal, sem esconder colunas.

## 11. Componentes compartilhados

Todos em `web/src/components/`:

| Componente | Responsabilidade |
| --- | --- |
| `Button`, `ButtonLink`, `buttonClass`, `IconButton` | Ações e links de ação |
| `Field`, `Input`, `Textarea`, `Select` | Formulários com label, ajuda e erro |
| `Card` | Superfície de nível 2 com cabeçalho opcional |
| `Badge`, `StatusText` | Estado semântico (pill e texto com ponto) |
| `PageHeader` | Título, breadcrumb, badge, descrição e ações de página |
| `Tabs` | Navegação por abas com teclado (setas) e `aria-selected` |
| `MetaList`, `MetaItem` | Dicionário de metadados (label → valor) |
| `TableShell`, `Th`, `Td` | Tabelas de dados |
| `Spinner`, `Skeleton`, `TableSkeleton` | Carregamento (botões, cartões, tabelas) |
| `EmptyState`, `ErrorState` | Estados vazios e de erro (título derivado do status HTTP) |
| `MenuButton` | Menu de ações contextuais (role=menu, setas, Esc, clique fora) |
| `Pagination` | Navegação de páginas compacta |
| `Modal`, `ConfirmDialog` | Overlay nível 3 com Esc, foco e bloqueio de scroll |
| `ToastProvider`, `useToast` | Notificações transitórias (`aria-live`) |
| `BuildStatusBadge`, `RunnerStatusBadge`, `WebhookStatusBadge` | Estados de domínio |
| `LogViewer` | Terminal de logs (SSE + fallback de polling) |

Antes de criar um componente, verifique se um destes já resolve (com variante nova, se fizer
sentido). Não duplique `Button`/`Card`/tabela em uma feature.

Notas de uso:

- **Indicadores do dashboard** são links para a listagem filtrada correspondente
  (`/builds?status=...`); nunca crie indicador clicável sem destino real.
- **MenuButton** agrupa ações secundárias ou perigosas (ex.: desativar/remover projeto). Ações
  frequentes permanecem visíveis como botões.
- **Erros de tela** derivam o título do status HTTP (`404` → "Não encontrado", `403` →
  "Sem permissão", `5xx` → "Erro no servidor", falha de rede → "Falha de comunicação").

## 12. Variantes de botões

| Variante | Aparência | Uso |
| --- | --- | --- |
| `primary` | Fundo `accent` (violeta), texto branco | Ação principal da tela (uma por região) |
| `secondary` | Superfície + borda `line-strong` | Ações de apoio, download, cancelar |
| `ghost` | Sem fundo, texto `fg-muted` | Ações terciárias, "limpar filtros" |
| `danger` | Fundo `danger`, texto branco | Ação destrutiva confirmada |
| `IconButton` | Quadrado `size-7/8`, sem fundo | Ações por linha/página; exige `label` |

- Tamanhos: `sm` (`h-7`, `text-meta`) e `md` (`h-8`, `text-body`). Não crie alturas novas.
- Ícones em botão: `size-3.5` (sm) ou `size-4` (md), sempre `aria-hidden` quando acompanhados
  de texto.
- Botão só de ícone **sempre** tem `aria-label` e `title` (o `IconButton` exige `label`).
- Ações destrutivas usam `ConfirmDialog`; nunca executam direto no clique.

## 13. Estados semânticos

Um mesmo estado tem a mesma apresentação em todo o painel (`StatusBadge.tsx`):

| Estado | Build | Runner | Webhook |
| --- | --- | --- | --- |
| neutro | `queued` | `offline` | — |
| `info` | `preparing` | — | `duplicate` |
| `accent` (violeta, pulsa em execução) | `running` | — | — |
| `success` | `success` | `online` | `accepted` |
| `danger` | `failed` | `disabled` | `rejected`, `error` |
| `warning` | `cancelled` | — | `ignored` |

Badges/pills sempre incluem **texto + ponto colorido**; a cor nunca é o único indicador.
Estado booleano (projeto/perfil ativo) usa `StatusText`, não uma badge.

## 14. Responsividade

- **Desktop largo:** sidebar de `232px`, recolhível para `60px` (preferência persistida em
  `localStorage`); no modo recolhido os rótulos viram `sr-only` e cada item expõe `title`.
  Conteúdo centralizado em até `1280px`.
- **Notebook/tablet:** mesmos componentes; grids de formulário passam de 2–3 colunas para 1.
- **Mobile (`< md`):** a sidebar vira cabeçalho com marca + navegação horizontal rolável;
  ações de página quebram linha (`flex-wrap`); tabelas rolam horizontalmente; modais ocupam
  a largura com margem de `16px` e altura limitada (`max-h-[65vh]` com rolagem interna).
- Cuidado com truncamento: em grid/flex, o item que contém texto longo precisa de `min-w-0`.
- Nunca esconda informação essencial: prefira rolagem ou quebra de linha a `hidden`.

## 15. Acessibilidade

- Contraste mínimo AA. `fg-subtle` não é usado para texto essencial sobre `canvas`.
- Foco visível padronizado pela classe `focus-ring` (contorno de 2 px em `accent/70` com
  offset). Nunca remova o foco sem alternativa.
- Todo campo tem label associado via `Field` (o label envolve o controle).
- Erros de formulário usam `role="alert"` e aparecem próximos ao campo (via `Field`).
- Modais: `role="dialog"`, `aria-modal`, título acessível, fecham com `Esc`, recebem foco ao
  abrir e devolvem o foco ao fechar; o scroll do body é bloqueado enquanto abertos.
- Ícones puramente decorativos usam `aria-hidden`; controles de ícone têm nome acessível.
- Tabs usam `role="tablist"`, `aria-selected` e navegação por setas.
- Toasts vivem em uma região `aria-live="polite"`.
- `prefers-reduced-motion` desativa animações e transições globalmente.
- Áreas interativas têm no mínimo 28 px (`h-7`) — acima do mínimo WCAG 2.2 de 24 px.

## 16. Anti-patterns proibidos

- Card para cada seção/indicador/campo; cards aninhados.
- Borda fina como contorno padrão de cards; bordas decorativas em listas e navegação.
- Sombra quase invisível em card ou sombra em todos os elementos.
- Gradientes decorativos, glow, ilustrações abstratas, emojis como ícones.
- Ícone dentro de quadrado/círculo colorido sem função.
- Badge colorida para nome, data, identificador ou metadata.
- Quatro (ou sete) cards idênticos de estatística com ícone dentro de quadrado colorido,
  número gigante, label minúsculo e variação percentual inventada. Métricas reais em cards
  separados, com ícone direto e número proporcional, são aceitáveis.
- Números/variações percentuais inventados.
- Grafos sem pergunta operacional.
- `rounded-xl/2xl` em tabelas, sidebar ou regiões grandes.
- `font-bold` e títulos enormes para dar ênfase.
- Espaçamento de landing page, hero, frase de marketing.
- Animações de bounce/escala; skeleton chamativo.
- Spinner de página inteira quando um skeleton preserva a estrutura da tela.
- Componente duplicado com pequenas variações entre páginas.
- Elemento sem função (busca global vazia, sino sem notificação, avatar decorativo).

## 17. Exemplos

**Correto — dashboard operacional**

```tsx
<PageHeader title="Dashboard" description="1 projeto · 1 ativo · 0/1 runner online" … />

{/* Uma métrica por card, com ícone direto e dado real — sem quadrado colorido */}
<div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
  <div className="rounded-surface bg-surface p-3.5 shadow-card">
    <div className="flex items-center gap-1.5 text-accent">
      <Play className="size-3.5 shrink-0" aria-hidden />
      <p className="truncate text-meta">Executando</p>
    </div>
    <p className="mt-2 text-xl font-semibold tabular-nums text-accent">2</p>
  </div>
  …
</div>
```

**Incorreto — coleção de caixas**

```tsx
{stats.map((s) => (
  <div className="rounded-xl border bg-white p-4 shadow-sm">
    <div className="flex size-10 items-center justify-center rounded-lg bg-indigo-100 text-indigo-600">
      <Icon />
    </div>
    <p className="text-3xl font-bold">{s.value}</p>
    <p className="text-[10px] uppercase text-gray-400">{s.label}</p>
  </div>
))}
```

**Correto — formulário em seções dentro de um card**

```tsx
<Card>
  <form className="flex flex-col gap-5">
    <section className="flex flex-col gap-3">
      <h3 className="text-body font-semibold">Identificação</h3>
      <div className="grid gap-3 sm:grid-cols-2">…</div>
    </section>
    <section className="flex flex-col gap-3 border-t border-line pt-4">
      <h3 className="text-body font-semibold">Repositório</h3>
      …
    </section>
  </form>
</Card>
```

**Incorreto — campos em caixas**

```tsx
<div className="rounded-lg border p-3 shadow-sm"><Field label="Nome">…</Field></div>
<div className="rounded-lg border p-3 shadow-sm"><Field label="Slug">…</Field></div>
```

**Correto — status**

```tsx
<BuildStatusBadge status={build.status} label={build.status_label} />
<StatusText tone="success">Ativo</StatusText>
```

**Incorreto — cor como único sinal e badge para tudo**

```tsx
<span className="text-green-600">●</span>
<Badge tone="accent">{project.name}</Badge>
<Badge>{formatDate(project.created_at)}</Badge>
```
