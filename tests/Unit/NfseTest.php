<?php declare(strict_types=1);

namespace Rhengles\NfseNacional\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Rhengles\NfseNacional\Ambiente;
use Rhengles\NfseNacional\Assinatura\AssinadorXml;
use Rhengles\NfseNacional\Configuracao;
use Rhengles\NfseNacional\Evento\PedidoRegistroEventoXml;
use Rhengles\NfseNacional\Excecao\CertificadoExcecao;
use Rhengles\NfseNacional\Excecao\DadosExcecao;
use Rhengles\NfseNacional\Excecao\XsdExcecao;
use Rhengles\NfseNacional\Nfse;
use Rhengles\NfseNacional\Tests\Support\CertificadoTeste;
use Rhengles\NfseNacional\Tests\Support\ClienteHttpFalso;
use Rhengles\NfseNacional\Tests\Support\DpsExemplo;
use Rhengles\NfseNacional\Util\Gzip;
use Rhengles\NfseNacional\Xsd\ValidadorXsd;

final class NfseTest extends TestCase
{
	private const CHAVE = '35503082123456780001950000000000012026110000000017';

	private function nfse(ClienteHttpFalso $http, Ambiente $amb = Ambiente::Homologacao): Nfse
	{
		return new Nfse(new Configuracao($amb, CertificadoTeste::certificado(), 'ddsoft-online/1.0'), $http);
	}

	public function testEmitirPreencheConstantesValidaAssinaEEnvia(): void
	{
		$http = (new ClienteHttpFalso())->responder(201, ['chaveAcesso' => self::CHAVE]);
		$inf = DpsExemplo::infDps();
		unset($inf['tpAmb'], $inf['verAplic'], $inf['dhEmi'], $inf['dCompet'], $inf['tpEmit'], $inf['prest']['CNPJ']);

		$r = $this->nfse($http)->emitir($inf);

		self::assertTrue($r->sucesso());
		$xml = Gzip::descomprimirBase64($http->ultimoJson()['dpsXmlGZipB64']);
		self::assertStringContainsString('<tpAmb>2</tpAmb>', $xml);
		self::assertStringContainsString('<verAplic>ddsoft-online/1.0</verAplic>', $xml);
		self::assertStringContainsString('<tpEmit>1</tpEmit>', $xml);
		self::assertStringContainsString('<prest><CNPJ>'.CertificadoTeste::CNPJ.'</CNPJ>', $xml);
		self::assertMatchesRegularExpression('#<dhEmi>\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d-03:00</dhEmi>#', $xml);
		self::assertTrue(AssinadorXml::verificar($xml)['valida']);
		self::assertSame([], (new ValidadorXsd())->validar($xml, 'DPS'));
	}

	public function testAmbienteDivergenteNaoSaiDeCasa(): void
	{
		$http = new ClienteHttpFalso();
		$inf = DpsExemplo::infDps();
		$inf['tpAmb'] = '1';

		try {
			$this->nfse($http)->emitir($inf);
			self::fail();
		} catch (DadosExcecao $e) {
			self::assertStringContainsString('E0006', $e->getMessage());
		}
		self::assertSame([], $http->chamadas);
	}

	public function testXsdInvalidoNaoSaiDeCasa(): void
	{
		$http = new ClienteHttpFalso();
		$inf = DpsExemplo::infDps();
		unset($inf['valores']);

		$this->expectException(XsdExcecao::class);
		try {
			$this->nfse($http)->emitir($inf);
		} finally {
			self::assertSame([], $http->chamadas);
		}
	}

	public function testCancelarMontaEventoAssinadoEValido(): void
	{
		$http = (new ClienteHttpFalso())->responder(200, ['eventoXmlGZipB64' => Gzip::comprimirBase64('<evento/>')]);

		$r = $this->nfse($http)->cancelar(self::CHAVE, PedidoRegistroEventoXml::MOTIVO_ERRO_NA_EMISSAO, 'Nota emitida com valor incorreto');

		self::assertTrue($r->sucesso());
		$xml = Gzip::descomprimirBase64($http->ultimoJson()['pedidoRegistroEventoXmlGZipB64']);
		self::assertStringContainsString('<infPedReg Id="PRE'.self::CHAVE.'101101">', $xml);
		self::assertStringContainsString('<CNPJAutor>'.CertificadoTeste::CNPJ.'</CNPJAutor>', $xml);
		self::assertStringContainsString('<e101101><xDesc>Cancelamento de NFS-e</xDesc><cMotivo>1</cMotivo><xMotivo>Nota emitida com valor incorreto</xMotivo></e101101>', $xml);
		self::assertTrue(AssinadorXml::verificar($xml)['valida']);
		self::assertSame([], (new ValidadorXsd())->validar($xml, 'pedRegEvento'));
	}

	public function testCancelarExigeJustificativaMinima(): void
	{
		$this->expectException(DadosExcecao::class);
		$this->nfse(new ClienteHttpFalso())->cancelar(self::CHAVE, 9, 'curta');
	}

	public function testCertificadoForaDaValidadeNaoAssina(): void
	{
		$p = CertificadoTeste::pems(1);
		$cert = \Rhengles\NfseNacional\Certificado\CertificadoDigital::dePem($p['cert'], $p['chave']);
		self::assertTrue($cert->vigente());
		self::assertFalse($cert->vigente(new \DateTimeImmutable('+10 days')));
		self::assertFalse($cert->vigente(new \DateTimeImmutable('-1 day')));

		// A fachada checa a vigência antes de assinar (E1203 na Sefin).
		$config = new Configuracao(Ambiente::Homologacao, $cert, relogio: static fn() => new \DateTimeImmutable('+10 days'));
		$http = new ClienteHttpFalso();
		try {
			(new Nfse($config, $http))->emitir(DpsExemplo::infDps());
			self::fail('Deveria recusar certificado vencido.');
		} catch (CertificadoExcecao $e) {
			self::assertStringContainsString('E1203', $e->getMessage());
		}
		self::assertSame([], $http->chamadas);
	}
}
