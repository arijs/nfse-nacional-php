<?php declare(strict_types=1);

namespace Rhengles\NfseNacional\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Rhengles\NfseNacional\Certificado\CertificadoDigital;
use Rhengles\NfseNacional\Excecao\CertificadoExcecao;
use Rhengles\NfseNacional\Tests\Support\CertificadoTeste;

final class CertificadoDigitalTest extends TestCase
{
	public function testLePfxEExtraiDados(): void
	{
		$cert = CertificadoDigital::dePfx(CertificadoTeste::pfx('segredo'), 'segredo');

		self::assertSame(CertificadoTeste::CNPJ, $cert->cnpjOuCpf());
		self::assertSame('EMPRESA TESTE LTDA:'.CertificadoTeste::CNPJ, $cert->nome());
		self::assertTrue($cert->vigente());
		self::assertStringStartsWith('-----BEGIN CERTIFICATE-----', $cert->certificadoPem);
		self::assertMatchesRegularExpression('/^[A-Za-z0-9+\/=]+$/', $cert->certificadoBase64());
		self::assertSame('MII', substr($cert->certificadoBase64(), 0, 3));
		self::assertArrayHasKey('valido_ate', $cert->resumo());
	}

	public function testSenhaErrada(): void
	{
		$this->expectException(CertificadoExcecao::class);
		CertificadoDigital::dePfx(CertificadoTeste::pfx('segredo'), 'outra');
	}

	public function testCpfNoCn(): void
	{
		$p = CertificadoTeste::pems(30, 'FULANO DE TAL:12345678901');
		self::assertSame('12345678901', CertificadoDigital::dePem($p['cert'], $p['chave'])->cnpjOuCpf());

		$p = CertificadoTeste::pems(30, 'sem padrao');
		self::assertNull(CertificadoDigital::dePem($p['cert'], $p['chave'])->cnpjOuCpf());
	}

	public function testChaveQueNaoCasaComCertificado(): void
	{
		$a = CertificadoTeste::pems();
		$b = CertificadoTeste::pems();
		$this->expectException(CertificadoExcecao::class);
		CertificadoDigital::dePem($a['cert'], $b['chave']);
	}

	public function testAssinaturaBrutaVerificavel(): void
	{
		$cert = CertificadoTeste::certificado();
		$sig = $cert->assinar('dados', OPENSSL_ALGO_SHA256);
		$pub = openssl_pkey_get_public($cert->certificadoPem);
		self::assertNotFalse($pub);
		self::assertSame(1, openssl_verify('dados', $sig, $pub, OPENSSL_ALGO_SHA256));
	}
}
