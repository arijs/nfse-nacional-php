<?php declare(strict_types=1);

namespace Arijs\NfseNacional\Sefin;

use Arijs\NfseNacional\Excecao\SefinExcecao;
use Arijs\NfseNacional\Http\RespostaHttp;
use Arijs\NfseNacional\Util\Gzip;

/**
 * Resposta de qualquer chamada à API Sefin, já interpretada.
 *
 * Sucesso de emissão (POST /nfse): `tipoAmbiente`, `versaoAplicativo`,
 * `dataHoraProcessamento`, `idDps`, `chaveAcesso`, `nfseXmlGZipB64` e,
 * opcionalmente, `alertas`. Rejeição: HTTP 4xx com `erros[]` (codigo,
 * descricao, complemento). Os XMLs comprimidos são descomprimidos uma vez e
 * ficam em {@see xmls()}.
 */
final class RespostaSefin
{
	/** @var list<ErroSefin> */
	private array $erros = [];
	/** @var list<ErroSefin> */
	private array $alertas = [];
	/** @var array<string,string> */
	private array $xmls = [];
	/** @var array<string,mixed> */
	private array $dados;

	public function __construct(
		public readonly string $operacao,
		public readonly int $status,
		public readonly string $corpoBruto,
	) {
		$dados = json_decode($corpoBruto, true);
		$this->dados = \is_array($dados) ? $dados : [];

		foreach (['erros', 'erro', 'Erros'] as $chave) {
			foreach ((array) ($this->dados[$chave] ?? []) as $item) {
				if (\is_array($item)) {
					$this->erros[] = ErroSefin::deArray($item);
				} elseif (\is_string($item)) {
					$this->erros[] = new ErroSefin('', $item);
				}
			}
		}
		foreach ((array) ($this->dados['alertas'] ?? []) as $item) {
			if (\is_array($item)) {
				$this->alertas[] = ErroSefin::deArray($item);
			}
		}
		foreach ($this->dados as $chave => $valor) {
			if (\is_string($valor) && '' !== $valor && preg_match('/^(.*)XmlGZipB64$/i', (string) $chave, $m)) {
				try {
					$this->xmls[strtolower($m[1])] = Gzip::descomprimirBase64($valor);
				} catch (\Throwable) {
					// Deixa o campo bruto disponível em dados(); não derruba a leitura.
				}
			}
		}
		// Sem JSON mas com XML no corpo (algumas consultas devolvem XML puro).
		if ([] === $this->dados && str_starts_with(ltrim($corpoBruto), '<')) {
			$this->xmls['corpo'] = $corpoBruto;
		}
	}

	public static function de(string $operacao, RespostaHttp $http): self
	{
		return new self($operacao, $http->status, $http->corpo);
	}

	public function sucesso(): bool
	{
		return $this->status >= 200 && $this->status < 300 && [] === $this->erros;
	}

	/** @return array<string,mixed> JSON decodificado, como veio */
	public function dados(): array
	{
		return $this->dados;
	}

	/** @return list<ErroSefin> */
	public function erros(): array
	{
		return $this->erros;
	}

	/** @return list<ErroSefin> */
	public function alertas(): array
	{
		return $this->alertas;
	}

	/** Chave de acesso da NFS-e (50 dígitos), quando a resposta traz uma. */
	public function chaveAcesso(): ?string
	{
		$c = $this->dados['chaveAcesso'] ?? $this->dados['ChaveAcesso'] ?? null;

		return \is_string($c) && '' !== $c ? $c : null;
	}

	public function idDps(): ?string
	{
		$c = $this->dados['idDps'] ?? $this->dados['idDPS'] ?? null;

		return \is_string($c) && '' !== $c ? $c : null;
	}

	/** @return array<string,string> XMLs descomprimidos, por nome do campo sem o sufixo (ex.: "nfse", "evento") */
	public function xmls(): array
	{
		return $this->xmls;
	}

	/** XML da NFS-e gerada (campo nfseXmlGZipB64), se veio. */
	public function nfseXml(): ?string
	{
		return $this->xmls['nfse'] ?? null;
	}

	/** Número da NFS-e lido do XML devolvido, quando há. */
	public function numeroNfse(): ?string
	{
		$xml = $this->nfseXml();
		if (null === $xml || !preg_match('/<nNFSe>(\d+)<\/nNFSe>/', $xml, $m)) {
			return null;
		}

		return $m[1];
	}

	public function resumoErros(): string
	{
		if ([] === $this->erros) {
			return sprintf('%s: HTTP %d sem lista de erros%s', $this->operacao, $this->status, '' !== trim($this->corpoBruto) ? ' — '.mb_substr(trim($this->corpoBruto), 0, 300) : '');
		}

		return sprintf('%s: HTTP %d — %s', $this->operacao, $this->status, implode(' | ', array_map('strval', $this->erros)));
	}

	/** @throws SefinExcecao quando a Sefin recusou */
	public function exigirSucesso(): self
	{
		if (!$this->sucesso()) {
			throw new SefinExcecao($this);
		}

		return $this;
	}

	/** @return array<string,mixed> */
	public function paraArray(): array
	{
		return [
			'operacao' => $this->operacao,
			'status' => $this->status,
			'sucesso' => $this->sucesso(),
			'chaveAcesso' => $this->chaveAcesso(),
			'idDps' => $this->idDps(),
			'numeroNfse' => $this->numeroNfse(),
			'erros' => array_map('strval', $this->erros),
			'alertas' => array_map('strval', $this->alertas),
			'xmls' => array_keys($this->xmls),
			'dados' => array_filter($this->dados, static fn($k) => !preg_match('/XmlGZipB64$/i', (string) $k), \ARRAY_FILTER_USE_KEY),
		];
	}
}
