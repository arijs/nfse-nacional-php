<?php declare(strict_types=1);

namespace Arijs\NfseNacional\Tests\Unit;

use Arijs\NfseNacional\Ambiente;
use Arijs\NfseNacional\Configuracao;
use Arijs\NfseNacional\Leiaute;
use Arijs\NfseNacional\Tests\Support\CertificadoTeste;
use Arijs\NfseNacional\Xsd\ValidadorXsd;
use PHPUnit\Framework\TestCase;

final class LeiauteTest extends TestCase
{
	public function testPadraoPorAmbienteEApelidos(): void
	{
		self::assertSame(Leiaute::V101, Leiaute::padraoPara(Ambiente::Producao));
		self::assertSame(Leiaute::V101Nt009, Leiaute::padraoPara(Ambiente::Homologacao));
		self::assertSame(Leiaute::V101Nt009, Leiaute::de(' NT009 '));
		self::assertSame(Leiaute::V101Nt009, Leiaute::de('1.01-nt009'));
		self::assertSame(Leiaute::V101, Leiaute::de('atual'));
		self::assertSame('1.01', Leiaute::V101Nt009->versaoXml());
		self::assertTrue(Leiaute::V101Nt009->exigeFinNfse());
		self::assertFalse(Leiaute::V101->exigeFinNfse());
	}

	public function testValidadorAchaOsArquivosDeCadaLeiaute(): void
	{
		$v = new ValidadorXsd();

		self::assertStringEndsWith('/1.01/DPS_v1.01.xsd', str_replace('\\', '/', $v->caminho('DPS', Leiaute::V101)));
		self::assertStringEndsWith('/1.01-nt009/DPS_v1.01.xsd', str_replace('\\', '/', $v->caminho('DPS', Leiaute::V101Nt009)));
		self::assertStringEndsWith('/1.01-nt009/pedRegEvento_v1.01.xsd', str_replace('\\', '/', $v->caminho('pedRegEvento', '1.01-nt009')));
		self::assertSame($v->caminho('DPS'), $v->caminho('DPS', Leiaute::V101));
	}

	public function testConfiguracaoDoAmbienteLeNfseLeiaute(): void
	{
		$dir = sys_get_temp_dir().'/nfse-leiaute-'.bin2hex(random_bytes(4));
		mkdir($dir);
		$pems = CertificadoTeste::pems();
		file_put_contents($dir.'/c.pem', $pems['cert']);
		file_put_contents($dir.'/k.pem', $pems['chave']);
		$env = ['NFSE_AMBIENTE' => '1', 'NFSE_CERT_PEM' => $dir.'/c.pem', 'NFSE_CHAVE_PEM' => $dir.'/k.pem'];

		try {
			self::assertSame(Leiaute::V101, Configuracao::doAmbiente($env)->leiaute());
			self::assertNull(Configuracao::doAmbiente($env)->leiauteDps);
			self::assertSame(Leiaute::V101Nt009, Configuracao::doAmbiente($env + ['NFSE_LEIAUTE' => 'nt009'])->leiaute());
		} finally {
			array_map('unlink', glob($dir.'/*') ?: []);
			rmdir($dir);
		}
	}
}
