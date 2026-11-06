<?php declare(strict_types=1);

namespace Rhengles\NfseNacional\Http;

final class RespostaHttp
{
	/** @param array<string,string> $cabecalhos nomes em minúsculas */
	public function __construct(
		public readonly int $status,
		public readonly string $corpo,
		public readonly array $cabecalhos = [],
	) {
	}

	/** @return array<string,mixed>|null null quando o corpo não é um objeto JSON */
	public function json(): ?array
	{
		if ('' === trim($this->corpo)) {
			return null;
		}
		$dados = json_decode($this->corpo, true);

		return \is_array($dados) ? $dados : null;
	}
}
