<?php declare(strict_types=1);

namespace Rhengles\NfseNacional\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Rhengles\NfseNacional\Ambiente;
use Rhengles\NfseNacional\Assinatura\PerfilAssinatura;
use Rhengles\NfseNacional\Excecao\DadosExcecao;
use Rhengles\NfseNacional\Util\Formato;
use Rhengles\NfseNacional\Util\Gzip;

final class UtilTest extends TestCase
{
	public function testGzipIdaEVolta(): void
	{
		$xml = '<?xml version="1.0" encoding="UTF-8"?><DPS>ção</DPS>';
		$b64 = Gzip::comprimirBase64($xml);
		self::assertMatchesRegularExpression('/^[A-Za-z0-9+\/=]+$/', $b64);
		self::assertSame($xml, Gzip::descomprimirBase64($b64));
		self::assertSame("\x1f\x8b", substr((string) base64_decode($b64, true), 0, 2));
	}

	public function testGzipRecusaLixo(): void
	{
		$this->expectException(DadosExcecao::class);
		Gzip::descomprimirBase64(base64_encode('nao e gzip'));
	}

	public function testDecimal(): void
	{
		self::assertSame('0.00', Formato::decimal(0));
		self::assertSame('1500.00', Formato::decimal(1500));
		self::assertSame('1234.57', Formato::decimal(1234.567));
		self::assertSame('10.50', Formato::decimal('10,5'));
		self::assertSame('2.000', Formato::decimal('2', 3));
		$this->expectException(\InvalidArgumentException::class);
		Formato::decimal(-1);
	}

	public function testDataHoraComFuso(): void
	{
		$dh = new \DateTimeImmutable('2026-11-06 10:15:00', new \DateTimeZone('America/Sao_Paulo'));
		self::assertSame('2026-11-06T10:15:00-03:00', Formato::dataHora($dh));
		self::assertSame('2026-11-06', Formato::data($dh));
		self::assertSame('12345678000195', Formato::somenteDigitos('12.345.678/0001-95'));
	}

	public function testAmbienteEPerfilAceitamNomes(): void
	{
		self::assertSame(Ambiente::Producao, Ambiente::de('PROD'));
		self::assertSame(Ambiente::Homologacao, Ambiente::de(2));
		self::assertSame(Ambiente::Homologacao, Ambiente::de('producao-restrita'));
		self::assertStringContainsString('producaorestrita', Ambiente::Homologacao->urlSefin());
		self::assertSame(PerfilAssinatura::Sha256, PerfilAssinatura::de('SHA256'));
		$this->expectException(\InvalidArgumentException::class);
		Ambiente::de('lua');
	}
}
