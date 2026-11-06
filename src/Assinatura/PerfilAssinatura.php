<?php declare(strict_types=1);

namespace Rhengles\NfseNacional\Assinatura;

/**
 * Dois conjuntos de algoritmos circulam para a assinatura da DPS:
 *
 *  - Sha1: C14N inclusiva + RSA-SHA1 + digest SHA-1. É o "subconjunto do
 *    XMLDSig" descrito na seção Padrão de Assinatura Digital dos manuais de
 *    integração da NFS-e (mesmo perfil da NF-e). É o padrão desta biblioteca.
 *  - Sha256: C14N exclusiva + RSA-SHA256 + digest SHA-256. É o perfil que a
 *    própria Sefin usa ao assinar a NFS-e que devolve, e que várias
 *    integrações usam na DPS sem rejeição.
 *
 * A Sefin valida a assinatura pelos algoritmos declarados no próprio XML, por
 * isso os dois funcionam. Se um dia um deles for recusado, troque o perfil na
 * configuração sem mexer em código.
 */
enum PerfilAssinatura: string
{
	case Sha1 = 'sha1';
	case Sha256 = 'sha256';

	public function algoritmoC14n(): string
	{
		return match ($this) {
			self::Sha1 => 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315',
			self::Sha256 => 'http://www.w3.org/2001/10/xml-exc-c14n#',
		};
	}

	public function c14nExclusiva(): bool
	{
		return self::Sha256 === $this;
	}

	public function algoritmoAssinatura(): string
	{
		return match ($this) {
			self::Sha1 => 'http://www.w3.org/2000/09/xmldsig#rsa-sha1',
			self::Sha256 => 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256',
		};
	}

	public function algoritmoDigest(): string
	{
		return match ($this) {
			self::Sha1 => 'http://www.w3.org/2000/09/xmldsig#sha1',
			self::Sha256 => 'http://www.w3.org/2001/04/xmlenc#sha256',
		};
	}

	public function hash(): string
	{
		return $this->value;
	}

	public function algoritmoOpenssl(): int
	{
		return match ($this) {
			self::Sha1 => OPENSSL_ALGO_SHA1,
			self::Sha256 => OPENSSL_ALGO_SHA256,
		};
	}

	public static function de(string|self $v): self
	{
		return $v instanceof self ? $v : (self::tryFrom(strtolower(trim($v))) ?? throw new \InvalidArgumentException("Perfil de assinatura desconhecido: '{$v}' (sha1 ou sha256)."));
	}
}
