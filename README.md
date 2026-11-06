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

Também consulta NFS-e, consulta DPS, registra eventos (cancelamento) e lê
parâmetros municipais. PHP ≥ 8.2 puro: só `ext-dom`, `ext-openssl`,
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
        { "type": "vcs", "url": "https://github.com/rhengles/nfse-nacional-php" }
    ],
    "require": {
        "rhengles/nfse-nacional": "^0.1"
    }
}
```

```bash
composer require rhengles/nfse-nacional
```

## Uso

```php
use Rhengles\NfseNacional\Ambiente;
use Rhengles\NfseNacional\Certificado\CertificadoDigital;
use Rhengles\NfseNacional\Configuracao;
use Rhengles\NfseNacional\Nfse;

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
vendor/bin/nfse validar examples/dps-exemplo.json --env=.env   # XML assinado, sem enviar
vendor/bin/nfse emitir  examples/dps-exemplo.json ./saida --env=.env
vendor/bin/nfse consultar <chave> ./saida --env=.env
vendor/bin/nfse cancelar <chave> 1 "Nota emitida com valor incorreto" --env=.env
```

Sem `--env`, as variáveis são lidas do ambiente do processo.

## Configuração

| Variável / parâmetro | Descrição |
|---|---|
| `NFSE_AMBIENTE` | `1`/`producao` ou `2`/`homologacao` |
| `NFSE_CERT_PFX` + `NFSE_CERT_SENHA` | certificado A1 (ou `NFSE_CERT_PEM` + `NFSE_CHAVE_PEM`) |
| `NFSE_VERSAO_APLICATIVO` | texto do `verAplic` (até 20 caracteres) |
| `NFSE_PERFIL_ASSINATURA` | `sha1` (padrão) ou `sha256`, ver abaixo |
| `NFSE_URL_SEFIN` | URL base alternativa (proxy, mock) |
| `NFSE_TIMEOUT`, `NFSE_CA_BUNDLE` | timeout em segundos; CA bundle se o sistema não tiver |

`Configuracao::doAmbiente()` lê tudo isso; o construtor aceita os mesmos
valores como parâmetros nomeados.

### Perfil de assinatura

O "Padrão de Assinatura Digital" dos manuais de integração da NFS-e descreve
C14N inclusiva + RSA-SHA1 (mesmo perfil da NF-e); é o padrão aqui. A própria
Sefin assina as NFS-e que devolve com C14N exclusiva + RSA-SHA256, e muitas
integrações enviam a DPS assim sem rejeição. A Sefin valida pelos algoritmos
declarados no XML, então os dois são aceitos. Se um dia a DPS for rejeitada
com E0714 sem motivo aparente, troque `NFSE_PERFIL_ASSINATURA` antes de
qualquer outra coisa.

## Roteiro para colocar em produção

1. **Certificado**: compre o e-CNPJ A1, exporte o `.pfx` com senha, guarde-o
   fora da raiz web com permissão só para o usuário do PHP. Nunca versione.
2. **Produção restrita**: `NFSE_AMBIENTE=2`, rode `nfse cert` e depois
   `nfse emitir` com um JSON real da sua empresa. Ajuste até a resposta vir
   com `chaveAcesso`. As notas de lá não valem.
3. **Numeração**: crie no seu banco o contador de `nDPS` por série, com
   incremento atômico, e grave a DPS assinada + resposta de cada envio.
4. **Produção**: `NFSE_AMBIENTE=1`. Emita uma nota de valor baixo para você
   mesmo, confira no portal (https://www.nfse.gov.br/ConsultaPublica) e
   cancele-a se quiser.
5. **Operação**: guarde o XML da NFS-e e o DANFSe; monitore o vencimento do
   certificado (`CertificadoDigital::validoAte()`); acompanhe as notas
   técnicas em gov.br/nfse, porque o leiaute muda (a 1.01 trouxe IBS/CBS).

## O que não está coberto (ainda)

- Geração do **DANFSe em PDF**: a Sefin devolve o XML; o PDF oficial pode ser
  baixado do ADN (`/danfse/{chave}`) com o mesmo certificado — a URL está em
  `Ambiente::urlAdn()`, mas não há helper pronto.
- **IBS/CBS** com ajuda de preenchimento: o grupo `IBSCBS` pode ser passado no
  array, mas sem ordenação automática nem cálculo. Para Simples Nacional só
  é exigido a partir de 2027.
- **Lote assíncrono** e consultas do ADN (distribuição por NSU).
- **Certificado A3**.

## Desenvolvimento

```bash
composer install
vendor/bin/phpunit
```

Os testes geram um certificado autoassinado em memória, validam o XML contra
os XSDs oficiais e verificam a assinatura; não tocam a rede. A única
alteração nos XSDs está documentada em `resources/xsd/1.01/ORIGEM.md`.

## Referências

- Documentação técnica oficial: https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica
- Manual dos Contribuintes, APIs do Emissor Público Nacional (v1.2, out/2025)
- Anexo I: Leiaute e Regras de Negócio DPS/NFS-e (v1.01, fev/2026)
- Esquemas XSD v1.01 (fev/2026)
- Swagger de produção restrita: https://adn.producaorestrita.nfse.gov.br/contribuintes/docs/index.html (exige certificado)
