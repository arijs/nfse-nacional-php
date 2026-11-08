<?php declare(strict_types=1);

namespace Arijs\NfseNacional\Danfse;

use Com\Tecnick\Pdf\Page\Unit;
use Com\Tecnick\Pdf\Tcpdf;

/**
 * DANFSe no LEIAUTE OFICIAL (v2.0, o PDF que o Emissor Nacional gera), em PDF,
 * a partir do XML AUTORIZADO da NFS-e.
 *
 *   $pdf = (new DanfseOficial())->gerar($nfseXml);          // bytes do PDF
 *   $pdf = (new DanfseOficial())->gerar($nfseXml, cancelada: true);
 *
 * Por que local: a API de DANFSe do ADN (`GET /danfse/{chave}`,
 * {@see \Arijs\NfseNacional\Adn\AdnContribuintes::baixarDanfse}) foi
 * descontinuada pela NT 008/2026 — o emissor gera o documento — e respondia
 * 404 na produção restrita. Desenhado com tecnickcom/tc-lib-pdf; a fonte é a
 * Helvetica core do PDF (não embutida), com as métricas em
 * `resources/danfse/fonts/`, e o logo vetorial do Emissor Nacional em
 * `resources/danfse/logo-nfse.svg`.
 *
 * As posições (mm, A4) foram medidas do DANFSe oficial de uma nota da
 * produção restrita e de uma nota real de produção: 119 textos casam com o
 * oficial com |Δ| ≤ 0,09 mm, e o QR Code sai módulo a módulo igual
 * ({@see QrCodeByte}). O que o XML não traz (nome do município do tomador
 * quando difere do do emitente) vem do resolvedor `$municipio`, que a
 * aplicação fornece (uma tabela IBGE → nome); sem ele sai o código. A UF sai
 * dos dois primeiros dígitos do código IBGE.
 *
 * Destinatário e intermediário saem como "NÃO IDENTIFICADO" ou numa linha só;
 * o bloco completo entra se um dia houver nota com eles. Uma página só:
 * descrição e informações longas são cortadas com "...", como no oficial.
 *
 * Fontes: a tc-lib-pdf procura as métricas pela constante global
 * K_PATH_FONTS. {@see gerar} a define apontando para `resources/danfse/fonts`
 * quando a aplicação ainda não a definiu; se a aplicação já usa outra pasta
 * de fontes, copie `helvetica.json` e `helveticab.json` para lá.
 */
final class DanfseOficial
{
	public const NS = 'http://www.sped.fazenda.gov.br/nfse';

	/** Colunas (mm): a x do texto no oficial; largura útil de cada uma. */
	private const C1 = 4.2;
	private const C2 = 55.2;
	private const C3 = 106.2;
	private const C4 = 157.2;
	private const W = 49.6;
	/** Altura de uma linha de campos (19,08 pt no oficial). */
	private const PASSO = 6.73;
	/** Traços: 0,5 pt nas divisões, 1 pt na moldura e no canhoto. */
	private const FINA = 0.5 * 25.4 / 72;
	private const GROSSA = 25.4 / 72;
	/**
	 * A lib põe a linha de base a (topo da FontBBox × corpo) abaixo do topo da
	 * célula: 931/1000 na Helvetica, 962/1000 na Bold (medido contra o oficial).
	 */
	private const TOPO_BBOX = ['' => 0.931, 'B' => 0.962];

	private const CINZA = '#f2f2f2';
	private const VERMELHO = '#ff0000';
	private const CINZA_LOGO = '#70758d';

	private const UF_IBGE = [
		'11' => 'RO', '12' => 'AC', '13' => 'AM', '14' => 'RR', '15' => 'PA', '16' => 'AP', '17' => 'TO',
		'21' => 'MA', '22' => 'PI', '23' => 'CE', '24' => 'RN', '25' => 'PB', '26' => 'PE', '27' => 'AL',
		'28' => 'SE', '29' => 'BA', '31' => 'MG', '32' => 'ES', '33' => 'RJ', '35' => 'SP', '41' => 'PR',
		'42' => 'SC', '43' => 'RS', '50' => 'MS', '51' => 'MT', '52' => 'GO', '53' => 'DF',
	];

	private Tcpdf $pdf;
	private \DOMXPath $x;

	/** Pasta dos recursos do DANFSe (logo e métricas das fontes). */
	public const PASTA_RECURSOS = __DIR__.'/../../resources/danfse';

	/**
	 * @param (\Closure(string): ?string)|null $municipio nome do município pelo código
	 *                                                    IBGE (null quando não souber)
	 * @param string                           $criador   o campo Creator do PDF
	 */
	public function __construct(
		private ?\Closure $municipio = null,
		private string $criador = 'arijs/nfse-nacional',
	) {
	}

	/**
	 * Os campos já no formato do DANFSe oficial (o que vai em cada caixa).
	 *
	 * @return array<string,string>
	 */
	public function campos(string $xml, bool $cancelada = false): array
	{
		$this->carregar($xml);
		$inf = $this->no('//n:infNFSe');
		$dps = $this->no('//n:infDPS');
		$emit = $this->no('n:emit', $inf);
		$toma = $this->no('n:toma', $dps);
		$cServ = $this->no('n:serv/n:cServ', $dps);
		$vInf = $this->no('n:valores', $inf);
		$vDps = $this->no('n:valores', $dps);
		// IBS/CBS (reforma tributária): a classificação vem da DPS (o que o
		// emitente declarou), os valores do grupo da NFS-e (o que a Sefin calculou).
		$ibsDps = $this->no('n:IBSCBS', $dps);
		$ibsNf = $this->no('n:IBSCBS', $inf);
		// A classificação fica em valores/trib/gIBSCBS (leiaute 1.01) ou em valores/trib (NT 009).
		$ibsTrib = $this->no('n:valores/n:trib/n:gIBSCBS', $ibsDps) ?? $this->no('n:valores/n:trib', $ibsDps);
		$ibsVal = $this->no('n:valores', $ibsNf);
		$ibsTot = $this->no('n:totCIBS', $ibsNf);
		$cLocIbs = $this->t('n:cLocalidadeIncid', $ibsNf);
		$vIbs = (float) $this->t('n:gIBS/n:vIBSTot', $ibsTot);
		$vCbs = (float) $this->t('n:gCBS/n:vCBS', $ibsTot);
		$vLiq = $this->t('n:vLiq', $vInf);

		$cMunEmit = $this->t('n:enderNac/n:cMun', $emit);
		$cMunToma = $this->t('n:end/n:endNac/n:cMun', $toma);
		$tpAmb = $this->t('n:tpAmb', $dps);
		$opSimp = $this->t('n:prest/n:regTrib/n:opSimpNac', $dps);

		return [
			'chave' => preg_replace('/^NFS/', '', (string) $inf?->attributes?->getNamedItem('Id')?->nodeValue) ?? '',
			'municipio_emissor' => trim($this->t('n:xLocEmi', $inf).' - '.self::uf($cMunEmit), ' -'),
			'amb_gerador' => $this->t('n:ambGer', $inf),
			'tp_amb' => $tpAmb,
			'sem_validade' => '2' === $tpAmb ? '1' : '',
			'cancelada' => $cancelada ? '1' : '',
			'numero' => $this->t('n:nNFSe', $inf),
			'competencia' => self::data($this->t('n:dCompet', $dps)),
			'dh_nfse' => self::dataHora($this->t('n:dhProc', $inf)),
			'n_dps' => $this->t('n:nDPS', $dps),
			'serie' => $this->t('n:serie', $dps),
			'dh_dps' => self::dataHora($this->t('n:dhEmi', $dps)),
			'emitente' => ['1' => 'Prestador', '2' => 'Tomador', '3' => 'Intermediário'][$this->t('n:tpEmit', $dps)] ?? '-',
			'situacao' => $cancelada ? 'NFS-e Cancelada' : ([
				'100' => 'NFS-e Gerada', '101' => 'NFS-e de Substituição Gerada',
				'102' => 'NFS-e de Decisão Judicial', '103' => 'NFS-e Avulsa',
			][$this->t('n:cStat', $inf)] ?? '-'),
			'finalidade' => '0' === $this->t('n:finNFSe', $dps) ? 'NFS-e regular' : '-',

			'prest_doc' => self::doc($this->t('n:CNPJ', $emit) ?: $this->t('n:CPF', $emit)),
			'prest_im' => $this->t('n:IM', $emit) ?: $this->t('n:prest/n:IM', $dps),
			'prest_fone' => self::fone($this->t('n:fone', $emit) ?: $this->t('n:prest/n:fone', $dps)),
			'prest_nome' => $this->t('n:xNome', $emit),
			'prest_mun' => $this->t('n:xLocEmi', $inf).' / '.self::uf($cMunEmit),
			'prest_ibge_cep' => self::ibge($cMunEmit).' / '.self::cep($this->t('n:enderNac/n:CEP', $emit)),
			'prest_end' => self::endereco($this->no('n:enderNac', $emit)),
			'prest_email' => $this->t('n:email', $emit) ?: $this->t('n:prest/n:email', $dps),
			// O oficial corta por NÚMERO DE CARACTERES (40 aqui, 170 na descrição
			// da tributação), não por largura: "Optante - Microempresa ou Empresa de ...".
			'simples' => self::limitar([
				'1' => 'Não Optante',
				'2' => 'Optante - Microempreendedor Individual (MEI)',
				'3' => 'Optante - Microempresa ou Empresa de Pequeno Porte (ME/EPP)',
			][$opSimp] ?? '-', 40),
			'regime_sn' => [
				'1' => 'Regime de apuração dos tributos federais e municipal pelo Simples Nacional',
				'2' => 'Regime de apuração dos tributos federais pelo SN e o ISSQN pela NFS-e conforme respectiva legislação municipal do tributo',
				'3' => 'Regime de apuração dos tributos federais e municipal pela NFS-e conforme respectivas legislações federal e municipal de cada tributo',
			][$this->t('n:prest/n:regTrib/n:regApTribSN', $dps)] ?? '-',

			'toma_doc' => self::doc($this->t('n:CNPJ', $toma) ?: $this->t('n:CPF', $toma) ?: $this->t('n:NIF', $toma)),
			'toma_im' => $this->t('n:IM', $toma),
			'toma_fone' => self::fone($this->t('n:fone', $toma)),
			'toma_nome' => $this->t('n:xNome', $toma),
			'toma_mun' => '' === $cMunToma ? '' : $this->nomeMunicipio($cMunToma, $cMunEmit).' / '.self::uf($cMunToma),
			'toma_ibge_cep' => '' === $cMunToma ? '' : self::ibge($cMunToma).' / '.self::cep($this->t('n:end/n:endNac/n:CEP', $toma)),
			'toma_end' => self::endereco($this->no('n:end', $toma)),
			'toma_email' => $this->t('n:email', $toma),
			'destinatario' => self::parteOpcional($this->no('n:dest', $dps)),
			'intermediario' => self::parteOpcional($this->no('n:interm', $dps)),

			'trib_nac_mun' => self::codTribNac($this->t('n:cTribNac', $cServ)).' / '.($this->t('n:cTribMun', $cServ) ?: '-'),
			'nbs' => self::nbs($this->t('n:cNBS', $cServ)),
			'local_prestacao' => $this->t('n:xLocPrestacao', $inf).' / '.self::uf($this->t('n:serv/n:locPrest/n:cLocPrestacao', $dps)).' / -',
			'desc_trib' => self::limitar($this->t('n:xTribMun', $inf) ?: $this->t('n:xTribNac', $inf), 170),
			'descricao' => $this->t('n:xDescServ', $cServ),

			'trib_issqn' => [
				'1' => 'Operação Tributável', '2' => 'Imunidade', '3' => 'Exportação de Serviço', '4' => 'Não Incidência',
			][$this->t('n:trib/n:tribMun/n:tribISSQN', $vDps)] ?? '-',
			'incidencia' => $this->t('n:xLocIncid', $inf).' / '.self::uf($this->t('n:cLocIncid', $inf)).' / -',
			'bc_issqn' => self::reais($this->t('n:vBC', $vInf)),
			'aliquota' => self::pct($this->t('n:pAliqAplic', $vInf)),
			'retencao' => ['1' => 'Não Retido', '2' => 'Retido pelo Tomador', '3' => 'Retido pelo Intermediário'][$this->t('n:trib/n:tribMun/n:tpRetISSQN', $vDps)] ?? '-',
			'issqn' => self::reais($this->t('n:vISSQN', $vInf)),

			'irrf' => self::reais($this->t('n:trib/n:tribFed/n:vRetIRRF', $vDps)),
			'cp' => self::reais($this->t('n:trib/n:tribFed/n:vRetCP', $vDps)),
			'csll' => self::reais($this->t('n:trib/n:tribFed/n:vRetCSLL', $vDps)),
			'pis' => self::reais($this->t('n:trib/n:tribFed/n:piscofins/n:vPis', $vDps)),
			'cofins' => self::reais($this->t('n:trib/n:tribFed/n:piscofins/n:vCofins', $vDps)),

			'v_servico' => self::reais($this->t('n:vServPrest/n:vServ', $vDps)),
			'desc_incond' => self::reais($this->t('n:vDescCondIncond/n:vDescIncond', $vDps)),
			'desc_cond' => self::reais($this->t('n:vDescCondIncond/n:vDescCond', $vDps)),
			'v_retencoes' => self::reais($this->t('n:vTotalRet', $vInf)),
			'v_liquido' => self::reais($this->t('n:vLiq', $vInf)),
			// IBS/CBS (NT 004): o bloco "TRIBUTAÇÃO IBS/CBS" do oficial. Sem o
			// grupo, as caixas saem como no oficial de uma nota sem ele.
			'ibs_cst_class' => ($this->t('n:CST', $ibsTrib) ?: '-').' / '.($this->t('n:cClassTrib', $ibsTrib) ?: '-'),
			'ibs_operacao' => implode(' / ', [
				$this->t('n:cIndOp', $ibsDps) ?: '-',
				'' === $cLocIbs ? '-' : self::ibge($cLocIbs),
				'' === $cLocIbs ? '-' : ($this->t('n:xLocalidadeIncid', $ibsNf) ?: $this->nomeMunicipio($cLocIbs, $cMunEmit)),
				'' === $cLocIbs ? '-' : self::uf($cLocIbs),
			]),
			'ibs_exclusoes' => null === $ibsVal ? 'R$ 0,00' : self::reais((string) array_sum(array_map(
				fn (string $e): float => (float) $this->t('n:'.$e, $ibsVal),
				['vCalcAjusteBCLocImoveis', 'vCalcAjusteBCIBSCBS', 'vCalcReeRepRes'],
			))),
			'ibs_bc' => self::reais($this->t('n:vBC', $ibsVal)),
			'ibs_red_aliq' => null === $ibsVal ? '- / - / -' : implode(' / ', [
				self::pct($this->t('n:uf/n:pRedAliqUF', $ibsVal)) ?: '-',
				self::pct($this->t('n:mun/n:pRedAliqMun', $ibsVal)) ?: '-',
				self::pct($this->t('n:fed/n:pRedAliqCBS', $ibsVal)) ?: '-',
			]),
			'ibs_aliq_uf_mun' => null === $ibsVal ? '- / -' : (self::pct($this->t('n:uf/n:pIBSUF', $ibsVal)) ?: '-').' / '.(self::pct($this->t('n:mun/n:pIBSMun', $ibsVal)) ?: '-'),
			'ibs_aliq_efet_mun' => self::pct($this->t('n:mun/n:pAliqEfetMun', $ibsVal)),
			'ibs_v_mun' => self::reais($this->t('n:gIBS/n:gIBSMunTot/n:vIBSMun', $ibsTot)),
			'ibs_aliq_efet_uf' => self::pct($this->t('n:uf/n:pAliqEfetUF', $ibsVal)),
			'ibs_v_uf' => self::reais($this->t('n:gIBS/n:gIBSUFTot/n:vIBSUF', $ibsTot)),
			'ibs_v_tot' => self::reais($this->t('n:gIBS/n:vIBSTot', $ibsTot)),
			'cbs_aliq' => self::pct($this->t('n:fed/n:pCBS', $ibsVal)),
			'cbs_aliq_efet' => self::pct($this->t('n:fed/n:pAliqEfetCBS', $ibsVal)),
			'cbs_v' => self::reais($this->t('n:gCBS/n:vCBS', $ibsTot)),
			// Sem o grupo IBSCBS, o total de IBS/CBS é zero e o líquido
			// "+ IBS/CBS" é o próprio líquido. (O oficial imprime R$ 0,00 aí —
			// erro dele; aqui sai o valor.) Com o grupo, IBS e CBS são por fora:
			// o total é vIBSTot + vCBS e o líquido "+ IBS/CBS" soma os dois.
			'v_ibscbs' => self::reais(null === $ibsTot ? '0' : (string) ($vIbs + $vCbs)),
			'v_liquido_ibscbs' => '' === $vLiq ? 'R$ 0,00' : self::reais((string) ((float) $vLiq + $vIbs + $vCbs)),

			'info' => trim(sprintf(
				'Totais aproximados dos Tributos cfe. Lei n° 12.741/2012: Federais: %s; Estaduais: %s; Municipais: %s; %s',
				$this->totTrib($vDps, 'Fed'), $this->totTrib($vDps, 'Est'), $this->totTrib($vDps, 'Mun'),
				$this->t('n:serv/n:infoCompl/n:xInfComp', $dps),
			)),
			'qr' => self::linkConsulta((string) preg_replace('/^NFS/', '', (string) $inf?->attributes?->getNamedItem('Id')?->nodeValue), $tpAmb),
		];
	}

	/** O PDF (bytes). */
	public function gerar(string $xml, bool $cancelada = false, bool $comprimir = true): string
	{
		$c = $this->campos($xml, $cancelada);

		if (!\defined('K_PATH_FONTS')) {
			\define('K_PATH_FONTS', self::PASTA_RECURSOS.'/fonts');
		}
		$this->pdf = new Tcpdf(unit: Unit::Millimeter, isunicode: true, subsetfont: false, compress: $comprimir);
		$this->pdf->setCreator($this->criador);
		$this->pdf->setTitle('DANFSe '.$c['chave']);
		$this->pdf->setPDFFilename('DANFSe-'.$c['chave'].'.pdf');
		$this->pdf->setDefaultCellPadding(0, 0, 0, 0);
		$this->pdf->setDefaultCellMargin(0, 0, 0, 0);
		$this->pdf->addPage(['format' => 'A4', 'orientation' => 'P', 'margin' => ['PL' => 0, 'PR' => 0, 'PT' => 0, 'HB' => 0, 'CT' => 0, 'CB' => 0, 'FT' => 0, 'PB' => 0]]);

		// Todas as medidas abaixo são do DANFSe oficial (PDFsharp) da nota #913:
		// textos pela LINHA DE BASE, em mm a partir do topo da página.
		$this->cabecalho($c);
		$this->blocoNota($c);
		$this->blocoParte(44.30, 'PRESTADOR / FORNECEDOR', 'prest', $c);
		$this->blocoParte(71.40, 'TOMADOR / ADQUIRENTE', 'toma', $c);
		$this->linha(91.76);
		$this->escrever($c['destinatario'] ?: 'DESTINATÁRIO DA OPERAÇÃO NÃO IDENTIFICADO NA NFS-e', 105.0, 94.12, 7, false, null, 'C');
		$this->linha(94.73);
		$this->escrever($c['intermediario'] ?: 'INTERMEDIÁRIO DA OPERAÇÃO NÃO IDENTIFICADO NA NFS-e', 105.0, 97.09, 7, false, null, 'C');
		$this->blocoServico($c);
		$this->blocoTributos($c);
		$this->blocoValores($c);
		$this->canhoto($c);
		// Moldura: 1 pt, como o `5 5 585 832 re` do oficial.
		$this->pdf->page->addContent($this->pdf->graph->getRect(1.76, 1.76, 206.37, 293.51, 'S', ['all' => $this->traco(self::GROSSA)]));

		return $this->pdf->getOutPDFString();
	}

	// ─────────────────────────────── blocos ─────────────────────────────────

	/** @param array<string,string> $c */
	private function cabecalho(array $c): void
	{
		$this->fundo(3.0, 2.0, 51.0, 12.0);
		$this->fundo(54.0, 2.0, 102.0, 12.0);
		$this->fundo(156.0, 2.0, 51.0, 12.0);

		// Logo vetorial do Emissor Nacional, na caixa do oficial (x 4,2, topo
		// 3,64, 40,8 × 8,08). O <text> "Nota Fiscal de Serviço eletrônica" do
		// SVG pede Segoe UI e foi removido: o texto vai aqui, na posição e na
		// cor do original.
		$larg = 40.8;
		$alt = $larg * 48.502 / 231.505;
		$topo = 3.64 + (8.08 - $alt) / 2;
		// '@' = SVG em string: a leitura de arquivo da lib passa por allowlist.
		$svg = $this->pdf->addSVG('@'.file_get_contents(self::PASTA_RECURSOS.'/logo-nfse.svg'), 4.2, $topo, $larg, $alt);
		$this->pdf->page->addContent($this->pdf->getSetSVG($svg));
		$xTexto = 4.2 + $larg * (1418 - 1295.495) / 231.505;
		$tam = $alt * 14 / 48.502 * 72 / 25.4;
		$this->escrever('Nota Fiscal de', $xTexto, $topo + $alt * 31.5 / 48.502, $tam, false, self::CINZA_LOGO);
		$this->escrever('Serviço eletrônica', $xTexto, $topo + $alt * 44.5 / 48.502, $tam, false, self::CINZA_LOGO);

		$this->escrever('DANFSe v2.0', 105.0, 5.50, 9, true, null, 'C');
		$this->escrever('Documento Auxiliar da NFS-e', 105.0, 9.15, 9, true, null, 'C');
		$aviso = array_filter([$c['sem_validade'] ? 'NFS-e SEM VALIDADE JURÍDICA' : '', $c['cancelada'] ? 'NFS-e CANCELADA' : '']);
		if ([] !== $aviso) {
			$this->escrever(implode(' · ', $aviso), 105.0, 12.80, 9, true, self::VERMELHO, 'C');
		}

		$this->escrever('Município: '.$c['municipio_emissor'], self::C4, 6.61, 8);
		$this->escrever('Ambiente Gerador: '.$c['amb_gerador'], self::C4, 9.15, 6);
		$this->escrever('Tipo de Ambiente: '.$c['tp_amb'], self::C4, 11.55, 6);
		$this->linha(14.09);
	}

	/** @param array<string,string> $c */
	private function blocoNota(array $c): void
	{
		// Neste bloco os rótulos são de 7 pt (nos outros, 6) e as linhas têm 7,13 mm.
		$this->campo('CHAVE DE ACESSO DA NFS-e', $c['chave'], self::C1, 17.99, 150, 7);
		$this->campo('NÚMERO DA NFS-e', $c['numero'], self::C1, 25.13, self::W, 7);
		$this->campo('COMPETÊNCIA DA NFS-e', $c['competencia'], self::C2, 25.13, self::W, 7);
		$this->campo('DATA E HORA DA EMISSÃO DA NFS-e', $c['dh_nfse'], self::C3, 25.13, self::W, 7);
		$this->campo('NÚMERO DA DPS', $c['n_dps'], self::C1, 32.26, self::W, 7);
		$this->campo('SÉRIE DA DPS', $c['serie'], self::C2, 32.26, self::W, 7);
		$this->campo('DATA E HORA DA EMISSÃO DA DPS', $c['dh_dps'], self::C3, 32.26, self::W, 7);
		$this->fundo(3.0, 37.08, 51.0, 7.13);
		$this->campo('EMITENTE DA NFS-e', $c['emitente'], self::C1, 39.40, self::W, 7);
		$this->campo('SITUAÇÃO DA NFS-e', $c['situacao'], self::C2, 39.40, self::W, 7);
		$this->campo('FINALIDADE', $c['finalidade'], self::C3, 39.40, self::W, 7);

		$this->qrCode($c['qr'], 173.56, 15.79, 15.87);
		$this->escrever('A autenticidade desta NFS-e pode ser verificada', self::C4, 34.37, 6);
		$this->escrever('pela leitura deste código QR ou pela consulta da', self::C4, 36.76, 6);
		$this->escrever('chave de acesso no portal nacional da NFS-e', self::C4, 39.16, 6);
		$this->linha(44.30);
	}

	/** @param array<string,string> $c */
	private function blocoParte(float $y, string $titulo, string $p, array $c): void
	{
		$this->cabecalhoBloco($y, $titulo);
		$this->campo('CNPJ / CPF / NIF', $c[$p.'_doc'], self::C2, $y + 2.08);
		$this->campo('Indicador Municipal (Inscrição)', $c[$p.'_im'], self::C3, $y + 2.08);
		$this->campo('Telefone', $c[$p.'_fone'], self::C4, $y + 2.08);
		$this->campo('Nome / Nome Empresarial', $c[$p.'_nome'], self::C1, $y + 8.81, 100);
		$this->campo('Município / Sigla UF', $c[$p.'_mun'], self::C3, $y + 8.81);
		$this->campo('Código IBGE / CEP', $c[$p.'_ibge_cep'], self::C4, $y + 8.81);
		$this->campo('Endereço', $c[$p.'_end'], self::C1, $y + 15.53, 100);
		$this->campo('E-mail', $c[$p.'_email'], self::C3, $y + 15.53, 100);
		if ('prest' === $p) {
			$this->campo('Simples Nacional na Data de Competência', $c['simples'], self::C1, $y + 22.26);
			$this->campo('Regime de Apuração Tributária pelo SN', $c['regime_sn'], self::C2, $y + 22.26, 150);
		}
	}

	/** @param array<string,string> $c */
	private function blocoServico(array $c): void
	{
		$y = 97.70;
		$this->cabecalhoBloco($y, 'SERVIÇO PRESTADO');
		$this->campo('Código de Tributação Nacional/Municipal', $c['trib_nac_mun'], self::C2, $y + 2.08);
		$this->campo('Código da NBS', $c['nbs'], self::C3, $y + 2.08);
		$this->campo('Local da Prestação / Sigla UF / País', $c['local_prestacao'], self::C4, $y + 2.08);
		$this->escrever($this->cortar($c['desc_trib'], 200.8, 7), self::C1, 106.80, 7);
		$this->campo('Descrição do Serviço', $c['descricao'], self::C1, 110.80, 200.8);
	}

	/** @param array<string,string> $c */
	private function blocoTributos(array $c): void
	{
		$y = 115.63;
		$this->cabecalhoBloco($y, 'TRIBUTAÇÃO MUNICIPAL (ISSQN)');
		$this->campo('Tipo de Tributação do ISSQN', $c['trib_issqn'], self::C2, $y + 2.08);
		$this->campo('Município / Sigla UF / País de Incidência do ISSQN', $c['incidencia'], self::C3, $y + 2.08, 100);
		$this->campo('BC ISSQN', $c['bc_issqn'], self::C1, $y + 8.81);
		$this->campo('Alíquota Aplicada', $c['aliquota'], self::C2, $y + 8.81);
		$this->campo('Retenção do ISSQN', $c['retencao'], self::C3, $y + 8.81);
		$this->campo('ISSQN Apurado', $c['issqn'], self::C4, $y + 8.81);

		$y = 129.27;
		$this->cabecalhoBloco($y, 'TRIBUTAÇÃO FEDERAL (EXCETO CBS)');
		$this->campo('IRRF', $c['irrf'], self::C2, $y + 2.08);
		$this->campo('Contribuição Previdenciária - Retida', $c['cp'], self::C3, $y + 2.08);
		$this->campo('Contribuições Sociais - Retidas', $c['csll'], self::C4, $y + 2.08);
		$this->campo('PIS - Débito Apuração Própria', $c['pis'], self::C1, $y + 8.81);
		$this->campo('COFINS - Débito Apuração Própria', $c['cofins'], self::C2, $y + 8.81);
		$this->campo('Descrição Contrib. Sociais - Retidas', '', self::C3, $y + 8.81);

		// IBS/CBS (NT 004, reforma tributária): a classificação da DPS e os
		// valores que a Sefin calculou; numa nota sem o grupo IBSCBS as caixas
		// saem como no oficial ("- / -", "R$ 0,00", vazias).
		$y = 142.90;
		$this->cabecalhoBloco($y, 'TRIBUTAÇÃO IBS/CBS');
		$this->campo('CST / cClassTrib', $c['ibs_cst_class'], self::C2, $y + 2.08);
		$this->campo('Indicador de Operação / Código IBGE Incidência / Município Incidência / Sigla UF', $c['ibs_operacao'], self::C3, $y + 2.08, 100);
		$linhas = [
			['Exclusões e Reduções da Base de Cálculo', $c['ibs_exclusoes'], 'Base de Cálculo Após Exclusões e Reduções', $c['ibs_bc'], 'Red. Alíquota IBS / Red. Alíquota CBS', $c['ibs_red_aliq'], 'Alíquota - IBS UF / IBS Mun', $c['ibs_aliq_uf_mun']],
			['Alíq. Efetiva Municipal - IBS', $c['ibs_aliq_efet_mun'], 'Valor Apurado Municipal - IBS', $c['ibs_v_mun'], 'Alíq. Efetiva Estadual - IBS', $c['ibs_aliq_efet_uf'], 'Valor Apurado Estadual - IBS', $c['ibs_v_uf']],
			['Valor Total Apurado - IBS', $c['ibs_v_tot'], 'Alíquota - CBS', $c['cbs_aliq'], 'Alíquota Efetiva - CBS', $c['cbs_aliq_efet'], 'Valor Total Apurado - CBS', $c['cbs_v']],
		];
		foreach ($linhas as $i => $l) {
			$ly = $y + 8.80 + $i * self::PASSO;
			$this->campo($l[0], $l[1], self::C1, $ly);
			$this->campo($l[2], $l[3], self::C2, $ly);
			$this->campo($l[4], $l[5], self::C3, $ly);
			$this->campo($l[6], $l[7], self::C4, $ly);
		}
	}

	/** @param array<string,string> $c */
	private function blocoValores(array $c): void
	{
		$y = 169.99;
		$this->cabecalhoBloco($y, 'VALOR TOTAL DA NFS-e');
		$this->campo('VALOR DA OPERAÇÃO / SERVIÇO', $c['v_servico'], self::C2, $y + 2.08);
		$this->campo('Desconto Incondicionado', $c['desc_incond'], self::C3, $y + 2.08);
		$this->campo('Desconto Condicionado', $c['desc_cond'], self::C4, $y + 2.08);
		$this->fundo(156.0, 176.81, 51.0, self::PASSO);
		$this->campo('Total das Retenções (ISSQN / Federais)', $c['v_retencoes'], self::C1, $y + 8.80);
		$this->campo('VALOR LÍQUIDO DA NFS-e', $c['v_liquido'], self::C2, $y + 8.80);
		$this->campo('Total do IBS/CBS', $c['v_ibscbs'], self::C3, $y + 8.80);
		$this->campo('VALOR LÍQUIDO DA NFS-e + IBS/CBS', $c['v_liquido_ibscbs'], self::C4, $y + 8.80);

		$this->linha(183.63);
		$this->escrever('INFORMAÇÕES COMPLEMENTARES', self::C1, 186.03, 7, true);
		$this->escrever($c['info'], self::C1, 193.13, 7);
	}

	/** @param array<string,string> $c */
	private function canhoto(array $c): void
	{
		$g = $this->traco(self::GROSSA);
		foreach ([280.74, 287.82] as $y) {
			$this->pdf->page->addContent($this->pdf->graph->getLine(3.0, $y, 207.35, $y, $g));
		}
		foreach ([3.18, 54.18, 105.18, 207.18] as $x) {
			$this->pdf->page->addContent($this->pdf->graph->getLine($x, 280.92, $x, 288.0, $g));
		}
		$this->escrever('DATA CIENTIFICAÇÃO:', 4.55, 282.90, 6, true);
		$this->escrever('IDENTIFICAÇÃO E ASSINATURA', 55.55, 282.90, 6, true);
		$this->escrever('N° NFS-e / CHAVE NFS-e', 106.55, 282.90, 6, true);
		$this->escrever($c['numero'].' / '.$c['chave'], 106.55, 285.63, 7);
	}

	// ─────────────────────────────── desenho ────────────────────────────────

	/** Linha do bloco, faixa cinza de 51 mm na 1ª coluna e o título (7 pt). */
	private function cabecalhoBloco(float $y, string $titulo): void
	{
		// A faixa ANTES da linha: desenhada depois, cobriria metade do traço.
		$this->fundo(3.0, $y + 0.09, 51.0, self::PASSO);
		$this->linha($y);
		$this->escrever($titulo, self::C1, $y + 2.41, 7, true);
	}

	/**
	 * Rótulo em negrito e o valor embaixo ('-' quando vazio, como no oficial).
	 * `$base` é a linha de base do RÓTULO; a do valor vem 2,72 mm abaixo
	 * (2,80 quando o rótulo é de 7 pt).
	 */
	private function campo(string $rotulo, string $valor, float $x, float $base, float $larg = self::W, float $tamRotulo = 6): void
	{
		$this->escrever($rotulo, $x, $base, $tamRotulo, true);
		$valor = '' === trim($valor) ? '-' : $valor;
		$this->escrever($this->cortar($valor, $larg, 7), $x, $base + (7.0 === $tamRotulo ? 2.80 : 2.72), 7);
	}

	/**
	 * Texto numa linha, posicionado pela LINHA DE BASE (como o oficial). A lib
	 * posiciona pelo topo da célula: topo = base − topo da FontBBox.
	 */
	private function escrever(string $s, float $x, float $base, float $tam, bool $negrito = false, ?string $cor = null, string $alinhar = 'L'): void
	{
		if ('' === $s) {
			return;
		}
		$fonte = $this->pdf->font->insert($this->pdf->pon, 'helvetica', $negrito ? 'B' : '', $tam);
		$this->pdf->page->addContent($fonte['out']);
		$this->pdf->page->addContent($this->pdf->color->getPdfColor($cor ?? 'black'));
		$larg = 0.0;
		if ('C' === $alinhar) {
			$larg = 2 * min($x - 2.0, 208.0 - $x);
			$x -= $larg / 2;
		}
		$topo = $base - self::TOPO_BBOX[$negrito ? 'B' : ''] * $tam * 25.4 / 72;
		$this->pdf->page->addContent($this->pdf->getTextCell($s, $x, $topo, $larg, 0, 0, 0, 'T', $alinhar, drawcell: false));
	}

	/**
	 * Corta com "..." o que não cabe numa linha, como o oficial: caractere a
	 * caractere, sem tirar o espaço antes dos pontos ("Empresa de ...").
	 */
	private function cortar(string $s, float $larg, float $tam): string
	{
		$fonte = $this->pdf->font->insert($this->pdf->pon, 'helvetica', '', $tam);
		$this->pdf->page->addContent($fonte['out']);
		$mede = fn (string $t): float => $this->pdf->toUnit($this->pdf->font->getOrdArrWidth($this->pdf->uniconv->strToOrdArr($t)));
		if ($mede($s) <= $larg) {
			return $s;
		}
		while ('' !== $s && $mede($s.'...') > $larg) {
			$s = mb_substr($s, 0, -1);
		}

		return $s.'...';
	}

	/**
	 * O QR do Emissor Nacional: versão 7, nível M, UM segmento em modo byte —
	 * o mesmo símbolo, módulo a módulo (ver QrCodeByte).
	 *
	 * Em BITMAP, como o oficial (em vetor os módulos pareciam mais grossos com o
	 * zoom reduzido): PNG RGB de 490 × 490 px, 10 px por
	 * módulo e 20 px de margem branca, posto na mesma caixa (45 pt) e com
	 * /Interpolate true — o visualizador suaviza ao reduzir, como no oficial.
	 */
	private function qrCode(string $conteudo, float $x, float $y, float $lado): void
	{
		$iid = $this->pdf->image->add('@'.self::pngQr((new QrCodeByte($conteudo, 1, 0, false))->getGrid()));
		$this->interpolar();
		$this->pdf->page->addContent($this->pdf->image->getSetImage($iid, $x, $y, $lado, $lado, $this->pdf->page->getPage()['height']));
	}

	/**
	 * PNG RGB 8 bits da grade do QR, no formato da imagem oficial: 10 px por
	 * módulo, 2 módulos de margem. Em PHP puro (zlib), sem depender do GD.
	 *
	 * @param list<string> $grade linhas de '0'/'1'
	 */
	public static function pngQr(array $grade, int $px = 10, int $margem = 2): string
	{
		$lado = (\count($grade) + 2 * $margem) * $px;
		$branca = str_repeat("\xFF\xFF\xFF", $lado);
		$linhas = array_fill(0, $margem * $px, "\0".$branca);
		foreach ($grade as $linha) {
			$pixels = str_repeat("\xFF\xFF\xFF", $margem * $px);
			foreach (str_split($linha) as $m) {
				$pixels .= str_repeat('1' === $m ? "\0\0\0" : "\xFF\xFF\xFF", $px);
			}
			$pixels .= str_repeat("\xFF\xFF\xFF", $margem * $px);
			array_push($linhas, ...array_fill(0, $px, "\0".$pixels));
		}
		array_push($linhas, ...array_fill(0, $margem * $px, "\0".$branca));

		$bloco = static fn (string $tipo, string $dados): string => pack('N', \strlen($dados)).$tipo.$dados.pack('N', crc32($tipo.$dados));

		return "\x89PNG\r\n\x1a\n"
			.$bloco('IHDR', pack('NNCCCCC', $lado, $lado, 8, 2, 0, 0, 0))
			.$bloco('IDAT', (string) gzcompress(implode('', $linhas), 9))
			.$bloco('IEND', '');
	}

	/**
	 * Põe /Interpolate true nas imagens importadas. A tc-lib-pdf não tem opção
	 * para isso; o `parms` de cada imagem entra literal no dicionário do
	 * XObject (é onde vai o /DecodeParms do PNG), então a marca vai junto.
	 * ponytail: reflexão num `protected` da lib — se a versão mudar e o campo
	 * sumir, o teste do DANFSe (que procura /Interpolate no PDF) acusa.
	 */
	private function interpolar(): void
	{
		$prop = new \ReflectionProperty($this->pdf->image, 'cache');
		$cache = $prop->getValue($this->pdf->image);
		foreach ($cache as $k => $dados) {
			if (\is_array($dados) && isset($dados['parms']) && !str_contains((string) $dados['parms'], '/Interpolate')) {
				$cache[$k]['parms'] = trim($dados['parms'].' /Interpolate true');
			}
		}
		$prop->setValue($this->pdf->image, $cache);
	}

	private function linha(float $y): void
	{
		$this->pdf->page->addContent($this->pdf->graph->getLine(3.0, $y, 207.0, $y, $this->traco()));
	}

	private function fundo(float $x, float $y, float $w, float $h): void
	{
		$this->pdf->page->addContent($this->pdf->graph->getRect($x, $y, $w, $h, 'F', ['all' => $this->preenchimento(self::CINZA)]));
	}

	/** @return array<string,mixed> */
	private function traco(float $espessura = self::FINA): array
	{
		return ['lineWidth' => $espessura, 'lineCap' => 'butt', 'lineJoin' => 'miter', 'dashArray' => [], 'dashPhase' => 0, 'lineColor' => 'black', 'fillColor' => 'white'];
	}

	/** @return array<string,mixed> */
	private function preenchimento(string $cor): array
	{
		return ['lineWidth' => 0, 'lineCap' => 'butt', 'lineJoin' => 'miter', 'dashArray' => [], 'dashPhase' => 0, 'lineColor' => $cor, 'fillColor' => $cor];
	}

	// ─────────────────────────────── XML ────────────────────────────────────

	private function carregar(string $xml): void
	{
		$doc = new \DOMDocument();
		if ('' === trim($xml) || !@$doc->loadXML($xml, \LIBXML_NONET)) {
			throw new \InvalidArgumentException('XML da NFS-e ilegível.');
		}
		$this->x = new \DOMXPath($doc);
		$this->x->registerNamespace('n', self::NS);
		if (null === $this->no('//n:infNFSe')) {
			throw new \InvalidArgumentException('O XML não é uma NFS-e autorizada (sem infNFSe).');
		}
	}

	private function no(string $caminho, ?\DOMNode $ctx = null): ?\DOMElement
	{
		if (null === $ctx && !str_starts_with($caminho, '//')) {
			return null;
		}
		$r = $this->x->query($caminho, $ctx);
		$n = false === $r ? null : $r->item(0);

		return $n instanceof \DOMElement ? $n : null;
	}

	private function t(string $caminho, ?\DOMNode $ctx): string
	{
		return null === $ctx ? '' : trim((string) $this->no($caminho, $ctx)?->textContent);
	}

	private function nomeMunicipio(string $cMun, string $cMunEmit): string
	{
		if ($cMun === $cMunEmit) {
			return $this->t('n:xLocEmi', $this->no('//n:infNFSe'));
		}

		return (null !== $this->municipio ? ($this->municipio)($cMun) : null) ?? $cMun;
	}

	private function totTrib(?\DOMElement $valores, string $esfera): string
	{
		$v = $this->t('n:trib/n:totTrib/n:vTotTrib/n:vTotTrib'.$esfera, $valores);
		if ('' !== $v) {
			return self::reais($v);
		}
		$p = $this->t('n:trib/n:totTrib/n:pTotTrib/n:pTotTrib'.$esfera, $valores);

		return '' === $p ? '-' : self::pct($p);
	}

	private static function parteOpcional(?\DOMElement $el): string
	{
		if (null === $el) {
			return '';
		}
		$nome = trim((string) $el->getElementsByTagNameNS(self::NS, 'xNome')->item(0)?->textContent);
		$doc = '';
		foreach (['CNPJ', 'CPF', 'NIF'] as $t) {
			$doc = $doc ?: trim((string) $el->getElementsByTagNameNS(self::NS, $t)->item(0)?->textContent);
		}

		return mb_strtoupper('interm' === $el->localName ? 'Intermediário' : 'Destinatário').' DA OPERAÇÃO: '.$nome.('' === $doc ? '' : ' — '.self::doc($doc));
	}

	private static function endereco(?\DOMElement $el): string
	{
		if (null === $el) {
			return '';
		}
		$v = static fn (string $tag): string => trim((string) $el->getElementsByTagNameNS(self::NS, $tag)->item(0)?->textContent);

		return implode(', ', array_filter([$v('xLgr'), $v('nro'), $v('xCpl'), $v('xBairro')], static fn (string $s): bool => '' !== $s));
	}

	// ─────────────────────────────── formatos ───────────────────────────────

	/** Até `$max` caracteres; passando, corta e termina em "..." (cabem $max no total). */
	public static function limitar(string $s, int $max): string
	{
		return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 3).'...' : $s;
	}

	public static function uf(string $cMun): string
	{
		return self::UF_IBGE[substr($cMun, 0, 2)] ?? '-';
	}

	public static function linkConsulta(string $chave, string $tpAmb): string
	{
		$host = '2' === $tpAmb ? 'www.producaorestrita.nfse.gov.br' : 'www.nfse.gov.br';

		// Exatamente o texto do QR oficial (sem barra antes do "?"): o mesmo
		// conteúdo é o que faz o símbolo sair igual.
		return "https://{$host}/ConsultaPublica?tpc=1&chave={$chave}";
	}

	public static function doc(string $d): string
	{
		return match (\strlen($d)) {
			14 => (string) preg_replace('/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/', '$1.$2.$3/$4-$5', $d),
			11 => (string) preg_replace('/^(\d{3})(\d{3})(\d{3})(\d{2})$/', '$1.$2.$3-$4', $d),
			default => $d,
		};
	}

	public static function fone(string $f): string
	{
		$f = (string) preg_replace('/\D/', '', $f);

		return match (\strlen($f)) {
			11 => (string) preg_replace('/^(\d{2})(\d{5})(\d{4})$/', '($1) $2-$3', $f),
			10 => (string) preg_replace('/^(\d{2})(\d{4})(\d{4})$/', '($1) $2-$3', $f),
			default => $f,
		};
	}

	/** `3550308` → `35.50308` (como o oficial). */
	public static function ibge(string $c): string
	{
		return 7 === \strlen($c) ? substr($c, 0, 2).'.'.substr($c, 2) : $c;
	}

	/** `04332050` → `04.332-050` (como o oficial). */
	public static function cep(string $c): string
	{
		return 8 === \strlen($c) ? substr($c, 0, 2).'.'.substr($c, 2, 3).'-'.substr($c, 5) : $c;
	}

	/** `010701` → `01.07.01`. */
	public static function codTribNac(string $c): string
	{
		return 6 === \strlen($c) ? implode('.', str_split($c, 2)) : $c;
	}

	/** `115100000` → `1.1510.00.00`. */
	public static function nbs(string $c): string
	{
		return 9 === \strlen($c) ? $c[0].'.'.substr($c, 1, 4).'.'.substr($c, 5, 2).'.'.substr($c, 7, 2) : ('' === $c ? '-' : $c);
	}

	public static function dataHora(string $v): string
	{
		return preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}:\d{2}:\d{2})/', $v, $m) ? "{$m[3]}/{$m[2]}/{$m[1]} {$m[4]}" : $v;
	}

	public static function data(string $v): string
	{
		return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) ? "{$m[3]}/{$m[2]}/{$m[1]}" : $v;
	}

	public static function reais(string $v): string
	{
		return '' === $v ? '' : 'R$ '.number_format((float) $v, 2, ',', '.');
	}

	public static function pct(string $v): string
	{
		return '' === $v ? '' : number_format((float) $v, 2, ',', '.').'%';
	}
}
