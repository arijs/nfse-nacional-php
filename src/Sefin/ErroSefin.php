<?php declare(strict_types=1);

namespace Arijs\NfseNacional\Sefin;

/**
 * Um item da lista `erros` (ou `alertas`) da API Sefin. O código segue a
 * planilha de regras de negócio do Anexo I (E0006, E1225, E1235...).
 */
final class ErroSefin
{
	public function __construct(
		public readonly string $codigo,
		public readonly string $descricao,
		public readonly ?string $complemento = null,
	) {
	}

	/** @param array<string,mixed> $dados */
	public static function deArray(array $dados): self
	{
		return new self(
			(string) ($dados['codigo'] ?? $dados['Codigo'] ?? ''),
			(string) ($dados['descricao'] ?? $dados['Descricao'] ?? $dados['mensagem'] ?? ''),
			isset($dados['complemento']) ? (string) $dados['complemento'] : null,
		);
	}

	public function __toString(): string
	{
		return trim($this->codigo.' '.$this->descricao.(null !== $this->complemento && '' !== $this->complemento ? ' ('.$this->complemento.')' : ''));
	}
}
