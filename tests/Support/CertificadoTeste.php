<?php declare(strict_types=1);

namespace Arijs\NfseNacional\Tests\Support;

use Arijs\NfseNacional\Certificado\CertificadoDigital;

/**
 * Certificado autoassinado gerado em memória, com CN no padrão ICP-Brasil
 * ("NOME:CNPJ"). Serve para assinar e verificar nos testes; a Sefin, claro,
 * rejeitaria (E1208 — raiz difere da ICP-Brasil).
 */
final class CertificadoTeste
{
	public const CNPJ = '12345678000195';

	private static ?CertificadoDigital $cache = null;

	/** @return array{cert:string,chave:string} PEMs */
	public static function pems(int $validadeDias = 30, string $cn = 'EMPRESA TESTE LTDA:'.self::CNPJ): array
	{
		$chave = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
		\assert(false !== $chave);
		$csr = openssl_csr_new(['CN' => $cn, 'O' => 'ICP-Brasil (teste)', 'C' => 'BR'], $chave, ['digest_alg' => 'sha256']);
		\assert(false !== $csr);
		$x509 = openssl_csr_sign($csr, null, $chave, $validadeDias, ['digest_alg' => 'sha256']);
		\assert(false !== $x509);
		openssl_x509_export($x509, $certPem);
		openssl_pkey_export($chave, $chavePem);

		return ['cert' => $certPem, 'chave' => $chavePem];
	}

	public static function certificado(): CertificadoDigital
	{
		if (null === self::$cache) {
			$p = self::pems();
			self::$cache = CertificadoDigital::dePem($p['cert'], $p['chave']);
		}

		return self::$cache;
	}

	public static function pfx(string $senha = 'segredo', ?string $cn = null): string
	{
		$p = null === $cn ? self::pems() : self::pems(30, $cn);
		$ok = openssl_pkcs12_export($p['cert'], $pfx, $p['chave'], $senha);
		\assert($ok);

		return $pfx;
	}
}
