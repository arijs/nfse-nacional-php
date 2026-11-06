# 2026-11-06 — Leiaute da NT 009 e distribuição do ADN

## Prompt original

> Vamos fazer as correções. [...] (sequência do teste com o certificado da
> SPCA no ddsoft-online: "Quer que eu corrija os três pontos? Seria a lib com
> o leiaute novo e a recuperação de notas, e o filtro por ambiente no ddsoft
> online, com testes.")

## O que foi implementado

### Leiaute da NT SE/CGNFS-e 009

A produção restrita passou a aplicar, desde 20/10/2026, o pacote de XSD da
NT 009 (ainda "1.01" no atributo `versao`). A biblioteca gerava só o leiaute
anterior e toda DPS de homologação voltava com E1235. A produção continua no
anterior (nota real emitida em 03/11/2026 sem `finNFSe`).

- `resources/xsd/1.01-nt009/`: pacote oficial `esquemas_prod_restrita_20261020.zip`
  sem alteração (ORIGEM.md com URL e SHA-256).
- `Leiaute` (enum): `V101` (`1.01`) e `V101Nt009` (`1.01-nt009`); padrão por
  ambiente (`padraoPara`: produção → `1.01`, homologação → `1.01-nt009`).
- `Configuracao::$leiauteDps` (null = padrão do ambiente), `leiaute()`,
  `comLeiaute()`; `doAmbiente()` lê `NFSE_LEIAUTE`.
- `DpsXml::gerar($inf, $leiaute)`: ordem por leiaute (`finNFSe` entre
  `cMotivoEmisTI` e `chNFSeRej`; `xDescServ` no fim de `cServ`; `vAjusteBC`
  no lugar de `vDedRed`). `cMotivoEmisTI`/`chNFSeRej` entraram também na ordem
  do `1.01`.
- `Nfse`: gera e valida (DPS e pedRegEvento) com o leiaute da configuração;
  preenche `finNFSe = 0` quando o leiaute o exige.
- `ValidadorXsd`: aceita `Leiaute` (pasta + sufixo de arquivo).
- CLI: `nfse cert` mostra o leiaute.

### Distribuição do ADN

Conferida em produção com certificado real:

- sem `cnpjConsulta` o ADN responde 400 `REJEICAO` com lista de erros vazia →
  `Nfse::sincronizarDfe` manda o CNPJ do certificado por padrão;
- o fim da lista é HTTP 404 `NENHUM_DOCUMENTO_LOCALIZADO` (E2220) → vira lote
  vazio em vez de `SefinExcecao`;
- status reais em CAIXA_ALTA (`DOCUMENTOS_LOCALIZADOS`) reconhecidos junto com
  as grafias antigas (`LoteDfe::nenhumDocumento`).

## Antes × depois

| | Antes | Depois |
|---|---|---|
| DPS em homologação | E1235 (`finNFSe` ausente; `cNBS` fora de ordem) | autorizada (nº 1 e 2 da série 49000 da SPCA em 06/11/2026) |
| DPS em produção | leiaute 1.01 | igual (sem mudança) |
| `nfse sincronizar 0` em produção | erro na 1ª chamada (400 sem `cnpjConsulta`) | 23 documentos, termina limpo no 404 |

## Como foi testado

- Automatizado: `vendor/bin/phpunit` → 70 testes OK (eram 56).
- Manual, contra a Sefin/ADN reais com o e-CNPJ da SPCA:
  - `nfse sincronizar 0` em produção (só leitura): 23 documentos;
  - `nfse emitir` em homologação: autorizada (nNFSe 2) com `cNBS` e `cTribMun`;
  - `nfse cancelar` da nota acima em homologação: 201 (evento pelo XSD novo).

## Testes criados/alterados

- `tests/Unit/LeiauteTest.php` (novo)
- `tests/Unit/NfseTest.php` — homologação no leiaute NT 009; produção sem
  `finNFSe`; ordem do `cServ`; leiaute configurado × padrão; `finNFSe` no
  leiaute antigo barrado antes da rede
- `tests/Unit/DpsXmlTest.php` — mesmo array nos dois leiautes; `vAjusteBC`;
  leiaute desconhecido
- `tests/Unit/AdnContribuintesTest.php` — respostas reais do ADN; 404 E2220;
  outro 404 continua erro; `cnpjConsulta` padrão na fachada
