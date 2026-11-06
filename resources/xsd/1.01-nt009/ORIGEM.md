# Esquemas XSD — leiaute 1.01 da NT SE/CGNFS-e 009 (produção restrita)

Copiados sem alteração do pacote publicado na documentação de produção
restrita do Portal da NFS-e:

- URL: https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/producao-restrita/esquemas_prod_restrita_20261020.zip
- SHA-256 do zip: 25fdcecc651a67a216496ab10d7566631e6ed10f721e37d62c7845dadb0ea267
- Arquivos na raiz do zip; nomes iguais aos de `../1.01/` (a versão no
  atributo `versao` continua "1.01").
- Nota técnica: https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/rtc/nota-tecnica-009-se-cgnfs-e-v-1-01.pdf

Em 06/11/2026 é o que a Sefin de **produção restrita** aplica
(`SefinNacional_1.6.0`); a **produção** ainda aplica `../1.01/`. A NT 009 não
traz data de entrada em produção ("será publicado o cronograma"), por isso a
biblioteca mantém os dois e escolhe pelo `Leiaute` da configuração.

## O que muda para uma DPS comum (prestador nacional, sem IBS/CBS)

- `infDPS/finNFSe` (0 = regular, 1 = crédito, 2 = débito) passa a ser
  obrigatório, entre `cMotivoEmisTI` e `chNFSeRej`. Saiu de dentro de
  `IBSCBS`, onde estava no leiaute anterior.
- `serv/cServ`: `xDescServ` vai para o fim —
  `cTribNac, cTribMun, cNBS, cIntContrib, xDescServ`.
- `valores/vDedRed` vira `valores/vAjusteBC` (com outra estrutura).
- `IBSCBS/cIndOp` passa a ser opcional; entram `bensMoveis`,
  `imovel/gLocacao`, `gUnidImob` e as notas de ajuste.

## Ajuste local

Nenhum. O padrão de `TSSerieDPS` deste pacote (`[0-9]{1,4}|[0-8][0-9]{4}`) não
usa `^`/`$`, ao contrário do de `../1.01/`.
