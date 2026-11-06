<?php declare(strict_types=1);

namespace Arijs\NfseNacional;

use Arijs\NfseNacional\Assinatura\AssinadorXml;
use Arijs\NfseNacional\Dps\DpsXml;
use Arijs\NfseNacional\Evento\PedidoRegistroEventoXml;
use Arijs\NfseNacional\Excecao\CertificadoExcecao;
use Arijs\NfseNacional\Excecao\DadosExcecao;
use Arijs\NfseNacional\Http\ClienteHttp;
use Arijs\NfseNacional\Http\ClienteHttpCurl;
use Arijs\NfseNacional\Sefin\RespostaSefin;
use Arijs\NfseNacional\Sefin\SefinNacional;
use Arijs\NfseNacional\Xsd\ValidadorXsd;

/**
 * Fachada: o fluxo completo de uma nota em uma chamada.
 *
 *   $nfse = new Nfse(Configuracao::doAmbiente());
 *   $resposta = $nfse->emitir([...infDPS...]);   // gera → valida XSD → assina → envia
 *   $resposta->chaveAcesso(); $resposta->nfseXml();
 *
 * Cada etapa também existe separada ({@see prepararDps}) para quem quer
 * guardar o XML assinado antes de enviar ou reenviar um XML já pronto.
 */
final class Nfse
{
	private readonly SefinNacional $sefin;
	private readonly AssinadorXml $assinador;
	private readonly ValidadorXsd $xsd;

	public function __construct(
		private readonly Configuracao $config,
		?ClienteHttp $http = null,
	) {
		$http ??= new ClienteHttpCurl($config->certificado, $config->timeoutSegundos, 10, $config->caBundle);
		$this->sefin = new SefinNacional($config->ambiente, $http, $config->urlSefin);
		$this->assinador = new AssinadorXml($config->certificado, $config->perfilAssinatura);
		$this->xsd = new ValidadorXsd();
	}

	public function sefin(): SefinNacional
	{
		return $this->sefin;
	}

	public function configuracao(): Configuracao
	{
		return $this->config;
	}

	/**
	 * Monta, valida e assina a DPS — sem enviar.
	 *
	 * Preenche o que é constante da configuração quando o array não traz:
	 * tpAmb (do ambiente), verAplic, dhEmi (agora, fuso de São Paulo),
	 * dCompet (data de dhEmi), tpEmit (1 = prestador) e, se o prestador não
	 * tiver CNPJ/CPF, o do certificado.
	 *
	 * @param array<string,mixed> $infDps
	 *
	 * @return string XML assinado, pronto para {@see enviarXml}
	 */
	public function prepararDps(array $infDps): string
	{
		$this->exigirCertificadoVigente();
		$infDps = $this->completar($infDps);
		$xml = DpsXml::gerar($infDps);
		if ($this->config->validarXsd) {
			$this->xsd->validarOuLancar($xml, 'DPS');
		}
		$assinado = $this->assinador->assinar($xml, 'infDPS');
		if ($this->config->validarXsd) {
			$this->xsd->validarOuLancar($assinado, 'DPS');
		}

		return $assinado;
	}

	/**
	 * @param array<string,mixed>|string $dps array do infDPS, ou XML já assinado
	 */
	public function emitir(array|string $dps): RespostaSefin
	{
		$xml = \is_array($dps) ? $this->prepararDps($dps) : $dps;

		return $this->sefin->enviarDps($xml);
	}

	public function consultar(string $chaveAcesso): RespostaSefin
	{
		return $this->sefin->consultarNfse($chaveAcesso);
	}

	/**
	 * Cancela uma NFS-e (evento e101101).
	 *
	 * @param int $motivo PedidoRegistroEventoXml::MOTIVO_*
	 * @param string|null $cnpjOuCpfAutor padrão: o do certificado
	 */
	public function cancelar(string $chaveAcesso, int $motivo, string $justificativa, ?string $cnpjOuCpfAutor = null): RespostaSefin
	{
		$xml = $this->prepararEvento(PedidoRegistroEventoXml::cancelamento(
			$this->config->ambiente,
			$this->config->versaoAplicativo,
			$cnpjOuCpfAutor ?? $this->inscricaoDoCertificado(),
			$chaveAcesso,
			$motivo,
			$justificativa,
			$this->config->agora(),
		));

		return $this->sefin->registrarEvento($chaveAcesso, $xml);
	}

	/** Valida (XSD pedRegEvento) e assina um pedido de registro de evento. */
	public function prepararEvento(string $pedRegEventoXml): string
	{
		$this->exigirCertificadoVigente();
		if ($this->config->validarXsd) {
			$this->xsd->validarOuLancar($pedRegEventoXml, 'pedRegEvento');
		}

		return $this->assinador->assinar($pedRegEventoXml, 'infPedReg');
	}

	/**
	 * @param array<string,mixed> $infDps
	 *
	 * @return array<string,mixed>
	 */
	private function completar(array $infDps): array
	{
		$agora = $this->config->agora();
		$infDps['tpAmb'] ??= (string) $this->config->ambiente->value;
		if ((string) $infDps['tpAmb'] !== (string) $this->config->ambiente->value) {
			throw new DadosExcecao(sprintf('tpAmb=%s não corresponde ao ambiente configurado (%s). A Sefin rejeitaria com E0006.', $infDps['tpAmb'], $this->config->ambiente->value));
		}
		$infDps['verAplic'] ??= $this->config->versaoAplicativo;
		$infDps['dhEmi'] ??= $agora;
		$infDps['dCompet'] ??= $infDps['dhEmi'] instanceof \DateTimeInterface ? $infDps['dhEmi'] : $agora;
		$infDps['tpEmit'] ??= '1';
		if (\is_array($infDps['prest'] ?? null) && !isset($infDps['prest']['CNPJ']) && !isset($infDps['prest']['CPF'])) {
			$insc = $this->config->certificado->cnpjOuCpf();
			if (null !== $insc) {
				$infDps['prest'][14 === \strlen($insc) ? 'CNPJ' : 'CPF'] = $insc;
			}
		}

		return $infDps;
	}

	private function inscricaoDoCertificado(): string
	{
		return $this->config->certificado->cnpjOuCpf()
			?? throw new CertificadoExcecao('Não foi possível ler o CNPJ/CPF do certificado; informe o autor do evento explicitamente.');
	}

	private function exigirCertificadoVigente(): void
	{
		if (!$this->config->certificado->vigente($this->config->agora())) {
			throw new CertificadoExcecao(sprintf(
				'Certificado fora da validade (%s a %s). A Sefin rejeita com E1203.',
				$this->config->certificado->validoDe()->format('d/m/Y'),
				$this->config->certificado->validoAte()->format('d/m/Y'),
			));
		}
	}
}
