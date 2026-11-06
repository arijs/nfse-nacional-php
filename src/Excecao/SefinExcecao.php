<?php declare(strict_types=1);

namespace Arijs\NfseNacional\Excecao;

use Arijs\NfseNacional\Sefin\RespostaSefin;

/**
 * A Sefin respondeu, mas recusou o pedido (HTTP 4xx/5xx ou lista `erros`).
 *
 * Carrega a {@see RespostaSefin} inteira: os códigos de erro (E0006, E1225,
 * ...) estão em `$resposta->erros()` e a mensagem desta exceção já os resume.
 */
final class SefinExcecao extends NfseExcecao
{
	public function __construct(public readonly RespostaSefin $resposta, ?string $mensagem = null)
	{
		parent::__construct($mensagem ?? $resposta->resumoErros(), $resposta->status);
	}
}
