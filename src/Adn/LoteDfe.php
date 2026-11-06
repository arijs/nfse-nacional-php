<?php declare(strict_types=1);

namespace Arijs\NfseNacional\Adn;

/**
 * Resultado de uma sincronização: os documentos recebidos (de uma ou várias
 * páginas) e o último NSU consumido, que é o que você persiste para a
 * próxima rodada.
 */
final class LoteDfe
{
	public const STATUS_NORMAL = 'ProcessamentoNormal';
	public const STATUS_NENHUM = 'NenhumDocumentoLocalizado';

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
