# Recursos do DANFSe oficial

Usados por `Arijs\NfseNacional\Danfse\DanfseOficial` (tc-lib-pdf). Vão junto
com o pacote; nada precisa ser copiado para a aplicação.

- `logo-nfse.svg` — logo da NFS-e do Emissor Nacional
  (`https://www.nfse.gov.br/EmissorNacional/img/logo-nfse-assinatura-horizontal.svg`,
  baixado em 06/11/2026), **sem** o elemento `<text>` "Nota Fiscal de Serviço
  eletrônica": ele pede a fonte Segoe UI, que não existe no servidor. O texto é
  desenhado pelo gerador em Helvetica, na mesma posição e cor (#70758d).
- `fonts/helvetica.json`, `fonts/helveticab.json` — métricas das fontes core do
  PDF (Helvetica e Helvetica Bold, não embutidas), geradas uma vez por
  `vendor/tecnickcom/tc-lib-pdf-font/util/build_fonts.php` (a partir de
  tc-font-core14-afms). Ficam no repositório para a instalação não precisar
  rodar esse build, que baixa e converte ~70 fontes. `K_PATH_FONTS` aponta
  para cá (o gerador define a constante se a aplicação ainda não definiu).

Para outra fonte: rodar o build acima e copiar o `.json` (e o `.z`/`.ctg.z`, se
houver) de `vendor/tecnickcom/tc-lib-pdf-font/target/fonts/<família>/`.
