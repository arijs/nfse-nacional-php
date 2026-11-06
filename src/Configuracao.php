<?php declare(strict_types=1);

namespace Arijs\NfseNacional;

use Arijs\NfseNacional\Assinatura\PerfilAssinatura;
use Arijs\NfseNacional\Certificado\CertificadoDigital;
use Arijs\NfseNacional\Excecao\CertificadoExcecao;

/**
 * Tudo que a {@see Nfse} precisa saber e que não muda de nota para nota.
 */
final class Configuracao
{
	public function __construct(
		public readonly Ambiente $ambiente,
		public readonly CertificadoDigital $certificado,
		/** Vai no campo verAplic (1-20 caracteres): identifique seu sistema e versão. */
		public readonly string $versaoAplicativo = 'nfse-nacional/0.1',
		public readonly PerfilAssinatura $perfilAssinatura = PerfilAssinatura::Sha1,
		/** Validar contra o XSD antes de assinar/enviar. Desligue só para depurar. */
		public readonly bool $validarXsd = true,
		/** Sobrescreve a URL da Sefin (ex.: proxy, mock local). */
		public readonly ?string $urlSefin = null,
		/** Sobrescreve a URL do ADN. */
		public readonly ?string $urlAdn = null,
		public readonly int $timeoutSegundos = 60,
		/** Caminho de um CA bundle, se o servidor não tiver o do sistema. */
		public readonly ?string $caBundle = null,
		/** Fonte de "agora" (dhEmi, dhEvento, vigência do certificado). Padrão: relógio do sistema em America/Sao_Paulo. */
		public readonly ?\Closure $relogio = null,
		/**
		 * Se a Sefin recusar a assinatura (E0714), assinar de novo com o outro
		 * perfil e reenviar — uma vez. O perfil que funcionou passa a ser usado
		 * nas chamadas seguintes desta instância de {@see Nfse}.
		 */
		public readonly bool $fallbackAssinatura = true,
		/**
		 * Chamado com o {@see PerfilAssinatura} que funcionou quando o fallback
		 * entra em ação — grave-o (NFSE_PERFIL_ASSINATURA) para as próximas
		 * execuções já começarem certas.
		 *
		 * @var null|\Closure(PerfilAssinatura):void
		 */
		public readonly ?\Closure $aoDescobrirPerfil = null,
	) {
	}

	public function comPerfil(PerfilAssinatura $perfil): self
	{
		return $this->copiar(['perfilAssinatura' => $perfil]);
	}

	public function comFallbackAssinatura(bool $ligado): self
	{
		return $this->copiar(['fallbackAssinatura' => $ligado]);
	}

	/** @param array<string,mixed> $alteracoes */
	private function copiar(array $alteracoes): self
	{
		$args = [];
		foreach ((new \ReflectionClass(self::class))->getConstructor()?->getParameters() ?? [] as $p) {
			$args[$p->getName()] = \array_key_exists($p->getName(), $alteracoes) ? $alteracoes[$p->getName()] : $this->{$p->getName()};
		}

		return new self(...$args);
	}

	public function agora(): \DateTimeImmutable
	{
		if (null !== $this->relogio) {
			return \DateTimeImmutable::createFromInterface(($this->relogio)());
		}

		return new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo'));
	}

	/**
	 * Lê das variáveis de ambiente:
	 *   NFSE_AMBIENTE            1|producao ou 2|homologacao
	 *   NFSE_CERT_PFX            caminho do .pfx (ou NFSE_CERT_PEM + NFSE_CHAVE_PEM)
	 *   NFSE_CERT_SENHA          senha do .pfx / da chave
	 *   NFSE_VERSAO_APLICATIVO   (opcional) texto do verAplic
	 *   NFSE_PERFIL_ASSINATURA   (opcional) sha1 | sha256
	 *   NFSE_FALLBACK_ASSINATURA (opcional) 0 desliga a tentativa com o outro perfil
	 *   NFSE_URL_SEFIN           (opcional) URL base alternativa da Sefin
	 *   NFSE_URL_ADN             (opcional) URL base alternativa do ADN
	 *   NFSE_TIMEOUT             (opcional) segundos
	 *   NFSE_CA_BUNDLE           (opcional) caminho do CA bundle
	 *
	 * @param array<string,mixed>|null $env fonte alternativa (padrão: $_ENV + $_SERVER + getenv)
	 */
	public static function doAmbiente(?array $env = null): self
	{
		$ler = static function (string $chave) use ($env): string {
			$v = null !== $env ? ($env[$chave] ?? null) : ($_ENV[$chave] ?? $_SERVER[$chave] ?? getenv($chave));

			return false === $v || null === $v ? '' : trim((string) $v);
		};
		$amb = $ler('NFSE_AMBIENTE');
		if ('' === $amb) {
			throw new \InvalidArgumentException('Defina NFSE_AMBIENTE (1/producao ou 2/homologacao).');
		}
		$senha = $ler('NFSE_CERT_SENHA');
		$pfx = $ler('NFSE_CERT_PFX');
		if ('' !== $pfx) {
			$cert = CertificadoDigital::deArquivoPfx($pfx, $senha);
		} elseif ('' !== $ler('NFSE_CERT_PEM')) {
			$cert = CertificadoDigital::dePem(
				(string) file_get_contents($ler('NFSE_CERT_PEM')),
				(string) file_get_contents($ler('NFSE_CHAVE_PEM')),
				'' !== $senha ? $senha : null,
			);
		} else {
			throw new CertificadoExcecao('Defina NFSE_CERT_PFX (ou NFSE_CERT_PEM + NFSE_CHAVE_PEM).');
		}

		return new self(
			ambiente: Ambiente::de($amb),
			certificado: $cert,
			versaoAplicativo: $ler('NFSE_VERSAO_APLICATIVO') ?: 'nfse-nacional/0.1',
			perfilAssinatura: PerfilAssinatura::de($ler('NFSE_PERFIL_ASSINATURA') ?: 'sha1'),
			urlSefin: $ler('NFSE_URL_SEFIN') ?: null,
			urlAdn: $ler('NFSE_URL_ADN') ?: null,
			timeoutSegundos: (int) ($ler('NFSE_TIMEOUT') ?: 60),
			caBundle: $ler('NFSE_CA_BUNDLE') ?: null,
			fallbackAssinatura: !\in_array(strtolower($ler('NFSE_FALLBACK_ASSINATURA')), ['0', 'false', 'nao', 'não', 'off'], true),
		);
	}
}
