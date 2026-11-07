# 2026-11-07 — DANFSe em PDF no leiaute oficial, gerado localmente

## Prompt original

> Vamos mover a geração do DANFSe PDF do ddsoft online para o repo
> arijs/nfse-nacional

## O que foi implementado

O gerador que vivia em `App\PlataformaNfse\DanfseOficial` no ddsoft-online
(06/11/2026: leiaute medido do PDF oficial, QR idêntico, bitmap com
`/Interpolate`) passa a fazer parte da biblioteca, sem dependência do banco
nem do Symfony:

- `Arijs\NfseNacional\Danfse\DanfseOficial`: `gerar($xml, $cancelada)` → bytes
  do PDF; `campos($xml)` → os textos de cada caixa no formato do oficial;
  formatadores públicos (`doc`, `fone`, `cep`, `ibge`, `nbs`, `reais`, `pct`,
  `uf`, `limitar`, `linkConsulta`). Construtor com o resolvedor de nome de
  município por código IBGE (closure, opcional — a aplicação liga à tabela
  que tiver; sem ele sai o código) e o `criador` (campo Creator do PDF,
  padrão `arijs/nfse-nacional`). O `municipiosDoBanco(\PDO)` da tabela
  `locais` ficou no ddsoft-online.
- `Arijs\NfseNacional\Danfse\QrCodeByte`: o `Encode` do tc-lib-barcode com um
  único segmento em modo byte (versão 7, como o Emissor Nacional).
- `resources/danfse/`: logo vetorial do Emissor Nacional e as métricas
  Helvetica/Helvetica Bold do tc-lib-pdf, no pacote. `K_PATH_FONTS` é definida
  pela biblioteca quando a aplicação não a definiu.
- `composer.json`: `tecnickcom/tc-lib-pdf` 8.81.1 (fixo: o `/Interpolate` da
  imagem entra por reflexão num `protected` da lib) e `ext-mbstring`.
- CLI: `nfse danfse-xml <nfse.xml> <saida.pdf> [--cancelada]`, sem certificado.
- README: seção "DANFSe em PDF, gerado localmente"; o item sai de "O que não
  está coberto".

## Antes × depois

| | Antes | Depois |
|---|---|---|
| DANFSe pela biblioteca | só `baixarDanfse()` (ADN, instável) | + `DanfseOficial::gerar()` local, do XML |
| Dependências | só extensões do PHP | + tc-lib-pdf (PHP puro) |

## Como foi testado

- `vendor/bin/phpunit`: 81 testes (eram 70). `DanfseOficialTest` (11): campos
  no formato do oficial a partir do XML real; QR módulo a módulo contra a
  matriz lida do PDF oficial; tomador de outro município com e sem
  resolvedor; cancelada; PDF de uma página com a imagem 490 × 490
  `/Interpolate`; ordem faixa → linha; PNG do QR; XML inválido; formatos;
  Creator do PDF.
- `nfse danfse-xml` com o XML da fixture, normal e `--cancelada`; PDF
  renderizado e conferido visualmente (logo, QR, faixas, aviso, canhoto).

## Testes criados/alterados

- `tests/Unit/DanfseOficialTest.php` (novo, vindo do ddsoft-online)
- `tests/fixtures/nfse-autorizada-teste.xml`, `tests/fixtures/qr-oficial-nota-913.txt` (novos)
