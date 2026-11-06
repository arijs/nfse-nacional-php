<?php declare(strict_types=1);

namespace Arijs\NfseNacional\Tests\Support;

use Arijs\NfseNacional\Http\ClienteHttp;
use Arijs\NfseNacional\Http\RespostaHttp;

/** Devolve respostas enfileiradas e guarda o que foi enviado. */
final class ClienteHttpFalso implements ClienteHttp
{
	/** @var list<array{metodo:string,url:string,cabecalhos:array<string,string>,corpo:?string}> */
	public array $chamadas = [];
	/** @var list<RespostaHttp> */
	private array $fila = [];

	public function responder(int $status, array|string $corpo): self
	{
		$this->fila[] = new RespostaHttp($status, \is_array($corpo) ? (string) json_encode($corpo) : $corpo);

		return $this;
	}

	public function enviar(string $metodo, string $url, array $cabecalhos = [], ?string $corpo = null): RespostaHttp
	{
		$this->chamadas[] = ['metodo' => $metodo, 'url' => $url, 'cabecalhos' => $cabecalhos, 'corpo' => $corpo];

		return array_shift($this->fila) ?? new RespostaHttp(500, '{"erros":[{"codigo":"TESTE","descricao":"sem resposta enfileirada"}]}');
	}

	/** @return array<string,mixed> */
	public function ultimoJson(): array
	{
		$c = $this->chamadas[array_key_last($this->chamadas)]['corpo'] ?? '';

		return (array) json_decode((string) $c, true);
	}
}
