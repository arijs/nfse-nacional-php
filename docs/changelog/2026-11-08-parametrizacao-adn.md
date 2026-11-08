# 2026-11-08 — Parâmetros municipais no ADN (`/parametrizacao`)

## Prompt original

> (resultado da consulta em produção) HTTP 404 — Requested URL:
> /SefinNacional/parametros_municipais/3550308/010701

## O que foi implementado

A API de parâmetros municipais não existe na Sefin: vive no host do ADN, em
`/parametrizacao` (documentação viva em
`https://adn.nfse.gov.br/parametrizacao/docs/index.html`), com rotas diferentes das do
manual de 2025.

- `Adn\AdnParametrizacao`: `convenio`, `historicoAliquotas`, `aliquota`,
  `regimesEspeciais`, `retencoes`, `beneficio`, e `consultar($caminho)` para sondar
  formatos que a especificação não fixa (código do serviço com o desdobro municipal, 9
  dígitos; competência). Valores vão como vieram, só codificados para a URL.
- `Nfse::parametrizacao()`, sobre o mesmo HTTP com mTLS e a `urlAdn` da configuração.
- `SefinNacional::consultarParametrosServico/ConvenioMunicipio/ParametrosContribuinte`
  ficam `@deprecated` (404 em produção).

## Testes

`AdnParametrizacaoTest` (rotas no host do ADN, produção e produção restrita, URL do ADN
da configuração pela fachada).
