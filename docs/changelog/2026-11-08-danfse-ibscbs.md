# 2026-11-08 — DANFSe imprime o bloco IBS/CBS

## Prompt original

> Na geração do DANFSe (pdf no servidor, gerado pelo arijs/nfse-nacional-php),
> eu preenchi os campos de ibs/cbs mas eles não aparecem no PDF - não sei se
> precisa alterar a lib ou só fazer o ddsoft preencher esses campos ao gerar o
> pdf

Era a biblioteca: `DanfseOficial::blocoTributos()` desenhava o bloco
"TRIBUTAÇÃO IBS/CBS" com as caixas fixas de uma nota sem o grupo ("- / -",
"R$ 0,00", vazias), e "Total do IBS/CBS" era sempre `R$ 0,00`.

## O que foi implementado

- `DanfseOficial::campos()` ganha os textos do bloco, lidos do XML autorizado:
  - **classificação** do grupo `IBSCBS` da DPS — `CST / cClassTrib`
    (`ibs_cst_class`) e `cIndOp / código IBGE / município / UF da incidência`
    (`ibs_operacao`). No leiaute 1.01 a classificação fica em
    `valores/trib/gIBSCBS`, na NT 009 em `valores/trib`; os dois são lidos. O
    município de incidência vem de `cLocalidadeIncid`/`xLocalidadeIncid` do
    grupo da NFS-e (ou do resolvedor de município, como o tomador).
  - **valores** do grupo `IBSCBS` da NFS-e (o que a Sefin calculou):
    exclusões/reduções da base (soma de `vCalcAjusteBCLocImoveis`,
    `vCalcAjusteBCIBSCBS` e `vCalcReeRepRes`), `vBC`, reduções de alíquota
    (`pRedAliqUF / pRedAliqMun / pRedAliqCBS`), `pIBSUF / pIBSMun`, alíquotas
    efetivas e valores apurados por esfera (`pAliqEfetMun`, `vIBSMun`,
    `pAliqEfetUF`, `vIBSUF`, `vIBSTot`, `pCBS`, `pAliqEfetCBS`, `vCBS`).
  - `v_ibscbs` = `vIBSTot + vCBS` e `v_liquido_ibscbs` = `vLiq + vIBSTot +
    vCBS` (IBS e CBS são por fora). Nota sem o grupo: `R$ 0,00` e o próprio
    líquido, como antes.
- `blocoTributos()` passa a imprimir esses campos nas caixas que já existiam;
  nada muda de posição.
- Fixture `tests/fixtures/nfse-autorizada-teste-ibscbs.xml`: a nota #913 com
  o grupo `IBSCBS` nas duas pontas (valores de produção restrita: CBS 0,90%,
  IBS UF 0,10%, IBS municipal 0,00%, sobre R$ 1,00).

## Como foi testado

`vendor/bin/phpunit` (84 testes). O PDF da fixture foi rasterizado e conferido
visualmente: o bloco sai preenchido no lugar do oficial, o resto da página não
muda.

## Testes criados/alterados

- `DanfseOficialTest::testSemOGrupoIbsCbsAsCaixasSaemComoNoOficial`
- `DanfseOficialTest::testComOGrupoIbsCbsImprimeAClassificacaoEOsValoresDaSefin`
  (campos e textos no PDF)
- `DanfseOficialTest::testLeiaute101GuardaAClassificacaoEmGIBSCBS`
