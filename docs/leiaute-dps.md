# Guia rápido do leiaute da DPS (1.01)

Resumo dos campos que uma prestadora de serviços comum precisa. A referência
completa é a planilha oficial *Anexo I — Leiaute e Regras de Negócio
DPS/NFS-e* e os XSDs em `resources/xsd/1.01/` (produção) e
`resources/xsd/1.01-nt009/` (produção restrita, NT 009). Todo nome aqui é o nome do
elemento no XML, e é a chave que você usa no array passado para
`Nfse::emitir()`.

## Cabeçalho (`infDPS`)

| Campo | Obrig. | Valor | Observação |
|---|---|---|---|
| `tpAmb` | sim | `1` produção, `2` homologação | preenchido pela biblioteca a partir da configuração; se vier diferente, ela recusa (a Sefin rejeitaria com E0006) |
| `dhEmi` | sim | data/hora com fuso (`2026-11-06T10:15:00-03:00`) | padrão: agora. Não pode ser posterior ao processamento na Sefin (E0008): relógio do servidor em dia |
| `verAplic` | sim | 1 a 20 caracteres | identifica seu sistema; padrão: `Configuracao::$versaoAplicativo` |
| `serie` | sim | 1 a 5 dígitos | **faixa 1 a 49999 para aplicativo próprio** (E0010). Use `1` e não mude |
| `nDPS` | sim | 1 a 15 dígitos, sequencial por série | **nunca repita**: a Sefin recusa DPS duplicada. Guarde o último número no seu banco |
| `dCompet` | sim | `AAAA-MM-DD` | data da prestação do serviço; não pode ser posterior a `dhEmi` (E0015) |
| `tpEmit` | sim | `1` prestador, `2` tomador, `3` intermediário | padrão `1` |
| `finNFSe` | só no leiaute NT 009 | `0` regular, `1` crédito, `2` débito | padrão `0` quando o leiaute é `1.01-nt009` (homologação, por padrão); **não existe** no `1.01` de produção — ver "Leiaute (NT 009)" no README |
| `cLocEmi` | sim | código IBGE (7 dígitos) | município do prestador |

O atributo `Id` do `infDPS` é calculado: `DPS` + `cLocEmi` + tipo de inscrição (1 CPF, 2 CNPJ) + inscrição com 14 posições + série com 5 + número com 15.

## Prestador (`prest`)

| Campo | Obrig. | Observação |
|---|---|---|
| `CNPJ` ou `CPF` | sim | padrão: o do certificado. Tem de ser o titular do certificado (E0718) |
| `IM` | condicional | inscrição municipal; obrigatória se o prestador tem cadastro complementar no CNC do município |
| `xNome`, `end`, `fone`, `email` | não | a Sefin usa o cadastro nacional; normalmente não se informa |
| `regTrib.opSimpNac` | sim | `1` não optante, `2` MEI, `3` ME/EPP do Simples |
| `regTrib.regApTribSN` | só se `opSimpNac = 3` | `1` tudo pelo SN, `2` ISS fora do SN, `3` tudo fora. Não informar para 1 ou 2 (E0162) |
| `regTrib.regEspTrib` | sim | `0` nenhum, `1` cooperativa, `2` estimativa, `3` microempresa municipal, `4` notário, `5` autônomo, `6` sociedade de profissionais, `9` outros |

## Tomador (`toma`)

Opcional no XSD, mas sem tomador a nota sai "sem identificação do tomador".
Para tomador nacional: `CNPJ` ou `CPF`, `xNome`, `end` (`endNac.cMun`,
`endNac.CEP`, `xLgr`, `nro`, `xCpl`, `xBairro`), `fone`, `email`. Tomador no
exterior: `NIF` ou `cNaoNIF`, e `end.endExt`.

## Serviço (`serv`)

| Campo | Obrig. | Observação |
|---|---|---|
| `locPrest.cLocPrestacao` | sim | IBGE do local da prestação (ou `cPaisPrestacao` no exterior) |
| `cServ.cTribNac` | sim | código de tributação nacional, 6 dígitos: item (2) + subitem (2) + desdobro (2). Ex.: 01.07 → `010700`, desdobro 01 → `010701`. Lista oficial: Anexo B |
| `cServ.cTribMun` | condicional | código municipal, se o município exigir (consulte `parametros_municipais`) |
| `cServ.xDescServ` | sim | discriminação, até 2000 caracteres. No leiaute NT 009 vai para o fim de `cServ` (a biblioteca ordena) |
| `cServ.cNBS` | não | Nomenclatura Brasileira de Serviços (9 dígitos) |
| `infoCompl.xInfComp` | não | informações complementares, até 2000 |

## Valores (`valores`)

| Campo | Obrig. | Observação |
|---|---|---|
| `vServPrest.vServ` | sim | valor do serviço |
| `vDescCondIncond.vDescIncond` / `vDescCond` | não | descontos |
| `trib.tribMun.tribISSQN` | sim | `1` tributável, `2` imunidade, `3` exportação, `4` não incidência |
| `trib.tribMun.tpRetISSQN` | sim | `1` não retido, `2` retido pelo tomador, `3` retido pelo intermediário |
| `trib.tribMun.pAliq` | condicional | alíquota do ISS (%). Para o Simples Nacional, a alíquota efetiva do ISS da sua faixa; para os demais, a do município (pode vir da consulta de parâmetros) |
| `trib.tribFed` | não | PIS/COFINS (`piscofins.CST` ...), retenções `vRetCP`, `vRetIRRF`, `vRetCSLL` |
| `trib.totTrib` | sim | **um** de: `vTotTrib` (valores), `pTotTrib` (percentuais), `pTotTribSN` (percentual do Simples) ou `indTotTrib = 0`. ME/EPP **não pode** usar `indTotTrib` (E0712) |

Floats viram texto com 2 casas (`1500.0` → `1500.00`). Passe strings quando
precisar de outro número de casas.

## IBS/CBS (`IBSCBS`)

Grupo da reforma tributária (vigente desde 2026). Para optantes do Simples
Nacional é obrigatório só a partir de 2027; para os demais, consulte o Anexo C
(indicadores de operação) e preencha o grupo seguindo a ordem do XSD, pois a
biblioteca só garante ordem automática nos grupos listados em
`DpsXml::ORDEM`. O XSD avisa se algo estiver fora de lugar.

## Códigos de erro mais comuns

| Código | Significado | O que fazer |
|---|---|---|
| E0006 | `tpAmb` diverge do ambiente da URL | a biblioteca já impede; confira `NFSE_AMBIENTE` |
| E0008 | `dhEmi` no futuro | relógio do servidor |
| E0010 | série fora da faixa | série entre 1 e 49999 |
| E0162 | `regApTribSN` sem ser ME/EPP | remova o campo |
| E0712 | `indTotTrib` em ME/EPP | use `pTotTribSN` |
| E0714 / E0718 | assinatura inválida / não é do emitente | certificado do próprio prestador; tente o outro perfil de assinatura |
| E1200..E1209 | certificado de transmissão inválido, vencido, revogado, sem CNPJ, não ICP-Brasil | troque o certificado |
| E1225 / E1226 | Base64/GZIP inválidos | não acontece pela biblioteca |
| E1235 | falha no esquema XML | valide localmente: `nfse validar dps.json`. Se o complemento disser *"expected: cMotivoEmisTI, finNFSe"*, a Sefin está no leiaute da NT 009 e a configuração não: `NFSE_LEIAUTE=nt009` |
