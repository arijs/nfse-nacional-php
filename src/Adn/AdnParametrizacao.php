<?php declare(strict_types=1);

namespace Arijs\NfseNacional\Adn;

use Arijs\NfseNacional\Ambiente;
use Arijs\NfseNacional\Http\ClienteHttp;
use Arijs\NfseNacional\Sefin\RespostaSefin;

/**
 * API de PARÂMETROS MUNICIPAIS do Ambiente de Dados Nacional (ADN), com mTLS:
 * o que cada município conveniado parametrizou no Sistema Nacional NFS-e
 * (alíquotas por serviço, regimes especiais, retenções, benefícios,
 * convênio). Vive no host do ADN, em `/parametrizacao` — NÃO na Sefin
 * (`/SefinNacional/parametros_municipais/...` responde 404 em produção;
 * conferido em 08/11/2026). Documentação viva:
 * https://adn.nfse.gov.br/parametrizacao/docs/index.html
 *
 *   GET /parametrizacao/{cMun}/convenio
 *   GET /parametrizacao/{cMun}/{codigoServico}/historicoaliquotas
 *   GET /parametrizacao/{cMun}/{codigoServico}/{competencia}/aliquota
 *   GET /parametrizacao/{cMun}/{codigoServico}/{competencia}/regimes_especiais
 *   GET /parametrizacao/{cMun}/{competencia}/retencoes
 *   GET /parametrizacao/{cMun}/{numeroBeneficio}/{competencia}/beneficio
 *
 * O `codigoServico` aqui é o código COMPLETO do serviço no município (código
 * de tributação nacional + o desdobro municipal: 9 dígitos, p. ex.
 * `010701001`), e a especificação não fixa o formato nem o da competência —
 * por isso os valores vão como vieram (só codificados para a URL); quem
 * chama decide (e {@see consultar} aceita um caminho cru para sondar).
 */
final class AdnParametrizacao
{
	private readonly string $urlBase;

	public function __construct(
		private readonly Ambiente $ambiente,
		private readonly ClienteHttp $http,
		?string $urlAdn = null,
	) {
		$this->urlBase = rtrim($urlAdn ?? $ambiente->urlAdn(), '/').'/parametrizacao';
	}

	public function ambiente(): Ambiente
	{
		return $this->ambiente;
	}

	/** Parâmetros do convênio do município (aderência ao emissor nacional etc.). */
	public function convenio(string $codigoMunicipio): RespostaSefin
	{
		return $this->consultar(self::seg($codigoMunicipio).'/convenio');
	}

	/** Histórico das alíquotas do ISSQN de um serviço no município. */
	public function historicoAliquotas(string $codigoMunicipio, string $codigoServico): RespostaSefin
	{
		return $this->consultar(self::seg($codigoMunicipio).'/'.self::seg($codigoServico).'/historicoaliquotas');
	}

	/** A alíquota do ISSQN de um serviço no município numa competência. */
	public function aliquota(string $codigoMunicipio, string $codigoServico, string $competencia): RespostaSefin
	{
		return $this->consultar(self::seg($codigoMunicipio).'/'.self::seg($codigoServico).'/'.self::seg($competencia).'/aliquota');
	}

	public function regimesEspeciais(string $codigoMunicipio, string $codigoServico, string $competencia): RespostaSefin
	{
		return $this->consultar(self::seg($codigoMunicipio).'/'.self::seg($codigoServico).'/'.self::seg($competencia).'/regimes_especiais');
	}

	public function retencoes(string $codigoMunicipio, string $competencia): RespostaSefin
	{
		return $this->consultar(self::seg($codigoMunicipio).'/'.self::seg($competencia).'/retencoes');
	}

	public function beneficio(string $codigoMunicipio, string $numeroBeneficio, string $competencia): RespostaSefin
	{
		return $this->consultar(self::seg($codigoMunicipio).'/'.self::seg($numeroBeneficio).'/'.self::seg($competencia).'/beneficio');
	}

	/** A URL completa de um caminho relativo a `/parametrizacao` (para mostrar o que foi pedido). */
	public function url(string $caminho): string
	{
		return $this->urlBase.'/'.ltrim($caminho, '/');
	}

	/**
	 * GET num caminho relativo a `/parametrizacao`, já codificado — para sondar
	 * formatos que a especificação não fixa.
	 */
	public function consultar(string $caminho): RespostaSefin
	{
		return RespostaSefin::de('parametrizacao', $this->http->enviar('GET', $this->url($caminho), ['Accept' => 'application/json']));
	}

	private static function seg(string $valor): string
	{
		return rawurlencode(trim($valor));
	}
}
