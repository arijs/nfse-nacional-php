<?php declare(strict_types=1);

namespace Arijs\NfseNacional\Danfse;

use Com\Tecnick\Barcode\Type\Square\QrCode\Data;
use Com\Tecnick\Barcode\Type\Square\QrCode\Encode;

/**
 * QR Code com o conteúdo inteiro num ÚNICO segmento em modo byte.
 *
 * O tc-lib-barcode sempre divide o texto no arranjo mais compacto (os 50
 * dígitos da chave viram um segmento numérico) e ignora o "8B" do tipo — o
 * link da consulta cabe então na versão 6. O Emissor Nacional codifica tudo
 * em byte, o que dá versão 7 (com 6 padrões de alinhamento). Com um segmento
 * só, o resto do Encode da lib (Reed-Solomon, posicionamento, máscara) produz
 * o símbolo idêntico ao do DANFSe oficial — conferido módulo a módulo com o da
 * nota #913 (nível M, máscara 2, 0 diferenças em 45 × 45).
 */
final class QrCodeByte extends Encode
{
	/** @return array<int, array{int, int, int}> */
	protected function getSegments(string $code, int $group, bool $kanji): array
	{
		return [[Data::MODE_BYTE, 0, \strlen($code)]];
	}
}
