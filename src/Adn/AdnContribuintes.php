<?php declare(strict_types=1);

namespace Arijs\NfseNacional\Adn;

use Arijs\NfseNacional\Ambiente;
use Arijs\NfseNacional\Excecao\DadosExcecao;
use Arijs\NfseNacional\Excecao\SefinExcecao;
use Arijs\NfseNacional\Http\ClienteHttp;
use Arijs\NfseNacional\Sefin\RespostaSefin;
use Arijs\NfseNacional\Util\Formato;

/**
 * API de Distribuição dos Contribuintes do ADN (Ambiente de Dados
 * Nacional), conforme o "Manual dos Contribuintes — APIs do ADN" (v1.0,
 * fev/2026):
 *
 *  GET /contribuintes/DFe/{nsu}?cnpjConsulta=&lote=true   documentos a partir do NSU
 *  GET /contribuintes/NFSe/{chave}/Eventos                 eventos de uma nota
 *  GET /danfse/{chave}                                     PDF oficial (ver observação)
 *
 * Cada documento fiscal de serviço ligado ao CNPJ (como prestador, tomador
 * ou intermediário) ganha um NSU — número sequencial único. Pedindo "a
 * partir do NSU N" vêm os seguintes, em páginas de até 50. É o jeito de
 * sincronizar tudo: notas emitidas por qualquer canal (API, página web,
 * app), notas recebidas de fornecedores e eventos de cancelamento.
 *
 * O certificado da conexão precisa ter o mesmo CNPJ raiz do CNPJ
 * consultado.
 */
final class AdnContribuintes
{
	private readonly string $urlBase;

	public function __construct(
		private readonly Ambiente $ambiente,
		private readonly ClienteHttp $http,
		?string $urlBase = null,
	) {
		$this->urlBase = rtrim($urlBase ?? $ambiente->urlAdn(), '/');
	}

	/**
	 * Uma página: os documentos com NSU maior que `$nsu`.
	 *
	 * Sem documentos depois de `$nsu`, o ADN responde 404 com E2220; isso volta
	 * como lote vazio (fim normal), não como exceção.
	 *
	 * @param string|null $cnpjConsulta CNPJ consultado (mesma raiz do certificado). O ADN de produção
	 *                                  recusa a consulta sem ele; {@see \Arijs\NfseNacional\Nfse::sincronizarDfe}
	 *                                  já manda o do certificado
	 */
	public function distribuir(int $nsu, ?string $cnpjConsulta = null, bool $lote = true): LoteDfe
	{
		if ($nsu < 0) {
			throw new DadosExcecao('NSU não pode ser negativo.');
		}
		$query = ['lote' => $lote ? 'true' : 'false'];
		if (null !== $cnpjConsulta) {
			$query['cnpjConsulta'] = Formato::somenteDigitos($cnpjConsulta);
		}
		$resposta = RespostaSefin::de('distribuirDfe', $this->http->enviar(
			'GET',
			$this->urlBase.'/contribuintes/DFe/'.$nsu.'?'.http_build_query($query),
			['Accept' => 'application/json'],
		));
		$dados = $resposta->dados();
		$status = $dados['StatusProcessamento'] ?? $dados['statusProcessamento'] ?? null;
		if (!$resposta->sucesso()) {
			// "Acabou" chega como 404 + NENHUM_DOCUMENTO_LOCALIZADO (E2220), não
			// como 200 com lista vazia: é o fim normal de toda sincronização.
			if (404 === $resposta->status && (LoteDfe::nenhumDocumento(\is_string($status) ? $status : null) || $resposta->temErro(LoteDfe::ERRO_NENHUM_DOCUMENTO))) {
				return new LoteDfe([], $nsu, \is_string($status) ? $status : LoteDfe::STATUS_NENHUM, false, 1);
			}
			throw new SefinExcecao($resposta);
		}
		$itens = $dados['LoteDFe'] ?? $dados['loteDFe'] ?? $dados['loteDfe'] ?? [];

		$documentos = [];
		$maior = $nsu;
		foreach (\is_array($itens) ? $itens : [] as $item) {
			if (!\is_array($item)) {
				continue;
			}
			$doc = DocumentoDfe::deArray($item);
			$documentos[] = $doc;
			$maior = max($maior, $doc->nsu);
		}

		return new LoteDfe($documentos, $maior, \is_string($status) ? $status : null, [] !== $documentos, 1);
	}

	/**
	 * Varre páginas a partir de `$ultimoNsu` até acabar ou bater em
	 * `$maxPaginas`. `$aoReceber` é chamado com cada página (útil para gravar
	 * no banco conforme chega, em vez de só no fim).
	 *
	 * @param null|\Closure(LoteDfe):void $aoReceber
	 */
	public function sincronizar(int $ultimoNsu = 0, int $maxPaginas = 20, ?string $cnpjConsulta = null, ?\Closure $aoReceber = null): LoteDfe
	{
		if ($maxPaginas < 1) {
			throw new DadosExcecao('maxPaginas deve ser pelo menos 1.');
		}
		$todos = [];
		$nsu = $ultimoNsu;
		$status = null;
		$temMais = false;
		$paginas = 0;
		while ($paginas < $maxPaginas) {
			$pagina = $this->distribuir($nsu, $cnpjConsulta);
			++$paginas;
			$status = $pagina->statusProcessamento ?? $status;
			if ($pagina->vazio() || $pagina->ultimoNsu <= $nsu) {
				$temMais = false;
				break;
			}
			if (null !== $aoReceber) {
				$aoReceber($pagina);
			}
			array_push($todos, ...$pagina->documentos);
			$nsu = $pagina->ultimoNsu;
			if (LoteDfe::nenhumDocumento($pagina->statusProcessamento)) {
				$temMais = false;
				break;
			}
			$temMais = true;
		}

		return new LoteDfe($todos, $nsu, $status, $temMais && $paginas >= $maxPaginas, $paginas);
	}

	public function consultarEventos(string $chaveAcesso): RespostaSefin
	{
		return RespostaSefin::de('adnEventos', $this->http->enviar(
			'GET',
			$this->urlBase.'/contribuintes/NFSe/'.self::chave($chaveAcesso).'/Eventos',
			['Accept' => 'application/json'],
		));
	}

	/**
	 * PDF oficial do DANFSe.
	 *
	 * Observação: este endpoint do ADN tem ficado indisponível por períodos
	 * (a NT 008 orienta os emissores a gerar o DANFSe localmente a partir do
	 * XML). Se ele responder erro, não é defeito da integração.
	 *
	 * @return string conteúdo binário do PDF
	 */
	public function baixarDanfse(string $chaveAcesso): string
	{
		$http = $this->http->enviar('GET', $this->urlBase.'/danfse/'.self::chave($chaveAcesso), ['Accept' => 'application/pdf']);
		if ($http->status < 200 || $http->status >= 300) {
			throw new SefinExcecao(RespostaSefin::de('danfse', $http));
		}
		if (!str_starts_with($http->corpo, '%PDF')) {
			throw new DadosExcecao('O ADN não devolveu um PDF (talvez o serviço de DANFSe esteja indisponível; gere o DANFSe a partir do XML).');
		}

		return $http->corpo;
	}

	private static function chave(string $chave): string
	{
		$c = Formato::somenteDigitos($chave);
		if (50 !== \strlen($c)) {
			throw new \InvalidArgumentException("Chave de acesso da NFS-e deve ter 50 dígitos, recebido '{$chave}'.");
		}

		return $c;
	}
}
