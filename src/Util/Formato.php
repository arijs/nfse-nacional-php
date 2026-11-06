<?php declare(strict_types=1);

namespace Arijs\NfseNacional\Util;

/**
 * Formatos exigidos pelos tipos simples do leiaute (tiposSimples_v1.01.xsd).
 */
final class Formato
{
	/**
	 * Decimal no padrão TSDecNVM: ponto como separador, sem separador de
	 * milhar, sem zeros à esquerda ("0", "0.50", "1234.00").
	 *
	 * Aceita int, float ou string numérica (inclusive com vírgula).
	 */
	public static function decimal(int|float|string $valor, int $casas = 2): string
	{
		if (\is_string($valor)) {
			$valor = str_replace(',', '.', trim($valor));
			if (!is_numeric($valor)) {
				throw new \InvalidArgumentException("Valor numérico inválido: '{$valor}'.");
			}
		}
		$s = number_format(round((float) $valor, $casas), $casas, '.', '');
		if (str_starts_with($s, '-')) {
			throw new \InvalidArgumentException("O leiaute não aceita valores negativos: {$s}.");
		}

		return $s;
	}

	public static function somenteDigitos(string $valor): string
	{
		return preg_replace('/\D+/', '', $valor) ?? '';
	}

	/**
	 * Data e hora no formato TSDateTimeUTC: AAAA-MM-DDThh:mm:ss±hh:mm.
	 * O leiaute só aceita fuso com minutos zerados (os do Brasil são).
	 */
	public static function dataHora(\DateTimeInterface $dh): string
	{
		return $dh->format('Y-m-d\TH:i:sP');
	}

	public static function data(\DateTimeInterface $d): string
	{
		return $d->format('Y-m-d');
	}

	/** Remove acentos/caracteres fora do permitido e espaços repetidos. */
	public static function texto(string $s, int $max): string
	{
		$s = trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
		if (mb_strlen($s) > $max) {
			$s = mb_substr($s, 0, $max);
		}

		return $s;
	}
}
