<?php declare(strict_types=1);

namespace Arijs\NfseNacional\Adn;

use Arijs\NfseNacional\Util\Gzip;

/**
 * Um item do lote de distribuição do ADN: uma NFS-e ou um evento em que o
 * CNPJ consultado figura como prestador, tomador ou intermediário.
 *
 * Nomes dos campos como o ADN devolve (`NSU`, `TipoDocumento`,
 * `ChaveAcesso`, `TipoEvento`, `DataHoraGeracao`, `ArquivoXml`), em
 * PascalCase; o parser aceita também camelCase.
 */
final class DocumentoDfe
{
	public const TIPO_NFSE = 'NFSE';
	public const TIPO_EVENTO = 'EVENTO';

	/** @param array<string,mixed> $bruto */
	public function __construct(
		public readonly int $nsu,
		/** "NFSE" ou "EVENTO" */
		public readonly ?string $tipoDocumento,
		public readonly ?string $chaveAcesso,
		/** Para eventos: texto como "CANCELAMENTO", "SUBSTITUICAO", "CONFIRMACAO_TOMADOR"... */
		public readonly ?string $tipoEvento,
		public readonly ?int $sequencialEvento,
		public readonly ?string $dataHoraGeracao,
		/** XML comprimido em GZIP + Base64, como veio */
		public readonly ?string $arquivoXmlGZipB64,
		public readonly array $bruto,
	) {
	}

	/** @param array<string,mixed> $d */
	public static function deArray(array $d): self
	{
		$ler = static function (array $d, string ...$nomes): mixed {
			foreach ($nomes as $n) {
				if (\array_key_exists($n, $d) && null !== $d[$n]) {
					return $d[$n];
				}
			}

			return null;
		};
		$nsu = $ler($d, 'NSU', 'nsu');
		$seq = $ler($d, 'SequencialEvento', 'sequencialEvento', 'nSeqEvento');
		$tipoEv = $ler($d, 'TipoEvento', 'tipoEvento');

		return new self(
			nsu: is_numeric($nsu) ? (int) $nsu : 0,
			tipoDocumento: self::texto($ler($d, 'TipoDocumento', 'tipoDocumento')),
			chaveAcesso: self::texto($ler($d, 'ChaveAcesso', 'chaveAcesso', 'chave')),
			tipoEvento: self::texto($tipoEv),
			sequencialEvento: is_numeric($seq) ? (int) $seq : null,
			dataHoraGeracao: self::texto($ler($d, 'DataHoraGeracao', 'dataHoraGeracao', 'DataHoraRegistro', 'dhProc')),
			arquivoXmlGZipB64: self::texto($ler($d, 'ArquivoXml', 'arquivoXml', 'ArquivoXmlGZipB64', 'arquivoXmlGZipB64')),
			bruto: $d,
		);
	}

	public function ehNfse(): bool
	{
		return 0 === strcasecmp((string) $this->tipoDocumento, self::TIPO_NFSE);
	}

	public function ehEvento(): bool
	{
		return 0 === strcasecmp((string) $this->tipoDocumento, self::TIPO_EVENTO);
	}

	/** Evento que invalida a nota (cancelamento ou cancelamento por substituição). */
	public function cancelaNota(): bool
	{
		$t = strtoupper((string) $this->tipoEvento);

		return $this->ehEvento() && (str_contains($t, 'CANCELAMENTO') || str_contains($t, 'SUBSTITUICAO') || str_contains($t, 'SUBSTITUIÇÃO'));
	}

	/** XML do documento, descomprimido; null se não veio. */
	public function xml(): ?string
	{
		if (null === $this->arquivoXmlGZipB64 || '' === $this->arquivoXmlGZipB64) {
			return null;
		}
		try {
			return Gzip::descomprimirBase64($this->arquivoXmlGZipB64);
		} catch (\Throwable) {
			return null;
		}
	}

	private static function texto(mixed $v): ?string
	{
		return null === $v ? null : (\is_scalar($v) ? (string) $v : null);
	}
}
