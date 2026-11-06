<?php declare(strict_types=1);

namespace Arijs\NfseNacional\Http;

use Arijs\NfseNacional\Excecao\TransporteExcecao;

/**
 * Transporte HTTP. Interface para o cliente da Sefin ser testável sem rede e
 * sem certificado (os testes injetam um duplo que devolve JSON gravado).
 */
interface ClienteHttp
{
	/**
	 * @param array<string,string> $cabecalhos
	 *
	 * @throws TransporteExcecao em falha de transporte (DNS, TLS, timeout) — nunca por status HTTP
	 */
	public function enviar(string $metodo, string $url, array $cabecalhos = [], ?string $corpo = null): RespostaHttp;
}
