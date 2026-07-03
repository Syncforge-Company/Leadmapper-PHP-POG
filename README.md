# LeadMapper Manaus

Sistema em PHP para prospeccao local de empresas em Manaus usando `SerpAPI` com `engine=google_maps`.

O objetivo e encontrar empresas/comercios, identificar oportunidades digitais e gerar uma proposta comercial inicial para venda de:

- landing page
- site institucional
- sistema simples

## O que o sistema faz

- busca empresas por nome ou segmento
- consulta dados via `SerpAPI`
- retorna:
  - nome
  - telefone
  - website
  - endereco
  - nota
  - quantidade de reviews
  - categoria
  - score de oportunidade
- destaca empresas sem website
- ordena leads por score
- gera proposta comercial automatica
- exporta resultados em CSV

## Stack atual

- `PHP` em arquivo unico
- frontend no proprio `index.php`
- consumo de API via backend PHP
- `SerpAPI` como fonte principal dos dados

## Estrutura

- [index.php](C:/Users/Peterson/Desktop/work/syncforge/leadmapper/index.php): aplicacao completa
- [README.md](C:/Users/Peterson/Desktop/work/syncforge/leadmapper/README.md): documentacao do projeto
- [.env](C:/Users/Peterson/Desktop/work/syncforge/leadmapper/.env): configuracao local da chave da API

## Requisitos

- PHP instalado
- chave da `SerpAPI`

## Configuracao

Crie ou edite o arquivo `.env` na raiz do projeto:

```env
SERP_API_KEY=sua_chave_aqui
MANAUS_COORDS=-3.1190,-60.0217
```

### Variaveis

- `SERP_API_KEY`
  - chave da SerpAPI
- `MANAUS_COORDS`
  - coordenadas base usadas na busca
  - padrao atual: `-3.1190,-60.0217`

## Como rodar

Na pasta do projeto:

```powershell
composer dev
```

Esse comando inicia o servidor em background e retorna imediatamente, evitando timeout em processos longos.

Para parar o servidor:

```powershell
composer dev:stop
```

Alternativa sem Composer:

```powershell
php -S localhost:8000 -t .
```

Depois abra no navegador:

```txt
http://localhost:8000/index.php
```

## Como funciona a busca

Quando voce pesquisa:

- se digitar um termo, o sistema busca algo como:
  - `"<sua busca> em Manaus AM"`
- a consulta vai para:
  - `SerpAPI`
  - `engine=google_maps`

O backend recebe os resultados em `local_results`, calcula um score e manda para o frontend.

## Busca textual e filtros locais

- os campos digitaveis aceitam busca parcial
- a comparacao ignora maiusculas/minusculas
- a comparacao ignora acentos
- o filtro local tenta tolerar pequenos erros de digitacao
- bairros com variacoes como `II` e `2` passam a combinar entre si

Exemplos:

- `coro` encontra `Coroado`
- `adrianopolis` encontra `Adrianópolis`
- `coroado 2` encontra `Coroado II`

## Regra atual de score

O score e calculado no backend com a logica:

- `+50` se nao possui website
- `+20` se possui telefone
- `+20` se nota for `>= 4.3`
- `+10` se reviews forem `>= 20`

Depois disso:

- os leads sao ordenados do maior para o menor score
- o frontend destaca visualmente os mais quentes

## Fluxo da interface

### Aba `Empresas`

- exibe resultados da busca
- mostra score
- mostra nota
- mostra reviews
- destaca `Sem site`
- destaca `Lead quente`

### Aba `Proposta`

- permite gerar uma proposta comercial inicial
- usa os dados do lead retornado pela busca
- gera texto comercial para abordagem

### Aba `API Google`

- hoje funciona mais como area informativa
- o sistema ja foi adaptado para `SerpAPI`, entao esse texto pode ser revisado depois se quiser deixar 100% coerente com a implementacao atual

## Exportacao CSV

O CSV exporta:

- nome
- categoria
- telefone
- endereco
- website
- avaliacao
- reviews
- score
- objetivo
- abordagem

## Observacoes importantes

- o projeto esta monolitico em `index.php`
- isso facilita deploy simples, mas dificulta manutencao no longo prazo
- a leitura do `.env` ja foi implementada diretamente no PHP
- o frontend usa controle de estado com `hidden`
- foi adicionado:

```css
[hidden] {
  display: none !important;
}
```

isso evita problemas visuais de loading e cards aparecendo juntos

## Possiveis proximos passos

- revisar a aba informativa para refletir `SerpAPI` em vez de Google Places
- criar filtro `somente sem site`
- ajustar score com mais criterios
- separar backend, HTML, CSS e JavaScript
- persistir leads em banco de dados
- adicionar login e historico de prospeccao

## Desenvolvimento

Para validar sintaxe do PHP:

```powershell
php -l index.php
```

## Resumo

Hoje o sistema funciona como:

- buscador local de empresas em Manaus
- detector de empresas com baixa presenca digital
- priorizador de leads por oportunidade
- gerador inicial de proposta comercial
