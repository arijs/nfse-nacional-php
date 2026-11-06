<?php declare(strict_types=1);

namespace Arijs\NfseNacional\Tests\Unit;

use Arijs\NfseNacional\Ambiente;
use Arijs\NfseNacional\Assinatura\AssinadorXml;
use Arijs\NfseNacional\Assinatura\PerfilAssinatura;
use Arijs\NfseNacional\Configuracao;
use Arijs\NfseNacional\DescobertaPerfil;
use Arijs\NfseNacional\Evento\PedidoRegistroEventoXml;
use Arijs\NfseNacional\Excecao\DadosExcecao;
use Arijs\NfseNacional\Nfse;
use Arijs\NfseNacional\Tests\Support\CertificadoTeste;
use Arijs\NfseNacional\Tests\Support\ClienteHttpFalso;
use Arijs\NfseNacional\Tests\Support\DpsExemplo;
use Arijs\NfseNacional\Util\Gzip;
use PHPUnit\Framework\TestCase;

final class FallbackAssinaturaTest extends TestCase
{
	private const CHAVE = '35503082123456780001950000000000012026110000000017';
	private const E0714 = ['erros' => [['codigo' => 'E0714', 'descricao' => 'Arquivo enviado com erro na assinatura.']]];

	/** @var list<PerfilAssinatura> */
	private array $descobertos = [];

	private function nfse(ClienteHttpFalso $http, bool $fallback = true, PerfilAssinatura $perfil = PerfilAssinatura::Sha1): Nfse
	{
		$config = new Configuracao(
			Ambiente::Homologacao,
			CertificadoTeste::certificado(),
			perfilAssinatura: $perfil,
			fallbackAssinatura: $fallback,
			aoDescobrirPerfil: function (PerfilAssinatura $p): void {
				$this->descobertos[] = $p;
			},
		);

		return new Nfse($config, $http);
	}

	private static function perfilDoEnvio(ClienteHttpFalso $http, int $n, string $campo = 'dpsXmlGZipB64'): ?PerfilAssinatura
	{
		$json = (array) json_decode((string) $http->chamadas[$n]['corpo'], true);

		return AssinadorXml::verificar(Gzip::descomprimirBase64((string) $json[$campo]))['perfil'];
	}

	public function testE0714ReassinaComOOutroPerfilELembra(): void
	{
		$http = (new ClienteHttpFalso())
			->responder(400, self::E0714)
			->responder(201, ['chaveAcesso' => self::CHAVE])
			->responder(201, ['chaveAcesso' => self::CHAVE]);
		$nfse = $this->nfse($http);

		$r = $nfse->emitir(DpsExemplo::infDps());

		self::assertTrue($r->sucesso());
		self::assertCount(2, $http->chamadas);
		self::assertSame(PerfilAssinatura::Sha1, self::perfilDoEnvio($http, 0));
		self::assertSame(PerfilAssinatura::Sha256, self::perfilDoEnvio($http, 1));
		self::assertSame(PerfilAssinatura::Sha256, $nfse->perfilAtivo());
		self::assertSame([PerfilAssinatura::Sha256], $this->descobertos);

		// A nota seguinte já sai com o perfil descoberto, em uma chamada só.
		$nfse->emitir(DpsExemplo::infDps());
		self::assertCount(3, $http->chamadas);
		self::assertSame(PerfilAssinatura::Sha256, self::perfilDoEnvio($http, 2));
	}

	public function testOutrosErrosNaoDisparamFallback(): void
	{
		$http = (new ClienteHttpFalso())->responder(400, ['erros' => [['codigo' => 'E0718', 'descricao' => 'A assinatura deve ser feita com o certificado digital do emitente da DPS.']]]);
		$r = $this->nfse($http)->emitir(DpsExemplo::infDps());

		self::assertFalse($r->sucesso());
		self::assertCount(1, $http->chamadas);
		self::assertSame([], $this->descobertos);
	}

	public function testFallbackDesligadoNaoTentaDeNovo(): void
	{
		$http = (new ClienteHttpFalso())->responder(400, self::E0714);
		$nfse = $this->nfse($http, fallback: false);
		$r = $nfse->emitir(DpsExemplo::infDps());

		self::assertTrue($r->temErro('E0714'));
		self::assertCount(1, $http->chamadas);
		self::assertSame(PerfilAssinatura::Sha1, $nfse->perfilAtivo());
	}

	public function testSeOsDoisFalhamDevolveASegundaRecusaSemTrocarPerfil(): void
	{
		$http = (new ClienteHttpFalso())->responder(400, self::E0714)->responder(400, self::E0714);
		$nfse = $this->nfse($http);
		$r = $nfse->emitir(DpsExemplo::infDps());

		self::assertFalse($r->sucesso());
		self::assertCount(2, $http->chamadas);
		self::assertSame(PerfilAssinatura::Sha1, $nfse->perfilAtivo());
		self::assertSame([], $this->descobertos);
	}

	public function testXmlJaAssinadoTambemGanhaFallback(): void
	{
		$http = (new ClienteHttpFalso())->responder(400, self::E0714)->responder(201, ['chaveAcesso' => self::CHAVE]);
		$nfse = $this->nfse($http, perfil: PerfilAssinatura::Sha256);
		$assinado = $nfse->prepararDps(DpsExemplo::infDps());

		$r = $nfse->emitir($assinado);

		self::assertTrue($r->sucesso());
		self::assertSame(PerfilAssinatura::Sha256, self::perfilDoEnvio($http, 0));
		self::assertSame(PerfilAssinatura::Sha1, self::perfilDoEnvio($http, 1));
		self::assertSame(PerfilAssinatura::Sha1, $nfse->perfilAtivo());
	}

	public function testCancelamentoTambemTemFallback(): void
	{
		$http = (new ClienteHttpFalso())->responder(400, self::E0714)->responder(200, ['eventoXmlGZipB64' => Gzip::comprimirBase64('<evento/>')]);
		$r = $this->nfse($http)->cancelar(self::CHAVE, PedidoRegistroEventoXml::MOTIVO_OUTROS, 'Cancelamento solicitado pelo cliente');

		self::assertTrue($r->sucesso());
		self::assertSame(PerfilAssinatura::Sha1, self::perfilDoEnvio($http, 0, 'pedidoRegistroEventoXmlGZipB64'));
		self::assertSame(PerfilAssinatura::Sha256, self::perfilDoEnvio($http, 1, 'pedidoRegistroEventoXmlGZipB64'));
		self::assertSame([PerfilAssinatura::Sha256], $this->descobertos);
	}

	public function testDescobertaTentaCadaPerfilComNumeroDiferente(): void
	{
		$http = (new ClienteHttpFalso())->responder(400, self::E0714)->responder(201, ['chaveAcesso' => self::CHAVE]);
		$config = new Configuracao(Ambiente::Homologacao, CertificadoTeste::certificado());
		$descoberta = new DescobertaPerfil($config, static fn(Configuracao $c) => new Nfse($c, $http));

		$resultados = $descoberta->executar(DpsExemplo::infDps());

		self::assertCount(2, $resultados);
		self::assertSame([PerfilAssinatura::Sha1, PerfilAssinatura::Sha256], array_column($resultados, 'perfil'));
		self::assertSame(['42', '43'], array_column($resultados, 'nDPS'));
		self::assertSame([false, true], array_column($resultados, 'aceito'));
		self::assertSame([true, false], array_column($resultados, 'assinatura_recusada'));
		self::assertSame(PerfilAssinatura::Sha256, DescobertaPerfil::recomendacao($resultados));
		// Sem fallback interno: exatamente uma chamada por perfil, cada uma com seu nDPS.
		self::assertCount(2, $http->chamadas);
		self::assertStringContainsString('<nDPS>43</nDPS>', Gzip::descomprimirBase64(json_decode((string) $http->chamadas[1]['corpo'], true)['dpsXmlGZipB64']));
	}

	public function testDescobertaRecomendaQuemNaoCaiuEmE0714MesmoSemAceite(): void
	{
		$outroErro = ['erros' => [['codigo' => 'E0162', 'descricao' => 'regApTribSN indevido']]];
		$http = (new ClienteHttpFalso())->responder(400, self::E0714)->responder(400, $outroErro);
		$config = new Configuracao(Ambiente::Homologacao, CertificadoTeste::certificado());
		$resultados = (new DescobertaPerfil($config, static fn(Configuracao $c) => new Nfse($c, $http)))->executar(DpsExemplo::infDps());

		self::assertSame([false, false], array_column($resultados, 'aceito'));
		self::assertSame(PerfilAssinatura::Sha256, DescobertaPerfil::recomendacao($resultados));

		$http2 = (new ClienteHttpFalso())->responder(400, self::E0714)->responder(400, self::E0714);
		$r2 = (new DescobertaPerfil($config, static fn(Configuracao $c) => new Nfse($c, $http2)))->executar(DpsExemplo::infDps());
		self::assertNull(DescobertaPerfil::recomendacao($r2));
	}

	public function testDescobertaRecusaProducao(): void
	{
		$config = new Configuracao(Ambiente::Producao, CertificadoTeste::certificado());
		$this->expectException(DadosExcecao::class);
		(new DescobertaPerfil($config, static fn(Configuracao $c) => new Nfse($c, new ClienteHttpFalso())))->executar(DpsExemplo::infDps());
	}

	public function testConfiguracaoCopiaPreservaOResto(): void
	{
		$config = new Configuracao(Ambiente::Homologacao, CertificadoTeste::certificado(), 'app/9', PerfilAssinatura::Sha1, urlSefin: 'https://x.local');
		$copia = $config->comPerfil(PerfilAssinatura::Sha256)->comFallbackAssinatura(false);

		self::assertSame(PerfilAssinatura::Sha256, $copia->perfilAssinatura);
		self::assertFalse($copia->fallbackAssinatura);
		self::assertSame('app/9', $copia->versaoAplicativo);
		self::assertSame('https://x.local', $copia->urlSefin);
		self::assertSame($config->certificado, $copia->certificado);
		self::assertTrue($config->fallbackAssinatura);
	}
}
