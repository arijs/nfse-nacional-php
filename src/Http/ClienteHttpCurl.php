<?php declare(strict_types=1);

namespace Arijs\NfseNacional\Http;

use Arijs\NfseNacional\Certificado\CertificadoDigital;
use Arijs\NfseNacional\Excecao\TransporteExcecao;

/**
 * {@see ClienteHttp} sobre cURL com autenticação mútua TLS (mTLS) pelo
 * certificado do emitente — a API Sefin não tem token: é o certificado da
 * conexão que identifica quem chama (regras E1200..E1209).
 *
 * A chave e o certificado vão para o cURL em memória (CURLOPT_SSLCERT_BLOB,
 * cURL ≥ 7.71). Em cURL mais antigo caem num arquivo temporário 0600 apagado
 * ao fim da chamada — é o que dá para fazer em hospedagem compartilhada.
 */
final class ClienteHttpCurl implements ClienteHttp
{
	public function __construct(
		private readonly ?CertificadoDigital $certificado,
		private readonly int $timeout = 60,
		private readonly int $timeoutConexao = 10,
		private readonly ?string $caBundle = null,
		private readonly string $userAgent = 'arijs/nfse-nacional (PHP)',
	) {
	}

	public function enviar(string $metodo, string $url, array $cabecalhos = [], ?string $corpo = null): RespostaHttp
	{
		if (!\function_exists('curl_init')) {
			throw new TransporteExcecao('A extensão cURL do PHP não está disponível.');
		}
		$ch = curl_init($url);
		if (false === $ch) {
			throw new TransporteExcecao('Não foi possível iniciar o cURL.');
		}
		$temporarios = [];
		try {
			$linhas = [];
			foreach ($cabecalhos as $nome => $valor) {
				$linhas[] = $nome.': '.$valor;
			}
			$respCabecalhos = [];
			curl_setopt_array($ch, [
				\CURLOPT_CUSTOMREQUEST => strtoupper($metodo),
				\CURLOPT_NOBODY => 'HEAD' === strtoupper($metodo),
				\CURLOPT_RETURNTRANSFER => true,
				\CURLOPT_HTTPHEADER => $linhas,
				\CURLOPT_TIMEOUT => $this->timeout,
				\CURLOPT_CONNECTTIMEOUT => $this->timeoutConexao,
				\CURLOPT_FOLLOWLOCATION => false,
				\CURLOPT_SSL_VERIFYPEER => true,
				\CURLOPT_SSL_VERIFYHOST => 2,
				\CURLOPT_USERAGENT => $this->userAgent,
				\CURLOPT_HEADERFUNCTION => static function ($ch, string $linha) use (&$respCabecalhos): int {
					$p = strpos($linha, ':');
					if (false !== $p) {
						$respCabecalhos[strtolower(trim(substr($linha, 0, $p)))] = trim(substr($linha, $p + 1));
					}

					return \strlen($linha);
				},
			]);
			if (null !== $this->caBundle) {
				curl_setopt($ch, \CURLOPT_CAINFO, $this->caBundle);
			}
			if (null !== $corpo) {
				curl_setopt($ch, \CURLOPT_POSTFIELDS, $corpo);
			}
			if (null !== $this->certificado) {
				$this->configurarMtls($ch, $temporarios);
			}

			$resposta = curl_exec($ch);
			if (false === $resposta) {
				throw new TransporteExcecao(sprintf('Falha de transporte ao chamar %s %s: [%d] %s', strtoupper($metodo), $url, curl_errno($ch), curl_error($ch)));
			}
			$status = (int) curl_getinfo($ch, \CURLINFO_RESPONSE_CODE);

			return new RespostaHttp($status, (string) $resposta, $respCabecalhos);
		} finally {
			curl_close($ch);
			foreach ($temporarios as $arquivo) {
				@unlink($arquivo);
			}
		}
	}

	/** @param list<string> $temporarios */
	private function configurarMtls(\CurlHandle $ch, array &$temporarios): void
	{
		\assert(null !== $this->certificado);
		$cert = $this->certificado->certificadoPem;
		// Certificados intermediários ajudam o servidor a montar a cadeia.
		foreach ($this->certificado->cadeiaPem as $extra) {
			$cert .= "\n".trim($extra)."\n";
		}
		$chave = $this->certificado->chavePrivadaPem;

		curl_setopt($ch, \CURLOPT_SSLCERTTYPE, 'PEM');
		curl_setopt($ch, \CURLOPT_SSLKEYTYPE, 'PEM');

		if (\defined('CURLOPT_SSLCERT_BLOB') && version_compare((string) curl_version()['version'], '7.71.0', '>=')) {
			curl_setopt($ch, \CURLOPT_SSLCERT_BLOB, $cert);
			curl_setopt($ch, \CURLOPT_SSLKEY_BLOB, $chave);

			return;
		}

		$temporarios[] = $arqCert = self::temporario($cert, 'nfse-cert-');
		$temporarios[] = $arqChave = self::temporario($chave, 'nfse-key-');
		curl_setopt($ch, \CURLOPT_SSLCERT, $arqCert);
		curl_setopt($ch, \CURLOPT_SSLKEY, $arqChave);
	}

	private static function temporario(string $conteudo, string $prefixo): string
	{
		$arquivo = tempnam(sys_get_temp_dir(), $prefixo);
		if (false === $arquivo) {
			throw new TransporteExcecao('Não foi possível criar arquivo temporário para o certificado.');
		}
		chmod($arquivo, 0600);
		if (false === file_put_contents($arquivo, $conteudo)) {
			@unlink($arquivo);
			throw new TransporteExcecao('Não foi possível gravar o arquivo temporário do certificado.');
		}

		return $arquivo;
	}
}
