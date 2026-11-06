<?php declare(strict_types=1);

namespace Arijs\NfseNacional;

use Arijs\NfseNacional\Adn\AdnContribuintes;
use Arijs\NfseNacional\Adn\LoteDfe;
use Arijs\NfseNacional\Assinatura\AssinadorXml;
use Arijs\NfseNacional\Assinatura\PerfilAssinatura;
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
 *
 * Fallback de assinatura: se a Sefin responder E0714 ("erro na assinatura")
 * e {@see Configuracao::$fallbackAssinatura} estiver ligado, o mesmo XML é
 * assinado com o outro {@see PerfilAssinatura} e reenviado uma vez. Uma DPS
 * recusada não gera NFS-e, então o reenvio com o mesmo número é seguro. Só
 * o E0714 dispara isso: E0715/E0716/E0718 são defeitos do certificado, e um
 * silêncio (timeout) é {@see \Arijs\NfseNacional\Excecao\TransporteExcecao},
 * onde reenviar às cegas seria errado — use {@see SefinNacional::dpsProcessada}.
 */
final class Nfse
{
	/** Código da Sefin para assinatura inválida na DPS e nos eventos. */
	public const ERRO_ASSINATURA = 'E0714';

	private readonly SefinNacional $sefin;
	private readonly AdnContribuintes $adn;
	private readonly ValidadorXsd $xsd;
	private PerfilAssinatura $perfilAtivo;

	public function __construct(
		private readonly Configuracao $config,
		?ClienteHttp $http = null,
	) {
		$http ??= new ClienteHttpCurl($config->certificado, $config->timeoutSegundos, 10, $config->caBundle);
		$this->sefin = new SefinNacional($config->ambiente, $http, $config->urlSefin);
		$this->adn = new AdnContribuintes($config->ambiente, $http, $config->urlAdn);
		$this->xsd = new ValidadorXsd();
		$this->perfilAtivo = $config->perfilAssinatura;
	}

	public function sefin(): SefinNacional
	{
		return $this->sefin;
	}

	public function adn(): AdnContribuintes
	{
		return $this->adn;
	}

	public function configuracao(): Configuracao
	{
		return $this->config;
	}

	/**
	 * NFS-e emitidas pelo prestador do certificado numa faixa de números de
	 * DPS (uma chamada por número). Ver {@see SefinNacional::listarPorFaixa}.
	 *
	 * @param null|\Closure(array{nDPS:int,idDps:string,chaveAcesso:string,nfseXml:?string}):void $aoEncontrar
	 *
	 * @return list<array{nDPS:int,idDps:string,chaveAcesso:string,nfseXml:?string}>
	 */
	public function listarPorFaixa(string $cLocEmi, string|int $serie, int $de, int $ate, bool $comXml = false, ?\Closure $aoEncontrar = null, ?string $cnpjOuCpf = null): array
	{
		return $this->sefin->listarPorFaixa($cLocEmi, $cnpjOuCpf ?? $this->inscricaoDoCertificado(), $serie, $de, $ate, $comXml, $aoEncontrar);
	}

	/**
	 * Todos os documentos (notas emitidas, recebidas e eventos) do CNPJ a
	 * partir do último NSU conhecido. Guarde `$lote->ultimoNsu` para a
	 * próxima chamada. Ver {@see AdnContribuintes::sincronizar}.
	 *
	 * @param null|\Closure(LoteDfe):void $aoReceber chamado a cada página
	 */
	public function sincronizarDfe(int $ultimoNsu = 0, int $maxPaginas = 20, ?\Closure $aoReceber = null, ?string $cnpjConsulta = null): LoteDfe
	{
		// O ADN de produção recusa a consulta sem `cnpjConsulta` (HTTP 400,
		// "TipoConsulta":"InscricaoCertificado", lista de erros vazia — visto
		// em 06/11/2026), mesmo quando o CNPJ é o do certificado.
		return $this->adn->sincronizar($ultimoNsu, $maxPaginas, $cnpjConsulta ?? $this->config->certificado->cnpjOuCpf(), $aoReceber);
	}

	/** PDF oficial do DANFSe (ver observação em {@see AdnContribuintes::baixarDanfse}). */
	public function baixarDanfse(string $chaveAcesso): string
	{
		return $this->adn->baixarDanfse($chaveAcesso);
	}

	/** Perfil em uso: o configurado, ou o que o fallback descobriu funcionar. */
	public function perfilAtivo(): PerfilAssinatura
	{
		return $this->perfilAtivo;
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
	 * @return string XML assinado, pronto para {@see emitir}
	 */
	public function prepararDps(array $infDps, ?PerfilAssinatura $perfil = null): string
	{
		return $this->assinar($this->montarDps($infDps), 'infDPS', 'DPS', $perfil);
	}

	/**
	 * @param array<string,mixed>|string $dps array do infDPS, ou XML já assinado
	 */
	public function emitir(array|string $dps): RespostaSefin
	{
		$xml = \is_array($dps) ? $this->prepararDps($dps) : $dps;

		return $this->enviarComFallback($xml, 'infDPS', 'DPS', fn(string $x) => $this->sefin->enviarDps($x));
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

		return $this->enviarComFallback($xml, 'infPedReg', 'pedRegEvento', fn(string $x) => $this->sefin->registrarEvento($chaveAcesso, $x));
	}

	/** Valida (XSD pedRegEvento) e assina um pedido de registro de evento. */
	public function prepararEvento(string $pedRegEventoXml, ?PerfilAssinatura $perfil = null): string
	{
		$this->exigirCertificadoVigente();
		if ($this->config->validarXsd) {
			$this->xsd->validarOuLancar($pedRegEventoXml, 'pedRegEvento', $this->config->leiaute());
		}

		return $this->assinar($pedRegEventoXml, 'infPedReg', 'pedRegEvento', $perfil);
	}

	/**
	 * @param array<string,mixed> $infDps
	 *
	 * @return string XML validado, SEM assinatura
	 */
	private function montarDps(array $infDps): string
	{
		$this->exigirCertificadoVigente();
		$xml = DpsXml::gerar($this->completar($infDps), $this->config->leiaute());
		if ($this->config->validarXsd) {
			$this->xsd->validarOuLancar($xml, 'DPS', $this->config->leiaute());
		}

		return $xml;
	}

	private function assinar(string $xmlSemAssinatura, string $tagAlvo, string $esquema, ?PerfilAssinatura $perfil): string
	{
		$assinado = (new AssinadorXml($this->config->certificado, $perfil ?? $this->perfilAtivo))->assinar($xmlSemAssinatura, $tagAlvo);
		if ($this->config->validarXsd) {
			$this->xsd->validarOuLancar($assinado, $esquema, $this->config->leiaute());
		}

		return $assinado;
	}

	/**
	 * @param \Closure(string):RespostaSefin $enviar
	 */
	private function enviarComFallback(string $xmlAssinado, string $tagAlvo, string $esquema, \Closure $enviar): RespostaSefin
	{
		$resposta = $enviar($xmlAssinado);
		if ($resposta->sucesso() || !$this->config->fallbackAssinatura || !$resposta->temErro(self::ERRO_ASSINATURA)) {
			return $resposta;
		}

		$perfilUsado = AssinadorXml::verificar($xmlAssinado)['perfil'] ?? $this->perfilAtivo;
		$outro = PerfilAssinatura::Sha1 === $perfilUsado ? PerfilAssinatura::Sha256 : PerfilAssinatura::Sha1;
		$reassinado = $this->assinar(AssinadorXml::semAssinatura($xmlAssinado), $tagAlvo, $esquema, $outro);

		$segunda = $enviar($reassinado);
		if ($segunda->sucesso()) {
			$this->perfilAtivo = $outro;
			if (null !== $this->config->aoDescobrirPerfil) {
				($this->config->aoDescobrirPerfil)($outro);
			}
		}

		return $segunda;
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
		if ($this->config->leiaute()->exigeFinNfse()) {
			$infDps['finNFSe'] ??= '0'; // NFS-e regular; 1 crédito e 2 débito são as notas de ajuste
		}
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
