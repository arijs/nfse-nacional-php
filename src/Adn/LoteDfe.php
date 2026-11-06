<?php declare(strict_types=1);

namespace Arijs\NfseNacional\Adn;

/**
 * Resultado de uma sincronização: os documentos recebidos (de uma ou várias
 * páginas) e o último NSU consumido, que é o que você persiste para a
 * próxima rodada.
 */
final class LoteDfe
{
	/** Como o ADN de produção escreve (06/11/2026). */
	public const STATUS_LOCALIZADOS = 'DOCUMENTOS_LOCALIZADOS';
	public const STATUS_NENHUM = 'NENHUM_DOCUMENTO_LOCALIZADO';
	/** Grafias das implementações de referência — também aceitas. */
	public const STATUS_NORMAL = 'ProcessamentoNormal';
	public const STATUS_NENHUM_ALT = 'NenhumDocumentoLocalizado';
	/** "Nenhum documento localizado a partir do NSU informado" — vem com HTTP 404. */
	public const ERRO_NENHUM_DOCUMENTO = 'E2220';

	/** Status de "não há mais nada", em qualquer das grafias. */
	public static function nenhumDocumento(?string $status): bool
	{
		return null !== $status && 'nenhumdocumentolocalizado' === strtolower(str_replace('_', '', $status));
	}

	/** @param list<DocumentoDfe> $documentos */
	public function __construct(
		public readonly array $documentos,
		/** Maior NSU recebido (ou o NSU pedido, se nada veio). Guarde-o. */
		public readonly int $ultimoNsu,
		/** Último `StatusProcessamento` devolvido pelo ADN. */
		public readonly ?string $statusProcessamento,
		/** True quando a sincronização parou pelo limite de páginas e ainda há mais. */
		public readonly bool $temMais,
		/** Quantas páginas (chamadas) foram feitas. */
		public readonly int $paginas,
	) {
	}

	public function vazio(): bool
	{
		return [] === $this->documentos;
	}

	/** @return list<DocumentoDfe> */
	public function nfses(): array
	{
		return array_values(array_filter($this->documentos, static fn(DocumentoDfe $d) => $d->ehNfse()));
	}

	/** @return list<DocumentoDfe> */
	public function eventos(): array
	{
		return array_values(array_filter($this->documentos, static fn(DocumentoDfe $d) => $d->ehEvento()));
	}

	/** @return list<string> chaves das notas canceladas/substituídas neste lote */
	public function chavesCanceladas(): array
	{
		$chaves = [];
		foreach ($this->documentos as $d) {
			if ($d->cancelaNota() && null !== $d->chaveAcesso) {
				$chaves[$d->chaveAcesso] = true;
			}
		}

		return array_keys($chaves);
	}
}
