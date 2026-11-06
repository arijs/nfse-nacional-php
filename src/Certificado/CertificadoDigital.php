<?php declare(strict_types=1);

namespace Rhengles\NfseNacional\Certificado;

use Rhengles\NfseNacional\Excecao\CertificadoExcecao;

/**
 * Certificado digital ICP-Brasil (e-CNPJ ou e-CPF) do emitente, modelo A1.
 *
 * O mesmo certificado faz dois papéis na API Sefin: assina a DPS (XMLDSig) e
 * autentica a conexão TLS (mTLS). A Sefin exige que ele seja do próprio
 * emitente da DPS (regra E0718), então não existe "certificado do sistema":
 * cada empresa emite com o seu.
 *
 * A3 (token/smartcard) não é suportado — a chave privada precisa estar em
 * software para o servidor assinar.
 */
final class CertificadoDigital
{
	/** @param list<string> $cadeiaPem certificados intermediários, se vieram no PFX */
	private function __construct(
		public readonly string $certificadoPem,
		public readonly string $chavePrivadaPem,
		public readonly array $cadeiaPem,
		private readonly array $info,
	) {
	}

	public static function deArquivoPfx(string $caminho, #[\SensitiveParameter] string $senha): self
	{
		if (!is_file($caminho) || !is_readable($caminho)) {
			throw new CertificadoExcecao("Arquivo de certificado não encontrado ou sem permissão de leitura: {$caminho}");
		}

		return self::dePfx((string) file_get_contents($caminho), $senha);
	}

	/**
	 * Lê um PKCS#12 (.pfx/.p12).
	 *
	 * PFX antigos usam cifras RC2/3DES que o OpenSSL 3 só abre com o provider
	 * "legacy"; quando `openssl_pkcs12_read` falha por isso, tentamos o binário
	 * `openssl` com `-legacy` antes de desistir.
	 */
	public static function dePfx(string $conteudoPfx, #[\SensitiveParameter] string $senha): self
	{
		$certs = [];
		if (openssl_pkcs12_read($conteudoPfx, $certs, $senha)) {
			return self::dePem(
				(string) $certs['cert'],
				(string) $certs['pkey'],
				null,
				array_values(array_filter((array) ($certs['extracerts'] ?? []))),
			);
		}
		$erro = self::ultimoErroOpenssl();

		$viaLegacy = self::lerPfxLegacy($conteudoPfx, $senha);
		if (null !== $viaLegacy) {
			return $viaLegacy;
		}

		throw new CertificadoExcecao(
			'Não foi possível abrir o certificado PFX (senha incorreta ou formato não suportado). '
			.'Se o arquivo é antigo (cifra RC2/3DES), converta-o: '
			.'openssl pkcs12 -legacy -in antigo.pfx -nodes | openssl pkcs12 -export -out novo.pfx. '
			.'OpenSSL: '.$erro,
		);
	}

	/**
	 * @param list<string> $cadeiaPem
	 */
	public static function dePem(string $certificadoPem, string $chavePrivadaPem, #[\SensitiveParameter] ?string $senhaChave = null, array $cadeiaPem = []): self
	{
		$x509 = openssl_x509_read($certificadoPem);
		if (false === $x509) {
			throw new CertificadoExcecao('Certificado PEM inválido: '.self::ultimoErroOpenssl());
		}
		$chave = openssl_pkey_get_private($chavePrivadaPem, $senhaChave ?? '');
		if (false === $chave) {
			throw new CertificadoExcecao('Chave privada PEM inválida ou senha incorreta: '.self::ultimoErroOpenssl());
		}
		if (!openssl_x509_check_private_key($x509, $chave)) {
			throw new CertificadoExcecao('A chave privada não corresponde ao certificado.');
		}
		// Chave sem senha, para o cURL e o openssl_sign usarem direto.
		if (null !== $senhaChave && '' !== $senhaChave) {
			openssl_pkey_export($chave, $chavePrivadaPem);
		}
		$info = openssl_x509_parse($x509);
		if (false === $info) {
			throw new CertificadoExcecao('Não foi possível ler os dados do certificado.');
		}

		return new self(trim($certificadoPem)."\n", trim($chavePrivadaPem)."\n", $cadeiaPem, $info);
	}

	/** Certificado em DER/Base64, numa linha só — formato do `<X509Certificate>`. */
	public function certificadoBase64(): string
	{
		$pem = preg_replace('/-----(BEGIN|END) CERTIFICATE-----/', '', $this->certificadoPem) ?? '';

		return preg_replace('/\s+/', '', $pem) ?? '';
	}

	/** CN do titular, ex.: "EMPRESA LTDA:12345678000195". */
	public function nome(): string
	{
		return (string) ($this->info['subject']['CN'] ?? '');
	}

	/**
	 * CNPJ (14) ou CPF (11) do titular, lido do CN no padrão ICP-Brasil
	 * ("NOME:NUMERO"). Null se o CN não seguir o padrão.
	 */
	public function cnpjOuCpf(): ?string
	{
		if (preg_match('/:(\d{11}|\d{14})\s*$/', $this->nome(), $m)) {
			return $m[1];
		}

		return null;
	}

	public function validoDe(): \DateTimeImmutable
	{
		return (new \DateTimeImmutable('@'.(int) $this->info['validFrom_time_t']))->setTimezone(new \DateTimeZone('America/Sao_Paulo'));
	}

	public function validoAte(): \DateTimeImmutable
	{
		return (new \DateTimeImmutable('@'.(int) $this->info['validTo_time_t']))->setTimezone(new \DateTimeZone('America/Sao_Paulo'));
	}

	public function vigente(?\DateTimeInterface $em = null): bool
	{
		$t = ($em ?? new \DateTimeImmutable())->getTimestamp();

		return $t >= (int) $this->info['validFrom_time_t'] && $t <= (int) $this->info['validTo_time_t'];
	}

	public function emissor(): string
	{
		return (string) ($this->info['issuer']['CN'] ?? '');
	}

	public function numeroSerie(): string
	{
		return (string) ($this->info['serialNumberHex'] ?? $this->info['serialNumber'] ?? '');
	}

	/** Assinatura RSA bruta de `$dados` com a chave privada. */
	public function assinar(string $dados, int $algoritmo = OPENSSL_ALGO_SHA256): string
	{
		$chave = openssl_pkey_get_private($this->chavePrivadaPem);
		if (false === $chave) {
			throw new CertificadoExcecao('Chave privada indisponível: '.self::ultimoErroOpenssl());
		}
		$assinatura = '';
		if (!openssl_sign($dados, $assinatura, $chave, $algoritmo)) {
			throw new CertificadoExcecao('openssl_sign falhou: '.self::ultimoErroOpenssl());
		}

		return $assinatura;
	}

	/** @return array<string,mixed> */
	public function resumo(): array
	{
		return [
			'titular' => $this->nome(),
			'cnpj_cpf' => $this->cnpjOuCpf(),
			'emissor' => $this->emissor(),
			'valido_de' => $this->validoDe()->format(\DATE_ATOM),
			'valido_ate' => $this->validoAte()->format(\DATE_ATOM),
			'vigente' => $this->vigente(),
			'numero_serie' => $this->numeroSerie(),
		];
	}

	private static function lerPfxLegacy(string $conteudoPfx, #[\SensitiveParameter] string $senha): ?self
	{
		if (!\function_exists('proc_open')) {
			return null;
		}
		$descritores = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
		$env = ['NFSE_PFX_SENHA' => $senha] + array_filter(['PATH' => getenv('PATH') ?: null]);
		$proc = @proc_open(
			['openssl', 'pkcs12', '-legacy', '-nodes', '-passin', 'env:NFSE_PFX_SENHA'],
			$descritores,
			$pipes,
			null,
			$env,
		);
		if (!\is_resource($proc)) {
			return null;
		}
		fwrite($pipes[0], $conteudoPfx);
		fclose($pipes[0]);
		$saida = (string) stream_get_contents($pipes[1]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		if (0 !== proc_close($proc) || '' === $saida) {
			return null;
		}
		if (!preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $saida, $certs)
			|| !preg_match('/-----BEGIN (?:RSA |EC |ENCRYPTED )?PRIVATE KEY-----.*?-----END (?:RSA |EC |ENCRYPTED )?PRIVATE KEY-----/s', $saida, $chave)) {
			return null;
		}
		$lista = $certs[0];
		$proprio = array_shift($lista);

		return self::dePem((string) $proprio, $chave[0], null, $lista);
	}

	private static function ultimoErroOpenssl(): string
	{
		$msgs = [];
		while (false !== ($e = openssl_error_string())) {
			$msgs[] = $e;
		}

		return implode('; ', $msgs) ?: '(sem detalhe)';
	}
}
