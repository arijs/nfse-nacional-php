<?php declare(strict_types=1);

namespace Arijs\NfseNacional\Dps;

use Arijs\NfseNacional\Excecao\DadosExcecao;
use Arijs\NfseNacional\Leiaute;
use Arijs\NfseNacional\Util\Formato;

/**
 * Gera o XML da DPS (Declaração de Prestação de Serviços) a partir de um
 * array que espelha o leiaute: cada chave é o nome do elemento no XSD, cada
 * array aninhado é um grupo.
 *
 * Por que array e não uma classe por grupo: o leiaute 1.01 tem ~400 campos e
 * muda a cada nota técnica. Espelhar o XSD deixa qualquer campo acessível sem
 * esperar a biblioteca "ganhar" o campo, e a validação XSD (que roda antes de
 * assinar) é quem garante a estrutura. A biblioteca cuida do que é chato:
 *  - ORDEM dos elementos (o XSD é xs:sequence; fora de ordem = E1235);
 *  - o atributo Id de 45 posições do infDPS;
 *  - formatação de números (float → "100.00") e de datas (DateTime → ISO);
 *  - namespace e declaração UTF-8.
 *
 * Chaves `@nome` viram atributos; listas (array_is_list) viram elementos
 * repetidos; null é omitido.
 *
 * @phpstan-type Grupo array<string, mixed>
 */
final class DpsXml
{
	public const NAMESPACE = 'http://www.sped.fazenda.gov.br/nfse';
	public const VERSAO_LEIAUTE = '1.01';

	/**
	 * Ordem dos filhos de cada grupo, extraída dos xs:sequence de
	 * tiposComplexos_v1.01.xsd (leiaute {@see Leiaute::V101}). Chaveada pelo
	 * caminho a partir de infDPS para não confundir grupos homônimos (ex.:
	 * `valores` da DPS × `valores` do IBSCBS). Elementos ausentes daqui mantêm
	 * a ordem em que foram passados.
	 */
	private const ORDEM = [
		'infDPS' => ['tpAmb', 'dhEmi', 'verAplic', 'serie', 'nDPS', 'dCompet', 'tpEmit', 'cMotivoEmisTI', 'chNFSeRej', 'cLocEmi', 'subst', 'prest', 'toma', 'interm', 'serv', 'valores', 'IBSCBS'],
		'infDPS/subst' => ['chSubstda', 'cMotivo', 'xMotivo'],
		'infDPS/prest' => ['CNPJ', 'CPF', 'NIF', 'cNaoNIF', 'CAEPF', 'IM', 'xNome', 'end', 'fone', 'email', 'regTrib'],
		'infDPS/prest/regTrib' => ['opSimpNac', 'regApTribSN', 'regEspTrib'],
		'infDPS/toma' => ['CNPJ', 'CPF', 'NIF', 'cNaoNIF', 'CAEPF', 'IM', 'xNome', 'end', 'fone', 'email'],
		'infDPS/interm' => ['CNPJ', 'CPF', 'NIF', 'cNaoNIF', 'CAEPF', 'IM', 'xNome', 'end', 'fone', 'email'],
		'infDPS/prest/end' => ['endNac', 'endExt', 'xLgr', 'nro', 'xCpl', 'xBairro'],
		'infDPS/toma/end' => ['endNac', 'endExt', 'xLgr', 'nro', 'xCpl', 'xBairro'],
		'infDPS/interm/end' => ['endNac', 'endExt', 'xLgr', 'nro', 'xCpl', 'xBairro'],
		'infDPS/prest/end/endNac' => ['cMun', 'CEP'],
		'infDPS/toma/end/endNac' => ['cMun', 'CEP'],
		'infDPS/interm/end/endNac' => ['cMun', 'CEP'],
		'infDPS/prest/end/endExt' => ['cPais', 'cEndPost', 'xCidade', 'xEstProvReg'],
		'infDPS/toma/end/endExt' => ['cPais', 'cEndPost', 'xCidade', 'xEstProvReg'],
		'infDPS/interm/end/endExt' => ['cPais', 'cEndPost', 'xCidade', 'xEstProvReg'],
		'infDPS/serv' => ['locPrest', 'cServ', 'comExt', 'obra', 'atvEvento', 'infoCompl'],
		'infDPS/serv/locPrest' => ['cLocPrestacao', 'cPaisPrestacao'],
		'infDPS/serv/cServ' => ['cTribNac', 'cTribMun', 'xDescServ', 'cNBS', 'cIntContrib'],
		'infDPS/serv/comExt' => ['mdPrestacao', 'vincPrest', 'tpMoeda', 'vServMoeda', 'mecAFComexP', 'mecAFComexT', 'movTempBens', 'nDI', 'nRE', 'mdic'],
		'infDPS/serv/obra' => ['inscImobFisc', 'cObra', 'cCIB', 'end'],
		'infDPS/serv/obra/end' => ['endNac', 'endExt', 'xLgr', 'nro', 'xCpl', 'xBairro'],
		'infDPS/serv/obra/end/endNac' => ['cMun', 'CEP'],
		'infDPS/serv/atvEvento' => ['xNome', 'dtIni', 'dtFim', 'idAtvEvt', 'end'],
		'infDPS/serv/atvEvento/end' => ['endNac', 'endExt', 'xLgr', 'nro', 'xCpl', 'xBairro'],
		'infDPS/serv/atvEvento/end/endNac' => ['cMun', 'CEP'],
		'infDPS/serv/infoCompl' => ['idDocTec', 'docRef', 'xPed', 'gItemPed', 'xInfComp'],
		'infDPS/serv/infoCompl/gItemPed' => ['xItemPed'],
		'infDPS/valores' => ['vServPrest', 'vDescCondIncond', 'vDedRed', 'trib'],
		'infDPS/valores/vServPrest' => ['vReceb', 'vServ'],
		'infDPS/valores/vDescCondIncond' => ['vDescIncond', 'vDescCond'],
		'infDPS/valores/vDedRed' => ['pDR', 'vDR', 'documentos'],
		'infDPS/valores/vDedRed/documentos' => ['docDedRed'],
		'infDPS/valores/vDedRed/documentos/docDedRed' => ['chNFSe', 'chNFe', 'NFSeMun', 'NFNFS', 'nDocFisc', 'nDoc', 'tpDedRed', 'xDescOutDed', 'dtEmiDoc', 'vDedutivelRedutivel', 'vDeducaoReducao', 'fornec'],
		'infDPS/valores/trib' => ['tribMun', 'tribFed', 'totTrib'],
		'infDPS/valores/trib/tribMun' => ['tribISSQN', 'cPaisResult', 'tpImunidade', 'exigSusp', 'BM', 'tpRetISSQN', 'pAliq'],
		'infDPS/valores/trib/tribMun/exigSusp' => ['tpSusp', 'nProcesso'],
		'infDPS/valores/trib/tribMun/BM' => ['nBM', 'vRedBCBM', 'pRedBCBM'],
		'infDPS/valores/trib/tribFed' => ['piscofins', 'vRetCP', 'vRetIRRF', 'vRetCSLL'],
		'infDPS/valores/trib/tribFed/piscofins' => ['CST', 'vBCPisCofins', 'pAliqPis', 'pAliqCofins', 'vPis', 'vCofins', 'tpRetPisCofins'],
		'infDPS/valores/trib/totTrib' => ['vTotTrib', 'pTotTrib', 'indTotTrib', 'pTotTribSN'],
		'infDPS/valores/trib/totTrib/vTotTrib' => ['vTotTribFed', 'vTotTribEst', 'vTotTribMun'],
		'infDPS/valores/trib/totTrib/pTotTrib' => ['pTotTribFed', 'pTotTribEst', 'pTotTribMun'],
	];

	/**
	 * O que o leiaute da NT 009 ({@see Leiaute::V101Nt009}) muda em
	 * {@see ORDEM}; o resto é igual. Extraído de
	 * resources/xsd/1.01-nt009/tiposComplexos_v1.01.xsd.
	 */
	private const ORDEM_NT009 = [
		'infDPS' => ['tpAmb', 'dhEmi', 'verAplic', 'serie', 'nDPS', 'dCompet', 'tpEmit', 'cMotivoEmisTI', 'finNFSe', 'chNFSeRej', 'cLocEmi', 'subst', 'prest', 'toma', 'interm', 'serv', 'valores', 'IBSCBS'],
		'infDPS/serv/cServ' => ['cTribNac', 'cTribMun', 'cNBS', 'cIntContrib', 'xDescServ'],
		'infDPS/valores' => ['vServPrest', 'vDescCondIncond', 'vAjusteBC', 'trib'],
		'infDPS/valores/vAjusteBC' => ['pAjusteBCISSQN', 'vAjusteBCISSQN', 'documentos'],
		'infDPS/valores/vAjusteBC/documentos' => ['docAjusteBC'],
		'infDPS/valores/vAjusteBC/documentos/docAjusteBC' => ['tpAjusteBC', 'xTpAjusteBC', 'vTotDoc', 'vAjusteAplic', 'dtEmiDoc', 'dtCompDoc', 'dFeNacional', 'docFiscalOutro', 'docOutro', 'fornec'],
		'infDPS/valores/vDedRed' => null,
		'infDPS/valores/vDedRed/documentos' => null,
		'infDPS/valores/vDedRed/documentos/docDedRed' => null,
	];

	/** Campos decimais com 2 casas — floats passados aqui viram "123.45". */
	private const DECIMAIS = ['pAjusteBCISSQN', 'vAjusteBCISSQN', 'vTotDoc', 'vAjusteAplic', 'vCalcAjusteBCIBSCBS', 'vCalcAjusteBCLocImoveis', 'vReceb','vServ', 'vDescIncond', 'vDescCond', 'vDR', 'vDedutivelRedutivel', 'vDeducaoReducao', 'vRedBCBM', 'vRetCP', 'vRetIRRF', 'vRetCSLL', 'vBCPisCofins', 'vPis', 'vCofins', 'vTotTribFed', 'vTotTribEst', 'vTotTribMun', 'vServMoeda', 'pDR', 'pRedBCBM', 'pAliq', 'pAliqPis', 'pAliqCofins', 'pTotTribFed', 'pTotTribEst', 'pTotTribMun', 'pTotTribSN', 'vBC', 'vCalcReeRepRes', 'vlrReeRepRes', 'pIBSUF', 'pRedAliqUF', 'pAliqEfetUF', 'pIBSMun', 'pRedAliqMun', 'pAliqEfetMun', 'pCBS', 'pRedAliqCBS', 'pAliqEfetCBS', 'pRedutor', 'pAliqEfeRegIBSUF', 'pAliqEfeRegIBSMun', 'pAliqEfeRegCBS', 'vTribRegIBSUF', 'vTribRegIBSMun', 'vTribRegCBS', 'pCredPres', 'pCredPresIBS', 'pCredPresCBS', 'vCredPresIBS', 'vCredPresCBS'];

	/**
	 * @param Grupo $infDps conteúdo do grupo infDPS (sem o Id — ele é calculado)
	 * @param string|Leiaute $leiaute qual XSD seguir na ordem dos elementos; string = valor do Leiaute ('1.01', '1.01-nt009')
	 *
	 * @return string XML sem assinatura, com declaração UTF-8
	 */
	public static function gerar(array $infDps, string|Leiaute $leiaute = Leiaute::V101): string
	{
		$leiaute = Leiaute::de($leiaute);
		$ordem = self::ordemDo($leiaute);
		$versao = $leiaute->versaoXml();
		$infDps = self::normalizar($infDps);
		$id = $infDps['@Id'] ?? self::idDps(
			(string) ($infDps['cLocEmi'] ?? ''),
			self::inscricaoPrestador($infDps),
			(string) ($infDps['serie'] ?? ''),
			(string) ($infDps['nDPS'] ?? ''),
		);
		unset($infDps['@Id']);

		$dom = new \DOMDocument('1.0', 'UTF-8');
		$dom->preserveWhiteSpace = false;
		$dom->formatOutput = false;

		$dps = $dom->createElementNS(self::NAMESPACE, 'DPS');
		$dps->setAttribute('versao', $versao);
		$dom->appendChild($dps);

		$inf = $dom->createElementNS(self::NAMESPACE, 'infDPS');
		$inf->setAttribute('Id', $id);
		$dps->appendChild($inf);

		self::preencher($dom, $inf, $infDps, 'infDPS', $ordem);

		$xml = $dom->saveXML();
		if (false === $xml) {
			throw new DadosExcecao('Falha ao serializar a DPS.');
		}

		return $xml;
	}

	/**
	 * Id do infDPS (TSIdDPS), 45 posições:
	 * "DPS" + cLocEmi (7) + tipo de inscrição (1: 1 = CPF, 2 = CNPJ) +
	 * inscrição (14, CPF com zeros à esquerda) + série (5) + nDPS (15).
	 *
	 * É também o identificador usado em GET/HEAD /dps/{id}.
	 */
	public static function idDps(string $cLocEmi, string $cnpjOuCpf, string|int $serie, string|int $nDps): string
	{
		$cLocEmi = Formato::somenteDigitos($cLocEmi);
		$insc = Formato::somenteDigitos($cnpjOuCpf);
		$serie = Formato::somenteDigitos((string) $serie);
		$nDps = Formato::somenteDigitos((string) $nDps);
		if (7 !== \strlen($cLocEmi)) {
			throw new DadosExcecao("cLocEmi deve ter 7 dígitos (código IBGE), recebido '{$cLocEmi}'.");
		}
		$tipo = match (\strlen($insc)) {
			11 => '1',
			14 => '2',
			default => throw new DadosExcecao("Inscrição do prestador deve ser CPF (11) ou CNPJ (14), recebido '{$insc}'."),
		};
		if ('' === $serie || \strlen($serie) > 5) {
			throw new DadosExcecao("Série da DPS deve ter de 1 a 5 dígitos, recebido '{$serie}'.");
		}
		if ('' === ltrim($nDps, '0') || \strlen($nDps) > 15) {
			throw new DadosExcecao("Número da DPS deve ter de 1 a 15 dígitos e ser maior que zero, recebido '{$nDps}'.");
		}

		return 'DPS'.$cLocEmi.$tipo.str_pad($insc, 14, '0', \STR_PAD_LEFT)
			.str_pad($serie, 5, '0', \STR_PAD_LEFT).str_pad($nDps, 15, '0', \STR_PAD_LEFT);
	}

	/** @param Grupo $infDps */
	private static function inscricaoPrestador(array $infDps): string
	{
		$prest = $infDps['prest'] ?? null;
		if (!\is_array($prest)) {
			throw new DadosExcecao('infDPS.prest é obrigatório.');
		}
		$insc = $prest['CNPJ'] ?? $prest['CPF'] ?? null;
		if (null === $insc) {
			throw new DadosExcecao('infDPS.prest precisa de CNPJ ou CPF para formar o Id da DPS.');
		}

		return (string) $insc;
	}

	/**
	 * Normaliza o topo: serie/nDPS como string sem zeros à esquerda (o XSD
	 * exige nDPS começando em 1-9), datas como objetos viram texto.
	 *
	 * @param Grupo $infDps
	 *
	 * @return Grupo
	 */
	private static function normalizar(array $infDps): array
	{
		if (isset($infDps['nDPS'])) {
			$infDps['nDPS'] = ltrim(Formato::somenteDigitos((string) $infDps['nDPS']), '0');
		}
		if (isset($infDps['serie'])) {
			$infDps['serie'] = ltrim(Formato::somenteDigitos((string) $infDps['serie']), '0') ?: '0';
		}

		return $infDps;
	}

	/** @return array<string,list<string>> */
	private static function ordemDo(Leiaute $leiaute): array
	{
		if (Leiaute::V101 === $leiaute) {
			return self::ORDEM;
		}

		return array_filter(array_merge(self::ORDEM, self::ORDEM_NT009), static fn(?array $o): bool => null !== $o);
	}

	/**
	 * @param Grupo $dados
	 * @param array<string,list<string>> $ordem
	 */
	private static function preencher(\DOMDocument $dom, \DOMElement $pai, array $dados, string $caminho, array $ordem): void
	{
		foreach (self::ordenar($dados, $ordem[$caminho] ?? null) as $nome => $valor) {
			if (null === $valor) {
				continue;
			}
			if (str_starts_with((string) $nome, '@')) {
				$pai->setAttribute(substr((string) $nome, 1), self::texto($valor, (string) $nome));
				continue;
			}
			$nome = (string) $nome;
			if (\is_array($valor) && array_is_list($valor)) {
				foreach ($valor as $item) {
					self::anexar($dom, $pai, $nome, $item, $caminho, $ordem);
				}
				continue;
			}
			self::anexar($dom, $pai, $nome, $valor, $caminho, $ordem);
		}
	}

	/** @param array<string,list<string>> $ordem */
	private static function anexar(\DOMDocument $dom, \DOMElement $pai, string $nome, mixed $valor, string $caminho, array $ordem): void
	{
		$el = $dom->createElementNS(self::NAMESPACE, $nome);
		$pai->appendChild($el);
		if (\is_array($valor)) {
			if (array_is_list($valor)) {
				throw new DadosExcecao("Lista dentro de lista em {$caminho}/{$nome}: use um array associativo por item.");
			}
			self::preencher($dom, $el, $valor, $caminho.'/'.$nome, $ordem);

			return;
		}
		$el->appendChild($dom->createTextNode(self::texto($valor, $nome)));
	}

	private static function texto(mixed $valor, string $nome): string
	{
		if ($valor instanceof \DateTimeInterface) {
			return str_starts_with($nome, 'dh') ? Formato::dataHora($valor) : Formato::data($valor);
		}
		if (\is_bool($valor)) {
			throw new DadosExcecao("Campo {$nome}: booleanos não existem no leiaute, use '0'/'1' ou o código previsto.");
		}
		if (\is_float($valor) || (\is_int($valor) && \in_array($nome, self::DECIMAIS, true))) {
			return Formato::decimal($valor);
		}
		if (\is_int($valor)) {
			return (string) $valor;
		}
		if (\is_string($valor)) {
			return $valor;
		}
		if ($valor instanceof \Stringable) {
			return (string) $valor;
		}
		throw new DadosExcecao("Campo {$nome}: tipo não suportado ".get_debug_type($valor).'.');
	}

	/**
	 * @param Grupo $dados
	 * @param list<string>|null $ordem
	 *
	 * @return Grupo
	 */
	private static function ordenar(array $dados, ?array $ordem): array
	{
		if (null === $ordem) {
			return $dados;
		}
		$saida = [];
		foreach ($dados as $k => $v) {
			if (str_starts_with((string) $k, '@')) {
				$saida[$k] = $v;
			}
		}
		foreach ($ordem as $nome) {
			if (\array_key_exists($nome, $dados)) {
				$saida[$nome] = $dados[$nome];
			}
		}
		foreach ($dados as $k => $v) {
			if (!\array_key_exists($k, $saida)) {
				$saida[$k] = $v;
			}
		}

		return $saida;
	}
}
