<?php declare(strict_types=1);

namespace Arijs\NfseNacional\Util;

use Arijs\NfseNacional\Excecao\DadosExcecao;

/**
 * A "área de dados" da API Sefin: o XML vai e volta comprimido em GZIP e
 * codificado em Base64 (campos `dpsXmlGZipB64`, `nfseXmlGZipB64`,
 * `pedidoRegistroEventoXmlGZipB64`, `eventoXmlGZipB64`).
 */
final class Gzip
{
	public static function comprimirBase64(string $dados): string
	{
		$gz = gzencode($dados, 9);
		if (false === $gz) {
			throw new DadosExcecao('Falha ao comprimir os dados em GZIP.');
		}

		return base64_encode($gz);
	}

	public static function descomprimirBase64(string $base64): string
	{
		$bin = base64_decode(trim($base64), true);
		if (false === $bin) {
			throw new DadosExcecao('Conteúdo não é Base64 válido.');
		}
		$xml = @gzdecode($bin);
		if (false === $xml) {
			throw new DadosExcecao('Conteúdo Base64 não é um GZIP válido.');
		}

		return $xml;
	}
}
