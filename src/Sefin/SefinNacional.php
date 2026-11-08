<?php declare(strict_types=1);

namespace Arijs\NfseNacional\Sefin;

use Arijs\NfseNacional\Ambiente;
use Arijs\NfseNacional\Dps\DpsXml;
use Arijs\NfseNacional\Http\ClienteHttp;
use Arijs\NfseNacional\Util\Formato;
use Arijs\NfseNacional\Util\Gzip;

/**
 * Cliente da API Sefin Nacional (Emissor Público Nacional), conforme o
 * "Manual dos Contribuintes — Guia para utilização das APIs do Emissor
 * Público Nacional" (v1.2, out/2025):
 *
 *  POST /nfse                          recepção da DPS → gera a NFS-e (síncrono)
 *  GET  /nfse/{chaveAcesso}            NFS-e pela chave
 *  GET  /dps/{id}                      chave de acesso a partir do Id da DPS
 *  HEAD /dps/{id}                      a DPS já virou NFS-e?
 *  POST /nfse/{chaveAcesso}/eventos    pedido de registro de evento (cancelamento etc.)
 *  GET  /nfse/{chaveAcesso}/eventos[/{tipo}[/{seq}]]
 *  (parâmetros municipais: ficam no ADN — {@see \Arijs\NfseNacional\Adn\AdnParametrizacao})
 *
 * Esta classe só fala JSON com a API. Montar, validar e assinar o XML é
 * trabalho de {@see \Arijs\NfseNacional\Nfse}.
 */
final class SefinNacional
{
	private readonly string $urlBase;

	public function __construct(
		private readonly Ambiente $ambiente,
		private readonly ClienteHttp $http,
		?string $urlBase = null,
	) {
		$this->urlBase = rtrim($urlBase ?? $ambiente->urlSefin(), '/');
	}

	public function ambiente(): Ambiente
	{
		return $this->ambiente;
	}

	/** @param string $dpsXmlAssinado XML da DPS já assinado */
	public function enviarDps(string $dpsXmlAssinado): RespostaSefin
	{
		return $this->chamar('emitir', 'POST', '/nfse', ['dpsXmlGZipB64' => Gzip::comprimirBase64($dpsXmlAssinado)]);
	}

	public function consultarNfse(string $chaveAcesso): RespostaSefin
	{
		return $this->chamar('consultarNfse', 'GET', '/nfse/'.self::chave($chaveAcesso));
	}

	/** Chave de acesso da NFS-e gerada a partir de uma DPS (só para quem é ator da nota). */
	public function consultarChavePorDps(string $idDps): RespostaSefin
	{
		return $this->chamar('consultarDps', 'GET', '/dps/'.self::idDps($idDps));
	}

	/** HEAD /dps/{id}: 200 = já existe NFS-e para esta DPS; 404 = não. */
	public function dpsProcessada(string $idDps): bool
	{
		$r = $this->chamar('dpsProcessada', 'HEAD', '/dps/'.self::idDps($idDps));
		if (404 === $r->status) {
			return false;
		}
		$r->exigirSucesso();

		return true;
	}

	/** @param string $pedRegEventoXmlAssinado XML do pedRegEvento já assinado */
	public function registrarEvento(string $chaveAcesso, string $pedRegEventoXmlAssinado): RespostaSefin
	{
		return $this->chamar('registrarEvento', 'POST', '/nfse/'.self::chave($chaveAcesso).'/eventos', [
			'pedidoRegistroEventoXmlGZipB64' => Gzip::comprimirBase64($pedRegEventoXmlAssinado),
		]);
	}

	public function consultarEventos(string $chaveAcesso, ?string $tipoEvento = null, ?int $numSeqEvento = null): RespostaSefin
	{
		$caminho = '/nfse/'.self::chave($chaveAcesso).'/eventos';
		if (null !== $tipoEvento) {
			$caminho .= '/'.rawurlencode($tipoEvento);
			if (null !== $numSeqEvento) {
				$caminho .= '/'.$numSeqEvento;
			}
		}

		return $this->chamar('consultarEventos', 'GET', $caminho);
	}

	/**
	 * "Lista" as NFS-e de uma faixa de números de DPS, já que a Sefin não tem
	 * endpoint de listagem: para cada número monta o Id da DPS, pede a chave
	 * (GET /dps/{id}) e, se `$comXml`, a nota (GET /nfse/{chave}).
	 *
	 * Uma chamada HTTP por número (duas com XML): use em faixas pequenas ou
	 * para reconciliar. Para sincronizar tudo, prefira o ADN
	 * ({@see \Arijs\NfseNacional\Adn\AdnContribuintes::sincronizar}).
	 *
	 * Números sem NFS-e (404) são pulados; qualquer outro erro é lançado para
	 * não mascarar falha de rede como "não existe".
	 *
	 * @param null|\Closure(array{nDPS:int,idDps:string,chaveAcesso:string,nfseXml:?string}):void $aoEncontrar
	 *
	 * @return list<array{nDPS:int,idDps:string,chaveAcesso:string,nfseXml:?string}>
	 */
	public function listarPorFaixa(string $cLocEmi, string $cnpjOuCpf, string|int $serie, int $de, int $ate, bool $comXml = false, ?\Closure $aoEncontrar = null): array
	{
		if ($de < 1 || $ate < $de) {
			throw new \InvalidArgumentException("Faixa inválida: de {$de} até {$ate}.");
		}
		$encontradas = [];
		for ($n = $de; $n <= $ate; ++$n) {
			$id = DpsXml::idDps($cLocEmi, $cnpjOuCpf, $serie, $n);
			$r = $this->consultarChavePorDps($id);
			if (404 === $r->status) {
				continue;
			}
			$r->exigirSucesso();
			$chave = $r->chaveAcesso();
			if (null === $chave) {
				continue;
			}
			$item = ['nDPS' => $n, 'idDps' => $id, 'chaveAcesso' => $chave, 'nfseXml' => null];
			if ($comXml) {
				$item['nfseXml'] = $this->consultarNfse($chave)->exigirSucesso()->nfseXml();
			}
			$encontradas[] = $item;
			if (null !== $aoEncontrar) {
				$aoEncontrar($item);
			}
		}

		return $encontradas;
	}

	/** @deprecated a rota não existe na Sefin (404 em produção, 08/11/2026): use {@see \Arijs\NfseNacional\Nfse::parametrizacao()}. */
	public function consultarConvenioMunicipio(string $codigoMunicipio): RespostaSefin
	{
		return $this->chamar('parametrosConvenio', 'GET', '/parametros_municipais/'.Formato::somenteDigitos($codigoMunicipio).'/convenio');
	}

	/** @deprecated a rota não existe na Sefin (404 em produção, 08/11/2026): use {@see \Arijs\NfseNacional\Nfse::parametrizacao()}. */
	public function consultarParametrosServico(string $codigoMunicipio, string $codigoServico): RespostaSefin
	{
		return $this->chamar('parametrosServico', 'GET', '/parametros_municipais/'.Formato::somenteDigitos($codigoMunicipio).'/'.Formato::somenteDigitos($codigoServico));
	}

	/** @deprecated a rota não existe na Sefin (404 em produção, 08/11/2026): use {@see \Arijs\NfseNacional\Nfse::parametrizacao()}. */
	public function consultarParametrosContribuinte(string $codigoMunicipio, string $cnpjOuCpf): RespostaSefin
	{
		return $this->chamar('parametrosContribuinte', 'GET', '/parametros_municipais/'.Formato::somenteDigitos($codigoMunicipio).'/'.Formato::somenteDigitos($cnpjOuCpf));
	}

	/** @param array<string,mixed>|null $json */
	private function chamar(string $operacao, string $metodo, string $caminho, ?array $json = null): RespostaSefin
	{
		$cabecalhos = ['Accept' => 'application/json'];
		$corpo = null;
		if (null !== $json) {
			$cabecalhos['Content-Type'] = 'application/json';
			$corpo = json_encode($json, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
		}

		return RespostaSefin::de($operacao, $this->http->enviar($metodo, $this->urlBase.$caminho, $cabecalhos, $corpo));
	}

	private static function chave(string $chave): string
	{
		$c = Formato::somenteDigitos($chave);
		if (50 !== \strlen($c)) {
			throw new \InvalidArgumentException("Chave de acesso da NFS-e deve ter 50 dígitos, recebido '{$chave}'.");
		}

		return $c;
	}

	private static function idDps(string $id): string
	{
		if (!preg_match('/^DPS\d{42}$/', $id)) {
			throw new \InvalidArgumentException("Id da DPS deve ser 'DPS' + 42 dígitos, recebido '{$id}'.");
		}

		return $id;
	}
}
