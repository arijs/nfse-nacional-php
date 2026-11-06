# nfse-nacional

Biblioteca PHP para emitir **NFS-e pelo Sistema Nacional NFS-e** (Sefin
Nacional, a API por trás do Emissor Nacional em nfse.gov.br), sem passar pela
página web e sem provedor intermediário.

Faz o ciclo inteiro de uma nota:

1. monta o XML da **DPS** (Declaração de Prestação de Serviços) no leiaute 1.01
   a partir de um array PHP, na ordem que o XSD exige;
2. valida contra os **XSDs oficiais** (inclusos em `resources/xsd/`);
3. **assina** (XMLDSig enveloped) com o certificado A1 do emitente;
4. comprime em GZIP + Base64 e envia por **REST com mTLS** à Sefin;
5. interpreta a resposta: chave de acesso, XML da NFS-e gerada, erros com
   código (E0006, E1235...) e alertas.

Também consulta NFS-e, consulta DPS, registra eventos (cancelamento), lê
parâmetros municipais, lista notas por faixa de números e sincroniza tudo
que o CNPJ emitiu ou recebeu pela distribuição do ADN. PHP ≥ 8.2 puro: só `ext-dom`, `ext-openssl`,
`ext-zlib`, `ext-curl`. Nenhuma dependência de framework nem de pacote de
terceiros — pensada para rodar em hospedagem compartilhada.

## O que você precisa antes

- **Certificado digital A1** (e-CNPJ ou e-CPF, ICP-Brasil) do prestador, em
  `.pfx`. A3 não serve: a chave precisa estar em software. Custa na faixa de
  R$ 100 a 200 por ano em qualquer AC.
- **Município conveniado** ao padrão nacional e empresa habilitada a emitir
  pelo Emissor Nacional (MEI sempre; demais dependem do convênio da
  prefeitura). Confira em https://www.gov.br/nfse → *Municípios*.
- Para testar: o ambiente de **produção restrita** aceita o certificado real
  e devolve notas sem valor fiscal.

## Instalação

Enquanto o pacote não estiver no Packagist, aponte o Composer para o
repositório Git:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/arijs/nfse-nacional-php" }
    ],
    "require": {
        "arijs/nfse-nacional": "^0.1"
    }
}
```

```bash
composer require arijs/nfse-nacional
```

## Uso

```php
use Arijs\NfseNacional\Ambiente;
use Arijs\NfseNacional\Certificado\CertificadoDigital;
use Arijs\NfseNacional\Configuracao;
use Arijs\NfseNacional\Nfse;

$nfse = new Nfse(new Configuracao(
    ambiente: Ambiente::Homologacao,                       // ou Ambiente::Producao
    certificado: CertificadoDigital::deArquivoPfx('/seguro/empresa.pfx', $senha),
    versaoAplicativo: 'meu-erp/2.3',                       // vai no campo verAplic
));

$resposta = $nfse->emitir([
    'serie'   => '1',
    'nDPS'    => $proximoNumero,          // sequencial por série, nunca repetir
    'cLocEmi' => '3550308',               // IBGE do município do prestador
    'prest'   => [
        // CNPJ/CPF: padrão é o do certificado
        'IM'      => '12345678',
        'regTrib' => ['opSimpNac' => '3', 'regApTribSN' => '1', 'regEspTrib' => '0'],
    ],
    'toma' => [
        'CNPJ'  => '98765432000188',
        'xNome' => 'CLIENTE EXEMPLO LTDA',
        'end'   => [
            'endNac'  => ['cMun' => '3550308', 'CEP' => '01001000'],
            'xLgr'    => 'Praça da Sé', 'nro' => '1', 'xBairro' => 'Sé',
        ],
        'email' => 'financeiro@cliente.example',
    ],
    'serv' => [
        'locPrest' => ['cLocPrestacao' => '3550308'],
        'cServ'    => ['cTribNac' => '010701', 'xDescServ' => 'Licença de uso de software - 11/2026'],
    ],
    'valores' => [
        'vServPrest' => ['vServ' => 1500.00],
        'trib' => [
            'tribMun' => ['tribISSQN' => '1', 'tpRetISSQN' => '1', 'pAliq' => 2.00],
            'totTrib' => ['pTotTribSN' => 6.00],
        ],
    ],
]);

if ($resposta->sucesso()) {
    $chave = $resposta->chaveAcesso();   // 50 dígitos
    $xml   = $resposta->nfseXml();       // XML da NFS-e, guarde-o
    $num   = $resposta->numeroNfse();
} else {
    foreach ($resposta->erros() as $erro) {
        echo $erro->codigo, ' ', $erro->descricao, "\n";
    }
}
```

O array espelha o leiaute: chave = nome do elemento, array = grupo, lista =
elemento repetido, `null` = omitido. `tpAmb`, `verAplic`, `dhEmi`, `dCompet`,
`tpEmit` e `prest.CNPJ` são preenchidos quando faltam. A ordem dos elementos é
corrigida automaticamente nos grupos conhecidos, e a validação XSD roda antes
de assinar, então um campo errado aparece como mensagem local, com o nome do
elemento, antes de qualquer ida à rede. Os campos e códigos mais usados estão
em [`docs/leiaute-dps.md`](docs/leiaute-dps.md).

### Outras operações

```php
$nfse->consultar($chave);                        // GET /nfse/{chave}
$nfse->cancelar($chave, PedidoRegistroEventoXml::MOTIVO_ERRO_NA_EMISSAO, 'Valor informado incorretamente');
$nfse->sefin()->consultarChavePorDps($idDps);    // GET /dps/{id}
$nfse->sefin()->dpsProcessada($idDps);           // HEAD /dps/{id} — útil para retomar após timeout
$nfse->sefin()->consultarEventos($chave);
$nfse->sefin()->consultarConvenioMunicipio('3550308');
$nfse->sefin()->consultarParametrosServico('3550308', '010701');

// Etapas separadas, para guardar o XML assinado antes de enviar:
$xmlAssinado = $nfse->prepararDps($infDps);
$resposta    = $nfse->emitir($xmlAssinado);
```

Toda resposta é uma `RespostaSefin`: `sucesso()`, `status`, `erros()`,
`alertas()`, `dados()` (JSON como veio), `xmls()` (XMLs já descomprimidos),
`exigirSucesso()` (lança `SefinExcecao`).

### Recuperar notas já emitidas

A Sefin não tem endpoint de listagem; há dois caminhos.

**Por faixa de números, na Sefin.** Como o número da DPS é sequencial e a
série é sua, dá para varrer: para cada número a biblioteca monta o Id da
DPS, pede a chave e, se quiser, o XML. Uma chamada por número (duas com o
XML), então serve para faixas pequenas e reconciliação.

```php
$lista = $nfse->listarPorFaixa('3550308', serie: 1, de: 1, ate: 200, comXml: true);
// [['nDPS' => 1, 'idDps' => 'DPS...', 'chaveAcesso' => '...', 'nfseXml' => '<NFSe...'], ...]
```

**Por NSU, no ADN.** O Ambiente de Dados Nacional dá a cada documento ligado
ao seu CNPJ um número sequencial único. Pedindo "a partir do NSU N" vêm os
seguintes, em páginas de até 50, com o XML embutido. Traz as notas em que
você é prestador, tomador ou intermediário, emitidas por qualquer canal
(API, página web, app), e os eventos de cancelamento. É o caminho para
sincronizar: guarde o último NSU e cada rodada só busca o que é novo.

```php
$lote = $nfse->sincronizarDfe($ultimoNsuSalvo, maxPaginas: 20, aoReceber: function (LoteDfe $pagina) {
    foreach ($pagina->documentos as $doc) {
        // $doc->nsu, $doc->tipoDocumento ('NFSE'|'EVENTO'), $doc->chaveAcesso,
        // $doc->tipoEvento ('CANCELAMENTO', 'SUBSTITUICAO', ...), $doc->xml()
    }
});
$salvar($lote->ultimoNsu);          // próxima rodada começa daqui
$lote->chavesCanceladas();          // notas invalidadas neste lote
if ($lote->temMais) { /* rode de novo */ }
```

O formato do JSON do ADN (`StatusProcessamento`, `LoteDFe[]` com `NSU`,
`TipoDocumento`, `ChaveAcesso`, `TipoEvento`, `DataHoraGeracao`,
`ArquivoXml`) foi conferido em produção em 06/11/2026. Duas coisas que o
manual não deixa claras e a biblioteca já trata:

- sem `cnpjConsulta` o ADN recusa a consulta (HTTP 400, `REJEICAO`, lista de
  erros vazia), mesmo sendo o CNPJ do certificado. `sincronizarDfe` manda o
  do certificado quando você não informa outro;
- o fim da lista chega como HTTP 404 com `NENHUM_DOCUMENTO_LOCALIZADO`
  (E2220), não como lista vazia. Isso volta como lote vazio, sem exceção.

O status de uma página com documentos é `DOCUMENTOS_LOCALIZADOS`; as grafias
em camelCase das implementações de referência continuam aceitas.

`$nfse->baixarDanfse($chave)` pede o PDF oficial ao ADN. Esse serviço tem
ficado indisponível por períodos (a NT 008 orienta gerar o DANFSe
localmente); se ele falhar, não é defeito da integração.

### Idempotência e retomada

A Sefin processa a DPS de forma síncrona. Se a conexão cair depois do envio,
você não sabe se a nota foi gerada. Antes de reenviar com o mesmo número,
chame `dpsProcessada($idDps)` (o Id está em `DpsXml::idDps(...)`): se
devolver `true`, recupere a chave com `consultarChavePorDps` em vez de
emitir de novo. Reenviar uma DPS já processada é rejeitado; pular o número
gera buraco na sequência, o que é permitido mas convém registrar.

## Linha de comando

```bash
cp .env.exemplo .env            # NFSE_AMBIENTE, NFSE_CERT_PFX, NFSE_CERT_SENHA
vendor/bin/nfse cert --env=.env                        # dados do certificado
vendor/bin/nfse perfil examples/dps-exemplo.json --env=.env    # qual perfil de assinatura a Sefin aceita (homologação)
vendor/bin/nfse validar examples/dps-exemplo.json --env=.env   # XML assinado, sem enviar
vendor/bin/nfse emitir  examples/dps-exemplo.json ./saida --env=.env
vendor/bin/nfse consultar <chave> ./saida --env=.env
vendor/bin/nfse cancelar <chave> 1 "Nota emitida com valor incorreto" --env=.env
vendor/bin/nfse faixa 3550308 1 1 50 ./xmls --env=.env       # notas da série 1, números 1 a 50
vendor/bin/nfse sincronizar 0 ./xmls --env=.env              # tudo do CNPJ via ADN, a partir do NSU 0
vendor/bin/nfse danfse <chave> nota.pdf --env=.env
```

Sem `--env`, as variáveis são lidas do ambiente do processo.

## Configuração

| Variável / parâmetro | Descrição |
|---|---|
| `NFSE_AMBIENTE` | `1`/`producao` ou `2`/`homologacao` |
| `NFSE_CERT_PFX` + `NFSE_CERT_SENHA` | certificado A1 (ou `NFSE_CERT_PEM` + `NFSE_CHAVE_PEM`) |
| `NFSE_VERSAO_APLICATIVO` | texto do `verAplic` (até 20 caracteres) |
| `NFSE_PERFIL_ASSINATURA` | `sha1` (padrão) ou `sha256`, ver abaixo |
| `NFSE_FALLBACK_ASSINATURA` | `0` desliga a nova tentativa com o outro perfil após E0714 |
| `NFSE_URL_SEFIN`, `NFSE_URL_ADN` | URLs base alternativas (proxy, mock) |
| `NFSE_TIMEOUT`, `NFSE_CA_BUNDLE` | timeout em segundos; CA bundle se o sistema não tiver |
| `NFSE_LEIAUTE` | `1.01` ou `nt009`; padrão: o que a Sefin do ambiente aplica, ver abaixo |

`Configuracao::doAmbiente()` lê tudo isso; o construtor aceita os mesmos
valores como parâmetros nomeados.

### Leiaute (NT 009)

Existem hoje **dois XSDs "1.01"**. A NT SE/CGNFS-e 009 republicou o leiaute
com `finNFSe` obrigatório no `infDPS`, `xDescServ` no fim de `cServ` e
`vDedRed` trocado por `vAjusteBC`. Desde 20/10/2026 a **produção restrita**
só aceita o novo (uma DPS no formato antigo leva E1235: *"invalid child
element 'cLocEmi' ... expected: cMotivoEmisTI, finNFSe"*), enquanto a
**produção** continua no anterior. A NT não diz quando a produção muda.

A biblioteca traz os dois (`resources/xsd/1.01/` e `resources/xsd/1.01-nt009/`)
e escolhe pelo enum `Leiaute`:

| Ambiente | Padrão | |
|---|---|---|
| produção | `Leiaute::V101` (`1.01`) | sem `finNFSe` |
| homologação | `Leiaute::V101Nt009` (`1.01-nt009`) | `finNFSe` = `0` (regular) se você não informar |

O array do `infDPS` é o mesmo nos dois; a biblioteca ordena conforme o
leiaute. Quando a produção adotar a NT 009 (o sinal é o mesmo E1235 em
produção), defina `NFSE_LEIAUTE=nt009` (ou `leiauteDps: Leiaute::V101Nt009`)
até atualizar a biblioteca para o padrão novo.

### Perfil de assinatura

O "Padrão de Assinatura Digital" dos manuais de integração da NFS-e descreve
C14N inclusiva + RSA-SHA1 (mesmo perfil da NF-e); é o padrão aqui. A própria
Sefin assina as NFS-e que devolve com C14N exclusiva + RSA-SHA256, e muitas
integrações enviam a DPS assim sem rejeição. A Sefin valida pelos algoritmos
declarados no XML, então os dois são aceitos.

Duas redes de segurança cuidam disso por você:

- **Descoberta na implantação**: `vendor/bin/nfse perfil dps.json --env=.env`
  emite, em homologação, uma DPS de teste com cada perfil (números `nDPS` e
  `nDPS+1`) e imprime qual a Sefin aceitou, já no formato
  `NFSE_PERFIL_ASSINATURA=...`. Grave isso no `.env` e produção começa certa.
  O comando se recusa a rodar em produção.
- **Fallback automático** (`fallbackAssinatura`, ligado por padrão): se a
  Sefin recusar com E0714, a mesma DPS é assinada com o outro perfil e
  reenviada uma vez. Uma DPS recusada não gera nota, então o reenvio com o
  mesmo número é seguro. Só o E0714 dispara isso; E0715, E0716 e E0718 são
  problemas do certificado. O perfil que funcionou passa a ser usado pela
  instância, e o callback `aoDescobrirPerfil` recebe o valor para você
  persistir. Desligue com `NFSE_FALLBACK_ASSINATURA=0`.

Um timeout depois do envio não dispara fallback nem reenvio: nesse caso não
se sabe se a nota saiu, e o caminho é `dpsProcessada()` antes de qualquer
nova tentativa.

## Roteiro para colocar em produção

1. **Certificado**: compre o e-CNPJ A1, exporte o `.pfx` com senha, guarde-o
   fora da raiz web com permissão só para o usuário do PHP. Nunca versione.
2. **Produção restrita**: `NFSE_AMBIENTE=2`, rode `nfse cert`, depois
   `nfse perfil` com um JSON real da sua empresa (grave o
   `NFSE_PERFIL_ASSINATURA` que ele indicar) e por fim `nfse emitir`. Ajuste
   até a resposta vir com `chaveAcesso`. As notas de lá não valem.
3. **Numeração**: crie no seu banco o contador de `nDPS` por série, com
   incremento atômico, e grave a DPS assinada + resposta de cada envio.
4. **Produção**: `NFSE_AMBIENTE=1`. Emita uma nota de valor baixo para você
   mesmo, confira no portal (https://www.nfse.gov.br/ConsultaPublica) e
   cancele-a se quiser.
5. **Operação**: guarde o XML da NFS-e e o DANFSe; monitore o vencimento do
   certificado (`CertificadoDigital::validoAte()`); acompanhe as notas
   técnicas em gov.br/nfse, porque o leiaute muda (a 1.01 trouxe IBS/CBS).

## O que não está coberto (ainda)

- Geração **local** do DANFSe em PDF a partir do XML (há só o download do
  PDF oficial, que depende do ADN estar servindo).
- **IBS/CBS** com ajuda de preenchimento: o grupo `IBSCBS` pode ser passado no
  array, mas sem ordenação automática nem cálculo. Para Simples Nacional só
  é exigido a partir de 2027.
- **Lote assíncrono** de DPS.
- **Certificado A3**.

## Desenvolvimento

```bash
composer install
vendor/bin/phpunit
```

Os testes geram um certificado autoassinado em memória, validam o XML contra
os XSDs oficiais e verificam a assinatura; não tocam a rede. A única
alteração nos XSDs está documentada em `resources/xsd/1.01/ORIGEM.md`; a
origem do pacote da NT 009 está em `resources/xsd/1.01-nt009/ORIGEM.md`.

No Windows, o `openssl_pkey_new` dos testes precisa de um `openssl.cnf`:
`OPENSSL_CONF=C:/php/extras/ssl/openssl.cnf vendor/bin/phpunit`.

## Referências

- Documentação técnica oficial: https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica
- Manual dos Contribuintes, APIs do Emissor Público Nacional (v1.2, out/2025)
- Anexo I: Leiaute e Regras de Negócio DPS/NFS-e (v1.01, fev/2026)
- Esquemas XSD v1.01 (fev/2026) e de produção restrita (20/10/2026)
- Nota Técnica SE/CGNFS-e 009 (v1.01): https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/rtc/nota-tecnica-009-se-cgnfs-e-v-1-01.pdf
- Swagger de produção restrita: https://adn.producaorestrita.nfse.gov.br/contribuintes/docs/index.html (exige certificado)
