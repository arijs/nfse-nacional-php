# Esquemas XSD — NFS-e Padrão Nacional, leiaute 1.01

Copiados sem alteração do pacote oficial publicado no Portal da NFS-e:

- URL: https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/documentacao-atual/nfse-esquemas_xsd-v1-01-20260209.zip
- SHA-256 do zip: e7935cbd9470527c6cc32984c1b2263e614183bf0139ce2733eaaed2de9a8072
- Pasta de origem dentro do zip: `Schemas/1.01/`

Quando o portal publicar uma versão nova, substitua a pasta inteira e atualize
`Rhengles\NfseNacional\Dps\DpsXml::VERSAO_LEIAUTE` se a versão mudar.

## Ajuste local (único)

`tiposSimples_v1.01.xsd`, tipo `TSSerieDPS`: o padrão oficial é
`^0{0,4}\d{1,5}$`. Em XML Schema, `^` e `$` não são âncoras, são caracteres
literais, e o libxml2 (que o PHP usa) segue a especificação à risca: nenhum
valor passaria. O validador da Sefin (.NET) trata os dois como âncoras, por
isso lá funciona. Aqui o padrão foi trocado por `0{0,4}\d{1,5}`, que é o que
os dois lados realmente aplicam. É a única diferença em relação ao pacote
oficial.
